#!/usr/bin/env bash
# 10-install-data.sh — run on the DATA VM (as root/sudo).
# Installs MariaDB, loads the MESH Cloud schema, creates app + radius users,
# and the first superadmin. Binds MariaDB to the LAN so edge/hub can reach it.
#
# Needs in the current dir:  cluster.env  AND  the SAAS/ repo (for schema.mysql.sql).
set -euo pipefail
cd "$(dirname "$0")"
[ -f cluster.env ] || { echo "cluster.env missing."; exit 1; }
# shellcheck disable=SC1091
source ./cluster.env
[ "$(id -u)" -eq 0 ] || { echo "Run with sudo."; exit 1; }

# locate schema.mysql.sql (repo may be alongside, or cloned to /opt/mesh)
SCHEMA=""
for p in "../../schema.mysql.sql" "./schema.mysql.sql" "/opt/mesh/${APP_SUBDIR}/schema.mysql.sql"; do
    [ -f "$p" ] && SCHEMA="$p" && break
done
[ -n "$SCHEMA" ] || { echo "schema.mysql.sql not found. Clone the repo here first."; exit 1; }

echo "==> Installing MariaDB"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y mariadb-server

echo "==> Binding MariaDB to the LAN (${DATA_IP})"
CONF="/etc/mysql/mariadb.conf.d/50-server.cnf"
sed -i "s/^bind-address.*/bind-address = ${DATA_IP}/" "$CONF" || echo "bind-address = ${DATA_IP}" >> "$CONF"
systemctl restart mariadb

echo "==> Securing root + creating database and users"
mysql <<SQL
ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_ROOT_PASS}';
DELETE FROM mysql.user WHERE User='';
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- app user: reachable from edge VM
CREATE USER IF NOT EXISTS '${DB_APP_USER}'@'${EDGE_IP}'   IDENTIFIED BY '${DB_APP_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_APP_USER}'@'${EDGE_IP}';
-- app user also from hub (poller writes metrics/alerts)
CREATE USER IF NOT EXISTS '${DB_APP_USER}'@'${HUB_IP}'    IDENTIFIED BY '${DB_APP_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_APP_USER}'@'${HUB_IP}';

-- radius user: reachable from hub VM (FreeRADIUS)
CREATE USER IF NOT EXISTS '${DB_RADIUS_USER}'@'${HUB_IP}' IDENTIFIED BY '${DB_RADIUS_PASS}';
GRANT SELECT,INSERT,UPDATE,DELETE ON ${DB_NAME}.* TO '${DB_RADIUS_USER}'@'${HUB_IP}';
FLUSH PRIVILEGES;
SQL

echo "==> Loading schema (idempotent: only if tables absent)"
if ! mysql -N -e "SELECT 1 FROM ${DB_NAME}.tenants LIMIT 1" >/dev/null 2>&1; then
    mysql "${DB_NAME}" < "$SCHEMA"
    echo "    schema loaded."
else
    echo "    tables already present — skipping schema load."
fi

echo "==> Creating first superadmin (${SUPERADMIN_USER})"
HASH=$(php -r "echo password_hash('${SUPERADMIN_PASS}', PASSWORD_BCRYPT);" 2>/dev/null || true)
if [ -z "$HASH" ]; then
    # php not installed on data VM — generate the bcrypt hash with python3 if present
    HASH=$(python3 - <<PY 2>/dev/null || true
import crypt # fallback; prefer bcrypt below
try:
    import bcrypt
    print(bcrypt.hashpw(b"${SUPERADMIN_PASS}", bcrypt.gensalt(rounds=12)).decode())
except Exception:
    print("")
PY
)
fi
if [ -n "$HASH" ]; then
    mysql "${DB_NAME}" -e "INSERT INTO superadmins (username, password_hash) VALUES ('${SUPERADMIN_USER}', '${HASH}') ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash);"
    echo "    superadmin ready: ${SUPERADMIN_USER}"
else
    echo "    !! Could not hash the password here (no php/bcrypt)."
    echo "    Run this on the EDGE VM after 30-install-edge.sh:"
    echo "      mysql -h ${DATA_IP} -u ${DB_APP_USER} -p ${DB_NAME} -e \\"
    echo "       \"INSERT INTO superadmins(username,password_hash) VALUES('${SUPERADMIN_USER}', '\$(php -r \"echo password_hash('${SUPERADMIN_PASS}',PASSWORD_BCRYPT);\")');\""
fi

echo "==> DONE (data). MariaDB on ${DATA_IP}:3306"
