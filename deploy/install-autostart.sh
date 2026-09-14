#!/usr/bin/env bash
# Installs the systemd unit that auto-starts EVE Helper on WSL boot.
# Run once:  sudo bash deploy/install-autostart.sh
set -euo pipefail

UNIT=/etc/systemd/system/eve-helper.service
SRC="$(cd "$(dirname "$0")" && pwd)/eve-helper.service"

# Clean hostname for the app: eve-helper.local -> 127.0.0.2 (its own loopback,
# so port 80 is free even while DDEV's router holds 127.0.0.1:80).
if ! grep -q 'eve-helper.local' /etc/hosts; then
    echo '127.0.0.2  eve-helper.local' >> /etc/hosts
    echo "Added eve-helper.local to /etc/hosts"
fi

install -m 0644 "$SRC" "$UNIT"
systemctl daemon-reload
systemctl enable eve-helper.service
systemctl start eve-helper.service
systemctl --no-pager status eve-helper.service | head -12

echo
echo "Done. EVE Helper (standalone stack) starts automatically on WSL/systemd boot,"
echo "independent of DDEV — 'ddev poweroff' will NOT stop it."
echo "Web app:   http://eve-helper.local"
echo "Workers:   scheduler + queue run alongside it."
echo
echo "One-time: add this callback URL to your EVE application at developers.eveonline.com:"
echo "  http://eve-helper.local/auth/eve/callback"
