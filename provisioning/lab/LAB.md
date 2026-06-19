# MESH Cloud — LAB setup (offline / LAN, single VM)

A self-contained lab on your Proxmox + MikroTik + pfSense, no public internet
required. Everything runs on **one Debian VM**, over plain **HTTP**, with tenants
selected by `?tenant=<slug>` (no DNS needed).

> This is separate from the production `proxmox/` kit and does not change it.
> **M-Pesa is kept.** The STK push is real; to receive Safaricom's callback on a
> LAN box you expose it with **ngrok** (Step 7). With no internet at all, use the
> **offline simulator** (Step 8) to test everything except the live Safaricom hop.

```
        LAN 192.168.1.0/24
  ┌──────────────┐     ┌──────────────────────────────┐
  │  MikroTik    │     │  LAB VM (Proxmox)            │
  │  hotspot     │────▶│  Caddy:80  PHP  MariaDB       │
  │              │  WG │  WireGuard hub 10.66.0.1      │
  ├──────────────┤◀───▶│  FreeRADIUS (tunnel only)     │
  │  pfSense     │     └──────────────────────────────┘
  │  captive CP  │
  └──────────────┘
   test phone/laptop joins the hotspot SSID
```

---

## What you need
- Proxmox with a Debian 12 VM on the LAN (give it a static IP, e.g. `192.168.1.50`).
- MikroTik (RouterOS v7) and/or pfSense on the same LAN, reachable from the VM.
- A test device (phone/laptop) to join the hotspot.

---

## Step 1 — Create the lab VM
Easiest: reuse the production host script for just one VM, or make a Debian 12
VM by hand (2 vCPU / 2–4 GB / 20 GB), static IP `192.168.1.50`, SSH enabled.
**Snapshot it now** in Proxmox so you can roll back while experimenting.

## Step 2 — Get the code + config onto the VM
```bash
sudo git clone https://github.com/alisonbonilla007-prog/proxmox.git /opt/mesh
cd /opt/mesh/provisioning/lab
cp lab.env.example lab.env
nano lab.env            # set LAB_IP, REPO_URL, passwords (lab defaults are fine)
```

## Step 3 — Install everything (one command)
```bash
sudo bash install-allinone.sh
```
It installs MariaDB + WireGuard + FreeRADIUS + Caddy (HTTP) + PHP + the app,
loads the MySQL schema, creates your superadmin, and prints the
**WireGuard hub public key** and URLs. Note that hub key — you'll give it to the
routers.

Check it:
```bash
curl -I http://192.168.1.50/          # expect HTTP 200
```
Open **http://192.168.1.50/superadmin** → log in (`root` / your `SUPERADMIN_PASS`).

## Step 4 — Create a tenant
In the operator console → **New tenant** (e.g. name `SwiftNet`, slug `swiftnet`,
owner password you choose). This also provisions a WireGuard peer slot + a NAS
row (RADIUS client) for that tenant. Its tunnel IP is `10.66.<tenant_id>.1`
(first tenant = `10.66.1.1`).

Log into that tenant's admin: **http://192.168.1.50/admin?tenant=swiftnet**
(`owner` / the password you set). Add a package or two under **Packages**.

## Step 5 — Onboard the router (WireGuard + RADIUS + captive portal)

### A) MikroTik
1. Tenant admin → **🛰 Connect router** → copy the **MikroTik (.rsc)** script.
2. On the MikroTik (Winbox/WebFig → **New Terminal**), paste it. It creates the
   WG tunnel to the lab VM, points hotspot RADIUS at `10.66.0.1`, and walls the
   garden for the portal. At the end it prints the router's **public key**.
3. Paste that public key into the **Activate** box in onboarding.
4. On the **lab VM**, register the peer:
   ```bash
   cd /opt/mesh/provisioning/lab
   sudo bash add-peer.sh <router_public_key> 10.66.1.1
   ```
5. Confirm the tunnel: `sudo wg show` on the VM shows a recent handshake.
6. Point the MikroTik hotspot's login page at
   `http://192.168.1.50/?tenant=swiftnet` (walled-garden already allows it).

### B) pfSense
1. Tenant admin → **Connect router** → follow the **pfSense (steps)** tab:
   - **VPN → WireGuard**: create a tunnel, address `10.66.<id>.1/16`, add a peer
     with the hub's public key + endpoint `192.168.1.50:51820`, allowed IPs
     `10.66.0.0/16`. Copy pfSense's tunnel **public key**.
   - **System → User Manager → Authentication Servers**: add RADIUS server
     `10.66.0.1`, the shared secret shown in onboarding, ports 1812/1813.
   - **Services → Captive Portal**: enable RADIUS auth + accounting; set the
     login/redirect to `http://192.168.1.50/?tenant=swiftnet`; add the portal to
     Allowed Hostnames.
   - **Services → SNMP**: enable, community from onboarding, bind to the WG iface
     (this feeds the Network dashboard).
