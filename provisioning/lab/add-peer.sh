#!/usr/bin/env bash
# add-peer.sh — register a tenant router as a WireGuard peer on the lab hub.
#   sudo bash add-peer.sh <router_public_key> <tunnel_ip>
#   e.g. sudo bash add-peer.sh AbC...= 10.66.3.1
# tunnel_ip must equal wg_peers.assigned_ip (10.66.<tenant_id>.1; shown on the
# onboarding page). Applies live AND persists to wg0.conf.
set -euo pipefail
WG_IFACE="${WG_IFACE:-wg0}"
PUBKEY="${1:?router public key required}"
TUNIP="${2:?tunnel ip required, e.g. 10.66.3.1}"
CONF="/etc/wireguard/${WG_IFACE}.conf"
wg set "$WG_IFACE" peer "$PUBKEY" allowed-ips "${TUNIP}/32"
grep -q "$PUBKEY" "$CONF" || printf '\n[Peer]\nPublicKey = %s\nAllowedIPs = %s/32\n' "$PUBKEY" "$TUNIP" >> "$CONF"
echo "Peer ${PUBKEY} -> ${TUNIP} added on ${WG_IFACE}."
