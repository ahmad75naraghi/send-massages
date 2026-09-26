<?php
/**
 * cli_run.php — اجرای یک action از sync_manual.php در محیط CLI
 * ============================================================
 *  کاربرد: cron / systemd / تست دستی بدون عبور از Apache
 *          (بدون محدودیت ProxyTimeout و بدون نیاز به SECURITY_KEY).
 *
 *  استفاده:
 *    ACTION=get_pending php cli_run.php
 *    ACTION=sync_single SYNC_BODY_FILE=/tmp/body.json php cli_run.php
 *    ACTION=send_report SYNC_BODY_FILE=/tmp/report.json php cli_run.php
 *    ACTION=sync_recent SYNC_BODY_FILE=/tmp/recent.json php cli_run.php
 *    ACTION=queue_status php cli_run.php
 *    ACTION=background_run SYNC_BODY_FILE=/tmp/bg.json php cli_run.php
 *      ← موتور صف پس‌زمینه؛ همان چیزی که دکمهٔ داشبورد با launcher اجرا می‌کند.
 *         فقط CLI مجاز است (از وب 403 می‌گیرد). بدنهٔ JSON:
 *         {"profile":"main","maxPosts":50,"gap":0,"only":["bale","rubika","soroush","igap"]}
 */
declare(strict_types=1);

$action = getenv('ACTION');
if (!is_string($action) or $action === '') {
    fwrite(STDERR, "Usage: ACTION=get_pending|sync_single|send_report php cli_run.php\n");
    exit(2);
}
$_GET['action'] = $action;

require __DIR__ . '/sync_manual.php';
