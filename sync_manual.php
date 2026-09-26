<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tehran');
error_reporting(E_ALL);

// ============================================================
//  پیکربندی: همهٔ مقدارها (به‌ویژه توکن‌ها) از فایل .env کنار برنامه
//  خوانده می‌شوند. ساخت .env:  bash setup_env.sh
//  هیچ secret ای در این فایل باقی نمانده است.
// ============================================================
require_once __DIR__ . '/config.php';

// در تولید، خطاهای PHP نباید وسط JSON/HTML چاپ شوند؛ با SYNC_DEBUG=1 می‌توان موقتاً روشن کرد.
ini_set('display_errors', envBool('SYNC_DEBUG', false) ? '1' : '0');
ini_set('log_errors', '1');

// اعتبارسنجی توکن دسترسی
if (php_sapi_name() !== 'cli') {
    if (SECURITY_KEY === '') {
        http_response_code(500);
        die("<h3 style='color:#b91c1c;'>پیکربندی ناقص: SECURITY_KEY در فایل .env مقدار ندارد.<br>"
          . "روی سرور اجرا کنید: <code>bash setup_env.sh</code> سپس <code>chmod 600 .env</code></h3>");
    }
    if (($_REQUEST['key'] ?? '') !== SECURITY_KEY) {
        http_response_code(403);
        die("<h3 style='color:red;'>Access Denied</h3>");
    }
    if (DASHBOARD_ALLOWED_IP !== '') {
        $allowed = array_map('trim', explode(',', DASHBOARD_ALLOWED_IP));
        $client  = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!in_array($client, $allowed, true)) {
            http_response_code(403);
            die("<h3 style='color:red;'>Access Denied (IP)</h3>");
        }
    }
}

// پایگاه داده وضعیت
$dbPath = STATE_DB_PATH;
$dbDir = dirname($dbPath);
if ($dbDir !== '' && $dbDir !== '.' && !is_dir($dbDir)) {
    @mkdir($dbDir, 0770, true);
}
$db = new PDO("sqlite:{$dbPath}");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA busy_timeout = 10000');
$db->exec('PRAGMA journal_mode = WAL');
$db->exec("CREATE TABLE IF NOT EXISTS sync_state (channel TEXT PRIMARY KEY, last_msg_id INTEGER NOT NULL)");
$db->exec("CREATE TABLE IF NOT EXISTS media_fail (msg_id INTEGER PRIMARY KEY, fails INTEGER NOT NULL DEFAULT 0, last_reason TEXT, updated_at TEXT)");
// دفتر تحویل: «پست × پلتفرم × پروفایل» → ok|fail (برای idempotency صف پس‌زمینه)
$db->exec("CREATE TABLE IF NOT EXISTS delivery (
    msg_id INTEGER NOT NULL,
    platform TEXT NOT NULL,
    profile TEXT NOT NULL DEFAULT 'main',
    status TEXT NOT NULL,
    info TEXT DEFAULT '',
    updated_at TEXT,
    PRIMARY KEY (msg_id, platform, profile)
)");

function getLastSeenId(PDO $db, string$channel): int {
    $stmt =$db->prepare("SELECT last_msg_id FROM sync_state WHERE channel = :channel");
    $stmt->execute([':channel' =>$channel]);
    $val =$stmt->fetchColumn();
    return $val !== false ? (int)$val : 0;
}

function setLastSeenId(PDO $db, string $channel, int$msgId): void {
    $stmt =$db->prepare("INSERT INTO sync_state (channel, last_msg_id) VALUES (:channel, :msg_id) ON CONFLICT(channel) DO UPDATE SET last_msg_id = :msg_id");
    $stmt->execute([':channel' => $channel, ':msg_id' =>$msgId]);
}

