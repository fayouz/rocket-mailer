#!/usr/bin/env bash
# Setup of the backend CI job of rocket-mailer (reusable workflow brick-backend.yml of rocket-core, input "setup-script"),
# run with "bash -e" from the repository root before "composer install" and the checks.
# Real SMTP + IMAP server for the sending mailbox tests (tests/Functional/MailboxTest.php, HealthCheckTest.php):
# they are skipped without it, so the job waits for its ports.
docker run -d --name greenmail -p 3025:3025 -p 3143:3143 \
  -e GREENMAIL_OPTS='-Dgreenmail.setup.test.all -Dgreenmail.hostname=0.0.0.0 -Dgreenmail.users=commercial:secret-pass@crm.example.org' \
  greenmail/standalone:2.1.3
echo "GREENMAIL_HOST=127.0.0.1" >> "$GITHUB_ENV"
# Ready when both servers greet (the published ports accept connections before GreenMail listens).
greets() {
  exec 3<>"/dev/tcp/127.0.0.1/$1" || return 1
  local line
  read -r -t 5 line <&3
  exec 3<&-
  [[ $line == "$2"* ]]
}
for i in $(seq 1 60); do
  greets 3025 220 2>/dev/null && greets 3143 '* OK' 2>/dev/null && exit 0
  sleep 1
done
docker logs greenmail
echo "GreenMail never became ready" && exit 1
