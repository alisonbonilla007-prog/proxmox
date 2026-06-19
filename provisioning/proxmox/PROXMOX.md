# Deploy MESH Cloud on Proxmox (3 separate VMs)

This kit builds the SaaS as **three VMs** so the public web node is isolated
from your database and your WireGuard/RADIUS core:

```
                 internet
                    │  (80/443 tcp, 51820 udp)
        ┌───────────┴────────────┐
        ▼                        ▼
   ┌─────────┐  private LAN  ┌─────────┐      ┌─────────┐
   │  EDGE   │──────────────▶│  DATA   │◀─────│  HUB    │
   │ Caddy + │   MySQL 3306  │ MariaDB │ 3306 │ WireGuard
   │ PHP-FPM │               │         │      │ FreeRADIUS
   │  + app  │               └─────────┘      │ + poller │
   └─────────┘                                └─────────┘
   public web                                 tunnels in here
```

- **EDGE** — the only internet-facing VM. Caddy (auto-HTTPS) + PHP-FPM + the app.
- **HUB** — terminates tenant WireGuard tunnels; runs FreeRADIUS + the health poller.
- **DATA** — MariaDB only, on the private LAN.

You only ever edit **one file** (`cluster.env`) and run **four scripts**.

---

## Prerequisites (do these first)
1. A domain, e.g. `meshcloud.example`, with **two DNS records** pointing at your
   EDGE public IP: `meshcloud.example` (A) and `*.meshcloud.example` (A/wildcard).
2. Your Proxmox host reachable, and ports open to the EDGE/HUB:
   - `TCP 80, 443` → EDGE  (portals + Let's Encrypt)
   - `UDP 51820`   → HUB   (WireGuard)
   If Proxmox is behind a home router, port-forward those to the EDGE/HUB LAN IPs.
3. For **wildcard HTTPS** you need a DNS-01 token (Cloudflare token shown in
   `cluster.env`). Without it you still get a cert for the apex, but tenant
   subdomains won't be covered.

---

## Step 0 — Configure once
On the **Proxmox host** (or anywhere you keep the repo):
```bash
cd SAAS/provisioning/proxmox
cp cluster.env.example cluster.env
nano cluster.env        # set DOMAIN, IPs, passwords, REPO_URL, SSH key, etc.
```
Pick three free static LAN IPs for EDGE/HUB/DATA and put your public IP in
`EDGE_PUBLIC_IP` / `WG_PUBLIC_ENDPOINT`.

## Step 1 — Create the VMs (on the Proxmox host)
```bash
bash 00-host-create-vms.sh
```
This downloads the Debian 12 cloud image and creates 3 cloud-init VMs
(`mesh-data`, `mesh-hub`, `mesh-edge`) with your SSH key and static IPs.
👉 **Snapshot each VM now** in the Proxmox UI (one click rollback later).

## Step 2 — Get the repo + cluster.env onto each VM
On each VM (SSH in as the `mesh` user), clone the repo and copy your filled-in
`cluster.env` next to the scripts. Easiest:
```bash
sudo git clone https://github.com/youruser/yourrepo.git /opt/mesh
cd /opt/mesh/SAAS/provisioning/proxmox
# copy your edited cluster.env here (scp from your machine), then:
```

## Step 3 — Install, in order
```bash
# on DATA:
sudo bash 10-install-data.sh
# on HUB:
sudo bash 20-install-hub.sh         # prints WG_SERVER_PUBKEY — copy it!
# put that key into cluster.env on the EDGE: WG_SERVER_PUBKEY="..."
# on EDGE:
sudo bash 30-install-edge.sh
```
That's it. Visit:
- `https://meshcloud.example/superadmin` — operator console (your superadmin login)
- `https://meshcloud.example/signup` — self-serve tenant signup
- `https://<slug>.meshcloud.example/` — a tenant's branded portal

---

## Onboarding a client router (per tenant)
1. In the operator console (or the tenant's own admin → **Connect router**),
   open onboarding. It generates the MikroTik `.rsc` / pfSense steps.
2. The client runs it; their router prints its **public key**; they paste it
   into the **Activate** box.
3. On the **HUB VM**, register the peer (the onboarding page shows the IP):
   ```bash
   cd /opt/mesh/SAAS/provisioning/proxmox
   sudo bash add-peer.sh <router_public_key> 10.66.<tenant_id>.1
   ```
   (You can automate this later; for now it's one command per new router.)

---

## Proxmox operational tips
- **Snapshots:** before every upgrade — `Datacenter → VM → Snapshots → Take`.
- **Backups:** `Datacenter → Backup` → nightly `vzdump` of all three VMs to
  local/NFS. This captures MySQL, WireGuard keys, and M-Pesa secrets together.
- **Firewall:** enable the Proxmox firewall; inbound allow only `22,80,443/tcp`
  to EDGE and `51820/udp` to HUB. DATA needs no inbound from the internet.
- **Updates:** `git -C /opt/mesh pull` on EDGE/HUB, then restart the relevant
  service (`systemctl restart caddy php*-fpm` / `freeradius`).

## Troubleshooting
- TLS not issuing wildcard → you need the Cloudflare (or your DNS) token and the
  caddy DNS plugin: `caddy add-package github.com/caddy-dns/cloudflare && systemctl restart caddy`.
- FreeRADIUS won't start → run it in debug: `freeradius -X` on the HUB.
- App can't reach DB → confirm `bind-address` on DATA = its LAN IP and the
  `meshcloud@EDGE_IP` / `meshradius@HUB_IP` grants exist.
- Router won't connect → check `UDP 51820` reaches the HUB and the peer was
  added with `add-peer.sh`; on the router check the WG handshake.

## Single-VM variant
If you'd rather start on one box, run `10`, `20`, `30` on the **same** VM and set
`DATA_IP=HUB_IP=EDGE_IP=<that VM>` in `cluster.env`. Split later by restoring a
backup onto new VMs and changing the IPs — the scripts are the same.
