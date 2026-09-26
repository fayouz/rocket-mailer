#!/usr/bin/env bash
# Smoke test of the images of rocket-mailer, beyond the common one (reusable workflow brick-images.yml of rocket-core,
# input "smoke-script"). Called with the component (api, front); IMAGE, API and FRONT in the environment.
case "$1" in
  front)
    # The widget script, loaded by the third-party hosts.
    curl -fsS "$FRONT/embed.js" | grep -q RocketMailer
    ;;
esac
