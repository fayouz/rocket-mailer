#!/usr/bin/env bash
# Starts the demo in a GitHub Codespace (also works locally, with localhost URLs).
set -euo pipefail
cd "$(dirname "$0")/../.."

if [ -n "${CODESPACE_NAME:-}" ]; then
  public_url() { echo "https://${CODESPACE_NAME}-$1.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN}"; }
  export DEMO_MAILER_URL="$(public_url 3000)"
  export DEMO_HOST_ORIGIN="$(public_url 4000)"
  export PUBLIC_MAILPIT_URL="$(public_url 8025)"
  # Use links that are valid even when Codespaces has not forwarded Docker port 3001.
  export DEMO_DOCS_URL="https://github.com/fayouz/rocket-mailer/tree/develop/docs/content"
  export DEMO_CHANGELOG_URL="https://github.com/fayouz/rocket-mailer/blob/develop/CHANGELOG.md"
else
  export DEMO_MAILER_URL=http://localhost:3000
  export DEMO_HOST_ORIGIN=http://localhost:4000
  export DEMO_DOCS_URL=http://localhost:3001
  export DEMO_CHANGELOG_URL=http://localhost:3001/changelog
fi

docker compose -f compose.yaml -f compose.demo.yaml up -d --build

# The Démo CRM page (4000) loads the composer iframe from the front (3000):
# the front must be reachable without a GitHub login.
if [ -n "${CODESPACE_NAME:-}" ]; then
  # Codespaces can discover Docker-published ports a few seconds after Compose starts.
  for i in $(seq 1 30); do
    if curl --fail --silent --output /dev/null http://localhost:3001/ \
      && gh codespace ports --json sourcePort -c "$CODESPACE_NAME" \
        --jq '.[].sourcePort' | grep -qx '3001'; then
      break
    fi
    sleep 2
  done

  gh codespace ports visibility 3000:public 4000:public -c "$CODESPACE_NAME" || true
  if gh codespace ports visibility 3001:public -c "$CODESPACE_NAME"; then
    export DEMO_DOCS_URL="$(public_url 3001)"
    export DEMO_CHANGELOG_URL="$(public_url 3001)/changelog"
    docker compose -f compose.yaml -f compose.demo.yaml up -d --no-deps front
  else
    echo "⚠️  Le port 3001 n'est pas public : les raccourcis utilisent GitHub pour éviter un 404."
  fi
fi

cat <<INFO

✅ Démo Rocket Mailer démarrée
   Rocket Mailer : ${DEMO_MAILER_URL}   (admin@example.org / demo-admin-password)
   Démo CRM      : ${DEMO_HOST_ORIGIN}
    Documentation : ${DEMO_DOCS_URL}   (changelog : ${DEMO_CHANGELOG_URL})
   Mailpit       : port 8025 (onglet Ports)
   Guide         : demo/README.md
INFO
