#!/usr/bin/env bash
# Installs the systemd unit that auto-starts EVE Helper on WSL boot.
# Run once:  sudo bash deploy/install-autostart.sh
set -euo pipefail

UNIT=/etc/systemd/system/eve-helper.service
SRC="$(cd "$(dirname "$0")" && pwd)/eve-helper.service"

install -m 0644 "$SRC" "$UNIT"
systemctl daemon-reload
systemctl enable eve-helper.service
systemctl start eve-helper.service
systemctl --no-pager status eve-helper.service | head -12

echo
echo "Done. EVE Helper will now start automatically whenever WSL/systemd boots."
echo "The web app, scheduler and queue worker all come up with it."
