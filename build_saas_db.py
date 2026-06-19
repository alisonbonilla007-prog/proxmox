#!/usr/bin/env python3
"""build_saas_db.py — Create the MESH Cloud multi-tenant SQLite test DB.

Loads schema.sql, then seeds the platform with a superadmin and two demo
tenants (different branding, packages, payments, sessions, NAS + WG peer)
so tenant isolation and the dashboards can be exercised locally.

Run:  python build_saas_db.py
Then: DB_DRIVER=sqlite php ... -t SAAS/public   and visit /?tenant=swiftnet
"""
import os
import sqlite3
from datetime import datetime, timedelta

HERE = os.path.dirname(os.path.abspath(__file__))
DB = os.path.join(HERE, "meshcloud.db")
SCHEMA = os.path.join(HERE, "schema.sql")

# Real PHP bcrypt hashes — verifiable with password_verify().
# "admin123" (superadmin "root") and "owner123" (each tenant owner).
HASH_ADMIN = "$2y$12$nTRy88fmVqDiD/YNhjpXMOam0SIQk4M8XPjD6TGwkixtYqjBDbFxe"
HASH_OWNER = "$2y$12$.lY3ffhglwrWjz41FfmyoefRL0zA6mO5gwymsbjBLxeoyQx6OC2YK"

GB = 1024 ** 3
MB = 1024 ** 2

if os.path.exists(DB):
    os.remove(DB)

con = sqlite3.connect(DB)
cur = con.cursor()
with open(SCHEMA, "r", encoding="utf-8") as fh:
    cur.executescript(fh.read())


def ts(delta):
    return (datetime.now() + delta).strftime("%Y-%m-%d %H:%M:%S")


# ---- superadmin ----
cur.execute("INSERT INTO superadmins (username, password_hash) VALUES ('root', ?)", (HASH_ADMIN,))

# ---- tenants ----
tenants = [
    # name, slug, brand_color, contact
    ("SwiftNet WiFi",   "swiftnet", "#25f4a7", "swiftnet@example.com"),
    ("BlueWave Hotspot", "bluewave", "#19c8ff", "ops@bluewave.example"),
]
SERVER_PUBKEY = "hubPUBLICkeyBASE64exampleAAAAAAAAAAAAAAAAAAA="
HUB_ENDPOINT = "hub.meshcloud.example:51820"

packages_by_tenant = {
    "swiftnet": [
        ("Quick 5 Min",       5.0,   300,     "5M/5M",   0),
        ("1 Hour",            20.0,  3600,    "10M/10M", 0),
        ("Daily Pass",        50.0,  86400,   "10M/10M", 0),
        ("Weekly",            250.0, 604800,  "15M/15M", 0),
    ],
    "bluewave": [
        ("Trial 10 Min",      0.0,   600,     "3M/3M",   0),
        ("3 Hours",           30.0,  10800,   "20M/20M", 0),
        ("Data 2GB",          99.0,  0,       "20M/20M", 2 * GB),
        ("Monthly Unlimited", 1500.0, 2592000, "30M/30M", 0),
    ],
}

