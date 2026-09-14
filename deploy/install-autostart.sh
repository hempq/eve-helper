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
echo "Done. EVE Helper (standalone stack) starts automatically on WSL/systemd boot,"
echo "independent of DDEV — 'ddev poweroff' will NOT stop it."
echo "Web app:   http://localhost:8899"
echo "Workers:   scheduler + queue run alongside it."
echo
echo "One-time: add this callback URL to your EVE application at developers.eveonline.com:"
echo "  http://localhost:8899/auth/eve/callback"