/** فقط جلو بردن state؛ هرگز مقدار را عقب نمی‌برد (برای sync/resend). */
function advanceLastSeenId(PDO $db, string $channel, int $msgId): void {
    $stmt = $db->prepare("INSERT INTO sync_state (channel, last_msg_id) VALUES (:channel, :msg_id)
                          ON CONFLICT(channel) DO UPDATE SET last_msg_id = MAX(sync_state.last_msg_id, excluded.last_msg_id)");
    $stmt->execute([':channel' => $channel, ':msg_id' => $msgId]);
}

/** نام فایل امن برای مسیر موقت و CURLFile (بدون /، کنترل‌کاراکتر، و طول غیرعادی). */
function safeFileName(?string $name, string $fallback = 'file.bin'): string {
    $name = trim((string)$name);
    if ($name === '') {
        $name = $fallback;
    }
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F<>:"\\\\|?*]+/u', '_', $name) ?: $fallback;
    $name = trim($name, " .\t\n\r\0\x0B");
    if ($name === '' || $name === '.' || $name === '..') {
        $name = $fallback;
    }
    return mb_substr($name, 0, 160);
}

/** تبدیل URL نسبی ایتا/CDN به URL کامل. */
function eitaaAbsoluteUrl(string $url): string {
    $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($url === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }
    return 'https://eitaa.com' . ($url[0] === '/' ? '' : '/') . $url;
}

/** استخراج URL از background-image: url(...) با پشتیبانی از کوتیشن تکی/دوتایی. */
function cssUrlFromStyle(string $style): ?string {
    if (preg_match("~url\(\s*['\"]?([^'\")]+)['\"]?\s*\)~i", $style, $m)) {
        return trim($m[1]);
    }
    return null;
}

/**
 * دانلود رسانهٔ ایتا به فایل موقت.
 *
 * لینک‌های رسانهٔ ایتا امضاشده و زمان‌دار هستند؛ بنابراین شکست دانلود یک
 * «دلیل قابل اقدام» برمی‌گرداند (پارامتر ارجاعی $reason) تا لایهٔ بالاتر
 * بتواند تصمیم درست بگیرد: تعویق پست (برای دریافت لینک تازه در scrape بعدی)
 * یا انتشار بدون رسانه همراه با گزارش صریح.
 */
function downloadMedia(string $url, string$targetFilename, ?string &$reason = null): ?string {
    $reason = null;
    $url = eitaaAbsoluteUrl($url);
    $targetFilename = safeFileName($targetFilename, 'media.bin');
    if ($url === '' or !preg_match('#^https?://#i', $url)) {
        $reason = 'BAD_URL';
        return null;
    }

    $tmpPath = sys_get_temp_dir() . '/sync_' . uniqid('', true) . '_' . $targetFilename;
    $fp = fopen($tmpPath, 'w+');
    if (!$fp) {
        $reason = 'TEMP_NOT_WRITABLE';
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_FOLLOWLOCATION => true, 
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        CURLOPT_HTTPHEADER     => [
            'Referer: https://eitaa.com/' . EITAA_CHANNEL_ID,
            'Accept-Encoding: identity',
        ]
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $size = (int)@filesize($tmpPath);
    curl_close($ch);
    fclose($fp);

    if ($res and in_array($code, [200, 206], true) and $size > 100) {
        return $tmpPath;
    }

    @unlink($tmpPath);
    if ($errno !== 0) {
        $reason = 'CURL_ERROR_' . $errno;
    } elseif ($code === 403 or $code === 401) {
        $reason = 'TOKEN_EXPIRED_HTTP_' . $code;      // لینک امضاشده منقضی شده → scrape تازه لازم است
    } elseif ($code === 404) {
        $reason = 'MEDIA_GONE_HTTP_404';
    } elseif ($code >= 500) {
        $reason = 'UPSTREAM_HTTP_' . $code;
    } elseif ($size <= 100) {
        $reason = 'EMPTY_OR_PLACEHOLDER_BODY';        // مثلاً صفحهٔ «حجم رسانه بالاست / مشاهده در ایتا»
    } else {
        $reason = 'HTTP_' . $code;
    }
    return null;
}

/** تعداد شکست‌های ثبت‌شدهٔ دانلود رسانه برای یک پست */
function getMediaFailCount(PDO $db, int $msgId): int {
    $stmt = $db->prepare('SELECT fails FROM media_fail WHERE msg_id = :id');
    $stmt->execute([':id' => $msgId]);
    $v = $stmt->fetchColumn();
    return $v !== false ? (int)$v : 0;
}

/** ثبت/افزایش شکست دانلود و بازگرداندن شمار جدید */
function bumpMediaFail(PDO $db, int $msgId, string $reason): int {
    $stmt = $db->prepare('INSERT INTO media_fail (msg_id, fails, last_reason, updated_at) VALUES (:id, 1, :r, :t)
                          ON CONFLICT(msg_id) DO UPDATE SET fails = fails + 1, last_reason = :r, updated_at = :t');
    $stmt->execute([':id' => $msgId, ':r' => $reason, ':t' => date('c')]);
    return getMediaFailCount($db, $msgId);
}

/** پاک‌سازی شمارندهٔ شکست پس از انتشار موفق */
function clearMediaFail(PDO $db, int $msgId): void {
    $db->prepare('DELETE FROM media_fail WHERE msg_id = :id')->execute([':id' => $msgId]);
}

// ============================================================
//  دفتر تحویل (delivery ledger)
// ------------------------------------------------------------
//  برای هر «پست × پلتفرم × پروفایل» ثبت می‌شود که ارسال موفق بوده
//  یا نه. صف پس‌زمینه با آن idempotent می‌شود: اگر یک اجرا وسط راه
//  متوقف شود، اجرای بعدی فقط پلتفرم‌های جاافتادهٔ هر پست را می‌فرستد
//  و در پلتفرم‌های موفق پست تکراری نمی‌سازد.
// ============================================================
function markDelivery(PDO $db, int $msgId, string $platform, string $profile, bool $ok, string $info = ''): void {
    if (!in_array($platform, ['bale', 'rubika', 'soroush', 'igap'], true)) {
        return;
    }
    $profile = normalizeDestinationProfile($profile);
    $stmt = $db->prepare('INSERT INTO delivery (msg_id, platform, profile, status, info, updated_at)
                          VALUES (:m, :p, :pr, :s, :i, :t)
                          ON CONFLICT(msg_id, platform, profile) DO UPDATE
                          SET status = excluded.status, info = excluded.info, updated_at = excluded.updated_at');
    $stmt->execute([
        ':m'  => $msgId,
        ':p'  => $platform,
        ':pr' => $profile,
        ':s'  => $ok ? 'ok' : 'fail',
        ':i'  => mb_substr($info, 0, 300),
        ':t'  => date('c'),
    ]);
}

/** پلتفرم‌هایی که این پست در آن‌ها قبلاً با موفقیت تحویل شده است */
function deliveredOkPlatforms(PDO $db, int $msgId, string $profile): array {
    $profile = normalizeDestinationProfile($profile);
    $stmt = $db->prepare("SELECT platform FROM delivery WHERE msg_id = :m AND profile = :pr AND status = 'ok'");
    $stmt->execute([':m' => $msgId, ':pr' => $profile]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return is_array($rows) ? $rows : [];
}

/** نوشتن اتمیک JSON روی دیسک (tmp + rename) تا خوانندهٔ هم‌زمان هرگز نیم‌کاره نخواند */
function writeJsonFileAtomic(string $path, array $data): bool {
    $dir = dirname($path);
    if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    $tmp = $path . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE)) === false) {
        return false;
    }
    return @rename($tmp, $path);
}

/** خواندن JSON از فایل؛ فایل نبود/خراب بود → null */
function readJsonFile(?string $path): ?array {
    if ($path === null || $path === '' || !is_readable($path)) {
        return null;
    }
    $decoded = json_decode((string)@file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}

/** فایل پیشرفت مشترک صف پس‌زمینه (worker می‌نویسد، داشبورد از queue_status می‌خواند) */
function backgroundProgressFile(): string {
    return rtrim(LOG_DIR, '/') . '/background_progress.json';
}

// استخراج آخرین JSON معتبر از خروجی اسکریپت Node
// (هشدارهای Playwright/Chromium که با 2>&1 به stdout می‌آیند، json_decode کل رشته را نامعتبر می‌کنند)
function parseNodeJsonOutput(string $output): ?array {
    $lines = preg_split('/\R/', trim($output));
    if (!is_array($lines)) {
        return null;
    }
    foreach (array_reverse($lines) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] !== '{') {
            continue;
        }
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return null;
}

/**
 * خواندن بدنهٔ درخواست.
 * در حالت وب از php://input می‌خواند؛ در حالت CLI (cron) می‌توان مسیر فایل
 * را با متغیر محیطی SYNC_BODY_FILE داد تا رفتار کاملاً قطعی باشد
 * (وابسته به رفتار php://input روی stdin نباشد).
 */
function readRequestBody(): string {
    $fromFile = getenv('SYNC_BODY_FILE');
    if (is_string($fromFile) and $fromFile !== '' and is_readable($fromFile)) {
        return (string)file_get_contents($fromFile);
    }
    return (string)file_get_contents('php://input');
}

function tailText(string $file, int $lines = 80): string {
    if (!is_readable($file)) {
        return '';
    }
    $rows = @file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($rows)) {
        return '';
    }
    return implode("\n", array_slice($rows, -max(1, $lines)));
}

function isPidRunning(int $pid): bool {
    if ($pid <= 0) {
        return false;
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    return is_dir('/proc/' . $pid);
}

function syncWorkerPidFile(): string {
    return rtrim(LOG_DIR, '/') . '/sync_worker.pid';
}

function currentSyncWorkerPid(): int {
    $pidFile = syncWorkerPidFile();
    if (!is_readable($pidFile)) {
        return 0;
    }
    return max(0, (int)trim((string)file_get_contents($pidFile)));
}

/** خط فرمان یک فرایند زنده (برای تشخیص PID بازیافتی/نامربوط) */
function processCmdline(int $pid): string {
    if ($pid <= 0) {
        return '';
    }
    $raw = @file_get_contents('/proc/' . $pid . '/cmdline');
    if (!is_string($raw) || $raw === '') {
        return '';
    }
    return str_replace("\0", ' ', $raw);
}

/**
 * وضعیت worker صف پس‌زمینه: زنده است یا نه؟
 * PID ذخیره‌شده باید واقعاً متعلق به worker ما باشد (cmdline شامل cli_run.php
 * یا background_launcher)؛ وگرنه PID بازیافتیِ فرایند دیگری است و پاک می‌شود.
 * خروجی: [running(bool), pid(int), stale(bool)]
 */
function syncWorkerStatus(): array {
    $pid = currentSyncWorkerPid();
    if ($pid <= 0) {
        return [false, 0, false];
    }
    if (!isPidRunning($pid)) {
        @unlink(syncWorkerPidFile());
        return [false, $pid, true];
    }
    $cmdline = processCmdline($pid);
    if ($cmdline !== '' && !str_contains($cmdline, 'cli_run.php') && !str_contains($cmdline, 'background_launcher')) {
        @unlink(syncWorkerPidFile());
        return [false, $pid, true];
    }
    return [true, $pid, false];
}

/**
 * یافتن باینری PHP CLI «سالم و تأییدشده» برای worker پس‌زمینه.
 * چرا این‌قدر سخت‌گیرانه؟ ریشهٔ خرابی دکمهٔ صف پس‌زمینه همین بود:
 * `command -v php` در محیط وب گاهی PHP قدیمی/فاقد اکستنشن برمی‌گرداند و
 * worker بی‌صدا در همان ثانیهٔ اول می‌مُرد. اینجا هر کاندید را با -v،
 * اکستنشن‌های لازم و lint خود sync_manual.php آزمایش می‌کنیم.
 * خروجی: [ok(bool), bin(string), attempts(array of string)]
 */
function resolvePhpCliBinary(): array {
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $candidates = [];
    if (PHP_CLI_BIN !== '') {
        $candidates[] = PHP_CLI_BIN;
    }
    // PHP_BINARY همان مفسری است که همین الان داره صفحه را اجرا می‌کند؛
    // فقط خروجی‌های fpm (که CLI نیستند) را کنار می‌گذاریم.
    if (defined('PHP_BINARY') && PHP_BINARY !== '' && !preg_match('#fpm#i', basename(PHP_BINARY))) {
        $candidates[] = PHP_BINARY;
    }
    foreach ([
        '/opt/cpanel/ea-php83/root/usr/bin/php',
        '/opt/cpanel/ea-php82/root/usr/bin/php',
        '/opt/cpanel/ea-php81/root/usr/bin/php',
    ] as $cPanelPhp) {
        $candidates[] = $cPanelPhp;
    }
    foreach (array_merge(
        glob('/opt/cpanel/ea-php8*/root/usr/bin/php') ?: [],
        glob('/usr/local/lsws/lsphp8*/bin/php') ?: [],
        glob('/usr/local/bin/php8*') ?: []
    ) as $g) {
        $candidates[] = $g;
    }
    $candidates[] = '/usr/bin/php';
    $candidates[] = '/usr/local/bin/php';
    if (function_exists('shell_exec') && is_callable('shell_exec')) {
        $which = trim((string)@shell_exec('command -v php 2>/dev/null'));
        if ($which !== '') {
            $candidates[] = $which;
        }
    }
    $candidates = array_values(array_unique(array_filter($candidates, fn($c) => is_string($c) && $c !== '')));

    $attempts = [];
    $canShell = function_exists('shell_exec') && is_callable('shell_exec');

    foreach ($candidates as $bin) {
        if (!is_executable($bin)) {
            $attempts[] = "{$bin}: NOT_EXECUTABLE";
            continue;
        }
        if (!$canShell) {
            // بدون shell_exec اصلاً نمی‌شود worker راه‌انداخت؛ همان اولی را برای پیام خطا برگردان
            $cached = [true, $bin, $attempts];
            return $cached;
        }
        $q = escapeshellarg($bin);
        $ver = trim((string)@shell_exec($q . ' -v 2>&1'));
        if (!preg_match('#^PHP\s+(\d+)\.#', $ver, $m) || (int)$m[1] < 8) {
            $attempts[] = "{$bin}: " . mb_substr(preg_replace('/\s+/u', ' ', $ver) ?: 'NO_OUTPUT', 0, 70);
            continue;
        }
        // اکستنشن‌های لازم برای worker (pdo_sqlite/curl/dom/mbstring)
        $probe = 'exit((int)!(extension_loaded("pdo_sqlite") && extension_loaded("curl") && extension_loaded("dom") && extension_loaded("mbstring")));';
        $probeOut = trim((string)@shell_exec($q . ' -r ' . escapeshellarg($probe) . ' 2>&1; echo "RC=$?"'));
        if (!str_contains($probeOut, 'RC=0')) {
            $attempts[] = "{$bin}: MISSING_EXTENSIONS";
            continue;
        }
        $lint = trim((string)@shell_exec($q . ' -l ' . escapeshellarg(__FILE__) . ' 2>&1'));
        if (!str_contains($lint, 'No syntax errors')) {
            $attempts[] = "{$bin}: LINT_FAIL " . mb_substr(preg_replace('/\s+/u', ' ', $lint) ?: '', 0, 70);
            continue;
        }
        $cached = [true, $bin, $attempts];
        return $cached;
    }

    $cached = [false, '', $attempts];
    return $cached;
}

function callApi(string $url, mixed$data, bool $isMultipart, array$headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    if (!empty($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER,$headers);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);
    $decoded = json_decode((string)$res, true);
    return [
        'code'  => $errno ? 0 : $code,
        'res'   => is_array($decoded) ? $decoded : $res,
        'errno' => $errno,
        'error' => $error,
    ];
}

function sendToBale(string $text, ?string $file, ?string $type, string $fileName, string $chatId): array {
    if (BALE_BOT_TOKEN === '') {
        return ['code' => 0, 'res' => ['ok' => false, 'description' => envMissingMessage(envMissing(['BALE_BOT_TOKEN']))]];
    }
    $cleanToken = preg_replace('/^bot/i', '', trim(BALE_BOT_TOKEN));
    $base = "https://tapi.bale.ai/bot{$cleanToken}/";
    if ($file and file_exists($file)) {$cFile = new CURLFile($file, '',$fileName);
        $caption = mb_substr($text, 0, 1000);
        $method = match($type) { 'video' => 'sendVideo', 'audio' => 'sendAudio', 'document' => 'sendDocument', default => 'sendPhoto' };
        $param  = match($type) { 'video' => 'video', 'audio' => 'audio', 'document' => 'document', default => 'photo' };
        return callApi($base . $method, ['chat_id' => $chatId, 'caption' =>$caption, $param =>$cFile], true);
    }
    return callApi($base . 'sendMessage', json_encode(['chat_id' => $chatId, 'text' =>$text]), false, ['Content-Type: application/json']);
}

function sendToRubika(string $text, ?string $file, ?string $type, string $fileName, string $chatId): array {
    if (RUBIKA_BOT_TOKEN === '') {
        return ['code' => 0, 'res' => ['status' => 'NOT_CONFIGURED', 'error_message' => envMissingMessage(envMissing(['RUBIKA_BOT_TOKEN']))]];
    }
    $base = "https://botapi.rubika.ir/v3/" . RUBIKA_BOT_TOKEN . "/";
    $caption = mb_substr($text, 0, 1000);
    if ($file and file_exists($file)) {$rType = match($type) { 'video' => 'Video', 'audio' => 'Music', 'voice' => 'Voice', 'document' => 'File', default => 'Image' };$req = callApi($base . 'requestSendFile', json_encode(['type' =>$rType]), false, ['Content-Type: application/json']);
        $uploadUrl = is_array($req['res']['data'] ?? null) ? ($req['res']['data']['upload_url'] ?? '') : (string)($req['res']['data'] ?? '');
        if ($uploadUrl) {
            $cFile = new CURLFile($file, mime_content_type($file) ?: 'application/octet-stream',$fileName);
            $upRes = callApi($uploadUrl, ['file' => $cFile], true);$fileId = is_array($upRes['res']['data'] ?? null) ? ($upRes['res']['data']['file_id'] ?? null) : ($upRes['res']['data'] ?? $upRes['res']['file_id'] ?? null);
            if ($fileId) {
                $sendPayload = ['chat_id' => $chatId, 'file_id' => (string)$fileId, 'type' => $rType, 'text' =>$caption, 'file_name' => $fileName];$final = callApi($base . 'sendFile', json_encode($sendPayload), false, ['Content-Type: application/json']);
                if (($final['res']['status'] ?? '') === 'OK') return$final;
            }
        }
        return callApi($base . 'sendMessage', json_encode(['chat_id' => $chatId, 'text' =>$caption . "\n(عدم آپلود فایل روبیکا)"]), false, ['Content-Type: application/json']);
    }
    return callApi($base . 'sendMessage', json_encode(['chat_id' => $chatId, 'text' =>$text]), false, ['Content-Type: application/json']);
}

/**
 * پیش‌بررسی محیط Node پیش از راه‌اندازی subprocess.
 *
 * چرا لازم است؟ اگر `node_modules` پاک شود (مثلاً با `git checkout` به برنچی
 * که آن را ردیابی نمی‌کند)، Node با `MODULE_NOT_FOUND` می‌میرد و کاربر فقط یک
 * stack trace خام می‌بیند. اینجا پیش از اجرا بررسی و پیامِ قابل‌اقدام می‌دهیم.
 *
 * خروجی: رشتهٔ خالی = سالم؛ در غیر این صورت پیام فارسیِ راهنما.
 */
function nodeEnvProblem(string $script): string {
    if (!is_file($script)) {
        return "اسکریپت Node پیدا نشد: $script\n"
             . 'رفع: مطمئن شوید فایل‌های پروژه کامل روی سرور هستند (git status / ls -l).';
    }
    if (!is_file(SYNC_APP_DIR . '/lib/pw_common.js')) {
        return 'فایل lib/pw_common.js پیدا نشد (کتابخانهٔ مشترک اسکریپت‌های Node).\n'
             . 'رفع: پوشهٔ lib/ را همراه بقیهٔ پروژه روی سرور بگذارید.';
    }
    $nodeBin = NODE_BIN;
    if ($nodeBin !== '' && str_contains($nodeBin, '/') && !is_executable($nodeBin) && !file_exists($nodeBin)) {
        return "باینری Node در مسیر پیکربندی‌شده پیدا نشد: $nodeBin\n"
             . 'رفع: `command -v node` را ببینید و NODE_BIN را در .env اصلاح کنید.';
    }
    foreach ([SYNC_APP_DIR . '/node_modules/playwright', __DIR__ . '/node_modules/playwright'] as $dir) {
        if (is_dir($dir)) {
            return '';
        }
    }
    return 'وابستگی Node «playwright» نصب نیست (node_modules/playwright وجود ندارد).\n'
         . 'این معمولاً وقتی رخ می‌دهد که `git checkout` به برنچ جدید، node_modules را\n'
         . 'که در کامیت قدیمی ردیابی می‌شد، از دیسک پاک کرده باشد.\n'
         . 'رفع (بدون نیاز به اینترنت، از همان ریپو):\n'
         . '    cd ' . SYNC_APP_DIR . '\n'
         . '    git restore --source=e5e4708 --worktree -- node_modules\n'
         . '    # یا اگر git restore نبود:  git checkout e5e4708 -- node_modules && git reset -q HEAD node_modules\n'
         . 'رفع (با اینترنت):  cd ' . SYNC_APP_DIR . ' && npm install --no-audit --no-fund\n'
         . "تأیید:  node -e \"require('playwright'); console.log('OK')\"";
}

/**
 * اجرای یک UserBot به‌عنوان subprocess ایمن.
 *
 * نکاتی که این تابع را با نسخهٔ پیشین متفاوت (و درست) می‌کند:
 *  ۱) `timeout N` دور دستور: یک Chromium گیرکرده دیگر worker PHP را بلوکه نمی‌کند.
 *  ۲) `--type` نوع رسانه را از لایهٔ scraping به Node می‌دهد تا تصمیم
 *     «Media یا File» بر اساس نوع واقعی باشد، نه حدس از روی پسوند.
 *  ۳) `--channel-name` نام نمایشی کانال را می‌دهد تا اسکریپت بتواند
 *     «باز شدن چت درست» را تأیید کند (جلوگیری از ارسال به چت اشتباه).
 *  ۴) وضعیت UNVERIFIED (ارسال شد ولی تأیید نشد) به‌صورت شکستِ صادقانه
 *     نگاشت می‌شود، نه OK کاذب.
 */
/**
 * ساخت خط فرمان اجرای یک UserBot (بدون اجرای آن).
 * خروجی: ['ok'=>true, 'cmd'=>string] یا ['ok'=>false, 'code'=>string, 'message'=>string]
 *
 * از این سازنده هم مسیر blocking (runUserbot) و هم مسیر هم‌زمان (صف پس‌زمینه)
 * استفاده می‌کنند تا خط فرمان هر دو دقیقاً یکی بماند.
 */
function buildUserbotCommand(string $script, string $profileDir, string $channel, string $channelName, string $text, ?string $filePath, ?string $mediaType, array $extraArgs = []): array {
    if (!function_exists('shell_exec') || !is_callable('shell_exec')) {
        return [
            'ok'      => false,
            'code'    => 'SHELL_EXEC_DISABLED',
            'message' => 'تابع shell_exec در PHP غیرفعال است؛ مسیر UserBot (سروش/آی‌گپ) اجرا نمی‌شود. در تنظیمات PHP آن را از disable_functions حذف کنید.',
        ];
    }

    // پیش‌بررسی: اگر playwright/Node نباشد، به‌جای stack trace خام، پیام روشن بده
    $envProblem = nodeEnvProblem($script);
    if ($envProblem !== '') {
        return ['ok' => false, 'code' => 'NODE_DEPS_MISSING', 'message' => $envProblem];
    }

    $cleanChannel = ltrim($channel, '@');
    @array_map('unlink', glob($profileDir . '/Singleton*') ?: []);

    $cmd  = 'timeout ' . (int)USERBOT_TIMEOUT_SEC . ' ';
    $cmd .= escapeshellarg(NODE_BIN) . ' ' . escapeshellarg($script);
    $cmd .= ' --channel=' . escapeshellarg($cleanChannel);
    if ($channelName !== '') {
        $cmd .= ' --channel-name=' . escapeshellarg($channelName);
    }
    if ($text !== '') {
        $cmd .= ' --text=' . escapeshellarg($text);
    }
    if ($filePath and file_exists($filePath)) {
        $cmd .= ' --file=' . escapeshellarg($filePath);
        $cmd .= ' --type=' . escapeshellarg((string)($mediaType ?? ''));
    }
    foreach ($extraArgs as $flag => $value) {
        if ($value === null or $value === '') {
            continue;
        }
        $cmd .= ' --' . $flag . '=' . escapeshellarg((string)$value);
    }

    return ['ok' => true, 'cmd' => $cmd];
}

/**
 * نگاشت خروجی خام اسکریپت Node به نتیجهٔ قراردادی PHP.
 * هم مسیر blocking و هم مسیر هم‌زمان از همین استفاده می‌کنند.
 */
function userbotResultFromOutput(string $output, string $stderr, string $profileDir): array {
    @array_map('unlink', glob($profileDir . '/Singleton*') ?: []);

    $combinedOutput = trim($output . "\n" . $stderr);
    $result = parseNodeJsonOutput($output) ?? parseNodeJsonOutput($combinedOutput);
    $status = $result['status'] ?? null;

    // اگر Node به خاطر ماژول گم‌شده مرده باشد، JSON قرارداد تولید نمی‌شود؛
    // پس خودمان تشخیص می‌دهیم و پیامِ قابل‌اقدام می‌دهیم (نه stack trace خام).
    if ($status !== 'OK' && preg_match("/Cannot find (?:module|package) '([^']+)'/u", $combinedOutput, $mm)) {
        return [
            'success' => false,
            'code'    => 'NODE_DEPS_MISSING',
            'message' => 'ماژول Node «' . $mm[1] . '» پیدا نشد.'
                       . ' وابستگی‌ها را نصب/بازیابی کنید:  cd ' . SYNC_APP_DIR
                       . ' && git restore --source=e5e4708 --worktree -- node_modules'
                       . '   (یا: npm install --no-audit --no-fund)',
        ];
    }
    if ($status !== 'OK' && str_contains($combinedOutput, 'MODULE_NOT_FOUND')) {
        return [
            'success' => false,
            'code'    => 'NODE_DEPS_MISSING',
            'message' => 'وابستگی‌های Node ناقص‌اند (MODULE_NOT_FOUND).'
                       . ' رفع:  cd ' . SYNC_APP_DIR . ' && git restore --source=e5e4708 --worktree -- node_modules'
                       . '   (یا: npm install --no-audit --no-fund)',
        ];
    }

    if ($status === 'OK') {
        return [
            'success'  => true,
            'message'  => $result['message'] ?? 'OK',
            'verified' => (bool)($result['verified'] ?? true),
            'proof'    => $result['proof'] ?? '',
        ];
    }

    $detail = $result['error'] ?? $result['message'] ?? '';
    $code   = $result['code'] ?? '';
    $logRef = isset($result['log']) ? ' [log: ' . basename((string)$result['log']) . ']' : '';
    $raw    = trim($combinedOutput);

    return [
        'success' => false,
        'code'    => $code !== '' ? $code : 'RUNTIME',
        'message' => ($code !== '' ? '[' . $code . '] ' : '')
                   . ($detail !== '' ? $detail : ($raw !== '' ? mb_substr($raw, -300) : 'Fail'))
                   . $logRef,
    ];
}

function runUserbot(string $script, string $profileDir, string $channel, string $channelName, string $text, ?string $filePath, ?string $mediaType, array $extraArgs = []): array {
    if (trim($text) === '' and (!$filePath or !file_exists($filePath))) {
        return ['success' => true, 'message' => 'SKIP: محتوایی برای ارسال نیست', 'skipped' => true];
    }

    $built = buildUserbotCommand($script, $profileDir, $channel, $channelName, $text, $filePath, $mediaType, $extraArgs);
    if (!$built['ok']) {
        return ['success' => false, 'code' => $built['code'], 'message' => $built['message']];
    }

    // stdout قرارداد JSON اسکریپت Node است. stderr را جدا نگه می‌داریم تا
    // لاگ‌های مرحله‌ای باعث خراب شدن json_decode در مسیرهای PHP/OPcache قدیمی نشوند.
    $errFile = tempnam(sys_get_temp_dir(), 'userbot_stderr_');
    $stderrRedir = $errFile ? (' 2>' . escapeshellarg($errFile)) : ' 2>&1';
    $output = shell_exec($built['cmd'] . $stderrRedir);
    $stderr = ($errFile && is_readable($errFile)) ? (string)file_get_contents($errFile) : '';
    if ($errFile) {
        @unlink($errFile);
    }

    return userbotResultFromOutput((string)$output, $stderr, $profileDir);
}

/**
 * اجرای هم‌زمان (غیرمسدودکننده) یک UserBot: فرایند با nohup در پس‌زمینه
 * شروع می‌شود و خروجی‌اش در فایل‌های موقت می‌نشیند. خروجی:
 *   ['pid'=>int, 'out'=>path, 'err'=>path, 'rc'=>path, 'started'=>int]
 * یا null در صورت شکست راه‌اندازی.
 *
 * چرا لازم است؟ سروش و آی‌گپ پروفایل‌های مرورگر مستقل دارند؛ اجرای هم‌زمانشان
 * زمان هر پست را از «مجموعِ دو مرورگر» به «کندترینِ دو مرورگر» می‌رساند.
 */
function launchUserbotAsync(string $cmd, string $profileDir): ?array {
    @array_map('unlink', glob($profileDir . '/Singleton*') ?: []);

    $out = tempnam(sys_get_temp_dir(), 'ub_out_');
    $err = tempnam(sys_get_temp_dir(), 'ub_err_');
    $rc  = tempnam(sys_get_temp_dir(), 'ub_rc_');
    if (!$out || !$err || !$rc) {
        foreach ([$out, $err, $rc] as $f) { if ($f) { @unlink($f); } }
        return null;
    }
    @unlink($rc);   // rc فقط پس از پایان فرایند ساخته می‌شود

    $inner = $cmd . ' > ' . escapeshellarg($out) . ' 2> ' . escapeshellarg($err) . '; echo $? > ' . escapeshellarg($rc);
    $wrap  = 'nohup bash -c ' . escapeshellarg($inner) . ' >/dev/null 2>&1 </dev/null & echo $!';
    $raw = (string)@shell_exec($wrap);
    $pid = preg_match('/\b(\d+)\b/', $raw, $m) ? (int)$m[1] : 0;
    if ($pid <= 0) {
        foreach ([$out, $err] as $f) { @unlink($f); }
        return null;
    }
    return ['pid' => $pid, 'out' => $out, 'err' => $err, 'rc' => $rc, 'started' => time()];
}

/** آیا فرایندِ هم‌زمان‌شده پایان یافته است؟ (rc ساخته شده یا PID مرده) */
function userbotAsyncDone(array $handle): bool {
    if (is_readable($handle['rc'] ?? '')) {
        return true;
    }
    return !isPidRunning((int)($handle['pid'] ?? 0));
}

/** انتظار برای پایان فرایند هم‌زمان (با سقف زمانی) */
function waitForUserbotAsync(array $handle, int $timeoutSec): bool {
    $deadline = time() + max(5, $timeoutSec);
    while (time() < $deadline) {
        if (userbotAsyncDone($handle)) {
            sleep(1);   // فایل‌های خروجی کامل شوند
            return true;
        }
        sleep(1);
    }
    return userbotAsyncDone($handle);
}

/** جمع‌کردن نتیجهٔ فرایند هم‌زمان و پاک‌سازی فایل‌های موقت آن */
function collectUserbotAsync(array $handle, string $profileDir): array {
    $output = is_readable($handle['out'] ?? '') ? (string)file_get_contents($handle['out']) : '';
    $stderr = is_readable($handle['err'] ?? '') ? (string)file_get_contents($handle['err']) : '';
    foreach (['out', 'err', 'rc'] as $k) {
        if (!empty($handle[$k])) { @unlink($handle[$k]); }
    }
    return userbotResultFromOutput($output, $stderr, $profileDir);
}

/**
 * کشتن یک گروه فرایند (برای متوقف کردن مرورگر runner صف پس‌زمینه در حالت گیرکردن).
 * pid باید session leader باشد تا -PID کل درخت فرایند (bash+node+chromium) را بگیرد.
 */
function killProcessGroup(int $pgid): void {
    if ($pgid <= 0) {
        return;
    }
    @shell_exec('kill -TERM ' . escapeshellarg('-' . $pgid) . ' 2>/dev/null');
    usleep(300000);
    @shell_exec('kill -KILL ' . escapeshellarg('-' . $pgid) . ' 2>/dev/null');
}

/**
 * راه‌اندازی runner یک پلتفرم UserBot برای «کل صف» با حالت batch اسکریپت‌های Node:
 * مرورگر یک بار باز می‌شود، کانال یک بار، و همهٔ آیتم‌ها پشت‌سرهم ارسال می‌شوند.
 * خروجی (handle): ['pid', 'pgid', 'batchFile', 'progressFile', 'out', 'err', 'rc', 'timeout', 'deadline']
 * یا ['ok'=>false, 'code', 'message'] در صورت عدم امکان راه‌اندازی.
 *
 * نکته: متن/فایل آیتم‌ها داخل batchFile می‌رود (نه خط فرمان) تا محدودیت طول
 * argv برای صف‌های بزرگ و متن‌های بلند مسئله‌ساز نشود.
 */
function launchUserbotBatch(string $platform, array $items, array $dest): array {
    $script     = $platform === 'soroush' ? SOROUSH_SCRIPT : IGAP_SCRIPT;
    $profileDir = $platform === 'soroush' ? SOROUSH_PROFILE_DIR : IGAP_PROFILE_DIR;
    $channel    = $platform === 'soroush' ? $dest['soroushChannel'] : $dest['igapChannel'];
    $channelName= $platform === 'soroush' ? $dest['soroushName'] : $dest['igapName'];
    $extra      = $platform === 'soroush' ? ['strict-channel' => '1'] : ['item-id' => (string)$dest['igapItemId']];

    $envProblem = nodeEnvProblem($script);
    if ($envProblem !== '') {
        return ['ok' => false, 'code' => 'NODE_DEPS_MISSING', 'message' => $envProblem];
    }
    if (!function_exists('shell_exec') || !is_callable('shell_exec')) {
        return ['ok' => false, 'code' => 'SHELL_EXEC_DISABLED', 'message' => 'shell_exec غیرفعال است.'];
    }

    $batchFile     = tempnam(sys_get_temp_dir(), 'ub_batch_');
    $progressFile  = $batchFile . '.progress.json';
    if (!writeJsonFileAtomic($batchFile, ['items' => array_values($items), 'progressFile' => $progressFile])) {
        return ['ok' => false, 'code' => 'BATCH_WRITE_FAILED', 'message' => "نوشتن فایل batch ناموفق بود: {$batchFile}"];
    }

    $timeout = 180 + (int)USERBOT_TIMEOUT_SEC * max(1, count($items));

    @array_map('unlink', glob($profileDir . '/Singleton*') ?: []);
    $cmd  = 'timeout ' . $timeout . ' ' . escapeshellarg(NODE_BIN) . ' ' . escapeshellarg($script);
    $cmd .= ' --batch=' . escapeshellarg($batchFile);
    $cmd .= ' --channel=' . escapeshellarg(ltrim((string)$channel, '@'));
    if ((string)$channelName !== '') {
        $cmd .= ' --channel-name=' . escapeshellarg((string)$channelName);
    }
    foreach ($extra as $flag => $value) {
        if ($value === null or $value === '') {
            continue;
        }
        $cmd .= ' --' . $flag . '=' . escapeshellarg((string)$value);
    }

    $out = tempnam(sys_get_temp_dir(), 'ub_out_');
    $err = tempnam(sys_get_temp_dir(), 'ub_err_');
    $rc  = tempnam(sys_get_temp_dir(), 'ub_rc_');
    if (!$out || !$err || !$rc) {
        return ['ok' => false, 'code' => 'TEMP_NOT_WRITABLE', 'message' => 'ساخت فایل‌های موقت ناموفق بود.'];
    }
    @unlink($rc);

    // setsid: runner و مرورگرش گروه فرایند خودش را دارند تا در حالت گیرکردن،
    // worker بتواند کل درخت (bash+node+chromium) را یک‌جا متوقف کند.
    $inner = $cmd . ' > ' . escapeshellarg($out) . ' 2> ' . escapeshellarg($err) . '; echo $? > ' . escapeshellarg($rc);
    $wrap  = 'setsid nohup bash -c ' . escapeshellarg($inner) . ' >/dev/null 2>&1 </dev/null & echo $!';
    $raw = (string)@shell_exec($wrap);
    $pid = preg_match('/\b(\d+)\b/', $raw, $m) ? (int)$m[1] : 0;
    if ($pid <= 0) {
        foreach ([$out, $err, $batchFile] as $f) { @unlink($f); }
        return ['ok' => false, 'code' => 'LAUNCH_FAILED', 'message' => 'شروع فرایند runner ناموفق بود: ' . trim($raw)];
    }

    return [
        'ok'           => true,
        'platform'     => $platform,
        'pid'          => $pid,
        'pgid'         => $pid,   // به‌خاطر setsid، pid همان رهبر گروه فرایند است
        'batchFile'    => $batchFile,
        'progressFile' => $progressFile,
        'out'          => $out,
        'err'          => $err,
        'rc'           => $rc,
        'timeout'      => $timeout,
        'deadline'     => time() + $timeout + 60,
    ];
}

/** پاک‌سازی فایل‌های موقت یک runner پس از پایان آن */
function cleanupUserbotBatch(array $handle): void {
    foreach (['batchFile', 'progressFile', 'out', 'err', 'rc'] as $k) {
        if (!empty($handle[$k])) { @unlink($handle[$k]); }
    }
}

/**
 * دانلود هم‌زمان رسانهٔ چند پست با curl_multi.
 * ورودی: [['id'=>.., 'mediaUrl'=>.., 'fileName'=>..], ...]
 * خروجی: [id => ['ok'=>bool, 'path'=>?string, 'reason'=>?string]]
 * همان سیاست‌های downloadMedia: Referer ایتا، مهلت ۱۸۰ ثانیه، بدنهٔ >۱۰۰ بایت.
 */
function downloadMediaBatch(array $posts): array {
    $results = [];
    $entries = [];
    $mh = curl_multi_init();
    if ($mh === false) {
        foreach ($posts as $p) {
            $results[(int)$p['id']] = ['ok' => false, 'path' => null, 'reason' => 'CURL_MULTI_INIT_FAILED'];
        }
        return $results;
    }

    foreach ($posts as $p) {
        $id  = (int)$p['id'];
        $url = eitaaAbsoluteUrl((string)($p['mediaUrl'] ?? ''));
        $results[$id] = ['ok' => false, 'path' => null, 'reason' => 'NO_URL'];
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            continue;
        }
        $target = safeFileName((string)($p['fileName'] ?? 'media.bin'), 'media.bin');
        $tmpPath = sys_get_temp_dir() . '/sync_bg_' . uniqid('', true) . '_' . $target;
        $fp = fopen($tmpPath, 'w+');
        if (!$fp) {
            $results[$id]['reason'] = 'TEMP_NOT_WRITABLE';
            continue;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fp);
            @unlink($tmpPath);
            $results[$id]['reason'] = 'CURL_INIT_FAILED';
            continue;
        }
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            CURLOPT_HTTPHEADER     => [
                'Referer: https://eitaa.com/' . EITAA_CHANNEL_ID,
                'Accept-Encoding: identity',
            ],
        ]);
        curl_multi_add_handle($mh, $ch);
        $entries[(int)$ch] = ['id' => $id, 'fp' => $fp, 'path' => $tmpPath, 'ch' => $ch];
        $results[$id]['reason'] = 'IN_PROGRESS';
    }

    $finalize = function (array $entry, int $code, int $errno) use (&$results) {
        $size = (int)@filesize($entry['path']);
        @fclose($entry['fp']);
        if ($code > 0 && in_array($code, [200, 206], true) && $errno === 0 && $size > 100) {
            $results[$entry['id']] = ['ok' => true, 'path' => $entry['path'], 'reason' => null];
            return;
        }
        @unlink($entry['path']);
        if ($errno !== 0) {
            $reason = 'CURL_ERROR_' . $errno;
        } elseif ($code === 403 || $code === 401) {
            $reason = 'TOKEN_EXPIRED_HTTP_' . $code;
        } elseif ($code === 404) {
            $reason = 'MEDIA_GONE_HTTP_404';
        } elseif ($code >= 500) {
            $reason = 'UPSTREAM_HTTP_' . $code;
        } elseif ($size <= 100) {
            $reason = 'EMPTY_OR_PLACEHOLDER_BODY';
        } else {
            $reason = 'HTTP_' . $code;
        }
        $results[$entry['id']] = ['ok' => false, 'path' => null, 'reason' => $reason];
    };

    $active = 0;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($status !== CURLM_OK) {
            break;
        }
        while (($info = curl_multi_info_read($mh)) !== false) {
            $ch = $info['handle'];
            $key = (int)$ch;
            if (!isset($entries[$key])) {
                continue;
            }
            $entry = $entries[$key];
            unset($entries[$key]);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = (int)($info['result'] ?? 0) !== 0 ? (int)$info['result'] : (int)curl_errno($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            $finalize($entry, $code, $errno);
        }
        if ($active > 0) {
            curl_multi_select($mh, 0.25);
        }
    } while ($active > 0);

    // تخلیهٔ نهایی و هر handle جامانده (مثلاً در صورت شکست curl_multi_exec)
    while (($info = curl_multi_info_read($mh)) !== false) {
        $ch = $info['handle'];
        $key = (int)$ch;
        if (isset($entries[$key])) {
            $entry = $entries[$key];
            unset($entries[$key]);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = (int)($info['result'] ?? 0) !== 0 ? (int)$info['result'] : (int)curl_errno($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            $finalize($entry, $code, $errno);
        }
    }
    foreach ($entries as $key => $entry) {
        curl_multi_remove_handle($mh, $entry['ch']);
        curl_close($entry['ch']);
        @fclose($entry['fp']);
        @unlink($entry['path']);
        $results[$entry['id']] = ['ok' => false, 'path' => null, 'reason' => 'CURLM_ABORTED'];
    }
    curl_multi_close($mh);

    return $results;
}

