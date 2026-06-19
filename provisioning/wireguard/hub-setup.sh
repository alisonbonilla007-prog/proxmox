#!/usr/bin/env bash
# hub-setup.sh — one-time WireGuard hub setup on the MESH Cloud VPS (Ubuntu/Debian).
# Run as root.  Tenants' routers dial this hub; RADIUS + monitoring ride the tunnel.
set -euo pipefail

WG_IFACE="${WG_IFACE:-wg0}"
WG_PORT="${WG_PORT:-51820}"
HUB_TUNNEL_IP="${HUB_TUNNEL_IP:-10.66.0.1/16}"   # hub address inside the overlay
WG_DIR="/etc/wireguard"

echo "==> Installing WireGuard"
apt-get update -y
apt-get install -y wireguard iptables

echo "==> Enabling IP forwarding"
sysctl -w net.ipv4.ip_forward=1
grep -q '^net.ipv4.ip_forward=1' /etc/sysctl.conf || echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf

echo "==> Generating hub keypair (if missing)"
umask 077
mkdir -p "$WG_DIR"
[ -f "$WG_DIR/hub_private.key" ] || wg genkey | tee "$WG_DIR/hub_private.key" | wg pubkey > "$WG_DIR/hub_public.key"
PRIV=$(cat "$WG_DIR/hub_private.key")
PUB=$(cat "$WG_DIR/hub_public.key")

echo "==> Writing $WG_DIR/$WG_IFACE.conf"
cat > "$WG_DIR/$WG_IFACE.conf" <<EOF
[Interface]
Address = $HUB_TUNNEL_IP
ListenPort = $WG_PORT
PrivateKey = $PRIV
# Tenant [Peer] blocks are appended by the onboarding flow (see add-peer.sh).
EOF

systemctl enable "wg-quick@$WG_IFACE"
systemctl restart "wg-quick@$WG_IFACE"

cat <<MSG

==> Hub is up.
    WG_ENDPOINT       = <this-server-public-ip>:$WG_PORT
    WG_SERVER_PUBKEY  = $PUB
    WG_SUBNET         = 10.66.0.0/16
    WG_RADIUS_IP      = 10.66.0.1   (run FreeRADIUS on the hub, bound here)

    Put these in the app environment (config.php reads WG_*), then onboard tenants.
MSG
