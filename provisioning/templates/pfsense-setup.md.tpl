# MESH Cloud onboarding for {{TENANT_NAME}} ({{SLUG}}) — pfSense

pfSense can't be configured by a single paste, so follow these steps once
(~10 minutes). Values specific to your account are filled in below.

## 1. WireGuard tunnel to the MESH Cloud hub
**VPN → WireGuard → Tunnels → Add**
- Listen Port: `51820`
- Interface Keys: click **Generate** (pfSense creates the keypair; the private
  key stays on your box). Copy the **Public Key** — you'll paste it back into
  the MESH Cloud onboarding page to finish.
- Interface Address: `{{ASSIGNED_IP}}/16`

**Add Peer** to that tunnel:
- Public Key: `{{SERVER_PUBKEY}}`   (the MESH hub)
- Endpoint: `{{HUB_ENDPOINT}}`
- Allowed IPs: `{{ALLOWED_IPS}}`
- Persistent Keepalive: `25`

Enable the tunnel, then **Interfaces → Assignments** add the WG interface and
enable it. Add a firewall rule on the WireGuard interface allowing the tunnel.

## 2. RADIUS server (over the tunnel)
**System → User Manager → Authentication Servers → Add**
- Type: `RADIUS`
- Hostname/IP: `{{RADIUS_IP}}`
- Shared Secret: `{{RADIUS_SECRET}}`
- Auth Port `1812`, Acct Port `1813`, enable accounting.

## 3. Captive Portal
**Services → Captive Portal →** your zone:
- Authentication: **RADIUS**, select the server from step 2.
- Enable **RADIUS MAC authentication** off; **Reauthenticate** as desired.
- Send RADIUS accounting: **on** (this is what powers your MESH dashboard).
- After-auth redirect / custom login: point users to your hosted portal
  `{{PORTAL_URL}}`.
- **Allowed Hostnames (walled garden):** add `{{PORTAL_HOST}}`,
  `*.safaricom.co.ke`, `sandbox.safaricom.co.ke`, `api.fontshare.com`,
  `*.fontshare.com`.

## 4. Monitoring (SNMP over the tunnel)
**Services → SNMP**
- Enable the SNMP daemon.
- Community String: `{{SNMP_COMMUNITY}}`  (read-only)
- Bind Interfaces: the **WireGuard** interface only (never WAN).
- Leave the polling port at `161`.

This lets MESH Cloud read CPU, uptime, and interface throughput for your
**Network** dashboard. It is read-only and reachable only inside the tunnel.

## 5. Finish
Back in MESH Cloud onboarding, paste your tunnel **Public Key** and click
**Activate**. Your router will show as *connected* within a minute, and
sessions will start appearing in **Live Sessions**.