/**
 * ارسال یک پست به «پلتفرم‌های انتخاب‌شده».
 *
 * $only خالی یا نامعتبر = هر چهار پلتفرم (رفتار پیشین، بدون تغییر).
 * کلیدهای مجاز: bale, rubika, soroush, igap
 *
 * چرا لازم است؟ اگر فقط یک مقصد شکست بخورد (مثلاً سروش به خاطر نبودِ
 * node_modules)، پست در state «دیده‌شده» ثبت می‌شود و دیگر به صف برنمی‌گردد؛
 * با این تابع و action=resend می‌توان فقط همان مقصدها را دوباره فرستاد،
 * بدون اینکه در بله/روبیکا پست تکراری برود.
 *
 * شکل خروجی دقیقاً همان چیزی است که داشبورد انتظار دارد:
 *   ['bale' => ['ok' => bool|null, 'info' => mixed], ...]
 * پلتفرم‌های ردشده: ['ok' => null, 'info' => 'SKIPPED']
 */
function normalizeDestinationProfile(string $profile): string {
    $profile = strtolower(trim($profile));
    return in_array($profile, ['main', 'test'], true) ? $profile : 'main';
}

function destinationProfile(string $profile): array {
    $profile = normalizeDestinationProfile($profile);
    if ($profile === 'test') {
        return [
            'profile'       => 'test',
            'label'         => 'تست',
            'baleChannel'   => TEST_BALE_CHANNEL_ID,
            'rubikaChannel' => TEST_RUBIKA_CHANNEL_ID,
            'soroushChannel'=> TEST_SOROUSH_CHANNEL_ID,
            'soroushName'   => TEST_SOROUSH_CHANNEL_NAME,
            'igapChannel'   => TEST_IGAP_CHANNEL_ID,
            'igapName'      => TEST_IGAP_CHANNEL_NAME,
            'igapItemId'    => TEST_IGAP_ITEM_ID,
        ];
    }
    return [
        'profile'       => 'main',
        'label'         => 'اصلی',
        'baleChannel'   => MAIN_BALE_CHANNEL_ID,
        'rubikaChannel' => MAIN_RUBIKA_CHANNEL_ID,
        'soroushChannel'=> MAIN_SOROUSH_CHANNEL_ID,
        'soroushName'   => MAIN_SOROUSH_CHANNEL_NAME,
        'igapChannel'   => MAIN_IGAP_CHANNEL_ID,
        'igapName'      => MAIN_IGAP_CHANNEL_NAME,
        'igapItemId'    => MAIN_IGAP_ITEM_ID,
    ];
}

function normalizePlatformList(array $only): array {
    $all = ['bale', 'rubika', 'soroush', 'igap'];
    $only = array_values(array_unique(array_filter(
        array_map(fn($x) => strtolower(trim((string)$x)), $only),
        fn($x) => in_array($x, $all, true)
    )));
    return $only === [] ? $all : $only;
}

function deliveredPlatformCount(array $sent, array $only): int {
    $count = 0;
    foreach (normalizePlatformList($only) as $platform) {
        $info = (string)($sent[$platform]['info'] ?? '');
        if (($sent[$platform]['ok'] ?? null) === true && !preg_match('/\bSKIP(?:PED)?\b/i', $info)) {
            $count++;
        }
    }
    return $count;
}

function dispatchToPlatforms(array $only, string $text, ?string $localFile, ?string $mediaType, string $fileName, string $profile = 'main', ?PDO $db = null, ?int $msgId = null): array {
    $all = ['bale', 'rubika', 'soroush', 'igap'];
    $only = normalizePlatformList($only);
    $dest = destinationProfile($profile);
    $fileName = safeFileName($fileName, 'file.bin');

    $out = [];
    foreach ($all as $platform) {
        if (!in_array($platform, $only, true)) {
            $out[$platform] = ['ok' => null, 'info' => 'SKIPPED'];
        }
    }

    // ---------- اجرای هم‌زمان سروش + آی‌گپ (پروفایل‌های مستقل) ----------
    // بله/روبیکا HTTP سریع‌اند و در همین فاصله ارسال می‌شوند؛ نتیجه:
    // زمان هر پست = max(سروش, آی‌گپ) به‌جای مجموعِ هر چهار پلتفرم.
    // با SYNC_PARALLEL_DISPATCH=0 در .env می‌توان به رفتار ترتیبی قبلی برگشت.
    $handles = [];
    $hasContent = (trim($text) !== '') || ($localFile !== null && file_exists($localFile));
    if (SYNC_PARALLEL_DISPATCH && $hasContent && in_array('soroush', $only, true) && in_array('igap', $only, true)) {
        $builtSoroush = buildUserbotCommand(
            SOROUSH_SCRIPT, SOROUSH_PROFILE_DIR, $dest['soroushChannel'], $dest['soroushName'],
            $text, $localFile, $mediaType, ['strict-channel' => '1']
        );
        if ($builtSoroush['ok']) {
            $h = launchUserbotAsync($builtSoroush['cmd'], SOROUSH_PROFILE_DIR);
            if ($h) { $handles['soroush'] = $h; }
        }
        $builtIgap = buildUserbotCommand(
            IGAP_SCRIPT, IGAP_PROFILE_DIR, $dest['igapChannel'], $dest['igapName'],
            $text, $localFile, $mediaType, ['item-id' => $dest['igapItemId']]
        );
        if ($builtIgap['ok']) {
            $h = launchUserbotAsync($builtIgap['cmd'], IGAP_PROFILE_DIR);
            if ($h) { $handles['igap'] = $h; }
        }
        // اگر راه‌اندازی هم‌زمان ممکن نشد، همان پلتفرم در ادامه ترتیبی اجرا می‌شود
        if (isset($builtSoroush['ok']) && !$builtSoroush['ok'] && !isset($handles['soroush'])) {
            $out['soroush'] = ['ok' => false, 'info' => $builtSoroush['message'], 'code' => $builtSoroush['code']];
        }
        if (isset($builtIgap['ok']) && !$builtIgap['ok'] && !isset($handles['igap'])) {
            $out['igap'] = ['ok' => false, 'info' => $builtIgap['message'], 'code' => $builtIgap['code']];
        }
    }

    if (in_array('bale', $only, true)) {
        $r = sendToBale($text, $localFile, $mediaType, $fileName, $dest['baleChannel']);
        $out['bale'] = [
            'ok'   => ($r['code'] === 200 and (bool)($r['res']['ok'] ?? false)),
            'info' => $r['code'] ?? 'ERR',
        ];
    }
    if (in_array('rubika', $only, true)) {
        $r = sendToRubika($text, $localFile, $mediaType, $fileName, $dest['rubikaChannel']);
        $out['rubika'] = [
            'ok'   => ($r['code'] === 200 and (($r['res']['status'] ?? '') === 'OK')),
            'info' => $r['code'] ?? 'ERR',
        ];
    }

    if (isset($handles['soroush'])) {
        $timeout = (int)USERBOT_TIMEOUT_SEC + 90;
        waitForUserbotAsync($handles['soroush'], $timeout);
        $r = collectUserbotAsync($handles['soroush'], SOROUSH_PROFILE_DIR);
        $out['soroush'] = [
            'ok'   => ($r['success'] === true),
            'info' => $r['message'] ?? 'ERR',
            'code' => $r['code'] ?? '',
        ];
    } elseif (in_array('soroush', $only, true) && !array_key_exists('soroush', $out)) {
        $r = sendToSoroush($dest['soroushChannel'], $text, $localFile, $mediaType, $dest['soroushName']);
        $out['soroush'] = [
            'ok'   => ($r['success'] === true),
            'info' => $r['message'] ?? 'ERR',
            'code' => $r['code'] ?? '',
        ];
    }

    if (isset($handles['igap'])) {
        $timeout = (int)USERBOT_TIMEOUT_SEC + 90;
        waitForUserbotAsync($handles['igap'], $timeout);
        $r = collectUserbotAsync($handles['igap'], IGAP_PROFILE_DIR);
        $out['igap'] = [
            'ok'   => userbotAccepted($r),
            'info' => $r['message'] ?? 'ERR',
            'code' => $r['code'] ?? '',
        ];
    } elseif (in_array('igap', $only, true) && !array_key_exists('igap', $out)) {
        $r = sendToIgap($dest['igapChannel'], $text, $localFile, $mediaType, $dest['igapName'], $dest['igapItemId']);
        $out['igap'] = [
            'ok'   => userbotAccepted($r),
            'info' => $r['message'] ?? 'ERR',
            'code' => $r['code'] ?? '',
        ];
    }

    // ---------- ثبت در دفتر تحویل (idempotency صف پس‌زمینه) ----------
    if ($db !== null && $msgId !== null && $msgId > 0) {
        foreach ($all as $platform) {
            if (($out[$platform]['ok'] ?? null) !== null) {
                markDelivery($db, $msgId, $platform, $profile, $out[$platform]['ok'] === true, (string)($out[$platform]['info'] ?? ''));
            }
        }
    }

    return $out;
}


function sendToSoroush(string $channel, string $text = '', ?string $filePath = null, ?string $mediaType = null, ?string $channelName = null): array {
    return runUserbot(SOROUSH_SCRIPT, SOROUSH_PROFILE_DIR, $channel, $channelName ?? SOROUSH_CHANNEL_NAME, $text, $filePath, $mediaType, ['strict-channel' => '1']);
}

function userbotAccepted(array $result): bool {
    if (($result['success'] ?? null) === true) {
        return true;
    }
    // دفاع در برابر خروجی‌های قدیمی/ترکیبی shell_exec: اگر Node واقعاً OK چاپ کرده
    // ولی لایهٔ PHP آن را بد parse کرده باشد، پنل نباید قرمز کاذب نشان دهد.
    $text = (string)($result['message'] ?? '') . "
" . (string)($result['raw'] ?? '');
    return (bool)preg_match('/"status"\s*:\s*"OK"|RUN END status=OK|Sent to iGap|caption-neighbor-modal-closed-accepted|snippet-in-chat|left-preview-changed/i', $text);
}

function sendToIgap(string $channel, string $text = '', ?string $filePath = null, ?string $mediaType = null, ?string $channelName = null, ?string $itemId = null): array {
    return runUserbot(IGAP_SCRIPT, IGAP_PROFILE_DIR, $channel, $channelName ?? IGAP_CHANNEL_NAME, $text, $filePath, $mediaType, ['item-id' => $itemId ?? IGAP_ITEM_ID]);
}

$action =$_GET['action'] ?? '';

/**
 * scrape نمای وب کانال ایتا و برگرداندن همهٔ پست‌های دیده‌شده.
 *
 * نکتهٔ مهم: لینک رسانه‌ها در ایتا «امضاشده و زمان‌دار» هستند، پس هر بار
 * باید پیش از دانلود، scrape تازه انجام شود (نه استفاده از لینک قدیمی).
 * خروجی: ['ok' => bool, 'error' => string, 'messages' => array] مرتب‌شده بر اساس id
 */
function fetchEitaaPosts(): array {
    $ch = curl_init("https://eitaa.com/" . EITAA_CHANNEL_ID);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        CURLOPT_HTTPHEADER     => ['Accept-Language: fa,en;q=0.9'],
    ]);
    $html = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code !== 200 || empty($html)) {
        return ['ok' => false, 'error' => 'عدم دسترسی به ایتا' . ($code ? " (HTTP $code)" : '') . ($err ? ": $err" : ''), 'messages' => []];
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    $nodes =$xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' etme_widget_message ')]");

    $allMessages = [];
    foreach ($nodes as$node) {
        $dataPost =$node->getAttribute('data-post');
        if (!$dataPost or !str_contains($dataPost, '/')) continue;
        $msgId = (int)substr(strrchr($dataPost, '/'), 1);

        $textNode =$xpath->query(".//div[contains(@class, 'etme_widget_message_text')]", $node)->item(0);$text = $textNode ? trim($textNode->textContent) : '';

        $mediaUrl = null; $mediaType = null; $fileName = null;

        $videoNode = $xpath->query(".//video", $node)->item(0);
        if ($videoNode and ($src =$videoNode->getAttribute('src'))) {
            $mediaUrl = eitaaAbsoluteUrl($src);
            $mediaType = 'video';$fileName = 'video.mp4';
        } elseif ($audioNode = $xpath->query(".//audio", $node)->item(0)) {
            if ($src =$audioNode->getAttribute('src')) {
                $mediaUrl = eitaaAbsoluteUrl($src);
                $mediaType = 'audio';$fileName = 'audio.mp3';
            }
        } elseif ($photoNode = $xpath->query(".//a[contains(@class, 'etme_widget_message_photo_wrap')]", $node)->item(0)) {
            $cssUrl = cssUrlFromStyle($photoNode->getAttribute('style'));
            if ($cssUrl !== null) {
                $mediaUrl = eitaaAbsoluteUrl($cssUrl);
                $mediaType = 'image';$fileName = 'photo.jpg';
            }
        } elseif ($docNode = $xpath->query(".//a[contains(@class, 'etme_widget_message_document_wrap')]", $node)->item(0)) {
            if ($href =$docNode->getAttribute('href')) {
                $mediaUrl = eitaaAbsoluteUrl($href);
                $mediaType = 'document';
                $tNode =$xpath->query(".//div[contains(@class, 'etme_widget_message_document_title')]", $docNode)->item(0);$fileName = safeFileName($tNode ? trim($tNode->textContent) : 'document.bin', 'document.bin');
            }
        }

        if ($text !== '' or $mediaUrl !== null) {$allMessages[] = [
                'id'        => $msgId,
                'text'      => $text,
                'mediaUrl'  => $mediaUrl,
                'mediaType' => $mediaType,
                'fileName'  => $fileName
            ];
        }
    }

    usort($allMessages, fn($a, $b) =>$a['id'] <=> $b['id']);
    return ['ok' => true, 'error' => '', 'messages' => $allMessages];
}

