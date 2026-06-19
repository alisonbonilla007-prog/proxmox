-- MESH Cloud — multi-tenant schema
-- Written to be portable between SQLite (testing) and MySQL (production).
-- Production note: the rad* tables follow the FreeRADIUS layout but add a
-- `tenant_id` column for fast scoping; usernames are ALSO namespaced per
-- tenant (<slug>-VCH-XXXXXX) so stock FreeRADIUS queries stay unique.

-- ---------- Platform ----------
CREATE TABLE superadmins (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at    TEXT DEFAULT (datetime('now'))
);

CREATE TABLE tenants (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL,
    slug        TEXT NOT NULL UNIQUE,        -- subdomain: <slug>.meshcloud.example
    status      TEXT NOT NULL DEFAULT 'active', -- active|suspended
    plan        TEXT NOT NULL DEFAULT 'starter',
    brand_color TEXT DEFAULT '#25f4a7',
    contact     TEXT,
    created_at  TEXT DEFAULT (datetime('now'))
);

CREATE TABLE tenant_users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id     INTEGER NOT NULL,
    username      TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    role          TEXT NOT NULL DEFAULT 'owner',
    created_at    TEXT DEFAULT (datetime('now')),
    UNIQUE (tenant_id, username)
);

-- Per-tenant M-Pesa (Daraja) credentials + branding/config.
CREATE TABLE tenant_settings (
    tenant_id       INTEGER PRIMARY KEY,
    mpesa_env       TEXT DEFAULT 'sandbox',   -- sandbox|production
    shortcode       TEXT DEFAULT '',
    passkey         TEXT DEFAULT '',
    consumer_key    TEXT DEFAULT '',
    consumer_secret TEXT DEFAULT '',
    callback_base   TEXT DEFAULT '',
    timeout         INTEGER DEFAULT 30
);

-- Our SaaS billing of the tenant (separate from voucher sales).
CREATE TABLE subscriptions (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id          INTEGER NOT NULL,
    plan               TEXT NOT NULL DEFAULT 'starter',
    status             TEXT NOT NULL DEFAULT 'trial', -- trial|active|past_due|canceled
    current_period_end TEXT,
    created_at         TEXT DEFAULT (datetime('now'))
);

-- ---------- Connectivity (WireGuard + NAS) ----------
-- FreeRADIUS-style clients table, one row per tenant router, linked to tenant.
CREATE TABLE nas (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id      INTEGER NOT NULL,
    nasname        TEXT NOT NULL,        -- tunnel IP of the router (RADIUS client IP)
    shortname      TEXT NOT NULL,
    type           TEXT DEFAULT 'other', -- mikrotik|pfsense|other
    secret         TEXT NOT NULL,        -- RADIUS shared secret
    mon_user       TEXT DEFAULT '',      -- RouterOS REST read-only user
    mon_pass       TEXT DEFAULT '',
    snmp_community TEXT DEFAULT 'public',-- pfSense/other SNMP community (read-only)
    created_at     TEXT DEFAULT (datetime('now'))
);

-- Health/availability alerts raised by the monitoring poller.
CREATE TABLE alerts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id   INTEGER NOT NULL,
    severity    TEXT NOT NULL DEFAULT 'warn', -- info|warn|crit
    kind        TEXT NOT NULL,                -- offline|cpu|uptime
    message     TEXT NOT NULL,
    created_at  TEXT DEFAULT (datetime('now')),
    resolved_at TEXT
);
CREATE INDEX idx_alerts_tenant ON alerts(tenant_id, resolved_at);

-- One WireGuard peer per tenant router on the self-hosted hub.
CREATE TABLE wg_peers (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id      INTEGER NOT NULL,
    public_key     TEXT NOT NULL,     -- router's public key
    server_pubkey  TEXT NOT NULL,     -- hub public key (shown to client)
    assigned_ip    TEXT NOT NULL,     -- tunnel IP handed to the router (= nas.nasname)
    endpoint       TEXT NOT NULL,     -- hub host:port the router dials
    allowed_ips    TEXT NOT NULL DEFAULT '10.66.0.0/16',
    status         TEXT NOT NULL DEFAULT 'pending', -- pending|connected
    last_handshake TEXT,
    created_at     TEXT DEFAULT (datetime('now'))
);

-- Router health samples (Phase 3 poller writes here).
CREATE TABLE device_metrics (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id   INTEGER NOT NULL,
    cpu_load    INTEGER,
    uptime_s    INTEGER,
    rx_bps      INTEGER,
    tx_bps      INTEGER,
    sampled_at  TEXT DEFAULT (datetime('now'))
);

-- ---------- Business data (tenant-scoped) ----------
CREATE TABLE vouchers (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id       INTEGER NOT NULL,
    name            TEXT NOT NULL,
    price           REAL NOT NULL,
    time_limit      INTEGER DEFAULT 0,
    bandwidth_limit TEXT DEFAULT '',
    data_limit      INTEGER DEFAULT 0
);

CREATE TABLE payments (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id           INTEGER NOT NULL,
    voucher_id          INTEGER NOT NULL,
    merchant_request_id TEXT,
    checkout_request_id TEXT,
    phone               TEXT,
    amount              REAL,
    status              TEXT DEFAULT 'pending',
    username            TEXT,
    password            TEXT,
    mpesa_response      TEXT,
    created_at          TEXT DEFAULT (datetime('now')),
    updated_at          TEXT DEFAULT (datetime('now'))
);

CREATE TABLE radcheck (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    username  TEXT NOT NULL,
    attribute TEXT NOT NULL,
    op        TEXT NOT NULL DEFAULT ':=',
    value     TEXT NOT NULL
);

CREATE TABLE radreply (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    username  TEXT NOT NULL,
    attribute TEXT NOT NULL,
    op        TEXT NOT NULL DEFAULT ':=',
    value     TEXT NOT NULL
);

CREATE TABLE radusergroup (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    username  TEXT NOT NULL,
    groupname TEXT NOT NULL,
    priority  INTEGER DEFAULT 1
);

CREATE TABLE radacct (
    radacctid          INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id          INTEGER NOT NULL,
    acctsessionid      TEXT,
    acctuniqueid       TEXT,
    username           TEXT,
    nasipaddress       TEXT,
    callingstationid   TEXT,
    framedipaddress    TEXT,
    acctstarttime      TEXT,
    acctstoptime       TEXT,
    acctsessiontime    INTEGER DEFAULT 0,
    acctinputoctets    INTEGER DEFAULT 0,
    acctoutputoctets   INTEGER DEFAULT 0,
    acctterminatecause TEXT
);

CREATE INDEX idx_vouchers_tenant ON vouchers(tenant_id);
CREATE INDEX idx_payments_tenant ON payments(tenant_id);
CREATE INDEX idx_radacct_tenant  ON radacct(tenant_id);
CREATE INDEX idx_radcheck_tenant ON radcheck(tenant_id);
CREATE INDEX idx_metrics_tenant  ON device_metrics(tenant_id, sampled_at);
