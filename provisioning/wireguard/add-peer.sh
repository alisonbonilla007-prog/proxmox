#!/usr/bin/env bash
# add-peer.sh — register a tenant router as a WireGuard peer on the hub.
# The onboarding page gives you the tenant's PUBLIC KEY and tunnel IP; pass them here.
#
#   ./add-peer.sh <router_public_key> <tunnel_ip>   e.g. ./add-peer.sh AbC...= 10.66.7.1
#
# Applies live (no restart) AND persists to wg0.conf so it survives reboots.
set -euo pipefail
WG_IFACE="${WG_IFACE:-wg0}"
PUBKEY="${1:?router public key required}"
TUNIP="${2:?tunnel ip required, e.g. 10.66.7.1}"
CONF="/etc/wireguard/$WG_IFACE.conf"

# live apply
wg set "$WG_IFACE" peer "$PUBKEY" allowed-ips "${TUNIP}/32"

# persist (idempotent)
if ! grep -q "$PUBKEY" "$CONF"; then
  cat >> "$CONF" <<EOF

[Peer]
PublicKey = $PUBKEY
AllowedIPs = ${TUNIP}/32
EOF
fi
echo "Peer $PUBKEY -> $TUNIP added on $WG_IFACE."
