# MESH Cloud — Field-test issues & remediation plan

Issues found while testing the lab end-to-end (MikroTik hotspot on `ether2`, VM
`192.168.1.50`, tenant `swiftnet`). Each entry has the **symptom**, the **root
cause** (with file references), and the **fix**. The implementation plan at the
bottom orders the work and groups the three tightly-coupled captive-portal items.

---

## Issue 1 — No "Connect"/auto-connect after buying a voucher
**Symptom:** After payment the modal shows username/password ([views/portal.php:99-104](views/portal.php#L99-L104)) but there is no button to actually get online; the user must find the router login page and type the creds manually. The connect step should adapt to the router (MikroTik / pfSense).

**Root cause:** The portal never receives the router's captive-portal handoff parameters and has no code to submit credentials back to the NAS. The buy flow ends at displaying creds; there is no "login to the gateway" step.

**Fix:** Add a **captive-portal login bridge** (shared with Issues 6 & 7):
- Capture the gateway params the NAS appends when it redirects an unauthenticated client to the portal:
  - MikroTik: `link-login-only`, `link-orig`, `mac`, `error`, (`chap-id`,`chap-challenge` if CHAP).
  - pfSense: `zone`, `redirurl` (+ the portal POST URL).
- Detect router type from those params (presence of `zone` ⇒ pfSense, `link-login-only` ⇒ MikroTik); fall back to `nas.type`.
- After issuance, render a **Connect now** button that auto-POSTs `username`/`password` to the gateway login URL (MikroTik `http-pap` POST to `link-login-only`; pfSense POST `auth_user`/`auth_pass`/`accept`/`zone`/`redirurl`).

---

## Issue 2 — Tenant admin shows no live data (active users empty)
**Symptom:** `/admin?tenant=swiftnet` overview/Live Sessions show no active users; only revenue/success-rate KPIs populate.

**Root cause:** `radacct.tenant_id` defaults to `0` ([schema.mysql.sql:190](schema.mysql.sql#L190)) and **stock FreeRADIUS accounting queries don't set it**. The admin queries are tenant-scoped — `WHERE tenant_id = ?` ([controllers_admin.php:109-112](src/controllers_admin.php#L109-L112)) — so every accounting row (tenant_id 0) is filtered out. Auth still works because usernames are globally namespaced (`<slug>-VCH-…`), and `radcheck` carries the right tenant_id from [Mpesa.php:97-113](src/Mpesa.php#L97-L113).

**Fix:** Populate `radacct.tenant_id` on insert. Preferred (FreeRADIUS-version independent): a `BEFORE INSERT` trigger on `radacct` that sets `tenant_id = (SELECT tenant_id FROM radcheck WHERE username = NEW.username AND attribute='Cleartext-Password' LIMIT 1)`. Add to `schema.mysql.sql` and as an idempotent migration in `install-allinone.sh`. (Alternative: patch the SQL module's `accounting_*_query` to a subquery — more fragile across versions.)

---

## Issue 3 — Network section says "Connect router" although it's connected; live sessions dead
**Symptom:** After the tunnel is up, **Network** keeps showing "No router telemetry yet / Connect router" ([views/admin.php:99-104](views/admin.php#L99-L104)).

**Root cause (two faults):**
1. The monitoring poller only runs for peers with `w.status = 'connected'` ([monitoring/poll.php:27](monitoring/poll.php#L27)), but **nothing ever sets that status to `connected`** — `ensurePeer` writes `'pending'` ([Provision.php:28-30](src/Provision.php#L28-L30)) and `savePublicKey` only updates the key ([Provision.php:50-52](src/Provision.php#L50-L52)). `add-peer.sh` doesn't touch the DB at all.
2. Even if it polled, `nas.type` is hard-coded `'other'` ([Provision.php:36-39](src/Provision.php#L36-L39)), so `Monitor::poll` takes the SNMP branch instead of the RouterOS REST branch ([Monitor.php:19-22](src/Monitor.php#L19-L22)) — MikroTik has no SNMP configured, so the poll fails → `device_metrics` stays empty.

**Fix:**
- Record the real router type (`mikrotik`/`pfsense`) on the NAS row — let the operator pick it in the onboarding/activate step (or auto-detect from a successful RouterOS REST probe).
- Add a **handshake sync**: read `wg show wg0 latest-handshakes`, and for each peer with a recent handshake set `wg_peers.status='connected'` + `last_handshake`. Run it from `poll.php` (before the poll query) or a tiny cron. This makes "connected" reflect reality and unblocks polling.

---

## Issue 4 — Time-limited voucher resets on re-login (reusable forever)
**Symptom:** A 5-min voucher disconnects at 5 min, but logging back in with the **same** voucher grants another 5 min, indefinitely.

**Root cause:** Only `Session-Timeout` is written ([Mpesa.php:100-103](src/Mpesa.php#L100-L103)), which is **per-login-session**. Nothing caps cumulative usage, so each new session restarts the clock.

**Fix:** Enforce a total budget with FreeRADIUS **sqlcounter** (no-reset):
- `issueVoucher` writes a check attribute `Max-All-Session := <time_limit>` (in `radcheck`) instead of relying solely on `Session-Timeout`.
- Configure an sqlcounter in `install-allinone.sh` that sums `acctsessiontime` per username, sets `Session-Timeout` to the **remaining** time, and **rejects** once the budget is spent.
- Optional cron: delete fully-consumed/expired voucher accounts so the Issued list stays clean.

---

## Issue 5 — Superadmin shows "tunnel pending" after a working connection; no per-router active view
**Symptom:** `/superadmin` still shows the tenant tunnel as **pending** after a successful connection, and doesn't indicate which router an active user is on.

**Root cause:** `wg_status` is read straight from `wg_peers.status` ([controllers_superadmin.php:99](src/controllers_superadmin.php#L99)), which is stuck at `'pending'` (same status-lifecycle bug as Issue 3).

**Fix:** The handshake sync from Issue 3 flips status to `connected`. Then enrich the fleet row to show the router (`nas.shortname`/`type`), handshake age, and the online-session count already computed at [controllers_superadmin.php:98](src/controllers_superadmin.php#L98).

---

## Issue 6 — Hotspot redirect lands on the apex landing page, not the portal
**Symptom:** With MikroTik `login.html` pointed at `http://192.168.1.50/?tenant=swiftnet`, clients land on the "hotspot operating system / Start free / Operator console" page — that's the apex `landing.php` ([views/landing.php](views/landing.php)), served by `/` when no tenant resolves ([public/index.php:121-132](public/index.php#L121-L132)). So `?tenant=swiftnet` isn't arriving.

**Root cause:** The hand-edited `login.html` redirect drops/omits the tenant query (and the gateway params), so `Tenancy::resolveSlug()` ([Tenancy.php:14-29](src/Tenancy.php#L14-L29)) finds nothing and the app falls back to the apex landing.

**Fix:** Ship a proper MikroTik hotspot **`login.html`** (and `alogin.html`/`error.html`) template under `provisioning/hotspot/mikrotik/` that redirects unauthenticated clients to `http://<portal>/?tenant=<slug>` **with** the MikroTik magic variables appended (`mac`, `ip`, `link-login-only`, `link-orig`, `error`). This fixes the redirect and feeds the connect bridge (Issues 1 & 7). Defensive add: if `/` resolves no tenant but exactly one tenant exists (lab) or a default is configured, log it clearly rather than silently showing the apex page.

---

## Issue 7 — No login form on the portal for users with an unused voucher
**Symptom:** A customer who already bought a voucher (still has time left) can't log back in from the portal — there's no form ([views/portal.php](views/portal.php) only has the buy grid).

**Root cause:** Portal was built buy-only; no re-auth path.

**Fix:** Add a "Have a voucher? Log in" form on the portal that submits through the **same captive-portal login bridge** as Issue 1 (POST creds to the gateway login URL, router-type aware).

---

## Status (2026-06-22)
- ✅ **Phase A** done — status sync (`pending→activated→connected` via `wg show`), real `nas.type` at activate, `radacct.tenant_id` trigger + backfill, superadmin fleet enrichment.
- ✅ **Phase B** done — `Max-All-Session` budget on vouchers + no-reset FreeRADIUS `sqlcounter` (rejects reuse after expiry, honors leftover time).
- ✅ **Phase C** done — MikroTik `hotspot/login.html` template (redirects to portal w/ gateway params), portal **Connect now** button + **"Already have a voucher?"** login form, IP-aware portal URL/host.
- ⏳ **Phase D** — end-to-end verification on the lab (requires the VM + router).

Deploy A/B (touch the installer/FreeRADIUS): `git pull` then `sudo bash install-allinone.sh` on the VM. C is app + a static hotspot page (upload via onboarding step 2).

## Implementation plan (ordered)

### Phase A — Make the backend reflect reality (unblocks 2, 3, 5)
1. **Handshake sync + status lifecycle.** Add a `wg show` reader that sets `wg_peers.status='connected'` + `last_handshake`; call it at the top of `poll.php` (and/or a 1-min cron). Unify status vocabulary (`pending → connected`). *(Issues 3, 5)*
2. **Correct `nas.type`.** Add router-type selection to the activate step (default by which tab/script was used); persist `mikrotik`/`pfsense`. *(Issue 3)*
3. **`radacct.tenant_id` trigger.** Add the BEFORE INSERT trigger to `schema.mysql.sql` + idempotent migration in `install-allinone.sh`; backfill existing rows once. *(Issue 2)*
4. **Superadmin fleet enrichment.** Show router name/type, handshake age, online count. *(Issue 5)*

### Phase B — One-time voucher enforcement (4)
5. **`Max-All-Session` + sqlcounter.** Write `Max-All-Session` in `issueVoucher`; configure the no-reset sqlcounter in the installer; (optional) cleanup cron for consumed accounts. *(Issue 4)*

### Phase C — Captive-portal login bridge (1, 6, 7) — the largest piece
6. **Hotspot page templates.** Add `provisioning/hotspot/mikrotik/{login,alogin,error}.html` that redirect to the tenant portal carrying the gateway params; document upload (or push via the .rsc/REST). *(Issue 6)*
7. **Portal receives gateway params** and detects router type (MikroTik vs pfSense vs `nas.type`). *(Issues 1, 7)*
8. **Connect bridge UI + submit.** Add the "Connect now" button after purchase and a "Have a voucher? Log in" form, both POSTing creds to the gateway login URL with the right fields per router. *(Issues 1, 7)*

### Phase D — Verify
9. End-to-end on the lab: buy → auto-connect; re-login with leftover voucher; 5-min voucher cannot be reused after expiry; Network + Live Sessions + superadmin all show live data and `connected`.

**Suggested sequencing:** Phase A first (highest value, makes the dashboards real and is low-risk), then C (the visible customer-facing gap), then B, then D. A and B touch `install-allinone.sh`/FreeRADIUS and need a re-run on the VM; C is mostly app + a static hotspot template.
