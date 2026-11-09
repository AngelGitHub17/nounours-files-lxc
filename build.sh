#!/usr/bin/env bash
# Assemble nounours-files.sh : script hôte + agent embarqué (base64).
set -euo pipefail
cd "$(dirname "$0")"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
cp src/nf-install.sh agent/index.php "$TMP/"
{
  cat src/nounours-files.head.sh
  echo 'nf_payload() {'
  echo "cat <<'__NF_PAYLOAD_EOF__'"
  tar -czf - -C "$TMP" nf-install.sh index.php | base64 -w 76
  echo '__NF_PAYLOAD_EOF__'
  echo '}'
  echo
  echo 'main "$@"'
  echo 'exit 0'
} > nounours-files.sh
chmod +x nounours-files.sh
echo "nounours-files.sh : $(wc -c < nounours-files.sh) octets"
