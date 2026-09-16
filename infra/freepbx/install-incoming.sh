#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 || "$#" -ne 1 ]]; then
    echo "Aufruf als root: $0 /pfad/zur/config.json" >&2
    exit 2
fi

source_dir="$(cd "$(dirname "$0")" && pwd)"
config_file="$1"
test -f "$config_file"
python3 -m json.tool "$config_file" >/dev/null

if ! id crm-speedphone-incoming >/dev/null 2>&1; then
    useradd --system --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin crm-speedphone-incoming
fi

install -o root -g root -m 0755 "$source_dir/crm-speedphone-incoming-listener.py" /usr/local/sbin/crm-speedphone-incoming-listener
install -d -o root -g crm-speedphone-incoming -m 0750 /etc/crm-speedphone-incoming
install -o root -g crm-speedphone-incoming -m 0640 "$config_file" /etc/crm-speedphone-incoming/config.json
install -o root -g root -m 0644 "$source_dir/crm-speedphone-incoming.service" /etc/systemd/system/crm-speedphone-incoming.service
systemctl daemon-reload
systemctl enable --now crm-speedphone-incoming.service
systemctl --no-pager --full status crm-speedphone-incoming.service
