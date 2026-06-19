#!/usr/bin/env bash
# 20-install-hub.sh — run on the HUB VM (as root/sudo).
# Installs WireGuard (the tunnel hub), FreeRADIUS (rlm_sql -> data VM), PHP CLI
# + the app code (for the monitoring poller), and schedules the poller cron.
#
# Needs: cluster.env + the SAAS/ repo cloned to /opt/mesh (this script clones it).
set -euo pipefail
cd "$(dirname "$0")"
[ -f cluster.env ] || { echo "cluster.env missing."; exit 1; }
# shellcheck disable=SC1091
source ./cluster.env
[ "$(id -u)" -eq 0 ] || { echo "Run with sudo."; exit 1; }

export DEBIAN_FRONTEND=noninteractive
APP_DIR="/opt/mesh/${APP_SUBDIR}"

echo "==> Installing packages (wireguard, freeradius, php-cli, snmp, git)"
apt-get update -y
apt-get install -y wireguard iptables freeradius freeradius-mysql freeradius-utils \
    php-cli php-mysql php-curl php-snmp snmp git curl

echo "==> Fetching app code to /opt/mesh"
if [ ! -d /opt/mesh/.git ]; then
    git clone --branch "$REPO_BRANCH" "$REPO_URL" /opt/mesh
else
    git -C /opt/mesh pull --ff-only || true
fi

# ---------------------------------------------------------------
# 1) WireGuard hub
# ---------------------------------------------------------------
echo "==> Configuring WireGuard hub on ${WG_HUB_TUNNEL_IP} (udp/${WG_PORT})"
sysctl -w net.ipv4.ip_forward=1 >/dev/null
grep -q '^net.ipv4.ip_forward=1' /etc/sysctl.conf || echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf

umask 077
mkdir -p /etc/wireguard
if [ ! -f /etc/wireguard/hub_private.key ]; then
    wg genkey | tee /etc/wireguard/hub_private.key | wg pubkey > /etc/wireguard/hub_public.key
fi
HUB_PRIV=$(cat /etc/wireguard/hub_private.key)
HUB_PUB=$(cat /etc/wireguard/hub_public.key)

# subnet prefix length from WG_SUBNET (e.g. /16)
WG_CIDR="${WG_SUBNET##*/}"

if [ ! -f /etc/wireguard/wg0.conf ]; then
cat > /etc/wireguard/wg0.conf <<EOF
[Interface]
Address = ${WG_HUB_TUNNEL_IP}/${WG_CIDR}
ListenPort = ${WG_PORT}
PrivateKey = ${HUB_PRIV}
# Tenant [Peer] blocks are appended by add-peer.sh (or the onboarding "Activate") flow.
EOF
fi
systemctl enable wg-quick@wg0 >/dev/null
systemctl restart wg-quick@wg0

# ---------------------------------------------------------------
# 2) FreeRADIUS -> MariaDB on the data VM
# ---------------------------------------------------------------
echo "==> Configuring FreeRADIUS (rlm_sql -> ${DATA_IP})"
SQLMOD="/etc/freeradius/3.0/mods-available/sql"
if [ -f "$SQLMOD" ]; then
    sed -i 's/^\(\s*\)dialect = .*/\1dialect = "mysql"/'         "$SQLMOD"
    sed -i 's/^\(\s*\)driver = .*/\1driver = "rlm_sql_${dialect}"/' "$SQLMOD"
    sed -i "s|^\(\s*\)server = .*|\1server = \"${DATA_IP}\"|"      "$SQLMOD"
    sed -i 's|^\(\s*\)#\?\s*port = .*|\tport = 3306|'             "$SQLMOD"
    sed -i "s|^\(\s*\)login = .*|\1login = \"${DB_RADIUS_USER}\"|" "$SQLMOD"
    sed -i "s|^\(\s*\)password = .*|\1password = \"${DB_RADIUS_PASS}\"|" "$SQLMOD"
    sed -i "s|^\(\s*\)radius_db = .*|\1radius_db = \"${DB_NAME}\"|" "$SQLMOD"
    # read RADIUS clients (the tenant routers) from the nas table
    sed -i 's/^\(\s*\)#\?\s*read_clients = .*/\1read_clients = yes/' "$SQLMOD"
    sed -i 's/^\(\s*\)#\?\s*client_table = .*/\1client_table = "nas"/' "$SQLMOD"
    ln -sf ../mods-available/sql /etc/freeradius/3.0/mods-enabled/sql
fi

# enable sql in the default site (authorize/accounting/post-auth/session)
SITE="/etc/freeradius/3.0/sites-available/default"
if [ -f "$SITE" ]; then
    # uncomment the bare "sql" lines
    sed -i 's/^\(\s*\)-sql$/\1sql/' "$SITE" 2>/dev/null || true
    sed -i 's/^\(\s*\)#\s*sql$/\1sql/' "$SITE" 2>/dev/null || true
fi

# bind RADIUS to the tunnel IP only (never the public NIC)
RADD="/etc/freeradius/3.0/radiusd.conf"
if [ -f "$RADD" ]; then
    sed -i "s/^\(\s*\)ipaddr = \*/\1ipaddr = ${WG_HUB_TUNNEL_IP}/" "$RADD" 2>/dev/null || true
fi
systemctl enable freeradius >/dev/null
systemctl restart freeradius || { echo "FreeRADIUS failed — check: freeradius -X"; }

# ---------------------------------------------------------------
# 3) Monitoring poller cron (writes device_metrics/alerts to the DB)
# ---------------------------------------------------------------
echo "==> Scheduling monitoring poller (every 2 min)"
cat > /etc/cron.d/mesh-poll <<EOF
# MESH Cloud router health poller
*/2 * * * * root DB_DRIVER=mysql DB_HOST=${DATA_IP} DB_NAME=${DB_NAME} DB_USER=${DB_APP_USER} DB_PASS=${DB_APP_PASS} php ${APP_DIR}/monitoring/poll.php >> /var/log/mesh-poll.log 2>&1
EOF
chmod 644 /etc/cron.d/mesh-poll

cat <<DONE

================================================================
 HUB ready.
   WireGuard hub pubkey : ${HUB_PUB}
   WG endpoint (routers dial) : ${WG_PUBLIC_ENDPOINT}
   FreeRADIUS bound to   : ${WG_HUB_TUNNEL_IP}:1812/1813 (tunnel only)

 >>> Put these two in the EDGE cluster.env BEFORE running 30-install-edge.sh:
       WG_SERVER_PUBKEY="${HUB_PUB}"
       (WG_PUBLIC_ENDPOINT is already in cluster.env)

 Open UDP ${WG_PORT} to this VM at your firewall/router.
 Add tenant peers later with:  bash add-peer.sh <router_pubkey> 10.66.<id>.1
================================================================
DONE
