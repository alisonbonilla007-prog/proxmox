#!/usr/bin/env bash
# install-allinone.sh — LAB install: everything on ONE Debian 12 VM.
# MariaDB + WireGuard hub + FreeRADIUS + Caddy (plain HTTP) + PHP-FPM + app,
# plus the billing & monitoring crons. M-Pesa is kept (real STK push); the
# callback is exposed separately with ngrok (see LAB.md).
#
# Run inside the lab VM as root, from this folder, with the repo at /opt/mesh
# and your edited lab.env beside this script.
set -euo pipefail
cd "$(dirname "$0")"
[ -f lab.env ] || { echo "Create lab.env from lab.env.example first."; exit 1; }
# shellcheck disable=SC1091
source ./lab.env
[ "$(id -u)" -eq 0 ] || { echo "Run with sudo."; exit 1; }

export DEBIAN_FRONTEND=noninteractive
APP_DIR="/opt/mesh/${APP_SUBDIR}"

echo "==> [1/7] Packages"
apt-get update -y
apt-get install -y mariadb-server wireguard iptables \
    freeradius freeradius-mysql freeradius-utils \
    php-fpm php-cli php-mysql php-curl php-snmp snmp git curl debian-keyring debian-archive-keyring apt-transport-https

# Caddy (used here only for plain HTTP reverse proxy to PHP-FPM)
if ! command -v caddy >/dev/null; then
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' > /etc/apt/sources.list.d/caddy-stable.list
    apt-get update -y && apt-get install -y caddy
fi
PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
PHP_SOCK="/run/php/php${PHP_VER}-fpm.sock"

echo "==> [2/7] App code at /opt/mesh"
# Allow root to run git here even after we chown the tree to www-data.
git config --global --add safe.directory /opt/mesh 2>/dev/null || true
if [ ! -d /opt/mesh/.git ]; then
    git clone --branch "$REPO_BRANCH" "$REPO_URL" /opt/mesh
else git -C /opt/mesh pull --ff-only || true; fi
chown -R www-data:www-data /opt/mesh
[ -f "${APP_DIR}/schema.mysql.sql" ] || { echo "schema.mysql.sql not found in ${APP_DIR}"; exit 1; }

echo "==> [3/7] MariaDB (localhost) + schema + users + superadmin"
systemctl enable --now mariadb
# Find a working root login and wait for the server to come up. A fresh MariaDB
# authenticates root@localhost via unix_socket (sudo mysql, no password); a prior
# run may already have set a password. We deliberately do NOT change root's auth,
# so re-runs stay idempotent (sudo mysql keeps working every time).
ROOT_MYSQL=()
for _ in $(seq 1 30); do
    if mysql -u root -e "SELECT 1" >/dev/null 2>&1; then ROOT_MYSQL=(mysql -u root); break; fi
    if mysql -u root -p"${DB_ROOT_PASS}" -e "SELECT 1" >/dev/null 2>&1; then ROOT_MYSQL=(mysql -u root -p"${DB_ROOT_PASS}"); break; fi
    sleep 1
