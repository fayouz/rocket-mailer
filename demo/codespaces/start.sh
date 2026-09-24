#!/usr/bin/env bash
# Starts the demo in a GitHub Codespace (also works locally, with localhost URLs).
set -euo pipefail
cd "$(dirname "$0")/../.."

if [ -n "${CODESPACE_NAME:-}" ]; then
  public_url() { echo "https://${CODESPACE_NAME}-$1.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN}"; }
  export DEMO_MAILER_URL="$(public_url 3000)"
  export DEMO_HOST_ORIGIN="$(public_url 4000)"
  export PUBLIC_MAILPIT_URL="$(public_url 8025)"
else
  DEMO_MAILER_URL=http://localhost:3000
  DEMO_HOST_ORIGIN=http://localhost:4000
fi

docker compose -f compose.yaml -f compose.demo.yaml up -d --build

# The Démo CRM page (4000) loads the composer iframe from the front (3000):
# the front must be reachable without a GitHub login.
if [ -n "${CODESPACE_NAME:-}" ]; then
  gh codespace ports visibility 3000:public 4000:public -c "$CODESPACE_NAME" \
    || echo "⚠️  Rendez les ports 3000 et 4000 publics (onglet Ports → clic droit → Port Visibility → Public)."
fi

cat <<INFO

✅ Démo Rocket Mailer démarrée
   Rocket Mailer : ${DEMO_MAILER_URL}   (admin@example.org / demo-admin-password)
   Démo CRM      : ${DEMO_HOST_ORIGIN}
   Mailpit       : port 8025 (onglet Ports)
   Guide         : demo/README.md
INFO
