#!/usr/bin/env bash
# Nounours Files — installation DANS le conteneur LXC (appelé par nounours-files.sh)
# Variables attendues : NF_TOKEN, NF_SMB_HOST, NF_DRIVES ("C D E F"),
#                       NF_DYNHOST_HOST, NF_DYNHOST_LOGIN, NF_DYNHOST_PASS (optionnelles)
set -Eeuo pipefail
export DEBIAN_FRONTEND=noninteractive

: "${NF_TOKEN:?NF_TOKEN manquant}"
: "${NF_DRIVES:?NF_DRIVES manquant}"
NF_SMB_HOST="${NF_SMB_HOST:-}"
NF_UPDATE_ONLY="${NF_UPDATE_ONLY:-0}"
SRC="$(cd "$(dirname "$0")" && pwd)"
APP_DIR=/opt/nounours-files
CONF_DIR=/etc/nounours-files
CACHE_DIR=/var/lib/nounours-files/cache

if [[ "$NF_UPDATE_ONLY" != "1" ]]; then
  echo ">> Installation des paquets (nginx, php-fpm)…"
  apt-get update -qq
  apt-get install -y -qq --no-install-recommends nginx php-fpm php-zip php-mbstring curl ca-certificates >/dev/null
fi

PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
FPM_SOCK="/run/php/php${PHPV}-fpm.sock"

echo ">> Déploiement de l'agent…"
install -d -m 755 "$APP_DIR"
install -d -m 750 -o root -g www-data "$CONF_DIR"
install -d -m 750 -o www-data -g www-data "$CACHE_DIR"
install -m 644 "$SRC/index.php" "$APP_DIR/index.php"

if [[ "$NF_UPDATE_ONLY" == "1" ]]; then
  systemctl restart "php${PHPV}-fpm"
  echo ">> Agent mis à jour."
  exit 0
fi

# Jeton : hors du dossier web, lisible uniquement par root et www-data.
printf "<?php\ndefine('AUTH_TOKEN', '%s');\n" "$NF_TOKEN" > "$CONF_DIR/auth-token.php"
chown root:www-data "$CONF_DIR/auth-token.php"; chmod 640 "$CONF_DIR/auth-token.php"

drives_php=""
for d in $NF_DRIVES; do drives_php+="    '$d' => '/mnt/disks/$d',"$'\n'; done

cat > "$APP_DIR/config.php" <<EOF
<?php
/**
 * Configuration de l'agent Nounours Files (conteneur LXC).
 * Les disques sont les partages SMB du PC Windows, montés par l'hôte
 * Proxmox dans /mnt/nounours-files puis visibles ici dans /mnt/disks.
 * Pour modifier les disques, relance le script sur l'hôte.
 */
require_once '$CONF_DIR/auth-token.php';

define('DRIVES', [
$drives_php]);

// PC Windows qui partage les disques : testé avant chaque accès pour
// répondre « PC distant indisponible » au lieu de rester bloqué.
define('SMB_HOST', '$NF_SMB_HOST');
define('REQUIRE_MOUNT', true);

define('MAX_UPLOAD_SIZE', 20 * 1024 * 1024 * 1024);
define('CACHE_DIR', '$CACHE_DIR');
define('CACHE_TTL', 300);

define('SKIP_ENTRIES', [
    'documents and settings',
    'system volume information',
    '\$recycle.bin',
    'msocache',
    'recovery',
    'pagefile.sys',
    'hiberfil.sys',
    'swapfile.sys',
    'dumpstack.log.tmp',
]);
EOF
chown root:www-data "$APP_DIR/config.php"; chmod 640 "$APP_DIR/config.php"

echo ">> Configuration de PHP…"
cat > "/etc/php/${PHPV}/fpm/conf.d/99-nounours-files.ini" <<'EOF'
upload_max_filesize = 20G
post_max_size = 20G
memory_limit = 512M
max_execution_time = 0
max_input_time = -1
output_buffering = 0
display_errors = Off
EOF
POOL="/etc/php/${PHPV}/fpm/pool.d/www.conf"
sed -i -E 's/^;?pm\.max_children = .*/pm.max_children = 12/' "$POOL"
sed -i -E 's/^;?request_terminate_timeout = .*/request_terminate_timeout = 0/' "$POOL"

