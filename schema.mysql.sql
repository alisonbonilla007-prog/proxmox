-- MESH Cloud — MySQL/MariaDB production schema (port of schema.sql).
-- Differences from the SQLite version: AUTO_INCREMENT, CURRENT_TIMESTAMP
-- defaults, explicit types, InnoDB + utf8mb4. The app's now_sql() emits NOW()
-- when DB_DRIVER=mysql, so timestamps stay consistent.
SET NAMES utf8mb4;

CREATE TABLE superadmins (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tenants (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(128) NOT NULL,
    slug        VARCHAR(64) NOT NULL UNIQUE,
    status      VARCHAR(16) NOT NULL DEFAULT 'active',
    plan        VARCHAR(32) NOT NULL DEFAULT 'starter',
    brand_color VARCHAR(16) DEFAULT '#25f4a7',
    contact     VARCHAR(190),
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tenant_users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id     INT NOT NULL,
    username      VARCHAR(64) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          VARCHAR(16) NOT NULL DEFAULT 'owner',
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenant_user (tenant_id, username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tenant_settings (
    tenant_id       INT PRIMARY KEY,
    mpesa_env       VARCHAR(16) DEFAULT 'sandbox',
    shortcode       VARCHAR(32) DEFAULT '',
    passkey         VARCHAR(255) DEFAULT '',
    consumer_key    VARCHAR(255) DEFAULT '',
    consumer_secret VARCHAR(255) DEFAULT '',
    callback_base   VARCHAR(255) DEFAULT '',
    timeout         INT DEFAULT 30
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE subscriptions (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id          INT NOT NULL,
    plan               VARCHAR(32) NOT NULL DEFAULT 'starter',
    status             VARCHAR(16) NOT NULL DEFAULT 'trial',
    current_period_end DATETIME NULL,
    created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sub_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE nas (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id      INT NOT NULL,
    nasname        VARCHAR(64) NOT NULL,
    shortname      VARCHAR(64) NOT NULL,
    type           VARCHAR(16) DEFAULT 'other',
    secret         VARCHAR(64) NOT NULL,
    server         VARCHAR(64) DEFAULT NULL,   -- FreeRADIUS stock client_query selects this
    mon_user       VARCHAR(64) DEFAULT '',
    mon_pass       VARCHAR(128) DEFAULT '',
    snmp_community VARCHAR(64) DEFAULT 'public',
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_nas_name (nasname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE alerts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT NOT NULL,
    severity    VARCHAR(8) NOT NULL DEFAULT 'warn',
    kind        VARCHAR(16) NOT NULL,
    message     VARCHAR(255) NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    KEY idx_alerts_tenant (tenant_id, resolved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE wg_peers (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id      INT NOT NULL,
    public_key     VARCHAR(64) NOT NULL DEFAULT '',
    server_pubkey  VARCHAR(64) NOT NULL,
    assigned_ip    VARCHAR(32) NOT NULL,
    endpoint       VARCHAR(128) NOT NULL,
    allowed_ips    VARCHAR(64) NOT NULL DEFAULT '10.66.0.0/16',
    status         VARCHAR(16) NOT NULL DEFAULT 'pending',
    last_handshake DATETIME NULL,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_wg_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE device_metrics (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT NOT NULL,
    cpu_load    INT,
    uptime_s    BIGINT,
    rx_bps      BIGINT,
    tx_bps      BIGINT,
    sampled_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_metrics_tenant (tenant_id, sampled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE vouchers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT NOT NULL,
    name            VARCHAR(128) NOT NULL,
    price           DECIMAL(10,2) NOT NULL,
    time_limit      INT DEFAULT 0,
    bandwidth_limit VARCHAR(32) DEFAULT '',
    data_limit      BIGINT DEFAULT 0,
    KEY idx_vouchers_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payments (
    id                  BIGINT AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT NOT NULL,
    voucher_id          INT NOT NULL,
    merchant_request_id VARCHAR(64),
    checkout_request_id VARCHAR(64),
    phone               VARCHAR(20),
    amount              DECIMAL(10,2),
    status              VARCHAR(16) DEFAULT 'pending',
    username            VARCHAR(64),
    password            VARCHAR(64),
    mpesa_response      TEXT,
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_payments_tenant (tenant_id),
    KEY idx_payments_checkout (checkout_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- FreeRADIUS-compatible auth tables (+ tenant_id for app scoping).
CREATE TABLE radcheck (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL DEFAULT 0,
    username  VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '',
    op        CHAR(2) NOT NULL DEFAULT ':=',
    value     VARCHAR(253) NOT NULL DEFAULT '',
    KEY idx_radcheck_user (username),
    KEY idx_radcheck_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE radreply (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL DEFAULT 0,
    username  VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '',
    op        CHAR(2) NOT NULL DEFAULT ':=',
    value     VARCHAR(253) NOT NULL DEFAULT '',
    KEY idx_radreply_user (username),
    KEY idx_radreply_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE radusergroup (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL DEFAULT 0,
    username  VARCHAR(64) NOT NULL DEFAULT '',
    groupname VARCHAR(64) NOT NULL DEFAULT '',
    priority  INT NOT NULL DEFAULT 1,
    KEY idx_rug_user (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Referenced by stock FreeRADIUS per-request group queries (kept so no runtime errors).
CREATE TABLE radgroupcheck (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    groupname VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '',
    op        CHAR(2) NOT NULL DEFAULT ':=',
    value     VARCHAR(253) NOT NULL DEFAULT '',
    KEY idx_rgc_group (groupname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE radgroupreply (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    groupname VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '',
    op        CHAR(2) NOT NULL DEFAULT ':=',
    value     VARCHAR(253) NOT NULL DEFAULT '',
    KEY idx_rgr_group (groupname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stock FreeRADIUS radacct columns (+ tenant_id). Matches packaged queries.conf.
-- NOTE: stock accounting INSERTs don't set tenant_id, so install-allinone.sh adds
-- a BEFORE INSERT trigger (radacct_set_tenant) that derives it from radcheck by
-- username. Without it, per-tenant Live Sessions/active-user views stay empty.
CREATE TABLE radacct (
    radacctid           BIGINT AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT NOT NULL DEFAULT 0,
    acctsessionid       VARCHAR(64) NOT NULL DEFAULT '',
    acctuniqueid        VARCHAR(32) NOT NULL DEFAULT '',
    username            VARCHAR(64) NOT NULL DEFAULT '',
    realm               VARCHAR(64) DEFAULT '',
    nasipaddress        VARCHAR(45) NOT NULL DEFAULT '',
    nasportid           VARCHAR(32),
    nasporttype         VARCHAR(32),
    acctstarttime       DATETIME NULL,
    acctupdatetime      DATETIME NULL,
    acctstoptime        DATETIME NULL,
    acctinterval        INT NULL,
    acctsessiontime     INT UNSIGNED NULL,
    acctauthentic       VARCHAR(32),
    connectinfo_start   VARCHAR(50),
    connectinfo_stop    VARCHAR(50),
    acctinputoctets     BIGINT NULL,
    acctoutputoctets    BIGINT NULL,
    calledstationid     VARCHAR(50) NOT NULL DEFAULT '',
    callingstationid    VARCHAR(50) NOT NULL DEFAULT '',
    acctterminatecause  VARCHAR(32) NOT NULL DEFAULT '',
    servicetype         VARCHAR(32),
    framedprotocol      VARCHAR(32),
    framedipaddress     VARCHAR(45) NOT NULL DEFAULT '',
    KEY idx_acct_session (acctsessionid),
    KEY idx_acct_unique (acctuniqueid),
    KEY idx_acct_user (username),
    KEY idx_acct_tenant (tenant_id),
    KEY idx_acct_stop (acctstoptime),
    KEY idx_acct_active (nasipaddress, acctstoptime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- FreeRADIUS post-auth log (created so stock post-auth query works).
CREATE TABLE radpostauth (
    id        BIGINT AUTO_INCREMENT PRIMARY KEY,
    username  VARCHAR(64) NOT NULL DEFAULT '',
    pass      VARCHAR(64) NOT NULL DEFAULT '',
    reply     VARCHAR(32) NOT NULL DEFAULT '',
    authdate  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_postauth_user (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
