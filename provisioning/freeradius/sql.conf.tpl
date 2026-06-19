# FreeRADIUS rlm_sql — MESH Cloud (multi-tenant).
# Copy to /etc/freeradius/3.0/mods-available/sql, enable with:
#   ln -s ../mods-available/sql /etc/freeradius/3.0/mods-enabled/sql
#
# Multi-tenancy strategy:
#  * RADIUS clients (the tenant routers) are read straight from the `nas` table
#    (read_clients = yes). Each tunnel IP is one client -> one tenant.
#  * Voucher usernames are globally unique (namespaced "<slug>-VCH-XXXXXX"),
#    so the STOCK authorize/accounting queries work unchanged. The extra
#    tenant_id column is for the app's dashboards, not for RADIUS itself.

sql {
    dialect = "mysql"
    driver  = "rlm_sql_${dialect}"

    mysql {
        # Point at the same database the PHP app uses.
        tls { }
    }

    server   = "127.0.0.1"     # if FreeRADIUS runs on the hub alongside MySQL
    port     = 3306
    login    = "meshradius"
    password = "CHANGE_ME"
    radius_db = "meshcloud"

    # Standard table names (match schema.sql)
    acct_table1   = "radacct"
    acct_table2   = "radacct"
    postauth_table = "radpostauth"
    authcheck_table = "radcheck"
    authreply_table = "radreply"
    usergroup_table = "radusergroup"

    # Read RADIUS clients (NAS) dynamically from the nas table.
    read_clients = yes
    client_table = "nas"

    # Write accounting (this is what powers the per-tenant dashboards).
    read_groups = yes
    delete_stale_sessions = yes

    pool {
        start = 4
        min   = 3
        max   = 32
        idle_timeout = 60
    }

    # Use the packaged default queries (queries.conf). They key on UserName,
    # which is unique per tenant thanks to the slug prefix.
    $INCLUDE ${modconfdir}/${.:name}/main/${dialect}/queries.conf
}