// ۱. لیست پیام‌های جدید ایتا
if ($action === 'get_pending') {
    header('Content-Type: application/json; charset=utf-8');
    $lastSeenId = getLastSeenId($db, EITAA_CHANNEL_ID);

    $scrape = fetchEitaaPosts();
    if (!$scrape['ok']) {
        echo json_encode(['success' => false, 'error' => $scrape['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $newMessages = array_values(array_filter($scrape['messages'], fn($m) => $m['id'] > $lastSeenId));
    if (count($newMessages) > MAX_MESSAGES_LIMIT) {
        $newMessages = array_slice($newMessages, -MAX_MESSAGES_LIMIT);
    }

    echo json_encode([
        'success'    => true,
        'lastSeenId' => $lastSeenId,
        'count'      => count($newMessages),
        'messages'   => $newMessages
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ۱-ب) فهرست N پست آخر کانال (بدون فیلتر «دیده‌شده») — برای پنل ارسال اجباری
//      ?action=list_posts&key=…&limit=10
if ($action === 'list_posts') {
    header('Content-Type: application/json; charset=utf-8');

    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    if ($limit < 1)  { $limit = 1; }
    if ($limit > 50) { $limit = 50; }

    $lastSeenId = getLastSeenId($db, EITAA_CHANNEL_ID);
    $scrape = fetchEitaaPosts();
    if (!$scrape['ok']) {
        echo json_encode(['success' => false, 'error' => 'SCRAPE_FAILED', 'message' => $scrape['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tail = array_slice($scrape['messages'], -$limit);
    $posts = array_map(function (array $m) use ($lastSeenId) {
        $text = (string)($m['text'] ?? '');
        return [
            'id'         => (int)$m['id'],
            'text'       => $text,
            'preview'    => mb_substr((string)preg_replace('/\s+/u', ' ', $text), 0, 90),
            'mediaUrl'   => $m['mediaUrl'] ?? null,
            'mediaType'  => $m['mediaType'] ?? null,
            'fileName'   => $m['fileName'] ?? null,
            'hasMedia'   => !empty($m['mediaUrl']),
            'sentBefore' => ((int)$m['id'] <= $lastSeenId),
        ];
    }, $tail);

    echo json_encode([
        'success'    => true,
        'lastSeenId' => $lastSeenId,
        'limit'      => $limit,
        'count'      => count($posts),
        'posts'      => $posts,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ۱-ج) ارسال ۵ پست آخر (یا N پست آخر) به پروفایل مقصد؛ مسیر اصلی دکمه‌های داشبورد و صف پس‌زمینه
if ($action === 'sync_recent') {
    set_time_limit(1800);
    header('Content-Type: application/json; charset=utf-8');

    $payload = json_decode(readRequestBody(), true);
    if (!is_array($payload)) {
        $payload = [];
    }
    $limit = (int)($payload['limit'] ?? 5);
    if ($limit < 1)  { $limit = 1; }
    if ($limit > 50) { $limit = 50; }
    $profile = normalizeDestinationProfile((string)($payload['profile'] ?? 'main'));
    $only = is_array($payload['only'] ?? null) ? (array)$payload['only'] : ['bale', 'rubika', 'soroush', 'igap'];
    $only = normalizePlatformList($only);
    $advance = array_key_exists('advance', $payload) ? !empty($payload['advance']) : true;
    $noMedia = !empty($payload['no_media']);
    $gap = isset($payload['gap']) ? max(0, min(120, (int)$payload['gap'])) : max(0, (int)SYNC_GAP_SEC);

    $scrape = fetchEitaaPosts();
    if (!$scrape['ok']) {
        echo json_encode(['success' => false, 'error' => 'SCRAPE_FAILED', 'message' => $scrape['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $selected = array_slice($scrape['messages'], -$limit);
    if ($selected === []) {
        echo json_encode(['success' => false, 'error' => 'NO_POSTS', 'message' => 'پستی در نمای وب ایتا پیدا نشد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $results = [];
    $maxOkId = 0;
    $stopped = false;
    foreach ($selected as $index => $m) {
        if ($index > 0 && $gap > 0) {
            sleep($gap);
        }

        $msgId = (int)$m['id'];
        $text = (string)($m['text'] ?? '');
        $mediaUrl = $m['mediaUrl'] ?? null;
        $mediaType = $m['mediaType'] ?? null;
        $fileName = safeFileName((string)($m['fileName'] ?? 'file.bin'), 'file.bin');
        $localFile = null;
        $mediaReason = null;
        $mediaInfo = 'none';

        if ($mediaUrl && $noMedia) {
            $mediaInfo = 'skipped';
        } elseif ($mediaUrl) {
            $localFile = downloadMedia((string)$mediaUrl, $fileName, $mediaReason);
            if (!$localFile) {
                sleep(1);
                $localFile = downloadMedia((string)$mediaUrl, $fileName, $mediaReason);
            }
            $mediaInfo = $localFile ? 'downloaded' : ('FAILED:' . (string)$mediaReason);
        }

        $hasDeliverable = (trim($text) !== '') || ($localFile && file_exists($localFile));
        if ($hasDeliverable) {
            $sent = dispatchToPlatforms($only, $text, $localFile, $mediaType, $fileName, $profile, $db, $msgId);
        } else {
            $sent = [];
            foreach (['bale', 'rubika', 'soroush', 'igap'] as $p) {
                $sent[$p] = ['ok' => null, 'info' => 'NO_CONTENT'];
            }
        }

        if ($localFile && file_exists($localFile)) {
            @unlink($localFile);
        }

        $delivered = deliveredPlatformCount($sent, $only);
        if ($delivered > 0) {
            $maxOkId = max($maxOkId, $msgId);
        } elseif ($hasDeliverable) {
            $stopped = true;
        }

        $results[] = [
            'id'      => $msgId,
            'profile' => $profile,
            'media'   => ['ok' => $mediaInfo === 'none' ? null : ($mediaInfo === 'downloaded'), 'info' => $mediaInfo],
            'bale'    => $sent['bale'],
            'rubika'  => $sent['rubika'],
            'soroush' => $sent['soroush'],
            'igap'    => $sent['igap'],
        ];

        if ($stopped) {
            break;
        }
    }

    if ($advance && $maxOkId > 0) {
        advanceLastSeenId($db, EITAA_CHANNEL_ID, $maxOkId);
    }

    echo json_encode([
        'success'    => !$stopped,
        'stopped'    => $stopped,
        'profile'    => $profile,
        'only'       => $only,
        'limit'      => $limit,
        'count'      => count($results),
        'lastSeenId' => getLastSeenId($db, EITAA_CHANNEL_ID),
        'results'    => $results,
        'message'    => $stopped ? 'به‌علت شکست کامل یک پست، صف متوقف شد تا پست‌ها جا نیفتند.' : 'پست‌های آخر پردازش شدند.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ۱-د) وضعیت اجرای صف پس‌زمینه: running + فایل پیشرفت زندهٔ worker
if ($action === 'queue_status') {
    header('Content-Type: application/json; charset=utf-8');
    [$running, $pid] = syncWorkerStatus();
    echo json_encode([
        'success'     => true,
        'running'     => $running,
        'pid'         => $running ? $pid : 0,
        'progress'    => readJsonFile(backgroundProgressFile()),
        'log'         => tailText(rtrim(LOG_DIR, '/') . '/cron_sync.log', 80),
        'launcherLog' => tailText(rtrim(LOG_DIR, '/') . '/background_sync.log', 30),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ۱-د-۲) شروع صف پس‌زمینه (وب): فقط worker را با یک launcher مستقل و
//         «تأییدشده» راه می‌اندازد و بلافاصله برمی‌گردد. خودِ پردازش در
//         action=background_run (CLI) انجام می‌شود.
//
//         چرا نسخهٔ قبلی کار نمی‌کرد؟
//          ۱) `command -v ea-php83 || … || php` در محیط وب اغلب PHP غلط/قدیمی
//             برمی‌گرداند (یا هیچ‌چیز) و worker در همان ثانیهٔ اول می‌مُرد؛
//             حالا باینری با resolvePhpCliBinary() «آزموده و تأییدشده» است.
//          ۲) nohup بدون setsid: با پایان درخواست وب، فرایند می‌توانست کشته شود؛
//             حالا با setsid از نشست Apache/PHP-FPM جدا می‌شود.
//          ۳) همه‌چیز در background_sync.log ثبت می‌شود تا علت هر شکست در
//             خود داشبورد دیده شود (از طریق queue_status → launcherLog).
if ($action === 'background_sync') {
    header('Content-Type: application/json; charset=utf-8');
    @mkdir(LOG_DIR, 0770, true);

    [$running, $pid] = syncWorkerStatus();
    if ($running) {
        echo json_encode([
            'success'       => true,
            'alreadyRunning'=> true,
            'pid'           => $pid,
            'progress'      => readJsonFile(backgroundProgressFile()),
            'message'       => 'صف هم‌اکنون در حال پردازش است.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!function_exists('shell_exec') || !is_callable('shell_exec')) {
        echo json_encode([
            'success' => false,
            'error'   => 'SHELL_EXEC_DISABLED',
            'message' => 'برای اجرای صف در پس‌زمینه، shell_exec باید در PHP فعال باشد. تا آن زمان cron/systemd می‌تواند cron_sync.sh را اجرا کند.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payload = json_decode(readRequestBody(), true);
    if (!is_array($payload)) {
        $payload = [];
    }
    $profile  = normalizeDestinationProfile((string)($payload['profile'] ?? ($_GET['profile'] ?? 'main')));
    $maxPosts = (int)($payload['maxPosts'] ?? ($_GET['maxPosts'] ?? BACKGROUND_MAX_POSTS));
    if ($maxPosts < 1)  { $maxPosts = 1; }
    if ($maxPosts > 50) { $maxPosts = 50; }
    $gap = (int)($payload['gap'] ?? BACKGROUND_GAP_SEC);
    if ($gap < 0)   { $gap = 0; }
    if ($gap > 120) { $gap = 120; }

    // ---------- ۱) باینری PHP CLI تأییدشده ----------
    [$phpOk, $phpBin, $phpAttempts] = resolvePhpCliBinary();
    if (!$phpOk) {
        @file_put_contents(
            rtrim(LOG_DIR, '/') . '/background_sync.log',
            '[' . date('Y-m-d H:i:s') . '] PHP_CLI_RESOLVE_FAILED attempts=' . json_encode($phpAttempts, JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND
        );
        echo json_encode([
            'success'  => false,
            'error'    => 'PHP_CLI_NOT_FOUND',
            'message'  => 'هیچ باینری PHP CLI سالم (نسخهٔ ۸+ با اکستنشن‌های لازم) پیدا نشد. '
                        . 'در فایل .env مقدار PHP_CLI_BIN را مسیر کامل PHP 8 قرار دهید (مثلاً /opt/cpanel/ea-php83/root/usr/bin/php).',
            'attempts' => $phpAttempts,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------- ۲) body و launcher مستقل و قابل‌دیباگ ----------
    $bodyFile = rtrim(LOG_DIR, '/') . '/background_body_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.json';
    @file_put_contents($bodyFile, json_encode([
        'profile'  => $profile,
        'maxPosts' => $maxPosts,
        'gap'      => $gap,
        'only'     => ['bale', 'rubika', 'soroush', 'igap'],
    ], JSON_UNESCAPED_UNICODE));
    @chmod($bodyFile, 0600);

    $pidFile   = syncWorkerPidFile();
    $logFile   = rtrim(LOG_DIR, '/') . '/background_sync.log';
    $launcher  = rtrim(LOG_DIR, '/') . '/background_launcher.sh';

    $homeDir = (string)getenv('HOME');
    if ($homeDir === '' && preg_match('#^(/home/[^/]+)#', (string)SYNC_APP_DIR, $hm)) {
        $homeDir = $hm[1];
    }
    $pathEnv = implode(':', array_unique(array_filter([
        dirname($phpBin),
        '/usr/local/bin',
        '/usr/bin',
        '/bin',
    ])));

    $launcherBody = '#!/usr/bin/env bash' . "\n"
        . '# تولیدشده توسط sync_manual.php (action=background_sync) در ' . date('Y-m-d H:i:s') . "\n"
        . '# نقش: اجرای مستقل worker صف پس‌زمینه و ثبت PID آن برای داشبورد' . "\n"
        . 'set -u' . "\n"
        . 'cd ' . escapeshellarg(SYNC_APP_DIR) . "\n"
        . 'export HOME=' . escapeshellarg($homeDir !== '' ? $homeDir : '/tmp') . "\n"
        . 'export PATH=' . escapeshellarg($pathEnv) . ':${PATH:-/usr/bin:/bin}' . "\n"
        . 'echo "[' . date('Y-m-d H:i:s') . '] launcher START php=' . $phpBin . ' profile=' . $profile . ' maxPosts=' . $maxPosts . '"' . "\n"
        . 'echo "[' . date('Y-m-d H:i:s') . '] php version: $(' . escapeshellarg($phpBin) . ' -r \'echo PHP_VERSION;\' 2>&1)"' . "\n"
        . 'ACTION=background_run SYNC_BODY_FILE=' . escapeshellarg($bodyFile) . ' ' . escapeshellarg($phpBin) . ' -f ' . escapeshellarg(SYNC_APP_DIR . '/cli_run.php') . ' &' . "\n"
        . 'WPID=$!' . "\n"
        . 'echo "$WPID" > ' . escapeshellarg($pidFile) . "\n"
        . 'echo "[' . date('Y-m-d H:i:s') . '] worker started pid=$WPID"' . "\n"
        . 'wait "$WPID"' . "\n"
        . 'RC=$?' . "\n"
        . 'rm -f ' . escapeshellarg($bodyFile) . "\n"
        . 'rm -f ' . escapeshellarg($pidFile) . "\n"
        . 'echo "[' . date('Y-m-d H:i:s') . '] launcher END rc=$RC"' . "\n"
        . 'exit $RC' . "\n";
    @file_put_contents($launcher, $launcherBody);
    @chmod($launcher, 0700);

    // setsid: جدا شدن کامل از نشست Apache/PHP-FPM تا با پایان درخواست،
    // worker کشته نشود. </dev/null هم تا fd های وب را نگه نداریم.
    $cmd = 'setsid nohup bash ' . escapeshellarg($launcher)
         . ' </dev/null >> ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';
    $out = trim((string)@shell_exec($cmd));
    $newPid = preg_match('/\b(\d+)\b/', $out, $m) ? (int)$m[1] : 0;
    if ($newPid > 0) {
        @file_put_contents($pidFile, (string)$newPid);
        echo json_encode([
            'success'  => true,
            'pid'      => $newPid,
            'phpBin'   => $phpBin,
            'profile'  => $profile,
            'maxPosts' => $maxPosts,
            'message'  => 'صف پس‌زمینه شروع شد؛ همهٔ پست‌های جدید روی سرور پردازش می‌شوند و می‌توانید صفحه را ببندید.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    @file_put_contents(
        $logFile,
        '[' . date('Y-m-d H:i:s') . '] LAUNCH_FAILED raw=' . $out . "\n",
        FILE_APPEND
    );
    echo json_encode(['success' => false, 'error' => 'START_FAILED', 'message' => 'شروع اجرای پس‌زمینه ناموفق بود.', 'raw' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * ارسال گزارش مدیریتی چندبخشی به بله (هر بخش < ۳۵۰۰ کاراکتر تا سقف پیام بله).
 * خروجی: [ok(bool), error(string)]
 */
function sendAdminReportLines(array $lines): array {
    if ($lines === []) {
        return [false, 'گزارشی برای ارسال وجود ندارد'];
    }
    $missing = envMissing(['BALE_BOT_TOKEN', 'BALE_ADMIN_CHAT_ID']);
    if ($missing) {
        return [false, envMissingMessage($missing)];
    }
    $url = 'https://tapi.bale.ai/bot' . preg_replace('/^bot/i', '', trim(BALE_BOT_TOKEN)) . '/sendMessage';
    $header = "📊 گزارش صف پس‌زمینهٔ همگام‌سازی\nزمان: " . date('Y-m-d H:i:s') . "\n\n";

    $chunks = [];
    $cur = $header;
    foreach ($lines as $line) {
        if (mb_strlen($cur) + mb_strlen($line) + 2 > 3500) {
            $chunks[] = $cur;
            $cur = '';
        }
        $cur .= $line . "\n\n";
    }
    if (trim($cur) !== '') {
        $chunks[] = $cur;
    }
    foreach ($chunks as $i => $chunk) {
        $sent = callApi($url, json_encode(['chat_id' => BALE_ADMIN_CHAT_ID, 'text' => trim($chunk)]), false, ['Content-Type: application/json']);
        if (!($sent['code'] === 200 && ($sent['res']['ok'] ?? false))) {
            return [false, 'ارسال گزارش به مدیر ناموفق بود (HTTP ' . ($sent['code'] ?? 0) . ') در بخش ' . ($i + 1) . ' از ' . count($chunks)];
        }
    }
    return [true, ''];
}

// ۱-د-۳) موتور صف پس‌زمینه (فقط CLI؛ توسط background_launcher.sh اجرا می‌شود)
//
//     • همهٔ «پست‌های جدید» (نه فقط ۵ پست آخر) پردازش می‌شوند.
//     • هر پلتفرم پست‌ها را به ترتیب می‌فرستد و در اولین شکستِ خودش می‌ایستد
//       (حفظ ترتیب زمانی هر کانال) — بقیهٔ پلتفرم‌ها به کار خود ادامه می‌دهند.
//     • سروش و آی‌گپ هم‌زمان اجرا می‌شوند و «مرورگر هر کدام فقط یک بار» برای
//       کل صف باز می‌شود (حالت batch اسکریپت‌های Node).
//     • دفتر تحویل (delivery) تکراری‌فرستادن را غیرممکن می‌کند: پلتفرم‌هایی که
//       قبلاً تحویل شده‌اند دوباره ارسال نمی‌شوند.
//     • برای آیتم‌های شکست‌خورده تا BACKGROUND_MAX_PASSES پاس تلاش مجدد.
//     • بدون هیچ مکثی بین پست‌ها (gap=0 پیش‌فرض؛ با BACKGROUND_GAP_SEC قابل تنظیم).
//     • پیشرفتِ لحظه‌ای در background_progress.json برای داشبورد (queue_status).
if ($action === 'background_run') {
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'CLI_ONLY', 'message' => 'این اکشن فقط از طریق launcher پس‌زمینه قابل اجراست.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    @set_time_limit(0);
    ignore_user_abort(true);

    $payload = readJsonFile(is_string(getenv('SYNC_BODY_FILE')) ? (string)getenv('SYNC_BODY_FILE') : null) ?? [];
    $profile  = normalizeDestinationProfile((string)($payload['profile'] ?? 'main'));
    $maxPosts = max(1, min(50, (int)($payload['maxPosts'] ?? BACKGROUND_MAX_POSTS)));
    $gap      = max(0, min(120, (int)($payload['gap'] ?? BACKGROUND_GAP_SEC)));
    $only     = normalizePlatformList(is_array($payload['only'] ?? null) ? (array)$payload['only'] : []);
    $dest     = destinationProfile($profile);

    $ALL_PLATFORMS = ['bale', 'rubika', 'soroush', 'igap'];

    // ---------- فایل پیشرفت مشترک با داشبورد ----------
    $progress = [
        'state'      => 'running',
        'phase'      => 'scrape',
        'startedAt'  => date('c'),
        'updatedAt'  => date('c'),
        'pid'        => getmypid(),
        'profile'    => $profile,
        'only'       => $only,
        'totalPosts' => 0,
        'posts'      => [],
        'summary'    => null,
        'message'    => 'در حال خواندن کانال ایتا…',
    ];
    $writeProgress = function () use (&$progress) {
        $progress['updatedAt'] = date('c');
        writeJsonFileAtomic(backgroundProgressFile(), $progress);
    };
    $writeProgress();

    $tmpFiles = [];      // رسانه‌های موقت برای پاک‌سازی پایانی
    $reportLines = [];

    // ---------- قفل مشترک با cron_sync.sh ----------
    // هر دو روی /tmp/cron_sync.lock همان flock(2) کرنل را می‌گیرند؛ نتیجه:
    // اگر cron وسط چرخه‌اش باشد این worker می‌ایستد تا تمام شود، و اگر این
    // worker در حال اجرا باشد cron دور بعدی را skip می‌کند — دیگر هرگز دو
    // فرایند هم‌زمان یک پست نمی‌فرستند (ارسال تکراری ممکن نیست).
    $lockFp = @fopen('/tmp/cron_sync.lock', 'c');
    $lockHeld = false;
    if ($lockFp !== false) {
        $shutdownReleaseLock = function () use (&$lockFp, &$lockHeld) {
            if ($lockFp) {
                if ($lockHeld) { @flock($lockFp, LOCK_UN); $lockHeld = false; }
                @fclose($lockFp);
                $lockFp = null;
            }
        };
        register_shutdown_function($shutdownReleaseLock);
    }

    try {
        if ($lockFp !== false) {
            $lockDeadline = time() + 600;   // حداکثر ۱۰ دقیقه انتظار برای پایان چرخهٔ جاری
            while (!flock($lockFp, LOCK_EX | LOCK_NB)) {
                if (time() >= $lockDeadline) {
                    throw new RuntimeException('LOCK_BUSY: چرخهٔ همگام‌سازی دیگری (cron) هنوز در حال اجراست؛ صف را دوباره اجرا کنید.');
                }
                $progress['message'] = 'در انتظار پایان چرخهٔ همگام‌سازی جاری (cron)…';
                $writeProgress();
                sleep(5);
            }
            $lockHeld = true;   // تا پایان همین فرایند نگه داشته می‌شود؛ shutdown آن را آزاد می‌کند
        }

        // ---------- ۱) scrape تازه + محاسبهٔ پست‌های جدید ----------
        $scrape = fetchEitaaPosts();
        if (!$scrape['ok']) {
            throw new RuntimeException('SCRAPE_FAILED: ' . $scrape['error']);
        }
        $lastSeen = getLastSeenId($db, EITAA_CHANNEL_ID);
        $pending = array_values(array_filter($scrape['messages'], fn($m) => (int)$m['id'] > $lastSeen));
        if (count($pending) > $maxPosts) {
            $pending = array_slice($pending, 0, $maxPosts);   // قدیمی‌ها اول؛ ترتیب زمانی حفظ می‌شود
        }

        if ($pending === []) {
            $progress['state'] = 'done';
            $progress['phase'] = 'done';
            $progress['message'] = 'پست جدیدی در کانال ایتا نبود.';
            $progress['summary'] = ['total' => 0, 'delivered' => 0, 'partial' => 0, 'failed' => 0, 'deferred' => 0, 'lastSeenId' => $lastSeen, 'advancedTo' => 0];
            $writeProgress();
            exit;
        }

        // ---------- ۲) ساخت وضعیت هر پست + اعمال دفتر تحویل ----------
        $posts = [];          // id => وضعیت کامل برای داشبورد
        $assigned = [];       // id => پلتفرم‌هایی که باید واقعاً ارسال شوند
        $fullyDelivered = []; // id => bool (هر ۴ پلتفرم قبلاً ok)
        foreach ($pending as $m) {
            $id = (int)$m['id'];
            $alreadyOk = deliveredOkPlatforms($db, $id, $profile);
            $assigned[$id] = array_values(array_filter($only, fn($p) => !in_array($p, $alreadyOk, true)));
            $fullyDelivered[$id] = ($assigned[$id] === []);
            $cell = [];
            foreach ($ALL_PLATFORMS as $p) {
                if (!in_array($p, $only, true)) {
                    $cell[$p] = ['ok' => null, 'info' => 'SKIPPED'];
                } elseif (in_array($p, $alreadyOk, true)) {
                    $cell[$p] = ['ok' => true, 'info' => 'قبلاً ارسال شده (دفتر تحویل)'];
                } else {
                    $cell[$p] = ['ok' => null, 'info' => 'QUEUED'];
                }
            }
            $posts[$id] = [
                'id'        => $id,
                'text'      => (string)($m['text'] ?? ''),
                'mediaType' => $m['mediaType'] ?? null,
                'fileName'  => $m['fileName'] ?? null,
                'status'    => $fullyDelivered[$id] ? 'delivered_before' : 'pending',
                'media'     => ['ok' => null, 'info' => empty($m['mediaUrl']) ? 'none' : 'queued'],
            ] + $cell;
        }
        $progress['totalPosts'] = count($pending);
        $progress['posts'] = $posts;
        $progress['message'] = count($pending) . ' پست جدید پیدا شد؛ در حال دانلود رسانه‌ها…';
        $writeProgress();

        // ---------- ۳) دانلود موازی رسانه + تلاش دوم با scrape تازه ----------
        $progress['phase'] = 'download';
        $writeProgress();

        $dlTargets = array_values(array_filter($pending, fn($m) => !empty($m['mediaUrl']) && empty($fullyDelivered[(int)$m['id']])));
        $downloads = [];
        if ($dlTargets !== []) {
            $downloads = downloadMediaBatch($dlTargets);
            $failedIds = array_keys(array_filter($downloads, fn($r) => !$r['ok']));
            if ($failedIds !== []) {
                // لینک‌های ایتا امضاشده و زمان‌دارند؛ یک scrape تازه شاید لینک سالم بدهد
                $fresh = fetchEitaaPosts();
                if ($fresh['ok']) {
                    $retryTargets = array_values(array_filter($fresh['messages'], fn($m) => in_array((int)$m['id'], $failedIds, true) && !empty($m['mediaUrl'])));
                    if ($retryTargets !== []) {
                        $downloads = array_replace($downloads, downloadMediaBatch($retryTargets));
                    }
                }
            }
        }

        // ---------- ۴) تعیین صف ارسال؛ تعویق پستِ بی‌رسانه (مثل حالت دستی) ----------
        $progress['phase'] = 'send';
        $sendList = [];
        $deferredId = 0;
        foreach ($pending as $m) {
            $id = (int)$m['id'];
            if ($fullyDelivered[$id]) {
                continue;   // قبلاً کامل تحویل شده؛ فقط برای advance حساب می‌شود
            }
            $localFile = null;
            if (!empty($m['mediaUrl'])) {
                $r = $downloads[$id] ?? ['ok' => false, 'path' => null, 'reason' => 'NO_RESULT'];
                if (!empty($r['ok']) && !empty($r['path']) && file_exists((string)$r['path'])) {
                    $localFile = (string)$r['path'];
                    $tmpFiles[] = $localFile;
                    $posts[$id]['media'] = ['ok' => true, 'info' => 'downloaded'];
                } else {
                    $reason = (string)($r['reason'] ?? 'UNKNOWN');
                    $attempts = bumpMediaFail($db, $id, $reason);
                    if ($attempts < MEDIA_MAX_RETRY) {
                        $posts[$id]['status'] = 'deferred';
                        $posts[$id]['media'] = ['ok' => false, 'info' => 'FAILED:' . $reason];
                        $deferredId = $id;
                        $reportLines[] = "⏸ پست {$id} [{$m['mediaType']}]: رسانه دانلود نشد ({$reason}) — تعویق تا چرخهٔ بعد با لینک تازه (تلاش {$attempts}/" . MEDIA_MAX_RETRY . ")";
                        break;   // حفظ ترتیب: پست‌های بعدی در این اجرا ارسال نمی‌شوند
                    }
                    // سقف تلاش رسیده → مثل sync_single: انتشار بدون رسانه + گزارش صریح
                    $posts[$id]['media'] = ['ok' => false, 'info' => 'DROPPED_AFTER_' . MEDIA_MAX_RETRY . '_TRIES:' . $reason];
                    $reportLines[] = "⚠️ پست {$id}: رسانه بعد از " . MEDIA_MAX_RETRY . " تلاش نیامد ({$reason}) — با متن فقط ارسال شد";
                }
            }
            $sendList[] = [
                'id'        => $id,
                'text'      => (string)($m['text'] ?? ''),
                'file'      => $localFile,
                'type'      => $m['mediaType'] ?? null,
                'fileName'  => safeFileName((string)($m['fileName'] ?? 'file.bin'), 'file.bin'),
                'mediaUrl'  => $m['mediaUrl'] ?? null,
            ];
            clearMediaFail($db, $id);   // پست منتشر می‌شود؛ شمارندهٔ شکست رسانه پاک شود (مثل sync_single)
        }

        // کمکی: به‌روزرسانی سلول یک پلتفرم از یک پست
        $setCell = function (int $id, string $platform, ?bool $ok, string $info) use (&$posts) {
            if (!isset($posts[$id])) { return; }
            $posts[$id][$platform] = ['ok' => $ok, 'info' => mb_substr($info, 0, 300)];
        };
        // کمکی: محاسبهٔ وضعیت هر پست از روی سلول‌ها
        $recomputeStatuses = function () use (&$posts, $assigned) {
            foreach ($posts as $id => $p) {
                if (in_array($p['status'], ['deferred', 'delivered_before'], true)) { continue; }
                $okCnt = 0; $assignedCnt = 0; $pendingCnt = 0;
                foreach (['bale', 'rubika', 'soroush', 'igap'] as $platform) {
                    if (!isset($assigned[$id]) || !in_array($platform, $assigned[$id], true)) { continue; }
                    $assignedCnt++;
                    $ok = $p[$platform]['ok'] ?? null;
                    if ($ok === true) { $okCnt++; }
                    elseif ($ok === null) { $pendingCnt++; }
                }
                if ($pendingCnt > 0) { $posts[$id]['status'] = 'sending'; }
                elseif ($assignedCnt === 0) { $posts[$id]['status'] = 'delivered_before'; }
                elseif ($okCnt === $assignedCnt) { $posts[$id]['status'] = 'ok'; }
                elseif ($okCnt > 0) { $posts[$id]['status'] = 'partial'; }
                else { $posts[$id]['status'] = 'failed'; }
            }
        };

        if ($sendList !== []) {
            // ---------- ۵) فهرست آیتم هر پلتفرم (بدون پلتفرم‌های قبلاً تحویل‌شده) ----------
            $itemsByPlatform = ['bale' => [], 'rubika' => [], 'soroush' => [], 'igap' => []];
            foreach ($sendList as $it) {
                foreach ($assigned[$it['id']] as $platform) {
                    $itemsByPlatform[$platform][] = $it;
                }
            }

            // ---------- ۶) راه‌اندازی runnerهای Node (سروش + آی‌گپ هم‌زمان) ----------
            $nodeWorkers = [];
            foreach (['soroush', 'igap'] as $platform) {
                $items = $itemsByPlatform[$platform];
                if ($items === []) { continue; }
                $h = launchUserbotBatch($platform, $items, $dest);
                if (!$h['ok']) {
                    foreach ($items as $it) {
                        $setCell($it['id'], $platform, false, 'LAUNCH: ' . $h['message']);
                        markDelivery($db, $it['id'], $platform, $profile, false, 'LAUNCH: ' . $h['message']);
                    }
                    continue;
                }
                $nodeWorkers[$platform] = [
                    'platform'  => $platform,
                    'handle'    => $h,
                    'items'     => $items,
                    'pass'      => 1,
                    'seen'      => [],
                    'profileDir'=> $platform === 'soroush' ? SOROUSH_PROFILE_DIR : IGAP_PROFILE_DIR,
                ];
            }

            // ---------- ۷) workerهای HTTP (بله/روبیکا؛ یک آیتم در هر دور) ----------
            $httpWorkers = [];
            foreach (['bale', 'rubika'] as $platform) {
                if ($itemsByPlatform[$platform] === []) { continue; }
                $httpWorkers[$platform] = ['platform' => $platform, 'queue' => array_values($itemsByPlatform[$platform]), 'pass' => 1, 'pos' => 0];
            }

            // ادغام نتیجهٔ runner در وضعیت داشبورد + دفتر تحویل
            $mergeRunner = function (array &$nw) use (&$posts, $db, $profile, $setCell) {
                $pr = readJsonFile($nw['handle']['progressFile']);
                if (!is_array($pr) || !isset($pr['items']) || !is_array($pr['items'])) { return; }
                foreach ($pr['items'] as $pi) {
                    $id = (int)($pi['id'] ?? 0);
                    $status = (string)($pi['status'] ?? '');
                    if ($id <= 0 || isset($nw['seen'][$id])) { continue; }
                    if (!in_array($status, ['OK', 'ERROR', 'UNVERIFIED', 'NOT_ATTEMPTED'], true)) { continue; }
                    $res = is_array($pi['result'] ?? null) ? $pi['result'] : [];
                    if ($status === 'OK') {
                        $ok = true;
                        $info = (string)($res['message'] ?? 'OK');
                    } else {
                        $ok = false;
                        $info = $status === 'NOT_ATTEMPTED'
                            ? 'تا این‌جا پیش رفت؛ این پست در این پاس تلاش نشد'
                            : '[' . (string)($res['code'] ?? $status) . '] ' . (string)($res['error'] ?? $res['message'] ?? '');
                    }
                    $setCell($id, $nw['platform'], $ok, trim($info));
                    markDelivery($db, $id, $nw['platform'], $profile, $ok, trim($info));
                    $nw['seen'][$id] = true;
                }
            };

            // آیتم‌های باقی‌ماندهٔ یک runner از روی progress آن
            $remainingItems = function (array $nw): array {
                $pr = readJsonFile($nw['handle']['progressFile']);
                $statusById = [];
                if (is_array($pr) && is_array($pr['items'] ?? null)) {
                    foreach ($pr['items'] as $pi) {
                        $statusById[(int)($pi['id'] ?? 0)] = (string)($pi['status'] ?? '');
                    }
                }
                $remaining = [];
                $failedSeen = false;
                foreach ($nw['items'] as $it) {
                    $st = $statusById[(int)$it['id']] ?? '';
                    if ($st !== 'OK') { $failedSeen = true; }
                    if ($failedSeen) { $remaining[] = $it; }   // خودِ آیتم شکست‌خورده + بعدی‌ها
                }
                return $remaining;
            };

            $fatalRunnerCode = function (array $nw): ?string {
                $pr = readJsonFile($nw['handle']['progressFile']);
                $code = (string)($pr['error']['code'] ?? '');
                $fatalCodes = ['SESSION_EXPIRED', 'CHANNEL_NOT_FOUND', 'COMPOSER_NOT_AVAILABLE', 'APP_NOT_LOADED', 'NODE_DEPS_MISSING', 'BAD_BATCH', 'SHELL_EXEC_DISABLED', 'BATCH_WRITE_FAILED', 'LAUNCH_FAILED'];
                return in_array($code, $fatalCodes, true) ? $code : null;
            };

            // ---------- ۸) حلقهٔ یکپارچهٔ اجرا (تا پایان همهٔ workerها) ----------
            while ($nodeWorkers !== [] || $httpWorkers !== []) {
                // --- یک آیتم از هر صف HTTP ---
                foreach (array_keys($httpWorkers) as $platform) {
                    if (!isset($httpWorkers[$platform])) { continue; }
                    $hw = &$httpWorkers[$platform];
                    $it = $hw['queue'][$hw['pos']];
                    if ($gap > 0 && $hw['pos'] > 0) { sleep($gap); }   // فقط اگر در .env خواسته شده باشد
                    $ok = false;
                    $info = '';
                    for ($try = 1; $try <= 2 && !$ok; $try++) {
                        if ($platform === 'bale') {
                            $r = sendToBale($it['text'], $it['file'], $it['type'], $it['fileName'], $dest['baleChannel']);
                            $ok = ($r['code'] === 200 and (bool)($r['res']['ok'] ?? false));
                        } else {
                            $r = sendToRubika($it['text'], $it['file'], $it['type'], $it['fileName'], $dest['rubikaChannel']);
                            $ok = ($r['code'] === 200 and (($r['res']['status'] ?? '') === 'OK'));
                        }
                        $info = (string)($r['code'] ?? 'ERR');
                        if (!$ok && $try === 1) { usleep(700000); }   // یک تلاش مجددِ همان لحظه
                    }
                    $setCell($it['id'], $platform, $ok, $ok ? $info : ('FAIL ' . $info));
                    markDelivery($db, $it['id'], $platform, $profile, $ok, $info);

                    if (!$ok) {
                        if ($hw['pass'] < BACKGROUND_MAX_PASSES) {
                            $hw['pass']++;
                            $hw['queue'] = array_slice($hw['queue'], $hw['pos']);   // از همین آیتم دوباره
                            $hw['pos'] = 0;
                        } else {
                            for ($j = $hw['pos']; $j < count($hw['queue']); $j++) {
                                $rest = $hw['queue'][$j];
                                if (($posts[$rest['id']][$platform]['ok'] ?? null) === null) {
                                    $setCell($rest['id'], $platform, false, 'FAILED_AFTER_RETRIES');
                                    markDelivery($db, $rest['id'], $platform, $profile, false, 'FAILED_AFTER_RETRIES');
                                }
                            }
                            unset($httpWorkers[$platform]);
                        }
                    } else {
                        $hw['pos']++;
                        if ($hw['pos'] >= count($hw['queue'])) {
                            unset($httpWorkers[$platform]);
                        }
                    }
                    unset($hw);
                }

                // --- ادغام و مدیریت runnerهای Node ---
                foreach (array_keys($nodeWorkers) as $platform) {
                    if (!isset($nodeWorkers[$platform])) { continue; }
                    $nw = &$nodeWorkers[$platform];
                    $mergeRunner($nw);

                    if (userbotAsyncDone($nw['handle'])) {
                        sleep(1);
                        $mergeRunner($nw);   // نتیجهٔ نهایی
                        $remaining = $remainingItems($nw);
                        $fatal = $fatalRunnerCode($nw);
                        cleanupUserbotBatch($nw['handle']);

                        if ($remaining !== [] && $fatal === null && $nw['pass'] < BACKGROUND_MAX_PASSES) {
                            sleep(2);   // مرورگر پاس قبل کامل بسته شود
                            $h2 = launchUserbotBatch($platform, $remaining, $dest);
                            if ($h2['ok']) {
                                $nw['handle'] = $h2;
                                $nw['items'] = $remaining;
                                $nw['pass']++;
                                $nw['seen'] = [];
                                $progress['message'] = "پاس تلاش مجدد {$platform} (پاس {$nw['pass']}) شروع شد.";
                            } else {
                                foreach ($remaining as $rest) {
                                    if (($posts[$rest['id']][$platform]['ok'] ?? null) === null) {
                                        $setCell($rest['id'], $platform, false, 'RELAUNCH: ' . $h2['message']);
                                        markDelivery($db, $rest['id'], $platform, $profile, false, 'RELAUNCH: ' . $h2['message']);
                                    }
                                }
                                unset($nodeWorkers[$platform]);
                            }
                        } else {
                            if ($remaining !== []) {
                                $note = $fatal !== null ? ('FATAL_' . $fatal) : 'NO_MORE_PASSES';
                                foreach ($remaining as $rest) {
                                    if (($posts[$rest['id']][$platform]['ok'] ?? null) === null) {
                                        $setCell($rest['id'], $platform, false, $note);
                                        markDelivery($db, $rest['id'], $platform, $profile, false, $note);
                                    }
                                }
                            }
                            unset($nodeWorkers[$platform]);
                        }
                    } elseif (time() > (int)$nw['handle']['deadline']) {
                        killProcessGroup((int)$nw['handle']['pgid']);
                        foreach ($nw['items'] as $rest) {
                            if (($posts[$rest['id']][$platform]['ok'] ?? null) === null) {
                                $setCell($rest['id'], $platform, false, 'RUNNER_TIMEOUT');
                                markDelivery($db, $rest['id'], $platform, $profile, false, 'RUNNER_TIMEOUT');
                            }
                        }
                        cleanupUserbotBatch($nw['handle']);
                        unset($nodeWorkers[$platform]);
                    }
                    unset($nw);
                }

                $recomputeStatuses();
                $progress['posts'] = $posts;
                $writeProgress();
                usleep($httpWorkers !== [] ? 500000 : 2000000);
            }
        }

        // ---------- ۹) جلو بردن state تا آخرین پستِ پیوستهٔ تحویل‌شده ----------
        $recomputeStatuses();
        // پست‌هایی که به‌خاطر تعویقِ پست قبل، هرگز تلاش نشدند → pending (نه sending)
        foreach ($posts as $id => $p) {
            if (($p['status'] ?? '') !== 'sending') { continue; }
            $started = false;
            foreach ($only as $platform) {
                $cell = $p[$platform] ?? ['ok' => null, 'info' => 'SKIPPED'];
                if ($cell['ok'] !== null || !in_array((string)($cell['info'] ?? ''), ['QUEUED', 'SKIPPED', ''], true)) {
                    $started = true;
                    break;
                }
            }
            if (!$started) { $posts[$id]['status'] = 'pending'; }
        }
        $progress['posts'] = $posts;
        $advanceTo = 0;
        foreach ($pending as $m) {
            $id = (int)$m['id'];
            $p = $posts[$id];
            if (($p['status'] ?? '') === 'deferred') { break; }
            $anyOk = false;
            foreach ($only as $platform) {
                if (($p[$platform]['ok'] ?? null) === true) { $anyOk = true; break; }
            }
            if ($anyOk) { $advanceTo = $id; } else { break; }
        }
        if ($advanceTo > 0) {
            advanceLastSeenId($db, EITAA_CHANNEL_ID, $advanceTo);
        }

        // ---------- ۱۰) گزارش مدیریتی ----------
        $mark = fn($cell) => (($cell['ok'] ?? null) === true) ? '✅' : ((($cell['ok'] ?? null) === null) ? '—' : '❌');
        foreach ($pending as $m) {
            $id = (int)$m['id'];
            $p = $posts[$id];
            $mediaNote = empty($m['mediaUrl']) ? '' : (' | رسانه: ' . (($p['media']['ok'] ?? false) === true ? '✅' : '❌ ' . (string)($p['media']['info'] ?? '')));
            $reportLines[] = "🔹 پست {$id} [" . ($m['mediaType'] ?? 'text') . "]: بله " . $mark($p['bale']) . " | روبیکا " . $mark($p['rubika']) . " | سروش " . $mark($p['soroush']) . " | آی‌گپ " . $mark($p['igap']) . $mediaNote;
        }

        $summary = ['total' => count($pending), 'delivered' => 0, 'partial' => 0, 'failed' => 0, 'deferred' => 0, 'lastSeenId' => getLastSeenId($db, EITAA_CHANNEL_ID), 'advancedTo' => $advanceTo];
        foreach ($posts as $p) {
            if ($p['status'] === 'ok' || $p['status'] === 'delivered_before') { $summary['delivered']++; }
            elseif ($p['status'] === 'partial') { $summary['partial']++; }
            elseif ($p['status'] === 'deferred') { $summary['deferred']++; }
            elseif ($p['status'] === 'failed') { $summary['failed']++; }
        }
        $reportLines[] = "📈 جمع‌بندی: از {$summary['total']} پست — کامل: {$summary['delivered']}، ناقص: {$summary['partial']}، ناموفق: {$summary['failed']}، تعویق‌شده: {$summary['deferred']} | last_msg_id → {$summary['advancedTo']}";

        $progress['state'] = 'done';
        $progress['phase'] = 'done';
        $progress['posts'] = $posts;
        $progress['summary'] = $summary;
        $progress['message'] = $deferredId > 0
            ? "صف تمام شد؛ پست {$deferredId} برای دانلود رسانه به چرخهٔ بعد موکول شد و پست‌های بعد از آن در این اجرا ارسال نشدند."
            : 'صف پس‌زمینه با موفقیت تمام شد.';
        $writeProgress();

        [$reportOk, $reportErr] = sendAdminReportLines($reportLines);
        if (!$reportOk) {
            error_log('[background_run] report: ' . $reportErr);
        }
    } catch (Throwable $e) {
        $progress['state'] = 'error';
        $progress['phase'] = 'error';
        $progress['message'] = 'خطای worker: ' . $e->getMessage();
        $writeProgress();
        error_log('[background_run] FATAL: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        try {
            sendAdminReportLines(array_merge(["❌ صف پس‌زمینه با خطا متوقف شد:"], [$e->getMessage()]));
        } catch (Throwable $ignored) {}
        foreach ($tmpFiles as $f) { @unlink($f); }
        exit(1);
    }

    foreach ($tmpFiles as $f) { @unlink($f); }
    exit(0);
}

// ۲. پردازش منفرد یک پست
if ($action === 'sync_single') {
    set_time_limit(300);
    header('Content-Type: application/json; charset=utf-8');

    $payload = json_decode(readRequestBody(), true);
    if (!$payload or empty($payload['id'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid payload']);
        exit;
    }

    $msgId     = (int)$payload['id'];
    $text      = (string)($payload['text'] ?? '');
    $mediaUrl  = isset($payload['mediaUrl']) ? (string)$payload['mediaUrl'] : null;
    $mediaType = isset($payload['mediaType']) ? (string)$payload['mediaType'] : null;
    $fileName  = safeFileName((string)($payload['fileName'] ?? 'file.bin'), 'file.bin');

    // ---------- دانلود رسانه با سیاست «تعویق هوشمند» ----------
    // اگر رسانه دانلود نشود، پست «متن‌خالی» منتشر نمی‌شود؛ بلکه تعویق می‌شود تا
    // در چرخهٔ بعد scrape تازه، لینک امضاشدهٔ جدید گرفته شود (خودترمیمی).
    // پس از MEDIA_MAX_RETRY تلاش ناموفق (مثلاً رسانهٔ حجیم که نمای وب ایتا
    // لینک مستقیم نمی‌دهد) پست فقط با متن منتشر و این اتفاق صریحاً گزارش می‌شود.
    $localFile   = null;
    $mediaReason = null;

    if ($mediaUrl) {
        $localFile = downloadMedia($mediaUrl, $fileName, $mediaReason);
        if (!$localFile) {
            sleep(2);                                   // تلاش دوم (خطای گذرای شبکه)
            $localFile = downloadMedia($mediaUrl, $fileName, $mediaReason);
        }
        if (!$localFile) {
            $attempts = bumpMediaFail($db, $msgId, (string)$mediaReason);
            if ($attempts < MEDIA_MAX_RETRY) {
                http_response_code(200);
                echo json_encode([
                    'success'   => false,
                    'deferred'  => true,
                    'stopQueue' => true,
                    'id'        => $msgId,
                    'error'     => 'MEDIA_DOWNLOAD_FAILED',
                    'reason'   => (string)$mediaReason,
                    'attempt'  => $attempts,
                    'message'  => "رسانهٔ پست $msgId دانلود نشد ($mediaReason). پست منتشر نشد و last_msg_id جلو نرفت؛ در چرخهٔ بعد با لینک تازه دوباره تلاش می‌شود (تلاش $attempts از " . MEDIA_MAX_RETRY . ')',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            // از این پس: انتشار بدون رسانه + گزارش صریح (دیگر بی‌صدا نیست)
        }
    }

    // «only» اختیاری: اگر داده شود، فقط همان مقصدها ارسال می‌شوند (برای
    // جبران شکست جزئی، بدون پست تکراری در بقیهٔ پلتفرم‌ها).
    $only = is_array($payload['only'] ?? null) ? (array)$payload['only'] : [];
    $onlyNorm = normalizePlatformList($only);
    $profile = normalizeDestinationProfile((string)($payload['profile'] ?? 'main'));
    $advanceState = array_key_exists('advance', $payload) ? !empty($payload['advance']) : true;
    $hasDeliverable = (trim($text) !== '') || ($localFile && file_exists($localFile));

    if ($hasDeliverable) {
        $sent = dispatchToPlatforms($onlyNorm, $text, $localFile, $mediaType, $fileName, $profile, $db, $msgId);
    } else {
        $sent = [];
        foreach (['bale', 'rubika', 'soroush', 'igap'] as $p) {
            $sent[$p] = ['ok' => null, 'info' => 'NO_CONTENT'];
        }
    }

    if ($localFile and file_exists($localFile)) {
        @unlink($localFile);
    }

    $mediaOk = true;
    $mediaInfo = 'none';
    if ($mediaUrl) {
        $mediaOk = ($mediaReason === null);
        $mediaInfo = $mediaOk ? 'downloaded' : ('DROPPED_AFTER_' . MEDIA_MAX_RETRY . '_TRIES:' . (string)$mediaReason);
    }

    // اگر هیچ مقصدی واقعاً پیام را نپذیرفت، state جلو نرود و صف متوقف شود؛
    // در غیر این صورت پردازش پست‌های بعدی باعث جا افتادن دائمی این پست می‌شود.
    $delivered = deliveredPlatformCount($sent, $onlyNorm);
    if ($hasDeliverable && $delivered === 0) {
        echo json_encode([
            'success'   => false,
            'stopQueue' => true,
            'id'        => $msgId,
            'error'     => 'ALL_DESTINATIONS_FAILED',
            'message'   => 'هیچ مقصدی ارسال را تأیید نکرد؛ last_msg_id جلو نرفت و صف باید متوقف شود تا پس از رفع خطا دوباره تلاش شود.',
            'only'      => $onlyNorm,
            'profile'   => $profile,
            'media'     => ['ok' => $mediaOk, 'info' => $mediaInfo],
            'bale'      => ['ok' => $sent['bale']['ok'], 'info' => $sent['bale']['info']],
            'rubika'    => ['ok' => $sent['rubika']['ok'], 'info' => $sent['rubika']['info']],
            'soroush'   => ['ok' => $sent['soroush']['ok'], 'info' => $sent['soroush']['info']],
            'igap'      => ['ok' => $sent['igap']['ok'], 'info' => $sent['igap']['info']],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($advanceState) {
        advanceLastSeenId($db, EITAA_CHANNEL_ID, $msgId);
    }

    // پستی که منتشر شد (یا بعد از سقف تلاش، بدون محتوای قابل ارسال رد شد) دیگر در صف تعویق نمی‌ماند
    clearMediaFail($db, $msgId);

    echo json_encode([
        'success' => true,
        'id'      => $msgId,
        'only'    => $onlyNorm,
        'profile' => $profile,
        'media'   => ['ok' => $mediaOk, 'info' => $mediaInfo],
        'bale'    => ['ok' => $sent['bale']['ok'], 'info' => $sent['bale']['info']],
        'rubika'  => ['ok' => $sent['rubika']['ok'], 'info' => $sent['rubika']['info']],
        'soroush' => ['ok' => $sent['soroush']['ok'], 'info' => $sent['soroush']['info']],
        'igap'    => ['ok' => $sent['igap']['ok'], 'info' => $sent['igap']['info']],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ۳. ارسال گزارش پایانی
if ($action === 'send_report') {
    header('Content-Type: application/json; charset=utf-8');
    $payload = json_decode(readRequestBody(), true);
    $reportItems = is_array($payload['report'] ?? null) ? $payload['report'] : [];

    $reportSent  = false;
    $reportError = '';

    if (!empty($reportItems)) {
        $missing = envMissing(['BALE_BOT_TOKEN', 'BALE_ADMIN_CHAT_ID']);
        if ($missing) {
            $reportError = envMissingMessage($missing);
        } else {
            $reportText = "📊 گزارش همگام‌سازی ۴ کانال:\nزمان: " . date('Y-m-d H:i:s') . "\nتعداد پست‌ها: " . count($reportItems) . "\n\n" . implode("\n\n", $reportItems);
            $adminUrl = "https://tapi.bale.ai/bot" . preg_replace('/^bot/i', '', trim(BALE_BOT_TOKEN)) . "/sendMessage";
            $sent = callApi($adminUrl, json_encode(['chat_id' => BALE_ADMIN_CHAT_ID, 'text' =>$reportText]), false, ['Content-Type: application/json']);
            $reportSent  = ($sent['code'] === 200 and ($sent['res']['ok'] ?? false));
            $reportError = $reportSent ? '' : ('ارسال گزارش به مدیر ناموفق بود (HTTP ' . ($sent['code'] ?? 0) . ')');
        }
    } else {
        $reportError = 'گزارشی برای ارسال وجود ندارد';
    }

    echo json_encode(['success' => $reportSent, 'error' => $reportError], JSON_UNESCAPED_UNICODE);
    exit;
}

// ۴. ارسالِ دوبارهٔ پست‌های «دیده‌شده» به مقصدهای انتخابی
//    کاربرد: وقتی پستی به بله/روبیکا رفته ولی سروش/آی‌گپ شکست خورده،
//    بدون پست تکراری در مقصدهای موفق، فقط مقصدهای جاافتاده جبران می‌شوند.
//    بدنهٔ JSON:
//      {"ids":[74136,74137]}                یا  {"from":74136,"to":74142}
//      {"only":["soroush","igap"]}          (پیش‌فرض: soroush,igap)
//      {"advance":false}                    (پیش‌فرض: last_msg_id دست نمی‌خورد)
if ($action === 'resend') {
    set_time_limit(1200);
    header('Content-Type: application/json; charset=utf-8');

    $payload = json_decode(readRequestBody(), true);
    if (!is_array($payload)) {
        $payload = [];
    }

    $ids = [];
    if (isset($payload['ids']) && is_array($payload['ids'])) {
        $ids = array_values(array_filter(array_map('intval', $payload['ids']), fn($x) => $x > 0));
    }
    $from = isset($payload['from']) ? (int)$payload['from'] : 0;
    $to   = isset($payload['to'])   ? (int)$payload['to']   : 0;

    if ($ids === [] && $from <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'BAD_REQUEST',
            'message' => 'باید "ids":[...] یا "from":<id> (و اختیاری "to":<id>) در بدنهٔ JSON باشد.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $only = is_array($payload['only'] ?? null) ? (array)$payload['only'] : ['soroush', 'igap'];
    $profile = normalizeDestinationProfile((string)($payload['profile'] ?? 'main'));
    $advance = !empty($payload['advance']);
    $noMedia = !empty($payload['no_media']);

    // scrape تازه: لینک رسانهٔ ایتا امضاشده و زمان‌دار است، پس لینک قدیمی به درد نمی‌خورد
    $scrape = fetchEitaaPosts();
    if (!$scrape['ok']) {
        echo json_encode(['success' => false, 'error' => 'SCRAPE_FAILED', 'message' => $scrape['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $selected = array_values(array_filter($scrape['messages'], function (array $m) use ($ids, $from, $to) {
        if ($ids !== []) {
            return in_array($m['id'], $ids, true);
        }
        if ($to > 0) {
            return $m['id'] >= $from && $m['id'] <= $to;
        }
        return $m['id'] >= $from;
    }));

    if ($selected === []) {
        $visible = array_map(fn($m) => $m['id'], $scrape['messages']);
        echo json_encode([
            'success' => false,
            'error'   => 'POSTS_NOT_VISIBLE',
            'message' => 'پست‌های خواسته‌شده در نمای وب ایتا پیدا نشدند (ایتا فقط پست‌های اخیر را نشان می‌دهد).',
            'visible' => $visible,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $results = [];
    $maxId   = 0;
    foreach ($selected as $index => $m) {
        if ($index > 0 && SYNC_GAP_SEC > 0) {
            sleep(SYNC_GAP_SEC);
        }
        $msgId    = (int)$m['id'];
        $maxId    = max($maxId, $msgId);
        $text     = (string)($m['text'] ?? '');
        $mediaUrl = $m['mediaUrl'] ?? null;
        $mediaType = $m['mediaType'] ?? null;
        $fileName = safeFileName((string)($m['fileName'] ?? 'file.bin'), 'file.bin');

        $localFile = null;
        $mediaReason = null;
        $mediaInfo = 'none';
        if ($mediaUrl && $noMedia) {
            $mediaInfo = 'skipped';          // کاربر خواسته فقط متن برود
        } elseif ($mediaUrl) {
            $localFile = downloadMedia((string)$mediaUrl, $fileName !== '' ? $fileName : 'file.bin', $mediaReason);
            if (!$localFile) {
                sleep(2);
                $localFile = downloadMedia((string)$mediaUrl, $fileName !== '' ? $fileName : 'file.bin', $mediaReason);
            }
            $mediaInfo = $localFile ? 'downloaded' : ('FAILED:' . (string)$mediaReason);
        }

        $sent = dispatchToPlatforms($only, $text, $localFile, $mediaType, $fileName !== '' ? $fileName : 'file.bin', $profile, $db, $msgId);

        if ($localFile and file_exists($localFile)) {
            @unlink($localFile);
        }

        $results[] = [
            'id'      => $msgId,
            'media'   => ['ok' => $mediaInfo === 'none' ? null : ($mediaInfo === 'downloaded'), 'info' => $mediaInfo],
            'bale'    => $sent['bale'],
            'rubika'  => $sent['rubika'],
            'soroush' => $sent['soroush'],
            'igap'    => $sent['igap'],
        ];
    }

    if ($advance && $maxId > 0) {
        advanceLastSeenId($db, EITAA_CHANNEL_ID, $maxId);
    }

    echo json_encode([
        'success'    => true,
        'only'       => $only,
        'profile'    => $profile,
        'count'      => count($results),
        'lastSeenId' => getLastSeenId($db, EITAA_CHANNEL_ID),
        'results'    => $results,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ۵. عقب بردن last_msg_id (تا پست‌ها دوباره در صف داشبورد ظاهر شوند)
//    بدنهٔ JSON: {"id":74135}   یا کوئری: ?action=rewind&key=…&id=74135
if ($action === 'rewind') {
    header('Content-Type: application/json; charset=utf-8');
    $payload = json_decode(readRequestBody(), true);
    $id = (int)($payload['id'] ?? ($_GET['id'] ?? -1));
    if ($id < 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'BAD_REQUEST', 'message' => 'شناسهٔ پست لازم است: {"id":74135}'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $before = getLastSeenId($db, EITAA_CHANNEL_ID);
    setLastSeenId($db, EITAA_CHANNEL_ID, $id);
    echo json_encode([
        'success'    => true,
        'before'     => $before,
        'lastSeenId' => getLastSeenId($db, EITAA_CHANNEL_ID),
        'note'       => 'پست‌های بزرگ‌تر از این شناسه دوباره در صف داشبورد ظاهر می‌شوند.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>داشبورد همگام‌سازی پیام‌رسان‌ها</title>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; font-family: 'Vazirmatn', sans-serif; }
        body { background: #0f172a; color: #e2e8f0; margin: 0; padding: 20px; }
        .container { max-width: 1320px; margin: 0 auto; }
        .layout { display: grid; grid-template-columns: minmax(0, 1fr) 384px; gap: 20px; align-items: start; }
        .main-col { min-width: 0; }
        .side-col { position: sticky; top: 20px; }
        @media (max-width: 1040px) {
            .layout { grid-template-columns: minmax(0, 1fr); }
            .side-col { position: static; }
        }
        .header { display: flex; justify-content: space-between; align-items: center; background: #1e293b; padding: 20px; border-radius: 12px; border: 1px solid #334155; margin-bottom: 20px; gap: 14px; }
        .header h1 { font-size: 1.25rem; margin: 0; color: #38bdf8; }
        .header-actions { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
        .btn { background: #2563eb; color: #fff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.2s; font-size: 0.95rem; }
        .btn:hover { background: #1d4ed8; }
        .btn:disabled { background: #475569; cursor: not-allowed; }
        .progress-box { background: #1e293b; padding: 15px; border-radius: 12px; border: 1px solid #334155; margin-bottom: 20px; }
        .progress-bar-bg { width: 100%; height: 12px; background: #334155; border-radius: 6px; overflow: hidden; margin-top: 10px; }
        .progress-bar-fill { width: 0%; height: 100%; background: linear-gradient(90deg, #38bdf8, #22c55e); transition: width 0.3s; }
        .cards-list { display: flex; flex-direction: column; gap: 12px; margin-bottom: 20px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 16px; display: flex; justify-content: space-between; align-items: center; }
        .card.active { border-color: #38bdf8; box-shadow: 0 0 10px rgba(56, 189, 248, 0.2); }
        .card-info { display: flex; flex-direction: column; gap: 4px; max-width: 65%; }
        .card-id { font-weight: bold; color: #f8fafc; font-size: 1rem; }
        .card-desc { font-size: 0.85rem; color: #94a3b8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .badges { display: flex; gap: 8px; }
        .badge { padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: bold; background: #334155; color: #cbd5e1; }
        .badge.ok { background: #166534; color: #86efac; }
        .badge.fail { background: #991b1b; color: #fca5a5; }
        .badge.loading { background: #075985; color: #7dd3fc; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .console { background: #000; color: #4ade80; font-family: monospace; padding: 15px; border-radius: 10px; height: 180px; overflow-y: auto; font-size: 0.85rem; border: 1px solid #22c55e33; }
        .console p { margin: 2px 0; }

        /* ============ پنل کناری: بررسی و شروع همگام‌سازی ============ */
        .panel { background: #1e293b; border: 1px solid #334155; border-radius: 12px; overflow: hidden; }
        .panel-head { padding: 16px 18px; background: linear-gradient(135deg, #1d4ed8 0%, #0f766e 100%); border-bottom: 1px solid #334155; }
        .panel-head h2 { margin: 0; font-size: 1.02rem; color: #fff; }
        .panel-head p { margin: 6px 0 0; font-size: 0.78rem; color: #dbeafe; line-height: 1.6; }
        .panel-body { padding: 16px 18px 18px; }
        .section-title { font-size: 0.78rem; font-weight: bold; color: #7dd3fc; margin: 16px 0 8px; padding-bottom: 6px; border-bottom: 1px dashed #334155; letter-spacing: .2px; }
        .section-title:first-child { margin-top: 0; }
        .lbl { display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 6px; }
        .lbl.inline { margin: 0; }
        .row { display: flex; gap: 8px; align-items: center; }
        .row.space { justify-content: space-between; }
        input[type="number"] { background: #0f172a; border: 1px solid #334155; color: #e2e8f0; border-radius: 8px; padding: 9px 10px; font-size: 0.9rem; width: 86px; font-family: inherit; }
        input[type="number"]:focus { outline: none; border-color: #38bdf8; }
        .hint { font-size: 0.72rem; color: #64748b; line-height: 1.7; margin-top: 8px; }
        .btn-ghost { background: #334155; color: #e2e8f0; padding: 9px 14px; font-size: 0.85rem; flex: 1; }
        .btn-ghost:hover { background: #475569; }
        .btn-block { width: 100%; margin-top: 16px; padding: 12px; font-size: 0.98rem; }
        .btn-go { background: linear-gradient(135deg, #16a34a, #0d9488); }
        .btn-go:hover:not(:disabled) { filter: brightness(1.12); }
        .quick { display: flex; gap: 6px; flex-wrap: wrap; margin: 10px 0 4px; }
        .quick button { background: #0f172a; border: 1px solid #334155; color: #94a3b8; border-radius: 6px; padding: 4px 9px; font-size: 0.72rem; cursor: pointer; font-family: inherit; transition: .15s; }
        .quick button:hover { border-color: #38bdf8; color: #7dd3fc; }

        .post-list { max-height: 268px; overflow-y: auto; display: flex; flex-direction: column; gap: 6px; margin-top: 10px; padding-left: 4px; }
        .post-list::-webkit-scrollbar { width: 6px; }
        .post-list::-webkit-scrollbar-thumb { background: #334155; border-radius: 3px; }
        .post-row { background: #0f172a; border: 1px solid #334155; border-radius: 8px; padding: 8px 10px; display: flex; gap: 8px; align-items: flex-start; cursor: pointer; transition: .15s; }
        .post-row:hover { border-color: #475569; }
        .post-row.sel { border-color: #38bdf8; background: #0b2436; }
        .post-row input { margin-top: 3px; accent-color: #38bdf8; cursor: pointer; }
        .post-main { flex: 1; min-width: 0; }
        .post-top { display: flex; justify-content: space-between; align-items: center; gap: 6px; }
        .post-id { font-size: 0.78rem; font-weight: bold; color: #f8fafc; font-family: monospace; }
        .post-txt { font-size: 0.74rem; color: #94a3b8; margin-top: 3px; line-height: 1.6; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .tag { font-size: 0.63rem; padding: 2px 6px; border-radius: 999px; white-space: nowrap; font-weight: bold; }
        .tag.new { background: #14532d; color: #86efac; }
        .tag.old { background: #78350f; color: #fcd34d; }
        .tag.media { background: #1e3a8a; color: #bfdbfe; }
        .tag.text { background: #334155; color: #cbd5e1; }
        .dots { display: flex; gap: 3px; margin-top: 6px; }
        .dot { width: 100%; text-align: center; font-size: 0.62rem; font-weight: bold; padding: 2px 0; border-radius: 4px; background: #1e293b; color: #64748b; border: 1px solid #334155; }
        .dot.on { background: #166534; color: #bbf7d0; border-color: #166534; }
        .dot.bad { background: #991b1b; color: #fecaca; border-color: #991b1b; }
        .dot.wait { background: #075985; color: #bae6fd; border-color: #075985; animation: pulse 1.2s infinite; }
        .dot.q { background: #78350f; color: #fde68a; border-color: #78350f; }
        .dot.skip { background: #1e293b; color: #475569; }

        .platforms { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .chk { display: flex; align-items: center; gap: 8px; background: #0f172a; border: 1px solid #334155; border-radius: 8px; padding: 9px 10px; cursor: pointer; font-size: 0.83rem; color: #cbd5e1; transition: .15s; user-select: none; }
        .chk:hover { border-color: #475569; }
        .chk input { accent-color: #22c55e; cursor: pointer; }
        .chk.on { border-color: #22c55e66; background: #0c2a1e; color: #dcfce7; }
        .chk.wide { grid-column: 1 / -1; }
        .chk .swatch { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
        .opts { display: flex; flex-direction: column; gap: 8px; }
        .summary { margin-top: 12px; font-size: 0.76rem; color: #94a3b8; background: #0f172a; border: 1px solid #334155; border-radius: 8px; padding: 9px 11px; line-height: 1.9; }
        .summary b { color: #7dd3fc; }
        .warnbox { margin-top: 10px; background: #451a03; border: 1px solid #b45309; color: #fcd34d; border-radius: 8px; padding: 10px 12px; font-size: 0.76rem; line-height: 1.8; }
        .force-result { margin-top: 12px; display: flex; flex-direction: column; gap: 6px; }
        .res-line { font-size: 0.76rem; padding: 7px 10px; border-radius: 8px; background: #0f172a; border: 1px solid #334155; color: #cbd5e1; }
        .res-line.ok { border-color: #16653466; }
        .res-line.bad { border-color: #991b1b66; }
        .spin { display: inline-block; width: 12px; height: 12px; border: 2px solid #7dd3fc44; border-top-color: #7dd3fc; border-radius: 50%; animation: sp .8s linear infinite; vertical-align: -2px; margin-left: 6px; }
        @keyframes sp { to { transform: rotate(360deg); } }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div>
            <h1>همگام‌سازی خودکار کانال‌ها</h1>
            <small style="color: #94a3b8;">پلتفرم‌ها: بله، روبیکا، سروش‌پلاس، آیگپ</small>
        </div>
        <div class="header-actions">
            <button id="queueBtn" class="btn" style="background:#0d9488" onclick="startBackgroundSync('main')">اجرای صف پس‌زمینه (همهٔ پست‌های جدید)</button>
            <button id="startBtn" class="btn" onclick="startSync()">اجرای دستی (۵ پست اصلی)</button>
            <button id="testBtn" class="btn" style="background:#7c3aed" onclick="startTestSync()">تست کانال‌های قبلی</button>
        </div>
    </div>

    <div class="layout">
      <div class="main-col">
        <div class="progress-box">
            <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                <span id="statusText">در انتظار شروع...</span>
                <span id="percentText">0%</span>
            </div>
            <div class="progress-bar-bg">
                <div id="progressFill" class="progress-bar-fill"></div>
            </div>
        </div>

        <div class="cards-list" id="cardsList"></div>

        <div class="console" id="consoleLogs">
            <p>[آماده] برای شروع روی دکمه بالا کلیک کنید.</p>
        </div>
      </div>

      <aside class="side-col">
        <div class="panel">
            <div class="panel-head">
                <h2>بررسی و شروع همگام‌سازی</h2>
                <p>ارسال <b>اجباری</b> پست‌های آخر کانال — بدون توجه به اینکه قبلاً رفته‌اند یا نه، با انتخاب مقصدها.</p>
            </div>
            <div class="panel-body">

                <div class="section-title">۱) چند پست آخر؟</div>
                <div class="row">
                    <input type="number" id="postCount" min="1" max="50" value="5" title="تعداد پست‌های آخر کانال ایتا">
                    <button class="btn btn-ghost" id="reviewBtn" onclick="reviewPosts()">بررسی پست‌ها</button>
                </div>
                <div class="hint" id="reviewHint">کانال ایتا خوانده می‌شود و فهرست پست‌ها با وضعیت «قبلاً رفته / نرفته» نمایش داده می‌شود.</div>

                <div class="quick" id="quickSelect" style="display:none;">
                    <button type="button" onclick="selAll(true)">انتخاب همه</button>
                    <button type="button" onclick="selAll(false)">هیچ</button>
                    <button type="button" onclick="selOnlyNew()">فقط نرفته‌ها</button>
                    <button type="button" onclick="selInvert()">معکوس</button>
                </div>
                <div class="post-list" id="postList"></div>

                <div class="section-title">۲) به کدام پیام‌رسان‌ها برود؟</div>
                <div class="platforms">
                    <label class="chk on" data-p="bale"><input type="checkbox" id="p_bale" checked onchange="syncPlatformUI()"><span class="swatch" style="background:#22c55e"></span><span>بله</span></label>
                    <label class="chk on" data-p="rubika"><input type="checkbox" id="p_rubika" checked onchange="syncPlatformUI()"><span class="swatch" style="background:#f59e0b"></span><span>روبیکا</span></label>
                    <label class="chk on" data-p="soroush"><input type="checkbox" id="p_soroush" checked onchange="syncPlatformUI()"><span class="swatch" style="background:#38bdf8"></span><span>سروش‌پلاس</span></label>
                    <label class="chk on" data-p="igap"><input type="checkbox" id="p_igap" checked onchange="syncPlatformUI()"><span class="swatch" style="background:#a78bfa"></span><span>آی‌گپ</span></label>
                </div>

                <div class="section-title">۳) گزینه‌ها</div>
                <div class="opts">
                    <label class="chk wide"><input type="checkbox" id="optMedia" checked><span>رسانهٔ پست هم ارسال شود</span></label>
                    <label class="chk wide"><input type="checkbox" id="optAdvance"><span>«آخرین پست دیده‌شده» جلو برود (تا این پست‌ها دوباره خودکار نیایند)</span></label>
                    <div class="row space">
                        <label class="lbl inline" for="optGap">فاصلهٔ بین پست‌ها (ثانیه)</label>
                        <input type="number" id="optGap" min="0" max="120" value="<?= SYNC_GAP_SEC ?>">
                    </div>
                </div>

                <div class="summary" id="panelSummary">
                    انتخاب: <b>۰ پست</b> — مقصدها: <b>—</b>
                </div>
                <div class="warnbox" id="dupWarn" style="display:none;"></div>

                <button class="btn btn-go btn-block" id="forceBtn" onclick="runForcedSync()" disabled>شروع همگام‌سازی اجباری</button>

                <div id="forceProgress" style="display:none;">
                    <div class="progress-bar-bg" style="margin-top:14px;">
                        <div id="forceFill" class="progress-bar-fill" style="width:0%"></div>
                    </div>
                    <div class="hint" id="forceStatus" style="margin-top:8px;"></div>
                </div>
                <div class="force-result" id="forceResult"></div>
            </div>
        </div>
      </aside>
    </div>
</div>

<script>
    const SECURITY_KEY = <?= json_encode(SECURITY_KEY, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const MEDIA_MAX_RETRY_TXT = <?= json_encode((string)MEDIA_MAX_RETRY, JSON_UNESCAPED_UNICODE) ?>;
    const apiUrl = (action, params = {}) => {
        const q = new URLSearchParams({ action, key: SECURITY_KEY, ...params });
        return `sync_manual.php?${q.toString()}`;
    };
    const escapeHtml = (s) => String(s ?? '').replace(/[&<>"']/g, ch => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[ch]));
    let pendingMessages = [];
    let reportList = [];

    function log(msg, color = '#4ade80') {
        const c = document.getElementById('consoleLogs');
        const p = document.createElement('p');
        p.style.color = color;
        p.innerText = `[${new Date().toLocaleTimeString('fa-IR')}] ${msg}`;
        c.appendChild(p);
        c.scrollTop = c.scrollHeight;
    }

    let queuePollTimer = null;
    let bgSummaryShown = false;

    async function startBackgroundSync(profile = 'main') {
        const btn = document.getElementById('queueBtn');
        btn.disabled = true;
        bgSummaryShown = false;
        document.getElementById('statusText').innerText = 'در حال افزودن پست‌های جدید به صف پس‌زمینه...';
        log(`شروع صف پس‌زمینه (${profile === 'test' ? 'تست' : 'اصلی'}): همهٔ پست‌های جدید روی سرور، با هم و بدون مکث پردازش می‌شوند؛ لازم نیست مرورگر باز بماند.`, '#7dd3fc');
        try {
            const res = await fetch(apiUrl('background_sync'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ profile, maxPosts: 50 })
            });
            const data = await res.json();
            if (!data.success) {
                log('❌ صف پس‌زمینه شروع نشد: ' + (data.message || data.error || 'خطای نامشخص'), '#f87171');
                if (Array.isArray(data.attempts) && data.attempts.length) {
                    log('   باینری‌های آزموده‌شده: ' + data.attempts.join(' | '), '#fbbf24');
                }
                btn.disabled = false;
                return;
            }
            log((data.alreadyRunning ? 'صف از قبل در حال اجراست' : 'صف پس‌زمینه شروع شد')
                + ` — PID: ${data.pid || '؟'} — PHP: ${data.phpBin || ''}`, '#4ade80');
            document.getElementById('cardsList').innerHTML = '';
            document.getElementById('progressFill').style.width = '0%';
            document.getElementById('percentText').innerText = '0%';
            document.getElementById('statusText').innerText = 'صف پس‌زمینه فعال است؛ پیشرفت از سرور خوانده می‌شود.';
            window.__bgRenderedIds = '';
            pollQueueStatus(true);
        } catch (e) {
            log('❌ خطای شروع صف پس‌زمینه: ' + e.message, '#f87171');
            btn.disabled = false;
        }
    }

    // رندر پیشرفت زندهٔ صف پس‌زمینه از روی background_progress.json
    function renderBackgroundProgress(p) {
        if (!p) return;
        const postsArr = Object.values(p.posts || {});
        const finals = ['ok', 'partial', 'failed', 'deferred', 'delivered_before'];
        if (postsArr.length) {
            const ids = postsArr.map(x => x.id).join(',');
            if (window.__bgRenderedIds !== ids) {
                window.__bgRenderedIds = ids;
                renderCards(postsArr.map(x => ({ id: x.id, text: x.text, mediaType: x.mediaType })));
            }
            postsArr.forEach(x => {
                const cell = (pl) => x[pl] || { ok: null, info: 'SKIPPED' };
                ['bale', 'rubika', 'soroush', 'igap'].forEach(pl => {
                    const c = cell(pl);
                    const b = document.getElementById(`${pl}-${x.id}`);
                    if (!b) return;
                    const waiting = !finals.includes(x.status) && c.ok === null && String(c.info || '') === 'QUEUED';
                    if (waiting) { b.className = 'badge loading'; b.style.background = ''; b.innerText = ({bale:'بله',rubika:'روبیکا',soroush:'سروش',igap:'آیگپ'})[pl] + '…'; b.title = 'در صف این پلتفرم'; }
                    else updateBadge(`${pl}-${x.id}`, c.ok, ({bale:'بله',rubika:'روبیکا',soroush:'سروش',igap:'آیگپ'})[pl], c.info);
                });
            });
            const done = postsArr.filter(x => finals.includes(x.status)).length;
            const pct = Math.round((done / postsArr.length) * 100);
            document.getElementById('progressFill').style.width = `${pct}%`;
            document.getElementById('percentText').innerText = `${pct}%`;
            if (p.state === 'running') {
                document.getElementById('statusText').innerText =
                    `صف پس‌زمینه: ${faNum(done)} از ${faNum(postsArr.length)} پست (${p.phase || '...'})`;
            }
        } else if (p.state === 'running') {
            document.getElementById('statusText').innerText = 'صف پس‌زمینه: ' + (p.message || p.phase || 'در حال آماده‌سازی…');
        }

        if ((p.state === 'done' || p.state === 'error') && !bgSummaryShown) {
            bgSummaryShown = true;
            const s = p.summary || {};
            document.getElementById('progressFill').style.width = '100%';
            document.getElementById('percentText').innerText = '100%';
            document.getElementById('statusText').innerText = p.state === 'error'
                ? ('صف پس‌زمینه با خطا متوقف شد: ' + (p.message || ''))
                : (p.message || 'صف پس‌زمینه تمام شد.');
            log(p.state === 'error' ? `❌ صف پس‌زمینه: ${p.message || 'خطا'}` : `■ پایان صف پس‌زمینه — کامل: ${faNum(s.delivered ?? 0)}، ناقص: ${faNum(s.partial ?? 0)}، ناموفق: ${faNum(s.failed ?? 0)}، تعویق: ${faNum(s.deferred ?? 0)} (last_msg_id → ${faNum(s.advancedTo ?? 0)})`,
                p.state === 'error' ? '#f87171' : '#4ade80');
        }
    }

    async function pollQueueStatus(force = false) {
        if (queuePollTimer && !force) return;
        const tick = async () => {
            try {
                const res = await fetch(apiUrl('queue_status'));
                const data = await res.json();
                if (!data.success) return false;
                renderBackgroundProgress(data.progress);
                const qBtn = document.getElementById('queueBtn');
                if (qBtn) qBtn.disabled = !!data.running;
                // آخرین خط لاگ launcher (برای خطاهای محیطی مثل PHP غلط)
                const lines = String(data.launcherLog || '').trim().split(/\n/).filter(Boolean).slice(-2);
                if (lines.length) {
                    const last = lines[lines.length - 1];
                    if (window.__lastQueueLine !== last) {
                        window.__lastQueueLine = last;
                        log('صف: ' + last, data.running ? '#7dd3fc' : '#4ade80');
                    }
                }
                if (!data.running) {
                    // اگر worker مرده ولی progress هنوز running است (کرش سخت):
                    if (data.progress && data.progress.state === 'running' && !bgSummaryShown) {
                        bgSummaryShown = true;
                        document.getElementById('statusText').innerText = 'پردازش صف پس‌زمینه ناتمام متوقف شد؛ logs/background_sync.log را ببینید.';
                        log('⚠️ فرایند صف پس‌زمینه بدون تکمیل پیشرفت متوقف شد؛ لاگ سرور را بررسی کنید.', '#fbbf24');
                    }
                    clearInterval(queuePollTimer);
                    queuePollTimer = null;
                    document.getElementById('queueBtn').disabled = false;
                }
                return !!data.running;
            } catch (e) {
                log('خواندن وضعیت صف ناموفق بود: ' + e.message, '#fbbf24');
                return false;
            }
        };
        const running = await tick();
        if (running && !queuePollTimer) queuePollTimer = setInterval(tick, 2500);
    }

    async function startSync() {
        return processRecentManual('main');
    }

    async function startTestSync() {
        return processRecentManual('test');
    }

    async function processRecentManual(profile = 'main') {
        const isTest = profile === 'test';
        const btn = document.getElementById(isTest ? 'testBtn' : 'startBtn');
        document.getElementById('startBtn').disabled = true;
        document.getElementById('testBtn').disabled = true;
        document.getElementById('queueBtn').disabled = true;
        document.getElementById('cardsList').innerHTML = '';
        document.getElementById('progressFill').style.width = '0%';
        document.getElementById('percentText').innerText = '0%';
        document.getElementById('statusText').innerText = `در حال دریافت ۵ پست آخر برای کانال‌های ${isTest ? 'تست' : 'اصلی'}...`;
        log(`خواندن ۵ پست آخر ایتا برای ارسال مرحله‌ای به کانال‌های ${isTest ? 'تست/قبلی' : 'اصلی'}...`, '#7dd3fc');

        try {
            const listRes = await fetch(apiUrl('list_posts', { limit: 5 }));
            const listData = await listRes.json();
            if (!listData.success) {
                log('خطا در خواندن پست‌ها: ' + (listData.message || listData.error || 'نامشخص'), '#f87171');
                return;
            }

            pendingMessages = (listData.posts || []).slice().sort((a, b) => a.id - b.id).map(p => ({
                id: p.id,
                text: p.text || '',
                mediaUrl: p.mediaUrl || null,
                mediaType: p.mediaType || null,
                fileName: p.fileName || 'file.bin',
                profile,
                advance: !isTest,
            }));
            if (!pendingMessages.length) {
                log('پستی برای ارسال پیدا نشد.', '#fbbf24');
                return;
            }

            renderCards(pendingMessages);
            reportList = [];
            let stoppedEarly = false;
            const total = pendingMessages.length;

            for (let i = 0; i < total; i++) {
                const m = pendingMessages[i];
                const card = document.getElementById(`card-${m.id}`);
                if (card) card.classList.add('active');
                ['bale', 'rubika', 'soroush', 'igap'].forEach(p => {
                    const b = document.getElementById(`${p}-${m.id}`);
                    if (b) { b.className = 'badge loading'; b.innerText = 'ارسال...'; b.style.background = ''; }
                });

                const percent = Math.round((i / total) * 100);
                document.getElementById('progressFill').style.width = `${percent}%`;
                document.getElementById('percentText').innerText = `${percent}%`;
                document.getElementById('statusText').innerText = `ارسال پست ${faNum(m.id)} به کانال‌های ${isTest ? 'تست' : 'اصلی'} (${faNum(i + 1)} از ${faNum(total)})...`;
                log(`شروع ارسال پست ${faNum(m.id)} (${isTest ? 'تست' : 'اصلی'})...`, '#e2e8f0');

                try {
                    const res = await fetch(apiUrl('sync_single'), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(m)
                    });
                    const result = await res.json();

                    if (result.deferred) {
                        ['bale', 'rubika', 'soroush', 'igap'].forEach(p => {
                            const b = document.getElementById(`${p}-${m.id}`);
                            if (b) { b.className = 'badge fail'; b.style.background = '#78350f'; b.innerText = 'تعویق'; b.title = result.message || ''; }
                        });
                        log(`⏸ پست ${m.id} منتشر نشد: ${result.message || result.reason || 'رسانه دانلود نشد'}`, '#fbbf24');
                        stoppedEarly = true;
                        if (card) card.classList.remove('active');
                        break;
                    }

                    if (result.success === false) {
                        ['bale', 'rubika', 'soroush', 'igap'].forEach(p => {
                            const b = document.getElementById(`${p}-${m.id}`);
                            if (b) { b.className = 'badge fail'; b.innerText = 'خطا'; b.title = result.message || result.error || ''; }
                        });
                        log(`❌ پست ${m.id}: ${result.message || result.error || 'خطای نامشخص'}`, '#f87171');
                        stoppedEarly = true;
                        if (card) card.classList.remove('active');
                        break;
                    }

                    updateBadge(`bale-${m.id}`, result.bale?.ok, 'بله', result.bale?.info);
                    updateBadge(`rubika-${m.id}`, result.rubika?.ok, 'روبیکا', result.rubika?.info);
                    updateBadge(`soroush-${m.id}`, result.soroush?.ok, 'سروش', result.soroush?.info);
                    updateBadge(`igap-${m.id}`, result.igap?.ok, 'آیگپ', result.igap?.info);

                    const mark = r => (r?.ok === true ? '✅' : (r?.ok === null || /\b(SKIPPED|SKIP|NO_CONTENT)\b/.test(r?.info || '') ? '—' : '❌'));
                    reportList.push(`🔹 پست ${m.id} (${isTest ? 'تست' : 'اصلی'}): بله ${mark(result.bale)} | روبیکا ${mark(result.rubika)} | سروش ${mark(result.soroush)} | آی‌گپ ${mark(result.igap)}`);
                    log(`#${m.id}: بله${mark(result.bale)} روبیکا${mark(result.rubika)} سروش${mark(result.soroush)} آی‌گپ${mark(result.igap)}`, '#4ade80');
                    if (result.soroush?.ok === false) log('   سروش: ' + (result.soroush.info || ''), '#fca5a5');
                    if (result.igap?.ok === false) log('   آی‌گپ: ' + (result.igap.info || ''), '#fca5a5');
                } catch (err) {
                    log(`خطا در درخواست پست ${m.id}: ${err.message}`, '#f87171');
                    stoppedEarly = true;
                    if (card) card.classList.remove('active');
                    break;
                }
                if (card) card.classList.remove('active');
            }

            document.getElementById('progressFill').style.width = '100%';
            document.getElementById('percentText').innerText = '100%';
            document.getElementById('statusText').innerText = stoppedEarly
                ? 'عملیات متوقف شد؛ مورد خطا را بررسی کنید.'
                : `پایان ارسال ۵ پست آخر به کانال‌های ${isTest ? 'تست' : 'اصلی'}`;

            if (reportList.length) {
                fetch(apiUrl('send_report'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ report: reportList })
                }).catch(() => {});
            }
        } catch (e) {
            log('خطای ارتباط با سرور: ' + e.message, '#f87171');
        } finally {
            document.getElementById('startBtn').disabled = false;
            document.getElementById('testBtn').disabled = false;
            document.getElementById('queueBtn').disabled = false;
        }
    }

    function renderCards(msgs) {
        const list = document.getElementById('cardsList');
        list.innerHTML = '';
        msgs.forEach(m => {
            const desc = m.text ? m.text.substring(0, 45) + '...' : `[${m.mediaType || 'رسانه'}]`;
            const div = document.createElement('div');
            div.className = 'card';
            div.id = `card-${m.id}`;
            div.innerHTML = `
                <div class="card-info">
                    <span class="card-id">پست ID ${escapeHtml(m.id)} (${escapeHtml(m.mediaType ? m.mediaType : 'متن')})</span>
                    <span class="card-desc">${escapeHtml(desc)}</span>
                </div>
                <div class="badges">
                    <span class="badge" id="bale-${m.id}">بله</span>
                    <span class="badge" id="rubika-${m.id}">روبیکا</span>
                    <span class="badge" id="soroush-${m.id}">سروش</span>
                    <span class="badge" id="igap-${m.id}">آیگپ</span>
                </div>
            `;
            list.appendChild(div);
        });
    }

    async function processQueue() {
        const total = pendingMessages.length;
        reportList = [];
        let stoppedEarly = false;

        for (let i = 0; i < total; i++) {
            const m = pendingMessages[i];
            const card = document.getElementById(`card-${m.id}`);
            card.classList.add('active');

            ['bale', 'rubika', 'soroush', 'igap'].forEach(p => {
                const b = document.getElementById(`${p}-${m.id}`);
                b.className = 'badge loading';
                b.innerText = 'ارسال...';
            });

            const percent = Math.round(((i) / total) * 100);
            document.getElementById('progressFill').style.width = `${percent}%`;
            document.getElementById('percentText').innerText = `${percent}%`;
            document.getElementById('statusText').innerText = `در حال ارسال پیام ID ${m.id} (${i + 1} از ${total})...`;

            log(`شروع ارسال پست ID ${m.id} به هر ۴ پلتفرم...`, '#e2e8f0');

            try {
                const res = await fetch(apiUrl('sync_single'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(m)
                });
                const result = await res.json();

                // پست تعویق‌شده: رسانه دانلود نشد، چیزی منتشر نشده است
                if (result.deferred) {
                    ['bale', 'rubika', 'soroush', 'igap'].forEach(p => {
                        const b = document.getElementById(`${p}-${m.id}`);
                        if (b) { b.className = 'badge fail'; b.style.background = '#78350f'; b.innerText = 'تعویق'; b.title = result.message || ''; }
                    });
                    log(`⏸ پست ${m.id} منتشر نشد: ${result.message || result.reason || 'رسانه دانلود نشد'}`, '#fbbf24');
                    reportList.push(`⏸ پست ${m.id}: رسانه دانلود نشد (${result.reason || '?'}) — منتشر نشد؛ تلاش ${result.attempt || 1}/${MEDIA_MAX_RETRY_TXT}`);
                    document.getElementById('statusText').innerText = 'توقف صف: یک پست برای دریافت لینک تازهٔ رسانه به چرخهٔ بعد موکول شد.';
                    stoppedEarly = true;
                    card.classList.remove('active');
                    break;
                }

                if (result.success === false) {
                    ['bale', 'rubika', 'soroush', 'igap'].forEach(p => {
                        const b = document.getElementById(`${p}-${m.id}`);
                        if (b) { b.className = 'badge fail'; b.innerText = 'خطا'; }
                    });
                    log(`❌ پست ${m.id}: ${result.message || result.error || 'خطای نامشخص'}`, '#f87171');
                    card.classList.remove('active');
                    if (result.stopQueue) {
                        document.getElementById('statusText').innerText = 'توقف صف: خطا را رفع کنید تا پست‌های بعدی باعث جاافتادن این پست نشوند.';
                        stoppedEarly = true;
                        break;
                    }
                    continue;
                }

                updateBadge(`bale-${m.id}`, result.bale?.ok, 'بله', result.bale?.info);
                updateBadge(`rubika-${m.id}`, result.rubika?.ok, 'روبیکا', result.rubika?.info);
                updateBadge(`soroush-${m.id}`, result.soroush?.ok, 'سروش', result.soroush?.info);
                updateBadge(`igap-${m.id}`, result.igap?.ok, 'آیگپ', result.igap?.info);

                const mediaDesc = m.mediaType ? m.mediaType : 'متن';
                const mediaState = result.media
                    ? (result.media.ok ? '✅' : `❌ ${result.media.info}`)
                    : (m.mediaUrl ? '⚠️ نامشخص' : '—');
                const mark = r => (r?.ok === true ? '✅' : (r?.ok === null || /\b(SKIPPED|SKIP|NO_CONTENT)\b/.test(r?.info || '') ? '—' : '❌'));
                reportList.push(`🔹 پست ${m.id} [${mediaDesc}]:\n  بله: ${mark(result.bale)} | روبیکا: ${mark(result.rubika)}\n  سروش: ${mark(result.soroush)} | آیگپ: ${mark(result.igap)}\n  رسانه: ${mediaState}`);

                const sInfo = result.soroush?.info || '';
                const gInfo = result.igap?.info || '';
                const word = (ok, info) => (ok === true ? 'OK'
                    : (ok === null || /\b(SKIPPED|SKIP|NO_CONTENT)\b/.test(info) ? 'رد شد'
                    : (/UNVERIFIED/i.test(info) ? 'تأیید نشد' : 'خطا')));
                log(`پست ID ${m.id} پردازش شد. (سروش: ${word(result.soroush?.ok, sInfo)} | آیگپ: ${word(result.igap?.ok, gInfo)})`);
                if (result.media && result.media.ok === false) {
                    log(`⚠️ رسانهٔ پست ${m.id} منتشر نشد: ${result.media.info}`, '#fbbf24');
                }
                if (!result.soroush?.ok && result.soroush?.ok !== null) log(`   سروش: ${sInfo}`, '#fca5a5');
                if (!result.igap?.ok && result.igap?.ok !== null) log(`   آیگپ: ${gInfo}`, '#fca5a5');
                if (!window.__depsWarned && /NODE_DEPS_MISSING|MODULE_NOT_FOUND|Cannot find (?:module|package)|playwright/i.test(`${sInfo} ${gInfo}`)) {
                    window.__depsWarned = true;
                    log('🔴 وابستگی Node (playwright) روی سرور نیست؛ احتمالاً با git checkout پاک شده.', '#fca5a5');
                    log('   برای بازیابی، روی سرور اجرا کنید:  cd <?= htmlspecialchars(SYNC_APP_DIR, ENT_QUOTES) ?> && bash restore_runtime.sh', '#fca5a5');
                }

            } catch (err) {
                log(`خطا در درخواست پست ${m.id}: ${err.message}`, '#f87171');
                ['bale', 'rubika', 'soroush', 'igap'].forEach(p => {
                    const b = document.getElementById(`${p}-${m.id}`);
                    b.className = 'badge fail';
                    b.innerText = 'خطا';
                });
                stoppedEarly = true;
                card.classList.remove('active');
                break;
            }

            card.classList.remove('active');
        }

        document.getElementById('progressFill').style.width = '100%';
        document.getElementById('percentText').innerText = '100%';
        document.getElementById('statusText').innerText = stoppedEarly
            ? 'عملیات متوقف شد؛ پس از رفع مورد قرمز دوباره اجرا کنید.'
            : 'تمام پیام‌های قابل پردازش توزیع شدند.';
        log(stoppedEarly ? 'صف برای جلوگیری از جاافتادن پست‌های بعدی متوقف شد. ارسال گزارش مدیریتی...' : 'همه پیام‌ها پردازش شدند. ارسال گزارش مدیریتی به بله...', '#38bdf8');

        try {
            const reportRes = await fetch(apiUrl('send_report'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ report: reportList })
            });
            const reportJson = await reportRes.json().catch(() => ({}));
            if (reportJson.success) log('گزارش نهایی ارسال شد.', '#4ade80');
            else log('ارسال گزارش نهایی ناموفق بود: ' + (reportJson.error || reportRes.status), '#fbbf24');
        } catch (e) {
            log('ارسال گزارش نهایی ناموفق بود: ' + e.message, '#fbbf24');
        }
        document.getElementById('startBtn').disabled = false;
    }

    // چهار حالت بصری: ✓ موفق تأییدشده، ؟ ارسال‌شده ولی تأیید‌نشده (UNVERIFIED)،
    //                 — ردشده (فیلتر only / SKIPPED)، ✕ شکست
    function updateBadge(elId, isOk, name, info) {
        const el = document.getElementById(elId);
        if (!el) return;
        const detail = typeof info === 'string' ? info : '';
        if (detail) el.title = detail;
        const unverified = /UNVERIFIED|SEND_NOT_VERIFIED/i.test(detail);
        if (isOk === null || /\b(SKIPPED|SKIP|NO_CONTENT)\b/.test(detail)) {
            el.className = 'badge';
            el.style.background = '#334155';
            el.innerText = `${name} —`;
            return;
        }
        if (isOk) {
            el.className = 'badge ok';
            el.innerText = `${name} ✓`;
        } else if (unverified) {
            el.className = 'badge fail';
            el.style.background = '#78350f';
            el.innerText = `${name} ؟`;
        } else {
            el.className = 'badge fail';
            el.innerText = `${name} ✕`;
        }
    }

    /* ==========================================================
       پنل کناری — «بررسی و شروع همگام‌سازی» (ارسال اجباری)
       ========================================================== */
    const PLATFORMS = [
        { id: 'bale',    fa: 'بله',      dot: 'ب' },
        { id: 'rubika',  fa: 'روبیکا',   dot: 'ر' },
        { id: 'soroush', fa: 'سروش',     dot: 'س' },
        { id: 'igap',    fa: 'آی‌گپ',    dot: 'آ' },
    ];
    let forcePosts = [];
    let forceBusy = false;

    const $ = id => document.getElementById(id);
    const sleep = ms => new Promise(r => setTimeout(r, ms));
    const faNum = n => String(n).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);

    async function reviewPosts() {
        if (forceBusy) return;
        const limit = Math.min(50, Math.max(1, parseInt($('postCount').value, 10) || 5));
        $('postCount').value = limit;
        const btn = $('reviewBtn');
        btn.disabled = true;
        btn.innerHTML = 'در حال خواندن ایتا<span class="spin"></span>';
        $('reviewHint').innerText = 'در حال خواندن کانال ایتا…';
        log(`پنل اجباری: خواندن ${faNum(limit)} پست آخر کانال ایتا…`, '#7dd3fc');

        try {
            const res = await fetch(apiUrl('list_posts', { limit }));
            const data = await res.json();
            if (!data.success) {
                const msg = data.message || data.error || 'خطای نامشخص';
                $('reviewHint').innerText = '❌ ' + msg;
                log('پنل اجباری: ' + msg, '#f87171');
                return;
            }
            forcePosts = data.posts || [];
            if (!forcePosts.length) {
                $('reviewHint').innerText = 'پستی در نمای وب ایتا پیدا نشد.';
                log('پنل اجباری: پستی پیدا نشد.', '#fbbf24');
                return;
            }
            renderForceList();
            $('quickSelect').style.display = 'flex';
            const news = forcePosts.filter(p => !p.sentBefore).length;
            $('reviewHint').innerHTML =
                `${faNum(forcePosts.length)} پست خوانده شد — <b style="color:#86efac">${faNum(news)}</b> نرفته، ` +
                `<b style="color:#fcd34d">${faNum(forcePosts.length - news)}</b> قبلاً رفته. ` +
                `آخرین پست دیده‌شده در پایگاه داده: <b style="color:#7dd3fc">${faNum(data.lastSeenId)}</b>`;
            log(`پنل اجباری: ${faNum(forcePosts.length)} پست یافت شد (lastSeenId=${data.lastSeenId}).`, '#7dd3fc');
        } catch (e) {
            $('reviewHint').innerText = '❌ خطای ارتباط با سرور: ' + e.message;
            log('پنل اجباری: ' + e.message, '#f87171');
        } finally {
            btn.disabled = false;
            btn.innerText = 'بررسی پست‌ها';
        }
    }

    function renderForceList() {
        const box = $('postList');
        box.innerHTML = '';
        forcePosts.slice().reverse().forEach(p => {          // جدیدترین بالا
            const row = document.createElement('label');
            row.className = 'post-row';
            row.dataset.id = p.id;
            const mediaTag = p.hasMedia
                ? `<span class="tag media">${p.mediaType === 'video' ? '🎬' : p.mediaType === 'audio' ? '🎧' : p.mediaType === 'document' ? '📎' : '🖼'} ${p.mediaType || 'media'}</span>`
                : '<span class="tag text">متن</span>';
            const stateTag = p.sentBefore
                ? '<span class="tag old">قبلاً رفته</span>'
                : '<span class="tag new">نرفته</span>';
            const safeId = Number.parseInt(p.id, 10) || 0;
            const safeText = escapeHtml(p.text || '');
            const safePreview = escapeHtml(p.preview || '(بدون متن)');
            row.innerHTML =
                `<input type="checkbox" data-id="${safeId}" ${p.sentBefore ? '' : 'checked'}>` +
                `<div class="post-main">` +
                    `<div class="post-top"><span class="post-id">#${faNum(safeId)}</span><span>${stateTag} ${mediaTag}</span></div>` +
                    `<div class="post-txt" title="${safeText}">${safePreview}</div>` +
                    `<div class="dots">` + PLATFORMS.map(x => `<span class="dot skip" data-dot="${x.id}" title="${escapeHtml(x.fa)}">${escapeHtml(x.dot)}</span>`).join('') + `</div>` +
                `</div>`;
            row.querySelector('input').addEventListener('change', () => { row.classList.toggle('sel', row.querySelector('input').checked); updateSummary(); });
            row.classList.toggle('sel', row.querySelector('input').checked);
            box.appendChild(row);
        });
        updateSummary();
    }

    function eachCheckbox(fn) {
        document.querySelectorAll('#postList input[type="checkbox"]').forEach(fn);
    }
    function selAll(v)    { eachCheckbox(cb => { cb.checked = v; cb.dispatchEvent(new Event('change')); }); }
    function selOnlyNew() { forcePosts.forEach(p => { const cb = document.querySelector(`#postList input[data-id="${p.id}"]`); if (cb) { cb.checked = !p.sentBefore; cb.dispatchEvent(new Event('change')); } }); }
    function selInvert()  { eachCheckbox(cb => { cb.checked = !cb.checked; cb.dispatchEvent(new Event('change')); }); }

    function selectedIds() {
        return Array.from(document.querySelectorAll('#postList input[type="checkbox"]:checked'))
                    .map(cb => parseInt(cb.dataset.id, 10)).filter(n => n > 0).sort((a, b) => a - b);
    }
    function selectedPlatforms() { return PLATFORMS.filter(p => $(`p_${p.id}`).checked).map(p => p.id); }

    function syncPlatformUI() {
        PLATFORMS.forEach(p => {
            const on = $(`p_${p.id}`).checked;
            const lbl = document.querySelector(`.chk[data-p="${p.id}"]`);
            if (lbl) lbl.classList.toggle('on', on);
        });
        updateSummary();
    }

    function updateSummary() {
        const ids = selectedIds(), pf = selectedPlatforms();
        const dupRisk = ids.some(id => (forcePosts.find(p => p.id === id) || {}).sentBefore)
                        && (pf.includes('bale') || pf.includes('rubika'));
        $('panelSummary').innerHTML =
            `انتخاب: <b>${faNum(ids.length)} پست</b> — مقصدها: <b>${pf.length ? pf.map(x => (PLATFORMS.find(p => p.id === x) || {}).fa).join('، ') : '—'}</b>` +
            `<br>رسانه: <b>${$('optMedia').checked ? 'با رسانه' : 'فقط متن'}</b> — ` +
            `advance: <b>${$('optAdvance').checked ? 'بله' : 'خیر'}</b> — ` +
            `فاصله: <b>${faNum($('optGap').value || 0)} ثانیه</b>`;
        const warn = $('dupWarn');
        if (dupRisk) {
            warn.style.display = 'block';
            warn.innerHTML = '⚠️ بعضی پست‌های انتخابی <b>قبلاً ارسال شده‌اند</b>. ارسال دوباره به <b>بله یا روبیکا</b> یعنی ' +
                             '<b>پست تکراری</b> در آن کانال‌ها. اگر فقط می‌خواهید سروش/آی‌گپ جبران شود، تیک بله و روبیکا را بردارید.';
        } else {
            warn.style.display = 'none';
        }
        $('forceBtn').disabled = !(ids.length && pf.length) || forceBusy;
        return dupRisk;
    }

    function setDot(id, pf, cls, title) {
        const el = document.querySelector(`#postList .post-row[data-id="${id}"] .dot[data-dot="${pf}"]`);
        if (!el) return;
        el.className = 'dot ' + cls;
        if (title) el.title = title;
    }

    async function runForcedSync() {
        if (forceBusy) return;
        const ids = selectedIds();
        const only = selectedPlatforms();
        if (!ids.length || !only.length) return;

        const dupRisk = updateSummary();
        if (dupRisk && !confirm('بعضی پست‌های انتخابی قبلاً ارسال شده‌اند و بله/روبیکا هم انتخاب شده است.\n' +
                               'این کار پست تکراری در آن کانال‌ها می‌گذارد.\n\nادامه می‌دهید؟')) {
            log('پنل اجباری: توسط کاربر لغو شد (خطر پست تکراری).', '#fbbf24');
            return;
        }

        forceBusy = true;
        $('forceBtn').disabled = true;
        $('reviewBtn').disabled = true;
        $('forceProgress').style.display = 'block';
        $('forceResult').innerHTML = '';
        const withMedia = $('optMedia').checked;
        const advance = $('optAdvance').checked;
        const gap = Math.max(0, parseInt($('optGap').value, 10) || 0);
        const faOnly = only.map(x => (PLATFORMS.find(p => p.id === x) || {}).fa).join('، ');

        log(`▶ شروع همگام‌سازی اجباری: ${faNum(ids.length)} پست → ${faOnly}${withMedia ? '' : ' (فقط متن)'}`, '#7dd3fc');
        document.getElementById('statusText').innerText = 'همگام‌سازی اجباری در جریان است…';

        let okCount = 0, failCount = 0;
        for (let i = 0; i < ids.length; i++) {
            const id = ids[i];
            const isLast = (i === ids.length - 1);
            $('forceFill').style.width = Math.round((i / ids.length) * 100) + '%';
            $('forceStatus').innerHTML = `پست ${faNum(id)} — ${faNum(i + 1)} از ${faNum(ids.length)}<span class="spin"></span>`;
            PLATFORMS.forEach(p => setDot(id, p.id, only.includes(p.id) ? 'wait' : 'skip', p.fa));

            try {
                const res = await fetch(apiUrl('resend'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ids: [id], only, profile: 'main', advance: advance && isLast, no_media: !withMedia }),
                });
                const data = await res.json();
                if (!data.success) {
                    failCount++;
                    PLATFORMS.forEach(p => setDot(id, p.id, 'bad', data.message || data.error || ''));
                    const m = data.message || data.error || 'خطای نامشخص';
                    $('forceResult').insertAdjacentHTML('beforeend', `<div class="res-line bad">#${faNum(id)} — ${escapeHtml(m)}</div>`);
                    log(`پست ${faNum(id)}: ❌ ${m}`, '#f87171');
                    continue;
                }
                const r = (data.results || [])[0] || {};
                let lineOk = true, parts = [];
                PLATFORMS.forEach(p => {
                    const cell = r[p.id] || {};
                    if (!only.includes(p.id)) { setDot(id, p.id, 'skip', 'رد شد'); return; }
                    const info = String(cell.info || '');
                    if (cell.ok === true) {
                        const unv = /UNVERIFIED/i.test(info);
                        setDot(id, p.id, unv ? 'q' : 'on', unv ? 'ارسال شد ولی تأیید نشد' : 'موفق');
                        parts.push(`${p.fa}${unv ? '؟' : '✓'}`);
                        if (unv) lineOk = false;
                    } else {
                        setDot(id, p.id, 'bad', info);
                        parts.push(`${p.fa}✕`);
                        lineOk = false;
                    }
                });
                const media = r.media || {};
                const mediaTxt = media.info === 'none' ? '' : media.info === 'skipped' ? ' | رسانه: رد شد'
                    : (media.ok ? ' | رسانه ✓' : ` | رسانه ✕ (${media.info})`);
                if (lineOk) okCount++; else failCount++;
                $('forceResult').insertAdjacentHTML('beforeend',
                    `<div class="res-line ${lineOk ? 'ok' : 'bad'}">#${faNum(id)} — ${escapeHtml(parts.join(' '))}${escapeHtml(mediaTxt)}</div>`);
                log(`پست ${faNum(id)}: ${parts.join(' ')}${mediaTxt}`, lineOk ? '#4ade80' : '#fca5a5');
            } catch (e) {
                failCount++;
                PLATFORMS.forEach(p => setDot(id, p.id, 'bad', e.message));
                $('forceResult').insertAdjacentHTML('beforeend', `<div class="res-line bad">#${faNum(id)} — خطای شبکه: ${escapeHtml(e.message)}</div>`);
                log(`پست ${faNum(id)}: ❌ ${e.message}`, '#f87171');
            }

            if (!isLast && gap > 0) await sleep(gap * 1000);
        }

        $('forceFill').style.width = '100%';
        $('forceStatus').innerHTML = `پایان — موفق: <b style="color:#86efac">${faNum(okCount)}</b> | ناموفق: <b style="color:#fca5a5">${faNum(failCount)}</b>` +
            (advance ? ' | last_msg_id جلو رفت' : '');
        log(`■ پایان همگام‌سازی اجباری — موفق: ${faNum(okCount)}، ناموفق: ${faNum(failCount)}`, failCount ? '#fbbf24' : '#4ade80');
        document.getElementById('statusText').innerText = 'پایان همگام‌سازی اجباری';
        forceBusy = false;
        $('reviewBtn').disabled = false;
        updateSummary();
    }

    // همگام‌سازی اولیهٔ UI پنل
    ['optMedia', 'optAdvance', 'optGap'].forEach(id => { const el = $(id); if (el) el.addEventListener('change', updateSummary); });
    syncPlatformUI();
    pollQueueStatus(true);
</script>

</body>
</html>