for tname, slug, color, contact in tenants:
    cur.execute(
        "INSERT INTO tenants (name, slug, brand_color, contact) VALUES (?, ?, ?, ?)",
        (tname, slug, color, contact),
    )
    tid = cur.lastrowid
    cur.execute(
        "INSERT INTO tenant_users (tenant_id, username, password_hash, role) VALUES (?, 'owner', ?, 'owner')",
        (tid, HASH_OWNER),
    )
    cur.execute(
        "INSERT INTO tenant_settings (tenant_id, mpesa_env, shortcode, passkey, consumer_key, consumer_secret, callback_base, timeout) "
        "VALUES (?, 'sandbox', '174379', 'sandboxpasskey', 'ckey', 'csecret', '', 30)",
        (tid,),
    )
    cur.execute(
        "INSERT INTO subscriptions (tenant_id, plan, status, current_period_end) VALUES (?, 'starter', 'active', ?)",
        (tid, ts(timedelta(days=20))),
    )

    # WireGuard peer + NAS (tunnel IP)
    tunnel_ip = f"10.66.{tid}.1"
    cur.execute(
        "INSERT INTO wg_peers (tenant_id, public_key, server_pubkey, assigned_ip, endpoint, status, last_handshake) "
        "VALUES (?, ?, ?, ?, ?, 'connected', ?)",
        (tid, f"router{tid}PUBKEYexampleBBBBBBBBBBBBBBBBBBBBBBB=", SERVER_PUBKEY, tunnel_ip, HUB_ENDPOINT, ts(timedelta(minutes=-2))),
    )
    ntype = "mikrotik" if slug == "swiftnet" else "pfsense"
    cur.execute(
        "INSERT INTO nas (tenant_id, nasname, shortname, type, secret, mon_user, mon_pass, snmp_community) "
        "VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        (tid, tunnel_ip, slug, ntype, f"secret-{slug}-{tid}",
         "mesh-monitor" if ntype == "mikrotik" else "", "monpass" if ntype == "mikrotik" else "", "mesh-ro"),
    )

    # 24h of hourly health samples so the Network dashboard has data
    base_cpu = 12 if ntype == "mikrotik" else 18
    up0 = 3600 * 24 * (5 + tid)  # a few days of uptime
    for hh in range(24, -1, -1):
        cpu = base_cpu + (hh * 7 + tid * 11) % 40
        rx = (40 + (hh * 13 + tid) % 60) * 1_000_000   # ~40-100 Mbps
        tx = (8 + (hh * 5 + tid) % 20) * 1_000_000
        cur.execute(
            "INSERT INTO device_metrics (tenant_id, cpu_load, uptime_s, rx_bps, tx_bps, sampled_at) "
            "VALUES (?, ?, ?, ?, ?, ?)",
            (tid, cpu, up0 + (24 - hh) * 3600, rx, tx, ts(timedelta(hours=-hh))),
        )

    # Packages
    pkg_ids = []
    for name, price, tl, bw, dl in packages_by_tenant[slug]:
        cur.execute(
            "INSERT INTO vouchers (tenant_id, name, price, time_limit, bandwidth_limit, data_limit) VALUES (?, ?, ?, ?, ?, ?)",
            (tid, name, price, tl, bw, dl),
        )
        pkg_ids.append(cur.lastrowid)

    # Payments across the last 7 days (mixed statuses), namespaced usernames
    sample = [
        (pkg_ids[1], "254712345678", "success", -5, -2),
        (pkg_ids[2], "254723456789", "success", -3, -4),
        (pkg_ids[0], "254700111222", "failed",  -2, -1),
        (pkg_ids[2], "254700333444", "success", -1, -3),
        (pkg_ids[1], "254700555666", "pending",  0, -1),
        (pkg_ids[3], "254700777888", "success",  0, -2),
    ]
    issued = []
    for i, (vid, phone, status, d, h) in enumerate(sample):
        price = cur.execute("SELECT price FROM vouchers WHERE id=?", (vid,)).fetchone()[0]
        uname = f"{slug}-VCH-{tid}{i:02d}AF" if status == "success" else None
        pw = f"{tid}{i:02d}c3d4" if status == "success" else None
        cur.execute(
            "INSERT INTO payments (tenant_id, voucher_id, merchant_request_id, checkout_request_id, phone, amount, status, username, password, created_at, updated_at) "
            "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            (tid, vid, f"MR-{tid}{i}", f"ws_CO_{tid}{i}", phone, price, status, uname, pw, ts(timedelta(days=d, hours=h)), ts(timedelta(days=d, hours=h))),
        )
        if uname:
            tl = cur.execute("SELECT time_limit FROM vouchers WHERE id=?", (vid,)).fetchone()[0]
            cur.execute("INSERT INTO radcheck (tenant_id, username, attribute, op, value) VALUES (?, ?, 'Cleartext-Password', ':=', ?)", (tid, uname, pw))
            if tl > 0:
                cur.execute("INSERT INTO radreply (tenant_id, username, attribute, op, value) VALUES (?, ?, 'Session-Timeout', ':=', ?)", (tid, uname, str(tl)))
            cur.execute("INSERT INTO radusergroup (tenant_id, username, groupname, priority) VALUES (?, ?, 'voucher', 1)", (tid, uname))
            issued.append((uname, tunnel_ip))

    # Active + historical accounting sessions for the first two issued accounts
    for j, (uname, nasip) in enumerate(issued[:3]):
        active = j < 2
        cur.execute(
            "INSERT INTO radacct (tenant_id, acctsessionid, acctuniqueid, username, nasipaddress, callingstationid, framedipaddress, "
            "acctstarttime, acctstoptime, acctsessiontime, acctinputoctets, acctoutputoctets, acctterminatecause) "
            "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            (tid, f"S-{tid}-{j}", f"U-{tid}-{j}", uname, nasip,
             f"AA:BB:CC:{tid:02d}:{j:02d}:10", f"192.168.{tid}.{100+j}",
             ts(timedelta(hours=-(j+1))), None if active else ts(timedelta(minutes=-20)),
             (j+1)*1800, (j+1)*300*MB, (j+1)*40*MB, None if active else "Session-Timeout"),
        )

# Sample alerts: one active (tenant 2 high CPU), one resolved (tenant 1 offline)
cur.execute("INSERT INTO alerts (tenant_id, severity, kind, message, created_at) VALUES (2,'warn','cpu','Router CPU above 85% for 10 min', ?)", (ts(timedelta(minutes=-25)),))
cur.execute("INSERT INTO alerts (tenant_id, severity, kind, message, created_at, resolved_at) VALUES (1,'crit','offline','Router unreachable over tunnel', ?, ?)",
            (ts(timedelta(hours=-6)), ts(timedelta(hours=-5, minutes=-40))))

con.commit()
n_t = cur.execute("SELECT COUNT(*) FROM tenants").fetchone()[0]
n_v = cur.execute("SELECT COUNT(*) FROM vouchers").fetchone()[0]
n_p = cur.execute("SELECT COUNT(*) FROM payments").fetchone()[0]
con.close()
print(f"Created {DB}")
print(f"  tenants: {n_t}  vouchers: {n_v}  payments: {n_p}")
print("  superadmin: root / admin123   |   tenant owner: owner / owner123 (per tenant)")
