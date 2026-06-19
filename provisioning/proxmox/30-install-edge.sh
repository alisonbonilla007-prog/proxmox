#!/usr/bin/env bash
# 30-install-edge.sh — run on the EDGE VM (as root/sudo).
# Installs Caddy (auto-HTTPS, wildcard) + PHP-FPM + the app, points it at the
# data VM, and schedules the billing cron. This is the only internet-facing VM.
#
# IMPORTANT: set WG_SERVER_PUBKEY in cluster.env first (printed by 20-install-hub.sh).
set -euo pipefail
cd "$(dirname "$0")"
[ -f cluster.env ] || { echo "cluster.env missing."; exit 1; }
# shellcheck disable=SC1091
source ./cluster.env
[ "$(id -u)" -eq 0 ] || { echo "Run with sudo."; exit 1; }
[ -n "${WG_SERVER_PUBKEY:-}" ] || { echo "Set WG_SERVER_PUBKEY in cluster.env (from the hub) first."; exit 1; }

export DEBIAN_FRONTEND=noninteractive
APP_DIR="/opt/mesh/${APP_SUBDIR}"

echo "==> Installing PHP-FPM, Caddy, git"
apt-get update -y
apt-get install -y php-fpm php-mysql php-curl php-snmp git curl debian-keyring debian-archive-keyring apt-transport-https
# Caddy official repo
if ! command -v caddy >/dev/null; then
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' > /etc/apt/sources.list.d/caddy-stable.list
    apt-get update -y && apt-get install -y caddy
fi

PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
PHP_SOCK="/run/php/php${PHP_VER}-fpm.sock"

echo "==> Fetching app code to /opt/mesh"
if [ ! -d /opt/mesh/.git ]; then
    git clone --branch "$REPO_BRANCH" "$REPO_URL" /opt/mesh
else
    git -C /opt/mesh pull --ff-only || true
fi
chown -R www-data:www-data /opt/mesh

# ---------------------------------------------------------------
# 1) App environment (php-fpm pool reads these)
# ---------------------------------------------------------------
echo "==> Writing app environment into the PHP-FPM pool"
POOL="/etc/php/${PHP_VER}/fpm/pool.d/www.conf"
# strip any prior MESH block, then append a fresh one
sed -i '/# >>> MESH env/,/# <<< MESH env/d' "$POOL"
cat >> "$POOL" <<EOF
# >>> MESH env
env[DB_DRIVER] = mysql
env[DB_HOST] = ${DATA_IP}
env[DB_NAME] = ${DB_NAME}
env[DB_USER] = ${DB_APP_USER}
env[DB_PASS] = ${DB_APP_PASS}
env[APP_DOMAIN] = ${DOMAIN}
env[WG_ENDPOINT] = ${WG_PUBLIC_ENDPOINT}
env[WG_SERVER_PUBKEY] = ${WG_SERVER_PUBKEY}
env[WG_SUBNET] = ${WG_SUBNET}
env[WG_RADIUS_IP] = ${WG_HUB_TUNNEL_IP}
# <<< MESH env
EOF
systemctl restart "php${PHP_VER}-fpm"

# ---------------------------------------------------------------
# 2) Caddy site (wildcard TLS)
# ---------------------------------------------------------------
echo "==> Writing Caddyfile"
if [ -n "${CLOUDFLARE_API_TOKEN:-}" ]; then
    # Wildcard cert via Cloudflare DNS-01. Requires the caddy-dns/cloudflare plugin:
    #   caddy add-package github.com/caddy-dns/cloudflare && systemctl restart caddy
    TLS_BLOCK="tls ${ACME_EMAIL} {
        dns cloudflare ${CLOUDFLARE_API_TOKEN}
    }"
    SITE_LABEL="*.${DOMAIN}, ${DOMAIN}"
else
    echo "    (no CLOUDFLARE_API_TOKEN: issuing a normal cert for ${DOMAIN} only — subdomains need the DNS plugin)"
    TLS_BLOCK="tls ${ACME_EMAIL}"
    SITE_LABEL="${DOMAIN}"
fi

cat > /etc/caddy/Caddyfile <<EOF
${SITE_LABEL} {
    root * ${APP_DIR}/public
    php_fastcgi unix/${PHP_SOCK}
    file_server
    encode gzip
    ${TLS_BLOCK}

    @db path *.db *.sqlite *.sqlite3
    respond @db 404
}
EOF

if [ -n "${CLOUDFLARE_API_TOKEN:-}" ] && ! caddy list-modules 2>/dev/null | grep -q 'dns.providers.cloudflare'; then
    echo "    installing caddy cloudflare DNS plugin"
    caddy add-package github.com/caddy-dns/cloudflare || \
        echo "    (could not add plugin automatically — run: caddy add-package github.com/caddy-dns/cloudflare)"
fi
systemctl enable caddy >/dev/null
systemctl restart caddy

# ---------------------------------------------------------------
# 3) Billing cron
# ---------------------------------------------------------------
echo "==> Scheduling billing cron (daily 02:07)"
cat > /etc/cron.d/mesh-billing <<EOF
7 2 * * * root DB_DRIVER=mysql DB_HOST=${DATA_IP} DB_NAME=${DB_NAME} DB_USER=${DB_APP_USER} DB_PASS=${DB_APP_PASS} php ${APP_DIR}/cron/billing_check.php >> /var/log/mesh-billing.log 2>&1
EOF
chmod 644 /etc/cron.d/mesh-billing

cat <<DONE

================================================================
 EDGE ready — your SaaS is live.

   Operator console : https://${DOMAIN}/superadmin   (${SUPERADMIN_USER} / your pass)
   Public signup    : https://${DOMAIN}/signup
   A tenant portal  : https://<slug>.${DOMAIN}/

 DNS: ensure *.${DOMAIN} and ${DOMAIN} both A-record to ${EDGE_PUBLIC_IP}.
 Firewall: allow TCP 80,443 to this EDGE VM.

 Quick check:
   curl -I https://${DOMAIN}/
================================================================
DONE
