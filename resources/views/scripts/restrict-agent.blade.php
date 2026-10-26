set -Eeuo pipefail

{{-- Agents listen on 127.0.0.1, where any site on the server could otherwise send them made-up events. --}}
cat > /usr/local/sbin/swage-nightwatch-firewall <<'SWAGE_EOF'
#!/usr/bin/env bash
# Lets only each Nightwatch agent's own site user, and root, connect to the agent's port. Each file in
# /etc/swage/nightwatch-agents is named after an agent's port and holds the user of the site it reports for.
set -euo pipefail

CHAIN=SWAGE-NIGHTWATCH

iptables -w -N "$CHAIN" 2> /dev/null || iptables -w -F "$CHAIN"
iptables -w -C OUTPUT -o lo -p tcp -j "$CHAIN" 2> /dev/null || iptables -w -I OUTPUT 1 -o lo -p tcp -j "$CHAIN"

for file in /etc/swage/nightwatch-agents/*; do
    [ -f "$file" ] || continue

    port=$(basename "$file")
    user=$(cat "$file")

    if ! [[ "$port" =~ ^[0-9]+$ ]] || ! [[ "$user" =~ ^[a-z_][a-z0-9_-]*$ ]] || ! id -u "$user" > /dev/null 2>&1; then
        continue
    fi

    iptables -w -A "$CHAIN" -p tcp --dport "$port" -m owner --uid-owner "$user" -j RETURN
    iptables -w -A "$CHAIN" -p tcp --dport "$port" -m owner --uid-owner 0 -j RETURN
    iptables -w -A "$CHAIN" -p tcp --dport "$port" -j REJECT --reject-with tcp-reset
done
SWAGE_EOF
chown root:root /usr/local/sbin/swage-nightwatch-firewall
chmod 755 /usr/local/sbin/swage-nightwatch-firewall

{{-- The rules are kept in memory only, so they are made again on every boot, once ufw has set up its own. --}}
cat > /etc/systemd/system/swage-nightwatch-firewall.service <<'SWAGE_EOF'
[Unit]
Description=Restrict Nightwatch agents to the sites they report for
After=network-pre.target ufw.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/local/sbin/swage-nightwatch-firewall

[Install]
WantedBy=multi-user.target
SWAGE_EOF

install -d -m 755 /etc/swage/nightwatch-agents
@if ($user === null)
rm -f /etc/swage/nightwatch-agents/{!! $port !!}
@else
echo '{!! $user !!}' > /etc/swage/nightwatch-agents/{!! $port !!}
@endif

systemctl daemon-reload
systemctl enable --quiet swage-nightwatch-firewall
/usr/local/sbin/swage-nightwatch-firewall

@if ($user === null)
echo "Removed the firewall rule for the agent on port {!! $port !!}."
@else
echo "Only {!! $user !!} and root can now reach the agent on port {!! $port !!}."
@endif
