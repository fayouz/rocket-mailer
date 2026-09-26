#!/usr/bin/env bash
# Demo scenarios of rocket-mailer, checked by the CI (reusable workflow brick-demo.yml of rocket-core) once the demo stack
# (compose.yaml + compose.demo.yaml) is up. Run with "bash -e" from the repository root; environment:
# COMPOSE (docker compose -f compose.yaml -f compose.demo.yaml), FRONT, DOCS (demo front and docs URLs), APP_VERSION.
# Locally: COMPOSE="docker compose -f compose.yaml -f compose.demo.yaml" FRONT=http://localhost:3000 DOCS=http://localhost:3001 bash -e .github/demo-scenarios.sh
set -x
# Seeded accounts: the first-run setup is closed
curl -fsS $FRONT/api/setup | jq -e '.required == false'
# Local account (through the front's same-origin /api proxy, as in Codespaces)
curl -fsS -X POST $FRONT/api/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"alice@example.org","password":"demo-alice-password"}' | jq -e .token
# LDAP account, admin through its directory group
TOKEN=$(curl -fsS -X POST $FRONT/api/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"marie.martin@example.org","password":"password"}' | jq -r .token)
curl -fsS $FRONT/api/me -H "Authorization: Bearer $TOKEN" | jq -e '.roles | index("ROLE_ADMIN")'
# Version of the images, shown by the API and the interface; no one-click update without UPDATER_TOKEN
curl -fsS $FRONT/api/system/version -H "Authorization: Bearer $TOKEN" | jq -e '.version == "0.0.0-ci"'
curl -fsS $FRONT/api/system/update -H "Authorization: Bearer $TOKEN" | jq -e '.current.release == "0.0.0-ci" and .method == "manual" and .methods.docker.configured == false'
curl -fsS $FRONT/login | grep -q 'appVersion:"v0.0.0-ci"'
# LDAP settings (environment until saved): the connection test finds the directory's users
curl -fsS $FRONT/api/ldap/config -H "Authorization: Bearer $TOKEN" | jq -e '.source == "environment" and .enabled'
curl -fsS -X POST $FRONT/api/ldap/test -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{}' \
  | jq -e '.ok and .count >= 2'
# Third-party host: embed token minted server-side, composer frameable only from its origin
APP_ID=$(curl -fsS http://localhost:4000/ | grep -o 'applicationId: "[^"]*"' | cut -d'"' -f2)
curl -fsS "http://localhost:4000/token?user=alice%40example.org" | jq -e .token
curl -fsSI "$FRONT/embed/compose?app=$APP_ID" | grep -i "frame-ancestors http://localhost:4000"
curl -fsS http://localhost:8025/ -o /dev/null
# Documentation site, with the changelog
curl -fsS $DOCS/changelog | grep -q 'Dernière version'
curl -fsS $DOCS/embed/widget | grep -q '<title>Intégrer le widget'
# Network health checks (also run by the worker's scheduler): the real OpenLDAP, the CRM mailbox (Mailpit SMTP,
# GreenMail IMAP) and the SMTP relay (Mailpit)
curl -fsS -X POST $FRONT/api/health/check -H "Authorization: Bearer $TOKEN" > health.json
jq -e '[.services[] | select(.id == "ldap" or .id == "mailboxes" or .id == "mailer") | .status] == ["operational", "operational", "operational"]' health.json \
  || { jq . health.json; exit 1; }
$COMPOSE exec -T api php bin/console app:health:check
# Dashboard: the whole platform and service details for admins
curl -fsS $FRONT/api/dashboard -H "Authorization: Bearer $TOKEN" \
  | jq -e '.scope == "platform" and (.daily | length) == 30 and ([.health.services[] | select(.id == "database" or .id == "ldap") | .status] == ["operational", "operational"])'
DEMO_TOKEN=rma_demo_rocket_mailer_do_not_use_in_production
# The demo application (Démo CRM) has its own sender settings, managed by administrators
curl -fsS $FRONT/api/application_senders -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  | jq -e 'map(select(.senderEmail == "contact@crm.example.org" and .allowedSenders == ["*@crm.example.org"])) | length == 1'
# An application sends from its own sender, never from the platform's addresses
test "$(curl -s -o /dev/null -w '%{http_code}' -X POST $FRONT/api/emails -H "Authorization: Bearer $DEMO_TOKEN" \
  -H 'X-Impersonate-User: alice@example.org' -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"to":["client@example.com"],"subject":"CI platform","htmlBody":"<p>no</p>","from":"no-reply@example.org"}')" = 422
curl -fsS -X POST $FRONT/api/emails -H "Authorization: Bearer $DEMO_TOKEN" \
  -H 'X-Impersonate-User: alice@example.org' -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"to":["client@example.com"],"subject":"CI app sender","htmlBody":"<p>ok</p>"}' | jq -e '.from == "Démo CRM <contact@crm.example.org>"'
# Sending mailbox of Démo CRM (designated by its address): through Mailpit, copy in GreenMail's IMAP "Sent"
MAILBOX_EMAIL=$(curl -fsS -X POST $FRONT/api/emails -H "Authorization: Bearer $DEMO_TOKEN" \
  -H 'X-Impersonate-User: alice@example.org' -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"to":["client@example.com"],"subject":"CI mailbox","htmlBody":"<p>ok</p>","from":"commercial@crm.example.org"}')
echo "$MAILBOX_EMAIL" | jq -e '.mailboxName == "Boîte commerciale du CRM"'
MAILBOX_EMAIL_ID=$(echo "$MAILBOX_EMAIL" | jq -r .id)
for i in $(seq 1 30); do
  curl -fsS "$FRONT/api/emails/$MAILBOX_EMAIL_ID" -H "Authorization: Bearer $DEMO_TOKEN" \
    -H 'X-Impersonate-User: alice@example.org' -H 'Accept: application/json' | jq -e '.archivedIn == "Sent"' && break
  [ "$i" = 30 ] && exit 1
  sleep 2
done
# Sending goes through the async worker and reaches Mailpit
ALICE=$(curl -fsS -X POST $FRONT/api/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"alice@example.org","password":"demo-alice-password"}' | jq -r .token)
# ... with an attachment uploaded through the proxy, delivered by the worker (shared volume),
# from the default sender seeded from MAILER_DEFAULT_FROM
echo "demo attachment" > /tmp/ci-note.txt
ATTACHMENT=$(curl -fsS -X POST $FRONT/api/attachments -H "Authorization: Bearer $ALICE" \
  -H 'Accept: application/json' -F "file=@/tmp/ci-note.txt" | jq -r .id)
curl -fsS -X POST $FRONT/api/emails -H "Authorization: Bearer $ALICE" -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d "{\"to\":[\"client@example.com\"],\"subject\":\"CI demo\",\"htmlBody\":\"<p>ok</p>\",\"attachments\":[\"/api/attachments/$ATTACHMENT\"]}" \
  | jq -e '.status == "queued"'
for i in $(seq 1 30); do
  curl -fsS http://localhost:8025/api/v1/messages | jq -e '.messages | map(select(.Subject == "CI demo" and .Attachments == 1 and .From.Address == "no-reply@example.org")) | length == 1' && exit 0
  sleep 2
done
exit 1
