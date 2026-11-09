#!/usr/bin/env bash
# ============================================================================
#  Nounours Files — installateur LXC pour Proxmox VE (style Community Scripts)
#
#  À lancer dans le shell de l'HÔTE Proxmox :
#     bash nounours-files.sh            # installation (ou menu si déjà installé)
#     bash nounours-files.sh update     # met à jour l'agent dans le conteneur
#     bash nounours-files.sh shares     # change les disques / le PC Windows
#
#  Ce qu'il fait :
#   1. crée un conteneur Debian 12 non privilégié (nginx + php-fpm + agent),
#   2. monte sur l'hôte les partages SMB du PC Windows et les donne au
#      conteneur (un LXC non privilégié ne peut pas monter du SMB lui-même),
#   3. installe un watchdog qui remonte les disques dès que le PC se rallume.
#  Le HTTPS est assuré par Nginx Proxy Manager, devant le conteneur.
# ============================================================================
set -Eeuo pipefail

APP="Nounours Files"
VERSION="1.0.0"
CONF_DIR=/etc/nounours-files
HOST_CONF="$CONF_DIR/host.conf"
SHARES_CONF="$CONF_DIR/shares.conf"
CRED_FILE="$CONF_DIR/smb.cred"
MOUNT_BASE=/mnt/nounours-files
WATCHDOG=/usr/local/sbin/nounours-files-mount
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

# ---------------------------------------------------------------- affichage
RD=$'\033[01;31m'; GN=$'\033[1;92m'; YW=$'\033[33m'; BL=$'\033[36m'; CL=$'\033[m'; BD=$'\033[1m'
msg_info()  { printf ' %s⏳%s %s…\n' "$YW" "$CL" "$1"; }
msg_ok()    { printf ' %s✔%s  %s\n' "$GN" "$CL" "$1"; }
msg_warn()  { printf ' %s⚠%s  %s\n' "$YW" "$CL" "$1"; }
msg_error() { printf ' %s✖%s  %s\n' "$RD" "$CL" "$1" >&2; }
die()       { msg_error "$1"; exit 1; }
trap 'msg_error "Erreur ligne $LINENO : $BASH_COMMAND"' ERR

header() {
  clear 2>/dev/null || true
  printf '%s' "$BL"
  cat <<'EOF'
    _   __                                         _______ __
   / | / /___  __  ______  ____  __  _______     / ____(_) /__  _____
  /  |/ / __ \/ / / / __ \/ __ \/ / / / ___/    / /_  / / / _ \/ ___/
 / /|  / /_/ / /_/ / / / / /_/ / /_/ / /       / __/ / / /  __(__  )
/_/ |_/\____/\__,_/_/ /_/\____/\__,_/_/       /_/   /_/_/\___/____/
EOF
  printf '%s   Installateur LXC Proxmox · v%s%s\n\n' "$CL" "$VERSION" "$CL"
}

WT_TITLE="$APP"
wt_input()  { whiptail --backtitle "$APP · Proxmox VE" --title "$WT_TITLE" --inputbox "$1" 11 70 "${2:-}" 3>&1 1>&2 2>&3; }
wt_pass()   { whiptail --backtitle "$APP · Proxmox VE" --title "$WT_TITLE" --passwordbox "$1" 10 70 3>&1 1>&2 2>&3; }
wt_yesno()  { whiptail --backtitle "$APP · Proxmox VE" --title "$WT_TITLE" --yesno "$1" "${2:-11}" 70; }
wt_msg()    { whiptail --backtitle "$APP · Proxmox VE" --title "$WT_TITLE" --msgbox "$1" "${2:-12}" 72; }

# ---------------------------------------------------------------- vérifications
check_host() {
  [[ $EUID -eq 0 ]] || die "Lance ce script en root dans le shell de l'hôte Proxmox."
  command -v pct >/dev/null 2>&1 || die "Commande pct introuvable : ce script doit tourner sur l'hôte Proxmox VE, pas dans un conteneur."
  command -v whiptail >/dev/null 2>&1 || { apt-get update -qq && apt-get install -y -qq whiptail >/dev/null; }
}

