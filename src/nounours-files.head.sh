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

