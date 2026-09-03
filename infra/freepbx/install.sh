#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 || "$#" -ne 1 ]]; then
    echo "Aufruf als root: $0 /pfad/zum/crm-speedphone-public-key.pub" >&2
    exit 2
fi

source_dir="$(cd "$(dirname "$0")" && pwd)"
public_key_file="$1"
gateway_user="crm-speedphone-call"
gateway_home="/var/lib/crm-speedphone-call"

test -f "$public_key_file"
test -f "$source_dir/crm-speedphone-call-gateway.py"
test -f "$source_dir/config.example.json"

if ! id "$gateway_user" >/dev/null 2>&1; then
    useradd --system --create-home --home-dir "$gateway_home" --shell /bin/bash "$gateway_user"
fi

install -o root -g asterisk -m 0750 "$source_dir/crm-speedphone-call-gateway.py" /usr/local/sbin/crm-speedphone-call-gateway
install -d -o root -g asterisk -m 0750 /etc/crm-speedphone-call
if [[ ! -f /etc/crm-speedphone-call/config.json ]]; then
    install -o root -g asterisk -m 0640 "$source_dir/config.example.json" /etc/crm-speedphone-call/config.json
fi

install -d -o "$gateway_user" -g "$gateway_user" -m 0700 "$gateway_home/.ssh"
forced_key="restrict,command=\"/usr/bin/sudo -n -u asterisk /usr/local/sbin/crm-speedphone-call-gateway\" $(tr -d '\r\n' < "$public_key_file")"
printf '%s\n' "$forced_key" > "$gateway_home/.ssh/authorized_keys"
chown "$gateway_user:$gateway_user" "$gateway_home/.ssh/authorized_keys"
chmod 0600 "$gateway_home/.ssh/authorized_keys"

printf '%s\n' \
    "$gateway_user ALL=(asterisk) NOPASSWD: /usr/local/sbin/crm-speedphone-call-gateway" \
    > /etc/sudoers.d/crm-speedphone-call
chmod 0440 /etc/sudoers.d/crm-speedphone-call
visudo -cf /etc/sudoers.d/crm-speedphone-call

echo "CRM-SpeedPhone-Gateway wurde installiert."