2. Paste pfSense's tunnel public key into **Activate**, then on the VM:
   `sudo bash add-peer.sh <pfsense_pubkey> 10.66.1.1`.

## Step 6 — Buy a voucher (the customer side)
Join the hotspot SSID with your test device → you land on
`http://192.168.1.50/?tenant=swiftnet`. Pick a package → enter an M-Pesa phone →
this fires the **real STK push** (needs Step 7 for the callback). Once paid,
the page shows the voucher username/password; use them on the captive-portal
login to get online. The session, data, and revenue appear in the tenant admin;
router CPU/uptime show under **Network**.

## Step 7 — Make M-Pesa callbacks reach your LAN (ngrok)
Safaricom must POST the result to a public URL. On the lab VM (or any machine
that can reach it):
```bash
ngrok http http://192.168.1.50          # gives e.g. https://ab12.ngrok-free.app
```
Then in the **tenant admin → Settings**, set:
- M-Pesa env/keys (your Daraja sandbox or live credentials),
- **Callback base URL** = the ngrok `https://…` URL.
The app builds the callback as `<callback_base>/mpesa_callback.php?tenant=swiftnet&...`,
so the ngrok host forwards Safaricom → your LAN VM. Now a real STK payment
completes end-to-end and issues the RADIUS voucher.

> Put the ngrok URL in `lab.env` `NGROK_PUBLIC_URL` just to keep a note of it;
> the value the app uses is the per-tenant **Callback base URL** in Settings.

## Step 8 — Fully offline (no internet at all): simulate the payment
When you can't reach Safaricom, test the RADIUS/portal/dashboard path with the
simulator. It marks a payment `success` and issues the **same** voucher account
the real callback would (it does NOT bypass or remove M-Pesa — it's a test stub):
```bash
cd /opt/mesh/provisioning/lab
ENV="DB_DRIVER=mysql DB_HOST=localhost DB_NAME=meshcloud DB_USER=meshcloud DB_PASS=labapppass"

# see pending payments from portal clicks:
sudo env $ENV php simulate_payment.php --tenant=swiftnet --list
# approve one:
sudo env $ENV php simulate_payment.php --tenant=swiftnet --payment=12
# or create+approve without clicking the portal:
sudo env $ENV php simulate_payment.php --tenant=swiftnet --voucher=3 --phone=254712345678
```
It prints a `swiftnet-VCH-XXXXXX / pass` you can use on the captive portal to
verify RADIUS auth, session accounting, and the dashboards — all offline.

---

## Verify checklist
- `curl -I http://192.168.1.50/` → 200, `/superadmin` loads.
- Tenant portal lists only that tenant's packages.
- `sudo wg show` → handshake with the router after `add-peer.sh`.
- Captive-portal login with a voucher → device gets internet; session appears in
  **Live Sessions**; **Network** shows router CPU/uptime within ~2 min.
- Short voucher (e.g. 5-min) disconnects on time (Session-Timeout enforced).

## Troubleshooting
- **Portal/admin 404 or "Unknown tenant"** → you must include `?tenant=<slug>`
  in the lab (no DNS). Check the slug exists in the operator console.
- **RADIUS auth fails** → `freeradius -X` on the VM; confirm the router's tunnel
  IP matches the `nas` row and `add-peer.sh` was run; secret matches onboarding.
- **No tunnel handshake** → router can reach `192.168.1.50:51820/udp`? Re-check
  the peer (`wg show`) and the router's WG config.
- **STK push works but never confirms** → that's the callback; finish Step 7
  (ngrok URL set as the tenant Callback base), or use Step 8 to simulate.
- **No router health data** → MikroTik needs the `mesh-monitor` REST user (in the
  .rsc) reachable on the tunnel; pfSense needs SNMP enabled on the WG interface.
- **`git: detected dubious ownership in /opt/mesh`** → the tree is owned by
  `www-data`. Run `sudo git config --global --add safe.directory /opt/mesh` once,
  then retry (the installer now does this for you).
- **`ERROR 1045 Access denied for user 'root'@'localhost'`** → an earlier run set
  a MariaDB root password, so `sudo mysql` no longer works passwordlessly. The
  installer now auto-detects this (it tries `DB_ROOT_PASS` from `lab.env`). If it
  still can't connect, your root password differs from `DB_ROOT_PASS`; either set
  the right value in `lab.env`, or reset MariaDB (you have no real data yet):
  ```bash
  sudo systemctl stop mariadb
  sudo rm -rf /var/lib/mysql
  sudo mariadb-install-db --user=mysql --auth-root-authentication-method=socket >/dev/null
  sudo systemctl start mariadb        # root is back to passwordless unix_socket
  ```
  Then re-run `sudo bash install-allinone.sh`.

## Reset
Re-running `install-allinone.sh` is safe (idempotent). To wipe data:
`sudo mysql -e "DROP DATABASE meshcloud"` then re-run the installer.