done
if [ ${#ROOT_MYSQL[@]} -eq 0 ]; then
    echo "!! Can't reach MariaDB as root (neither unix_socket nor DB_ROOT_PASS worked)."
    echo "   If you set a different root password earlier, fix DB_ROOT_PASS in lab.env,"
    echo "   or reset MariaDB (see LAB.md -> Troubleshooting), then re-run this script."
    exit 1
fi
"${ROOT_MYSQL[@]}" <<SQL
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_APP_USER}'@'localhost'    IDENTIFIED BY '${DB_APP_PASS}';
ALTER  USER               '${DB_APP_USER}'@'localhost'    IDENTIFIED BY '${DB_APP_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_APP_USER}'@'localhost';
CREATE USER IF NOT EXISTS '${DB_RADIUS_USER}'@'localhost' IDENTIFIED BY '${DB_RADIUS_PASS}';
ALTER  USER               '${DB_RADIUS_USER}'@'localhost' IDENTIFIED BY '${DB_RADIUS_PASS}';
GRANT SELECT,INSERT,UPDATE,DELETE ON ${DB_NAME}.* TO '${DB_RADIUS_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
if ! "${ROOT_MYSQL[@]}" -N -e "SELECT 1 FROM ${DB_NAME}.tenants LIMIT 1" >/dev/null 2>&1; then
    "${ROOT_MYSQL[@]}" "${DB_NAME}" < "${APP_DIR}/schema.mysql.sql"
    echo "    schema loaded"
else echo "    schema already present"; fi
# Migrate DBs created by an older schema so FreeRADIUS' stock queries work.
"${ROOT_MYSQL[@]}" "${DB_NAME}" <<SQL || true
ALTER TABLE nas ADD COLUMN IF NOT EXISTS server VARCHAR(64) DEFAULT NULL;
CREATE TABLE IF NOT EXISTS radgroupcheck (id INT AUTO_INCREMENT PRIMARY KEY, groupname VARCHAR(64) NOT NULL DEFAULT '', attribute VARCHAR(64) NOT NULL DEFAULT '', op CHAR(2) NOT NULL DEFAULT ':=', value VARCHAR(253) NOT NULL DEFAULT '', KEY idx_rgc_group (groupname)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS radgroupreply (id INT AUTO_INCREMENT PRIMARY KEY, groupname VARCHAR(64) NOT NULL DEFAULT '', attribute VARCHAR(64) NOT NULL DEFAULT '', op CHAR(2) NOT NULL DEFAULT ':=', value VARCHAR(253) NOT NULL DEFAULT '', KEY idx_rgr_group (groupname)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
# Stamp tenant_id on accounting rows so the per-tenant dashboards (Live Sessions,
# active users) actually populate. Stock FreeRADIUS accounting INSERTs don't know
# about tenant_id, so a BEFORE INSERT trigger derives it from the namespaced
# username via radcheck. Idempotent (drop+recreate) + one-time backfill. The
# quoted heredoc keeps $$ literal for the trigger body.
"${ROOT_MYSQL[@]}" "${DB_NAME}" <<'SQL' || true
DROP TRIGGER IF EXISTS radacct_set_tenant;
DELIMITER $$
CREATE TRIGGER radacct_set_tenant BEFORE INSERT ON radacct
FOR EACH ROW
BEGIN
  IF NEW.tenant_id IS NULL OR NEW.tenant_id = 0 THEN
    SET NEW.tenant_id = COALESCE(
      (SELECT tenant_id FROM radcheck
        WHERE username = NEW.username AND attribute = 'Cleartext-Password' LIMIT 1), 0);
  END IF;
END$$
DELIMITER ;
UPDATE radacct ra
  JOIN radcheck rc ON rc.username = ra.username AND rc.attribute = 'Cleartext-Password'
   SET ra.tenant_id = rc.tenant_id
 WHERE ra.tenant_id = 0;
SQL
HASH=$(php -r "echo password_hash('${SUPERADMIN_PASS}', PASSWORD_BCRYPT);")
"${ROOT_MYSQL[@]}" "${DB_NAME}" -e "INSERT INTO superadmins (username,password_hash) VALUES ('${SUPERADMIN_USER}','${HASH}') ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash);"

echo "==> [4/7] WireGuard hub (${WG_HUB_TUNNEL_IP}, udp/${WG_PORT})"
sysctl -w net.ipv4.ip_forward=1 >/dev/null
# Persist via sysctl.d (Debian 13 ships no /etc/sysctl.conf by default).
echo 'net.ipv4.ip_forward=1' > /etc/sysctl.d/99-mesh.conf
umask 077; mkdir -p /etc/wireguard
[ -f /etc/wireguard/hub_private.key ] || wg genkey | tee /etc/wireguard/hub_private.key | wg pubkey > /etc/wireguard/hub_public.key
HUB_PRIV=$(cat /etc/wireguard/hub_private.key); HUB_PUB=$(cat /etc/wireguard/hub_public.key)
WG_CIDR="${WG_SUBNET##*/}"
if [ ! -f /etc/wireguard/wg0.conf ]; then
cat > /etc/wireguard/wg0.conf <<EOF
[Interface]
Address = ${WG_HUB_TUNNEL_IP}/${WG_CIDR}
ListenPort = ${WG_PORT}
PrivateKey = ${HUB_PRIV}
# add tenant peers with: bash add-peer.sh <router_pubkey> 10.66.<id>.1
EOF
fi
systemctl enable wg-quick@wg0 >/dev/null; systemctl restart wg-quick@wg0

echo "==> [5/7] FreeRADIUS -> local MariaDB, clients from nas table"
SQLMOD="/etc/freeradius/3.0/mods-available/sql"
if [ -f "$SQLMOD" ]; then
    sed -i 's/^\(\s*\)dialect = .*/\1dialect = "mysql"/' "$SQLMOD"
    sed -i 's/^\(\s*\)driver = .*/\1driver = "rlm_sql_${dialect}"/' "$SQLMOD"
    sed -i 's|^\(\s*\)server = .*|\1server = "localhost"|' "$SQLMOD"
    sed -i 's|^\(\s*\)#\?\s*port = .*|\tport = 3306|' "$SQLMOD"
    # login/password may ship commented on Debian; match optional '#' and only the
    # first occurrence (the connection block) so we don't touch doc examples.
    sed -i "0,/^[[:space:]]*#\?[[:space:]]*login[[:space:]]*=.*/s//\tlogin = \"${DB_RADIUS_USER}\"/" "$SQLMOD"
    sed -i "0,/^[[:space:]]*#\?[[:space:]]*password[[:space:]]*=.*/s//\tpassword = \"${DB_RADIUS_PASS}\"/" "$SQLMOD"
    sed -i "s|^\(\s*\)radius_db = .*|\1radius_db = \"${DB_NAME}\"|" "$SQLMOD"
    sed -i 's/^\(\s*\)#\?\s*read_clients = .*/\1read_clients = yes/' "$SQLMOD"
    sed -i 's/^\(\s*\)#\?\s*client_table = .*/\1client_table = "nas"/' "$SQLMOD"
    # Debian's sql module ships a mysql{ tls{ ca_file=... } } pointing at a CA file
    # that doesn't exist, which aborts startup. We talk to a localhost DB with no
    # TLS, so comment those file refs out.
    for k in ca_file ca_path certificate_file private_key_file; do
        sed -i "s|^\(\s*\)${k} = |\1# ${k} = |" "$SQLMOD"
    done
    ln -sf ../mods-available/sql /etc/freeradius/3.0/mods-enabled/sql
fi
SITE="/etc/freeradius/3.0/sites-available/default"
[ -f "$SITE" ] && { sed -i 's/^\(\s*\)-sql$/\1sql/' "$SITE" 2>/dev/null || true; sed -i 's/^\(\s*\)#\s*sql$/\1sql/' "$SITE" 2>/dev/null || true; }

# Per-voucher TOTAL-time budget (stops a time voucher being reused after expiry).
# A no-reset sqlcounter sums acctsessiontime per user, sets Session-Timeout to the
# REMAINING budget each auth, and rejects once Max-All-Session is spent. Vouchers
# carry Max-All-Session in radcheck (see src/Mpesa.php :: issueVoucher). The quoted
# heredoc keeps ${key} literal for FreeRADIUS to expand at runtime.
cat > /etc/freeradius/3.0/mods-available/meshcounter <<'EOF'
sqlcounter noresetcounter {
    sql_module_instance = sql
    dialect = "mysql"
    counter_name = Max-All-Session-Time
    check_name = Max-All-Session
    reply_name = Session-Timeout
    key = User-Name
    reset = never
    query = "SELECT IFNULL(SUM(acctsessiontime),0) FROM radacct WHERE username='%{${key}}'"
}
EOF
ln -sf ../mods-available/meshcounter /etc/freeradius/3.0/mods-enabled/meshcounter
# Call the counter inside authorize{} (just before pap), once. A module in
# mods-enabled only runs if a processing section references it.
if [ -f "$SITE" ] && ! grep -q 'noresetcounter' "$SITE"; then
    awk '
      /^authorize[[:space:]]*\{/ { inauth=1 }
      inauth && !done && /^[[:space:]]*pap[[:space:]]*$/ { print "\tnoresetcounter"; done=1 }
      { print }
      inauth && /^\}/ { inauth=0 }
    ' "$SITE" > "$SITE.mesh" && mv "$SITE.mesh" "$SITE"
fi

systemctl enable freeradius >/dev/null
systemctl restart freeradius || echo "    !! FreeRADIUS failed — debug with: freeradius -X"

echo "==> [6/7] PHP-FPM env + Caddy (plain HTTP on :80)"
POOL="/etc/php/${PHP_VER}/fpm/pool.d/www.conf"
# PHP-FPM pool files are INI: comments use ';', NOT '#'. Remove any old block of
# either marker style, then write a ';'-commented one.
sed -i '/[#;] >>> MESH env/,/[#;] <<< MESH env/d' "$POOL"
cat >> "$POOL" <<EOF
; >>> MESH env
env[DB_DRIVER] = "mysql"
env[DB_HOST] = "localhost"
env[DB_NAME] = "${DB_NAME}"
env[DB_USER] = "${DB_APP_USER}"
env[DB_PASS] = "${DB_APP_PASS}"
env[APP_DOMAIN] = "${LAB_IP}"
env[WG_ENDPOINT] = "${WG_PUBLIC_ENDPOINT}"
env[WG_SERVER_PUBKEY] = "${HUB_PUB}"
env[WG_SUBNET] = "${WG_SUBNET}"
env[WG_RADIUS_IP] = "${WG_HUB_TUNNEL_IP}"
; <<< MESH env
EOF
systemctl restart "php${PHP_VER}-fpm"
# Plain HTTP (no TLS in the lab). ":80" matches any host, so http://LAB_IP works.
cat > /etc/caddy/Caddyfile <<EOF
:80 {
    root * ${APP_DIR}/public
    php_fastcgi unix/${PHP_SOCK}
    file_server
    encode gzip
    @db path *.db *.sqlite *.sqlite3
    respond @db 404
}
EOF
systemctl enable caddy >/dev/null; systemctl restart caddy

echo "==> [7/7] Crons (billing daily, monitoring every 2 min)"
cat > /etc/cron.d/mesh-lab <<EOF
7 2 * * * root DB_DRIVER=mysql DB_HOST=localhost DB_NAME=${DB_NAME} DB_USER=${DB_APP_USER} DB_PASS=${DB_APP_PASS} php ${APP_DIR}/cron/billing_check.php >> /var/log/mesh-billing.log 2>&1
*/2 * * * * root DB_DRIVER=mysql DB_HOST=localhost DB_NAME=${DB_NAME} DB_USER=${DB_APP_USER} DB_PASS=${DB_APP_PASS} php ${APP_DIR}/monitoring/poll.php >> /var/log/mesh-poll.log 2>&1
EOF
chmod 644 /etc/cron.d/mesh-lab

cat <<DONE

================================================================
 LAB READY (single VM, plain HTTP)

   Operator console : http://${LAB_IP}/superadmin   (${SUPERADMIN_USER} / ${SUPERADMIN_PASS})
   Self-serve signup: http://${LAB_IP}/signup
   Tenant portal    : http://${LAB_IP}/?tenant=<slug>
   Tenant admin     : http://${LAB_IP}/admin?tenant=<slug>

   WireGuard hub pubkey : ${HUB_PUB}
   Routers dial         : ${WG_PUBLIC_ENDPOINT}

 Next: create a tenant, onboard your MikroTik/pfSense (LAB.md),
 add its WG peer:  bash add-peer.sh <router_pubkey> 10.66.<id>.1

 M-Pesa: STK push is live. To receive the callback on this LAN box,
 run ngrok and set the tenant's Callback base URL (see LAB.md).
 Offline (no internet) testing: use simulate_payment.php (LAB.md).
================================================================
DONE
