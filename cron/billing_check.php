<?php
// cron/billing_check.php — subscription lifecycle enforcement.
// Run daily, e.g.:  0 2 * * *  DB_DRIVER=mysql php /path/SAAS/cron/billing_check.php
//
//   active/trial whose period has ended      -> past_due
//   past_due past the grace window (3 days)   -> suspend the tenant portal
// Extending a subscription from the operator console reactivates both.

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/Database.php';
$pdo = Database::pdo($config);

$now      = date('Y-m-d H:i:s');
$graceEnd = date('Y-m-d H:i:s', time() - 3 * 86400); // 3-day grace

// 1) lapsed active/trial -> past_due
$st = $pdo->prepare(
    "UPDATE subscriptions SET status='past_due'
     WHERE status IN ('active','trial') AND current_period_end IS NOT NULL AND current_period_end < ?"
);
$st->execute([$now]);
$lapsed = $st->rowCount();

// 2) past_due beyond grace -> suspend tenant
$find = $pdo->prepare(
    "SELECT tenant_id FROM subscriptions WHERE status='past_due' AND current_period_end < ?"
);
$find->execute([$graceEnd]);
$suspended = 0;
foreach ($find->fetchAll(PDO::FETCH_COLUMN) as $tid) {
    $u = $pdo->prepare("UPDATE tenants SET status='suspended' WHERE id=? AND status<>'suspended'");
    $u->execute([$tid]);
    $suspended += $u->rowCount();
}

fwrite(STDOUT, "[" . date('c') . "] billing_check: lapsed={$lapsed} suspended={$suspended}\n");
