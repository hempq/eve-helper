#!/usr/bin/env bash
# Installs the systemd unit that auto-starts EVE Helper on WSL boot.
# Run once:  sudo bash deploy/install-autostart.sh
set -euo pipefail

UNIT=/etc/systemd/system/eve-helper.service
SRC="$(cd "$(dirname "$0")" && pwd)/eve-helper.service"

# Hostname inside WSL (the Windows hosts file needs its own entry — see below).
if ! grep -q 'eve-helper.local' /etc/hosts; then
    echo '127.0.0.1  eve-helper.local' >> /etc/hosts
    echo "Added eve-helper.local to WSL /etc/hosts"
fi

install -m 0644 "$SRC" "$UNIT"
systemctl daemon-reload
systemctl enable eve-helper.service
systemctl start eve-helper.service
systemctl --no-pager status eve-helper.service | head -12

echo
echo "Done. EVE Helper (standalone stack) starts automatically on WSL/systemd boot,"
echo "independent of DDEV — 'ddev poweroff' will NOT stop it."
echo "Web app:   http://localhost:8899   (works from Windows immediately)"
echo "     or:   http://eve-helper.local:8899   (after the Windows hosts entry below)"
echo "Workers:   scheduler + queue run alongside it."
echo
echo "On WINDOWS, as Administrator, add to C:\\Windows\\System32\\drivers\\etc\\hosts:"
echo "  127.0.0.1  eve-helper.local"
echo
echo "Then add this callback URL to your EVE application at developers.eveonline.com:"
echo "  http://eve-helper.local:8899/auth/eve/callback"