# ---------------------------------------------------------------- payload intégré
# L'agent est embarqué dans la fonction nf_payload (en fin de script) : ça
# marche aussi via « bash -c "$(curl …)" », où le script ne peut pas se relire.
extract_payload() {
  nf_payload | base64 -d | tar -xz -C "$TMP_DIR" || die "Contenu de l'agent illisible (script tronqué ?)."
  [[ -f "$TMP_DIR/index.php" && -f "$TMP_DIR/nf-install.sh" ]] || die "Contenu de l'agent incomplet."
}

# ---------------------------------------------------------------- SMB (hôte)
install_host_tools() {
  if ! command -v mount.cifs >/dev/null 2>&1 || ! command -v smbclient >/dev/null 2>&1; then
    msg_info "Installation de cifs-utils et smbclient sur l'hôte"
    apt-get update -qq && apt-get install -y -qq cifs-utils smbclient >/dev/null
    msg_ok "Outils SMB installés sur l'hôte"
  fi
}

# Transforme un nom de partage en identifiant de disque : « Disque C » -> C
drive_key() {
  local s="$1" k
  if [[ "$s" =~ ^([Dd]isque[[:space:]_-]*)?([A-Za-z])\$?$ ]]; then
    k="${BASH_REMATCH[2]^^}"
  else
    k="$(printf '%s' "$s" | iconv -f utf-8 -t ascii//TRANSLIT 2>/dev/null | tr -cd 'A-Za-z0-9_-')"
  fi
  printf '%s' "${k:-Disque}"
}

