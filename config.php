<?php
// config.php — environment-driven configuration for MESH Cloud.
//
// DB_DRIVER=sqlite  -> uses SAAS/meshcloud.db (local testing)
// DB_DRIVER=mysql   -> uses DB_HOST/DB_NAME/DB_USER/DB_PASS from the environment
//
// APP_DOMAIN is the apex SaaS domain; tenant portals live at <slug>.APP_DOMAIN.
// WG_* describe the self-hosted WireGuard hub used for onboarding configs.

return [
    'db_driver'   => getenv('DB_DRIVER') ?: 'sqlite',
    'sqlite_path' => __DIR__ . '/meshcloud.db',
    'mysql' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'name' => getenv('DB_NAME') ?: 'meshcloud',
        'user' => getenv('DB_USER') ?: 'meshcloud',
        'pass' => getenv('DB_PASS') ?: '',
    ],
    'app_domain'  => getenv('APP_DOMAIN') ?: 'meshcloud.example',
    'wg' => [
        'endpoint'      => getenv('WG_ENDPOINT')   ?: 'hub.meshcloud.example:51820',
        'server_pubkey' => getenv('WG_SERVER_PUBKEY') ?: 'hubPUBLICkeyBASE64exampleAAAAAAAAAAAAAAAAAAA=',
        'subnet'        => getenv('WG_SUBNET')     ?: '10.66.0.0/16',
        'radius_ip'     => getenv('WG_RADIUS_IP')  ?: '10.66.0.1',  // hub-side RADIUS reachable over tunnel
    ],
];
