# MESH Cloud — production deployment

A single VPS can run everything to start (split later as you grow): the PHP app,
MySQL, the WireGuard hub, and FreeRADIUS.

## 0. Prerequisites
- Ubuntu/Debian VPS with a public IP and a wildcard DNS record `*.APP_DOMAIN` → it.
- A reverse proxy with TLS for `*.APP_DOMAIN` (Caddy is simplest):
  ```
  *.meshcloud.example, meshcloud.example {
      root * /var/www/SAAS/public
      php_fastcgi unix//run/php/php8.3-fpm.sock
      file_server
  }
  ```

## 1. Database (MySQL)
```bash
mysql -e "CREATE DATABASE meshcloud CHARACTER SET utf8mb4;"
mysql -e "CREATE USER 'meshcloud'@'localhost' IDENTIFIED BY '...'; GRANT ALL ON meshcloud.* TO 'meshcloud'@'localhost';"
mysql -e "CREATE USER 'meshradius'@'localhost' IDENTIFIED BY '...'; GRANT SELECT,INSERT,UPDATE,DELETE ON meshcloud.* TO 'meshradius'@'localhost';"
mysql meshcloud < SAAS/schema.sql   # change SQLite datetime('now') defaults to CURRENT_TIMESTAMP first
```
Create the first superadmin:
```sql
INSERT INTO superadmins (username, password_hash)
VALUES ('root', '<output of: php -r "echo password_hash(\"yourpass\",PASSWORD_BCRYPT);">');
```

## 2. App environment
Set for php-fpm (e.g. in the pool or systemd):
```
DB_DRIVER=mysql  DB_HOST=127.0.0.1  DB_NAME=meshcloud  DB_USER=meshcloud  DB_PASS=...
APP_DOMAIN=meshcloud.example
WG_ENDPOINT=<vps-ip>:51820   WG_SERVER_PUBKEY=<from hub-setup>   WG_SUBNET=10.66.0.0/16   WG_RADIUS_IP=10.66.0.1
```

## 3. WireGuard hub
```bash
sudo bash SAAS/provisioning/wireguard/hub-setup.sh     # prints WG_SERVER_PUBKEY etc.
# When a tenant submits its router public key in onboarding, the page shows the
# exact [Peer] block + a `wg set` command. Or run:
sudo bash SAAS/provisioning/wireguard/add-peer.sh <router_pubkey> 10.66.<tid>.1
```
Open UDP `51820` in the firewall.

## 4. FreeRADIUS
```bash
apt-get install -y freeradius freeradius-mysql
cp SAAS/provisioning/freeradius/sql.conf.tpl /etc/freeradius/3.0/mods-available/sql   # edit password
ln -s ../mods-available/sql /etc/freeradius/3.0/mods-enabled/sql
# enable `sql` in sites-enabled/default (authorize, accounting, post-auth, session)
# Bind RADIUS to the tunnel IP so only tunnelled routers reach it:
#   listen { ipaddr = 10.66.0.1  port = 1812  type = auth }   (+ 1813 acct)
systemctl restart freeradius
```
Clients are read from the `nas` table automatically (`read_clients = yes`), so
each onboarded tenant router authenticates without editing `clients.conf`.

## 5. Billing cron
```
0 2 * * *  DB_DRIVER=mysql DB_HOST=... DB_NAME=meshcloud DB_USER=meshcloud DB_PASS=... \
           php /var/www/SAAS/cron/billing_check.php >> /var/log/meshcloud-billing.log 2>&1
```
Lapsed subscriptions → `past_due`; past the 3-day grace → tenant portal suspended.
Operators renew with **+30d** in the fleet console, which reactivates the tenant.

## 6. Monitoring cron (router health)
```
*/2 * * * *  DB_DRIVER=mysql DB_HOST=... DB_NAME=meshcloud DB_USER=meshcloud DB_PASS=... \
             php /var/www/SAAS/monitoring/poll.php >> /var/log/meshcloud-poll.log 2>&1
```
Polls each connected, active tenant router **over the tunnel**:
- **MikroTik** — RouterOS REST API (`/rest/system/resource`, `/interface`) using the
  read-only `mesh-monitor` user the onboarding script creates. Requires `www-ssl`
  bound to the tunnel IP. PHP needs `ext-curl`.
- **pfSense / other** — SNMP v2c (`HOST-RESOURCES`, `IF-MIB`) with the read-only
  community from onboarding. PHP needs `ext-snmp` (`apt-get install php-snmp`).

Samples land in `device_metrics` (pruned after 14 days). Thresholds raise rows in
`alerts` (CPU ≥ 85% → warn; unreachable → crit) which auto-resolve on recovery.
They surface in the tenant **Network** tab and the operator fleet console.

Local demo without real routers:
```
DB_DRIVER=sqlite php monitoring/poll.php --simulate
```

## Security notes
- Bind FreeRADIUS to the WireGuard interface only (never the public NIC).
- Keep `meshcloud.db` (SQLite) out of the web root in production — use MySQL.
- Per-tenant M-Pesa secrets live in `tenant_settings`; restrict DB access and
  consider encrypting `consumer_secret`/`passkey` at rest.
- Put the SQLite file and dotfiles behind the `.htaccess` deny rules (already included).
