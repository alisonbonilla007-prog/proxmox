#!/usr/bin/env bash
# add-peer.sh — run on the HUB VM to register a tenant router as a WG peer.
#   bash add-peer.sh <router_public_key> <tunnel_ip>
#   e.g. bash add-peer.sh AbC...= 10.66.7.1
# Applies live AND persists to wg0.conf. The tunnel_ip must match wg_peers.assigned_ip
# (the onboarding page shows it; it is 10.66.<tenant_id>.1).
set -euo pipefail
WG_IFACE="${WG_IFACE:-wg0}"
PUBKEY="${1:?router public key required}"
TUNIP="${2:?tunnel ip required, e.g. 10.66.7.1}"
CONF="/etc/wireguard/${WG_IFACE}.conf"

wg set "$WG_IFACE" peer "$PUBKEY" allowed-ips "${TUNIP}/32"
if ! grep -q "$PUBKEY" "$CONF"; then
    printf '\n[Peer]\nPublicKey = %s\nAllowedIPs = %s/32\n' "$PUBKEY" "$TUNIP" >> "$CONF"
fi
echo "Peer ${PUBKEY} -> ${TUNIP} added on ${WG_IFACE}."