ask_smb() {
  WT_TITLE="PC Windows (partages SMB)"
  SMB_HOST="$(wt_input "Adresse IP locale du PC Windows qui partage les disques :\n(sur le PC : ipconfig → « Adresse IPv4 »)" "${SMB_HOST:-}")" || die "Annulé."
  [[ -n "$SMB_HOST" ]] || die "Adresse du PC obligatoire."
  if ! timeout 3 bash -c "</dev/tcp/$SMB_HOST/445" 2>/dev/null; then
    wt_yesno "Le port 445 (partage de fichiers) ne répond pas sur $SMB_HOST.\n\nLe PC est peut-être éteint, ou le partage de fichiers / le pare-feu Windows le bloque.\n\nContinuer quand même ?" 13 || die "Annulé."
  fi
  SMB_USER="$(wt_input "Utilisateur Windows qui a accès aux partages\n(compte local, ou adresse e-mail du compte Microsoft) :" "${SMB_USER:-}")" || die "Annulé."
  SMB_PASS="$(wt_pass "Mot de passe de $SMB_USER :")" || die "Annulé."
  SMB_DOMAIN="$(wt_input "Domaine / groupe de travail (laisse vide si tu ne sais pas) :" "${SMB_DOMAIN:-}")" || die "Annulé."

  install -d -m 700 "$CONF_DIR"
  umask 077
  { printf 'username=%s\npassword=%s\n' "$SMB_USER" "$SMB_PASS"; [[ -n "$SMB_DOMAIN" ]] && printf 'domain=%s\n' "$SMB_DOMAIN"; } > "$CRED_FILE"
  umask 022
  chmod 600 "$CRED_FILE"

  msg_info "Recherche des partages sur $SMB_HOST"
  local list
  list="$(smbclient -L "//$SMB_HOST" -A "$CRED_FILE" -g -m SMB3 2>/dev/null | awk -F'|' '$1=="Disk" && $2 !~ /\$$/ {print $2}')" || true
  local -a shares=()
  if [[ -n "$list" ]]; then
    mapfile -t shares <<<"$list"
    msg_ok "${#shares[@]} partage(s) trouvé(s)"
  else
    msg_warn "Aucun partage listé automatiquement (identifiants refusés, PC éteint ou découverte bloquée)"
  fi

  local -a chosen=()
  if ((${#shares[@]})); then
    local -a items=()
    for s in "${shares[@]}"; do items+=("$s" "→ disque $(drive_key "$s")" "ON"); done
    local sel
    sel="$(whiptail --backtitle "$APP · Proxmox VE" --title "Disques à partager" --separate-output \
      --checklist "Partages trouvés sur $SMB_HOST (espace pour cocher/décocher) :" 18 70 10 "${items[@]}" 3>&1 1>&2 2>&3)" || die "Annulé."
    mapfile -t chosen <<<"$sel"
  else
    local manual
    manual="$(wt_input "Noms exacts des partages, séparés par des virgules :" "Disque C,Disque D,Disque E,Disque F")" || die "Annulé."
    IFS=',' read -r -a chosen <<<"$manual"
  fi

  : > "$TMP_DIR/shares.conf"
  DRIVE_KEYS=""
  local s key
  for s in "${chosen[@]}"; do
    s="$(printf '%s' "$s" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
    [[ -n "$s" ]] || continue
    key="$(drive_key "$s")"
    while grep -q "^${key}|" "$TMP_DIR/shares.conf"; do key="${key}2"; done
    printf '%s|%s\n' "$key" "$s" >> "$TMP_DIR/shares.conf"
    DRIVE_KEYS+="$key "
  done
  DRIVE_KEYS="${DRIVE_KEYS% }"
  [[ -n "$DRIVE_KEYS" ]] || die "Aucun disque sélectionné."
  install -m 600 "$TMP_DIR/shares.conf" "$SHARES_CONF"
  msg_ok "Disques retenus : $DRIVE_KEYS"
}

install_watchdog() {
  msg_info "Installation du watchdog de montage sur l'hôte"
  install -d "$MOUNT_BASE"
  cat > "$WATCHDOG" <<'WATCHDOG_EOF'
#!/usr/bin/env bash
# Nounours Files — montage des partages SMB du PC Windows pour le conteneur.
# Lancé toutes les minutes : monte les disques quand le PC est joignable,
# les démonte proprement quand il disparaît (pour éviter les blocages).
set -u
. /etc/nounours-files/host.conf
BASE=/mnt/nounours-files
CRED=/etc/nounours-files/smb.cred
STAMP=/run/nounours-files.reboot-stamp
log() { logger -t nounours-files "$*"; echo "$*"; }

# Dossier de base en propagation partagée : les montages faits après le
# démarrage du conteneur lui apparaissent sans avoir à le redémarrer.
mountpoint -q "$BASE" || mount --bind "$BASE" "$BASE"
mount --make-rshared "$BASE"

ONLINE=0
timeout 2 bash -c "</dev/tcp/$SMB_HOST/445" 2>/dev/null && ONLINE=1
CHANGED=0

while IFS='|' read -r KEY SHARE; do
  [[ -n "$KEY" ]] || continue
  DIR="$BASE/$KEY"
  mkdir -p "$DIR"
  if mountpoint -q "$DIR"; then
    if (( ! ONLINE )); then
      umount -l "$DIR" && log "PC injoignable : disque $KEY démonté"
    fi
    continue
  fi
  (( ONLINE )) || continue
  if mount -t cifs "//$SMB_HOST/$SHARE" "$DIR" -o "credentials=$CRED,uid=100033,gid=100033,forceuid,forcegid,file_mode=0664,dir_mode=0775,iocharset=utf8,noserverino,soft,echo_interval=10,vers=3.0" 2>/tmp/nf-mount.err; then
    log "Disque $KEY monté (//$SMB_HOST/$SHARE)"
    CHANGED=1
  else
    log "Échec du montage de $KEY : $(tr '\n' ' ' </tmp/nf-mount.err)"
  fi
done < /etc/nounours-files/shares.conf

# Filet de sécurité : si un disque monté sur l'hôte n'apparaît pas dans le
# conteneur, on redémarre le conteneur (au plus une fois toutes les 10 min).
if (( CHANGED )) && pct status "$CTID" 2>/dev/null | grep -q running; then
  sleep 2
  MISSING=0
  while IFS='|' read -r KEY SHARE; do
    [[ -n "$KEY" ]] || continue
    mountpoint -q "$BASE/$KEY" || continue
    pct exec "$CTID" -- mountpoint -q "/mnt/disks/$KEY" 2>/dev/null || MISSING=1
  done < /etc/nounours-files/shares.conf
  if (( MISSING )); then
    NOW=$(date +%s); LAST=$(cat "$STAMP" 2>/dev/null || echo 0)
    if (( NOW - LAST > 600 )); then
      echo "$NOW" > "$STAMP"
      log "Disques invisibles dans le conteneur $CTID : redémarrage du conteneur"
      pct reboot "$CTID" >/dev/null 2>&1 || true
    fi
  fi
fi
WATCHDOG_EOF
  chmod 750 "$WATCHDOG"

  cat > /etc/systemd/system/nounours-files-mount.service <<EOF
[Unit]
Description=Nounours Files - montage des disques du PC Windows
After=network-online.target
Wants=network-online.target
Before=pve-guests.service
[Service]
Type=oneshot
ExecStart=$WATCHDOG
EOF
  cat > /etc/systemd/system/nounours-files-mount.timer <<'EOF'
[Unit]
Description=Nounours Files - vérification des disques toutes les minutes
[Timer]
OnBootSec=15s
OnUnitActiveSec=60s
AccuracySec=5s
[Install]
WantedBy=timers.target
EOF
  # Au démarrage de l'hôte : montage avant le lancement des conteneurs.
  cat > /etc/systemd/system/nounours-files-boot.service <<EOF
[Unit]
Description=Nounours Files - montage initial avant les conteneurs
After=network-online.target
Wants=network-online.target
Before=pve-guests.service
[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=$WATCHDOG
[Install]
WantedBy=multi-user.target
EOF
  systemctl daemon-reload
  systemctl enable --now nounours-files-mount.timer >/dev/null 2>&1
  systemctl enable nounours-files-boot.service >/dev/null 2>&1
  msg_ok "Watchdog installé (vérification toutes les minutes)"
}

write_host_conf() {
  install -d -m 700 "$CONF_DIR"
  cat > "$HOST_CONF" <<EOF
CTID='$CTID'
SMB_HOST='$SMB_HOST'
SMB_USER='$SMB_USER'
SMB_DOMAIN='$SMB_DOMAIN'
EOF
  chmod 600 "$HOST_CONF"
}

# ---------------------------------------------------------------- conteneur
pick_storage() {
  local content="$1" label="$2" list count
  list="$(pvesm status -content "$content" 2>/dev/null | awk 'NR>1 && $3=="active" {print $1}')"
  count="$(printf '%s\n' "$list" | grep -c . || true)"
  ((count)) || die "Aucun stockage Proxmox actif n'accepte « $content »."
  if ((count == 1)); then printf '%s' "$list"; return; fi
  local -a items=()
  local s; for s in $list; do items+=("$s" " "); done
  whiptail --backtitle "$APP · Proxmox VE" --title "Stockage" --menu "$label" 16 60 6 "${items[@]}" 3>&1 1>&2 2>&3 || die "Annulé."
}

ask_container() {
  WT_TITLE="Conteneur LXC"
  CTID="$(pvesh get /cluster/nextid)"
  HN="nounours-files"; DISK=4; RAM=512; CORES=2; BRIDGE=vmbr0; NET=dhcp; GATE=""
  if ! wt_yesno "Créer le conteneur avec les réglages par défaut ?\n\n  ID : $CTID\n  Nom : $HN\n  Disque : ${DISK} Go · RAM : ${RAM} Mo · CPU : $CORES\n  Réseau : $BRIDGE en DHCP\n\n(Non = personnaliser)" 16; then
    CTID="$(wt_input "ID du conteneur :" "$CTID")" || die "Annulé."
    HN="$(wt_input "Nom d'hôte :" "$HN")" || die "Annulé."
    DISK="$(wt_input "Taille du disque (Go) :" "$DISK")" || die "Annulé."
    RAM="$(wt_input "Mémoire (Mo) :" "$RAM")" || die "Annulé."
    CORES="$(wt_input "Nombre de cœurs :" "$CORES")" || die "Annulé."
    BRIDGE="$(wt_input "Pont réseau :" "$BRIDGE")" || die "Annulé."
    NET="$(wt_input "IP du conteneur : « dhcp » ou fixe en CIDR (ex : 192.168.1.50/24) :" "$NET")" || die "Annulé."
    if [[ "$NET" != "dhcp" ]]; then GATE="$(wt_input "Passerelle (la Freebox, souvent 192.168.1.254) :" "192.168.1.254")" || die "Annulé."; fi
  fi
  pct status "$CTID" >/dev/null 2>&1 && die "L'ID $CTID est déjà utilisé."
  ROOT_STORAGE="$(pick_storage rootdir "Stockage du disque du conteneur :")"
  TPL_STORAGE="$(pick_storage vztmpl "Stockage des modèles (templates) :")"
}

ask_token() {
  WT_TITLE="Jeton de l'agent"
  local gen
  gen="$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 48 || true)"
  TOKEN="$(wt_input "Jeton secret de l'agent.\n\nPour garder ton site actuel sans rien changer, colle le jeton déjà présent dans frontend-config.php (AGENT_TOKEN). Sinon garde celui généré et reporte-le là-bas." "$gen")" || die "Annulé."
  [[ "$TOKEN" =~ ^[A-Za-z0-9_-]{16,}$ ]] || die "Jeton invalide (au moins 16 caractères, lettres/chiffres/_/-)."
}

ask_dynhost() {
  WT_TITLE="DynHost OVH (optionnel)"
  DYN_HOST=""; DYN_LOGIN=""; DYN_PASS=""
  if wt_yesno "Faire mettre à jour le DynHost OVH par le conteneur quand ton IP publique change ?\n\n(Identifiants : espace client OVH → didikaria.fr → DynHost → Gérer les accès.)\n\nInutile si Nginx Proxy Manager ou autre chose le fait déjà." 14; then
    DYN_HOST="$(wt_input "Nom DynHost :" "files.didikaria.fr")" || die "Annulé."
    DYN_LOGIN="$(wt_input "Identifiant DynHost :")" || die "Annulé."
    DYN_PASS="$(wt_pass "Mot de passe DynHost :")" || die "Annulé."
  fi
}

get_template() {
  msg_info "Recherche du modèle Debian 12"
  pveam update >/dev/null 2>&1 || msg_warn "Mise à jour de la liste des modèles impossible (on utilise le cache)"
  TEMPLATE="$(pveam available -section system 2>/dev/null | awk '$2 ~ /^debian-12-standard_.*amd64/ {print $2}' | sort -V | tail -1)"
  [[ -n "$TEMPLATE" ]] || die "Modèle Debian 12 introuvable (pas d'accès Internet depuis l'hôte ?)."
  if ! pveam list "$TPL_STORAGE" 2>/dev/null | grep -q "$TEMPLATE"; then
    msg_info "Téléchargement de $TEMPLATE"
    pveam download "$TPL_STORAGE" "$TEMPLATE" >/dev/null
  fi
  msg_ok "Modèle : $TEMPLATE"
}

create_container() {
  local net="name=eth0,bridge=$BRIDGE,ip=$NET"
  [[ -n "$GATE" ]] && net+=",gw=$GATE"
  msg_info "Création du conteneur $CTID"
  pct create "$CTID" "$TPL_STORAGE:vztmpl/$TEMPLATE" \
    --hostname "$HN" --cores "$CORES" --memory "$RAM" --swap 512 \
    --rootfs "$ROOT_STORAGE:$DISK" --net0 "$net" \
    --unprivileged 1 --features nesting=1 --onboot 1 --ostype debian \
    --tags nounours-files \
    --description "<b>Nounours Files</b><br>Agent façon Google Drive pour les disques du PC $SMB_HOST.<br>Disques : $DRIVE_KEYS<br>Commande : <code>nounours-files status</code>" >/dev/null
  pct set "$CTID" -mp0 "$MOUNT_BASE,mp=/mnt/disks" >/dev/null
  msg_ok "Conteneur $CTID créé"
  msg_info "Démarrage du conteneur"
  pct start "$CTID"
  local i
  for i in $(seq 1 60); do
    pct exec "$CTID" -- getent hosts deb.debian.org >/dev/null 2>&1 && break
    sleep 2
    ((i == 60)) && die "Le conteneur n'a pas accès à Internet (DNS / passerelle ?)."
  done
  msg_ok "Conteneur démarré et connecté"
}

run_inside() {
  local update_only="${1:-0}"
  pct exec "$CTID" -- mkdir -p /root/nf-setup
  pct push "$CTID" "$TMP_DIR/nf-install.sh" /root/nf-setup/nf-install.sh
  pct push "$CTID" "$TMP_DIR/index.php" /root/nf-setup/index.php
  pct exec "$CTID" -- env \
    NF_UPDATE_ONLY="$update_only" NF_TOKEN="${TOKEN:-unused}" NF_SMB_HOST="$SMB_HOST" NF_DRIVES="$DRIVE_KEYS" \
    NF_DYNHOST_HOST="${DYN_HOST:-}" NF_DYNHOST_LOGIN="${DYN_LOGIN:-}" NF_DYNHOST_PASS="${DYN_PASS:-}" \
    bash /root/nf-setup/nf-install.sh
  pct exec "$CTID" -- rm -rf /root/nf-setup
}

ct_ip() {
  pct exec "$CTID" -- hostname -I 2>/dev/null | awk '{print $1}'
}

summary() {
  local ip; ip="$(ct_ip)"
  echo
  printf '%s%s installé !%s\n\n' "$GN$BD" "$APP" "$CL"
  printf '  Conteneur  : %s (%s)\n' "$CTID" "$HN"
  printf '  Agent      : %shttp://%s/%s  (réseau local)\n' "$BL" "$ip" "$CL"
  printf '  Disques    : %s\n' "$DRIVE_KEYS"
  printf '  Test       : pct exec %s -- nounours-files status\n\n' "$CTID"
  printf '%sDans Nginx Proxy Manager → Proxy Hosts → Add :%s\n' "$BD" "$CL"
  printf '  Domain : files.didikaria.fr · Scheme : http · IP : %s · Port : 80\n' "$ip"
  printf '  SSL    : Let'"'"'s Encrypt, Force SSL\n'
  printf '  Advanced (Custom Nginx Configuration) :\n'
  printf '%s    client_max_body_size 0;\n    proxy_request_buffering off;\n    proxy_buffering off;\n    proxy_read_timeout 3600s;\n    proxy_send_timeout 3600s;%s\n\n' "$YW" "$CL"
  printf '  Puis dans frontend-config.php : AGENT_URL = https://files.didikaria.fr/\n\n'
  [[ "$NET" == "dhcp" ]] && msg_warn "IP en DHCP : réserve-la dans la Freebox pour qu'elle ne change pas (sinon NPM perdra le conteneur)."
  return 0
}

# ---------------------------------------------------------------- actions
do_install() {
  header
  wt_yesno "Installer $APP dans un nouveau conteneur LXC ?\n\nLes disques du PC Windows seront montés sur cet hôte Proxmox (outils cifs-utils / smbclient installés si besoin) puis transmis au conteneur." 13 || exit 0
  extract_payload
  install_host_tools
  ask_container
  ask_smb
  ask_token
  ask_dynhost
  write_host_conf
  install_watchdog
  msg_info "Montage des disques"
  "$WATCHDOG" >/dev/null 2>&1 || true
  local k mounted=""
  for k in $DRIVE_KEYS; do mountpoint -q "$MOUNT_BASE/$k" && mounted+="$k "; done
  if [[ -n "$mounted" ]]; then msg_ok "Disques montés : $mounted"; else msg_warn "Aucun disque monté pour l'instant (PC éteint ?) : le watchdog réessaiera chaque minute"; fi
  get_template
  create_container
  msg_info "Installation de l'agent dans le conteneur (1 à 3 min)"
  run_inside 0 >/tmp/nounours-files-install.log 2>&1 || { msg_error "Échec : voir /tmp/nounours-files-install.log"; tail -20 /tmp/nounours-files-install.log; exit 1; }
  msg_ok "Agent installé"
  summary
}

load_existing() {
  [[ -f "$HOST_CONF" ]] || die "Aucune installation trouvée ($HOST_CONF absent)."
  # shellcheck disable=SC1090
  . "$HOST_CONF"
  pct status "$CTID" >/dev/null 2>&1 || die "Le conteneur $CTID n'existe plus."
  DRIVE_KEYS="$(cut -d'|' -f1 "$SHARES_CONF" | tr '\n' ' ')"; DRIVE_KEYS="${DRIVE_KEYS% }"
}

do_update() {
  header
  load_existing
  extract_payload
  msg_info "Mise à jour de l'agent dans le conteneur $CTID"
  run_inside 1 >/tmp/nounours-files-install.log 2>&1 || die "Échec : voir /tmp/nounours-files-install.log"
  msg_ok "Agent mis à jour (configuration, jeton et disques conservés)"
}

do_shares() {
  header
  load_existing
  extract_payload
  local old_keys="$DRIVE_KEYS"
  ask_smb
  write_host_conf
  install_watchdog
  # Démonte les anciens disques retirés de la liste.
  local k
  for k in $old_keys; do
    grep -q "^${k}|" "$SHARES_CONF" || { umount -l "$MOUNT_BASE/$k" 2>/dev/null || true; rmdir "$MOUNT_BASE/$k" 2>/dev/null || true; }
  done
  "$WATCHDOG" >/dev/null 2>&1 || true
  TOKEN="$(pct exec "$CTID" -- nounours-files token)"
  msg_info "Mise à jour de la configuration du conteneur"
  run_inside 0 >/tmp/nounours-files-install.log 2>&1 || die "Échec : voir /tmp/nounours-files-install.log"
  msg_ok "Disques mis à jour : $DRIVE_KEYS"
}

main() {
  check_host
  case "${1:-}" in
    update) do_update ;;
    shares) do_shares ;;
    "")
      if [[ -f "$HOST_CONF" ]]; then
        header
        local choice
        choice="$(whiptail --backtitle "$APP · Proxmox VE" --title "$APP déjà installé" --menu "Que veux-tu faire ?" 15 66 4 \
          update "Mettre à jour l'agent" \
          shares "Changer de PC / de disques" \
          status "Voir l'état des disques" \
          install "Installer un nouveau conteneur" 3>&1 1>&2 2>&3)" || exit 0
        case "$choice" in
          update) do_update ;;
          shares) do_shares ;;
          status) load_existing; "$WATCHDOG"; pct exec "$CTID" -- nounours-files status ;;
          install) do_install ;;
        esac
      else
        do_install
      fi ;;
    *) echo "Usage : bash $0 [update|shares]"; exit 1 ;;
  esac
}

nf_payload() {
cat <<'__NF_PAYLOAD_EOF__'
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
uud/QM3+VgCgAAA=
__NF_PAYLOAD_EOF__
}

main "$@"
exit 0