echo ">> Configuration de nginx…"
# IPv6 seulement s'il est disponible (désactivé sur certains hôtes Proxmox).
LISTEN6=""
[[ -s /proc/net/if_inet6 ]] && LISTEN6="listen [::]:80 default_server;"
cat > /etc/nginx/sites-available/nounours-files <<EOF
# Nounours Files — agent derrière Nginx Proxy Manager
server {
    listen 80 default_server;
    $LISTEN6
    server_name _;
    root $APP_DIR;

    # Gros fichiers : pas de limite, pas de mise en tampon sur le disque du
    # conteneur (sinon un ISO de 5 Go remplirait les 4 Go du conteneur).
    client_max_body_size 0;
    client_body_buffer_size 4m;

    # Vraie IP du visiteur transmise par Nginx Proxy Manager (réseau local).
    set_real_ip_from 10.0.0.0/8;
    set_real_ip_from 172.16.0.0/12;
    set_real_ip_from 192.168.0.0/16;
    real_ip_header X-Forwarded-For;

    access_log /var/log/nginx/nounours-files.access.log;
    error_log  /var/log/nginx/nounours-files.error.log;

    # Seule l'API est exposée : tout le reste répond 404.
    location ~ ^/(index\.php)?$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root/index.php;
        fastcgi_pass unix:$FPM_SOCK;
        fastcgi_request_buffering off;
        fastcgi_buffering off;
        fastcgi_read_timeout 3600s;
        fastcgi_send_timeout 3600s;
    }
    location / { return 404; }
}
EOF
rm -f /etc/nginx/sites-enabled/default
ln -sf /etc/nginx/sites-available/nounours-files /etc/nginx/sites-enabled/nounours-files
nginx -t -q

# ---- DynHost OVH (optionnel) ----
if [[ -n "${NF_DYNHOST_LOGIN:-}" && -n "${NF_DYNHOST_PASS:-}" && -n "${NF_DYNHOST_HOST:-}" ]]; then
  echo ">> Activation de la mise à jour DynHost…"
  umask 077
  cat > "$CONF_DIR/dynhost.conf" <<EOF
DYN_HOST='$NF_DYNHOST_HOST'
DYN_LOGIN='$NF_DYNHOST_LOGIN'
DYN_PASS='$NF_DYNHOST_PASS'
EOF
  umask 022
  chmod 600 "$CONF_DIR/dynhost.conf"
  cat > /usr/local/bin/nounours-dynhost <<'EOF'
#!/usr/bin/env bash
# Met à jour le DynHost OVH si l'IP publique a changé.
set -u
. /etc/nounours-files/dynhost.conf
IP="$(curl -fsS --max-time 8 https://api.ipify.org || curl -fsS --max-time 8 https://ifconfig.me/ip || true)"
[[ "$IP" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "IP publique introuvable"; exit 0; }
STATE=/var/lib/nounours-files/dynhost.last
[[ -f "$STATE" && "$(cat "$STATE")" == "$IP" ]] && exit 0
for ENDPOINT in https://dns.eu.ovhapis.com https://www.ovh.com; do
  R="$(curl -fsS --max-time 15 -u "$DYN_LOGIN:$DYN_PASS" "$ENDPOINT/nic/update?system=dyndns&hostname=$DYN_HOST&myip=$IP" 2>&1)" || continue
  echo "DynHost $DYN_HOST -> $IP : $R"
  [[ "$R" =~ ^(good|nochg) ]] && echo "$IP" > "$STATE"
  exit 0
done
echo "DynHost : OVH n'a pas répondu"
EOF
  chmod 750 /usr/local/bin/nounours-dynhost
  cat > /etc/systemd/system/nounours-dynhost.service <<'EOF'
[Unit]
Description=Nounours Files - mise à jour DynHost OVH
After=network-online.target
Wants=network-online.target
[Service]
Type=oneshot
ExecStart=/usr/local/bin/nounours-dynhost
EOF
  cat > /etc/systemd/system/nounours-dynhost.timer <<'EOF'
[Unit]
Description=Nounours Files - DynHost toutes les 5 minutes
[Timer]
OnBootSec=1min
OnUnitActiveSec=5min
[Install]
WantedBy=timers.target
EOF
  systemctl daemon-reload
  systemctl enable --now nounours-dynhost.timer >/dev/null 2>&1 || true
fi

# ---- Petit outil en ligne de commande ----
cat > /usr/local/bin/nounours-files <<'EOF'
#!/usr/bin/env bash
# nounours-files status | token | logs
TOKEN="$(php -r "require '/etc/nounours-files/auth-token.php'; echo AUTH_TOKEN;")"
case "${1:-status}" in
  status)
    echo "Agent :"; curl -fsS --max-time 5 "http://127.0.0.1/?action=health&token=$TOKEN"; echo
    echo "Disques montés :"; for d in /mnt/disks/*; do
      if mountpoint -q "$d"; then echo "  $(basename "$d") : monté"; else echo "  $(basename "$d") : NON monté"; fi; done ;;
  token) echo "$TOKEN" ;;
  logs)  tail -n 50 /var/log/nginx/nounours-files.error.log /var/log/nginx/nounours-files.access.log ;;
  *) echo "Usage : nounours-files status | token | logs" ;;
esac
EOF
chmod 755 /usr/local/bin/nounours-files

systemctl enable "php${PHPV}-fpm" nginx >/dev/null 2>&1 || true
systemctl restart "php${PHPV}-fpm"
systemctl restart nginx
echo ">> Agent installé."
