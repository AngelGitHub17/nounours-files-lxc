# Nounours Files — LXC Proxmox

Agent « façon Google Drive » qui expose les disques d'un PC Windows (partages SMB),
installé dans un conteneur LXC Proxmox et publié via Nginx Proxy Manager.

```
Internet ──► Freebox ──► Nginx Proxy Manager (HTTPS) ──► LXC nounours-files (nginx + php-fpm, port 80)
                                                              │ /mnt/disks/C, D, E, F
                                     hôte Proxmox  ◄──────────┘ (montages SMB transmis au conteneur)
                                          │
                                          └── SMB (port 445) ──► PC Windows : partages « Disque C »…
```

## Installation

Dans le shell de l'**hôte** Proxmox (pas dans un conteneur) :

```bash
bash -c "$(curl -fsSL https://raw.githubusercontent.com/AngelGitHub17/nounours-files-lxc/main/nounours-files.sh)"
```

Ou, après avoir copié le script sur l'hôte : `bash nounours-files.sh`

L'assistant demande : l'ID / la taille du conteneur, l'IP du PC Windows et le compte
Windows qui a accès aux partages (les partages sont détectés automatiquement),
le jeton de l'agent, et éventuellement les identifiants DynHost OVH.

Ensuite :

| Commande (sur l'hôte) | Effet |
|---|---|
| relancer la commande d'installation | menu : mettre à jour / changer de disques / état |
| `bash nounours-files.sh update` | met à jour l'agent (jeton, disques et réglages conservés) |
| `bash nounours-files.sh shares` | change de PC, de compte Windows ou de disques |
| `pct exec <ID> -- nounours-files status` | état de l'agent et des disques |

Sans copier le script, ajoute l'action après `_` : `bash -c "$(curl -fsSL https://raw.githubusercontent.com/AngelGitHub17/nounours-files-lxc/main/nounours-files.sh)" _ update`
| `journalctl -t nounours-files` | historique des montages / démontages |

## Prérequis côté PC Windows

- Les disques sont partagés (clic droit sur le disque → Propriétés → Partage → Partage avancé),
  avec le droit « Modifier » pour le compte utilisé.
- Partage de fichiers autorisé dans le pare-feu Windows pour le réseau privé.
- Une IP fixe (réservation DHCP dans la Freebox) pour le PC **et** pour le conteneur.

## Nginx Proxy Manager

**Proxy Hosts → Add Proxy Host**

- *Details* : Domain `files.didikaria.fr`, Scheme `http`, IP du conteneur, Port `80`,
  cocher *Block Common Exploits*.
- *SSL* : Let's Encrypt, *Force SSL*, *HTTP/2*. Si le port 80 de la Freebox ne va pas à NPM,
  activer *Use a DNS Challenge* → fournisseur **OVH** (clés API OVH).
- *Advanced* → Custom Nginx Configuration :

```nginx
client_max_body_size 0;
proxy_request_buffering off;
proxy_buffering off;
proxy_read_timeout 3600s;
proxy_send_timeout 3600s;
```

Puis, dans `frontend-config.php` sur didikaria.fr, mettre l'URL publique de NPM dans `AGENT_URL`.

## Fonctionnement

- Les partages sont montés sur l'hôte dans `/mnt/nounours-files/<disque>` (un conteneur non
  privilégié ne peut pas monter de SMB lui-même) et transmis au conteneur dans `/mnt/disks`.
- Un watchdog (`nounours-files-mount.timer`, toutes les minutes) monte les disques quand le PC
  répond sur le port 445 et les démonte quand il disparaît : l'agent répond alors
  « PC distant indisponible » au lieu de rester bloqué, et le site se reconnecte tout seul.
- Seule l'API (`/` et `/index.php`) est servie par nginx dans le conteneur ; le jeton est stocké
  hors du dossier web (`/etc/nounours-files/auth-token.php`).

## Développement

`nounours-files.sh` est généré par `./build.sh` à partir de `src/nounours-files.head.sh`,
`src/nf-install.sh` et `agent/index.php` (embarqués en base64 pour que le script fonctionne
seul, y compris via `bash -c "$(curl -fsSL …)"`).
