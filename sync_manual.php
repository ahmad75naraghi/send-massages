<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tehran');
ini_set('display_errors', '1');
error_reporting(E_ALL);

// ============================================================
//  پیکربندی: همهٔ مقدارها (به‌ویژه توکن‌ها) از فایل .env کنار برنامه
//  خوانده می‌شوند. ساخت .env:  bash setup_env.sh
//  هیچ secret ای در این فایل باقی نمانده است.
// ============================================================
require_once __DIR__ . '/config.php';

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
$dbPath = STATE_DB_PATH;$db = new PDO("sqlite:{$dbPath}");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);$db->exec("CREATE TABLE IF NOT EXISTS sync_state (channel TEXT PRIMARY KEY, last_msg_id INTEGER NOT NULL)");
$db->exec("CREATE TABLE IF NOT EXISTS media_fail (msg_id INTEGER PRIMARY KEY, fails INTEGER NOT NULL DEFAULT 0, last_reason TEXT, updated_at TEXT)");

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
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        CURLOPT_HTTPHEADER     => ['Referer: https://eitaa.com/' . EITAA_CHANNEL_ID]
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
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'res' => json_decode((string)$res, true) ?? $res];
}

function sendToBale(string $text, ?string $file, ?string $type, string $fileName): array {
    if (BALE_BOT_TOKEN === '') {
        return ['code' => 0, 'res' => ['ok' => false, 'description' => envMissingMessage(envMissing(['BALE_BOT_TOKEN']))]];
    }
    $cleanToken = preg_replace('/^bot/i', '', trim(BALE_BOT_TOKEN));
    $base = "https://tapi.bale.ai/bot{$cleanToken}/";
    if ($file and file_exists($file)) {$cFile = new CURLFile($file, '',$fileName);
        $caption = mb_substr($text, 0, 1000);
        $method = match($type) { 'video' => 'sendVideo', 'audio' => 'sendAudio', 'document' => 'sendDocument', default => 'sendPhoto' };
        $param  = match($type) { 'video' => 'video', 'audio' => 'audio', 'document' => 'document', default => 'photo' };
        return callApi($base . $method, ['chat_id' => BALE_CHANNEL_ID, 'caption' =>$caption, $param =>$cFile], true);
    }
    return callApi($base . 'sendMessage', json_encode(['chat_id' => BALE_CHANNEL_ID, 'text' =>$text]), false, ['Content-Type: application/json']);
}

function sendToRubika(string $text, ?string $file, ?string $type, string $fileName): array {
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
                $sendPayload = ['chat_id' => RUBIKA_CHANNEL_ID, 'file_id' => (string)$fileId, 'type' => $rType, 'text' =>$caption, 'file_name' => $fileName];$final = callApi($base . 'sendFile', json_encode($sendPayload), false, ['Content-Type: application/json']);
                if (($final['res']['status'] ?? '') === 'OK') return$final;
            }
        }
        return callApi($base . 'sendMessage', json_encode(['chat_id' => RUBIKA_CHANNEL_ID, 'text' =>$caption . "\n(عدم آپلود فایل روبیکا)"]), false, ['Content-Type: application/json']);
    }
    return callApi($base . 'sendMessage', json_encode(['chat_id' => RUBIKA_CHANNEL_ID, 'text' =>$text]), false, ['Content-Type: application/json']);
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
    if ($nodeBin !== '' && !is_executable($nodeBin) && !file_exists($nodeBin)) {
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
         . 'تأیید:  node -e "require(\'playwright\'); console.log(\'OK\')"';
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
function runUserbot(string $script, string $profileDir, string $channel, string $channelName, string $text, ?string $filePath, ?string $mediaType, array $extraArgs = []): array {
    if (trim($text) === '' and (!$filePath or !file_exists($filePath))) {
        return ['success' => true, 'message' => 'SKIP: محتوایی برای ارسال نیست', 'skipped' => true];
    }

    // پیش‌بررسی: اگر playwright/Node نباشد، به‌جای stack trace خام، پیام روشن بده
    $envProblem = nodeEnvProblem($script);
    if ($envProblem !== '') {
        return ['success' => false, 'code' => 'NODE_DEPS_MISSING', 'message' => $envProblem];
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

    $output = shell_exec($cmd . ' 2>&1');
    @array_map('unlink', glob($profileDir . '/Singleton*') ?: []);

    $result = parseNodeJsonOutput((string)$output);
    $status = $result['status'] ?? null;

    // اگر Node به خاطر ماژول گم‌شده مرده باشد، JSON قرارداد تولید نمی‌شود؛
    // پس خودمان تشخیص می‌دهیم و پیامِ قابل‌اقدام می‌دهیم (نه stack trace خام).
    if ($status !== 'OK' && preg_match("/Cannot find (?:module|package) '([^']+)'/u", (string)$output, $mm)) {
        return [
            'success' => false,
            'code'    => 'NODE_DEPS_MISSING',
            'message' => 'ماژول Node «' . $mm[1] . '» پیدا نشد.'
                       . ' وابستگی‌ها را نصب/بازیابی کنید:  cd ' . SYNC_APP_DIR
                       . ' && git restore --source=e5e4708 --worktree -- node_modules'
                       . '   (یا: npm install --no-audit --no-fund)',
        ];
    }
    if ($status !== 'OK' && str_contains((string)$output, 'MODULE_NOT_FOUND')) {
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
    $raw    = trim((string)$output);

    return [
        'success' => false,
        'code'    => $code !== '' ? $code : 'RUNTIME',
        'message' => ($code !== '' ? '[' . $code . '] ' : '')
                   . ($detail !== '' ? $detail : ($raw !== '' ? mb_substr($raw, -300) : 'Fail'))
                   . $logRef,
    ];
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
function dispatchToPlatforms(array $only, string $text, ?string $localFile, ?string $mediaType, string $fileName): array {
    $all = ['bale', 'rubika', 'soroush', 'igap'];
    $only = array_values(array_unique(array_filter(
        array_map(fn($x) => strtolower(trim((string)$x)), $only),
        fn($x) => in_array($x, $all, true)
    )));
    if ($only === []) {
        $only = $all;
    }

    $out = [];
    foreach ($all as $platform) {
        if (!in_array($platform, $only, true)) {
            $out[$platform] = ['ok' => null, 'info' => 'SKIPPED'];
        }
    }

    if (in_array('bale', $only, true)) {
        $r = sendToBale($text, $localFile, $mediaType, $fileName);
        $out['bale'] = [
            'ok'   => ($r['code'] === 200 and (bool)($r['res']['ok'] ?? false)),
            'info' => $r['code'] ?? 'ERR',
        ];
    }
    if (in_array('rubika', $only, true)) {
        $r = sendToRubika($text, $localFile, $mediaType, $fileName);
        $out['rubika'] = [
            'ok'   => ($r['code'] === 200 and (($r['res']['status'] ?? '') === 'OK')),
            'info' => $r['code'] ?? 'ERR',
        ];
    }
    if (in_array('soroush', $only, true)) {
        $r = sendToSoroush(SOROUSH_CHANNEL_ID, $text, $localFile, $mediaType);
        $out['soroush'] = [
            'ok'   => ($r['success'] === true),
            'info' => $r['message'] ?? 'ERR',
            'code' => $r['code'] ?? '',
        ];
    }
    if (in_array('igap', $only, true)) {
        $r = sendToIgap(IGAP_CHANNEL_ID, $text, $localFile, $mediaType);
        $out['igap'] = [
            'ok'   => ($r['success'] === true),
            'info' => $r['message'] ?? 'ERR',
            'code' => $r['code'] ?? '',
        ];
    }

    return $out;
}

function sendToSoroush(string $channel, string $text = '', ?string $filePath = null, ?string $mediaType = null): array {
    return runUserbot(SOROUSH_SCRIPT, SOROUSH_PROFILE_DIR, $channel, SOROUSH_CHANNEL_NAME, $text, $filePath, $mediaType);
}

function sendToIgap(string $channel, string $text = '', ?string $filePath = null, ?string $mediaType = null): array {
    return runUserbot(IGAP_SCRIPT, IGAP_PROFILE_DIR, $channel, IGAP_CHANNEL_NAME, $text, $filePath, $mediaType, ['item-id' => IGAP_ITEM_ID]);
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
        CURLOPT_TIMEOUT        => 25
    ]);
    $html = curl_exec($ch);
    curl_close($ch);

    if (empty($html)) {
        return ['ok' => false, 'error' => 'عدم دسترسی به ایتا', 'messages' => []];
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
            $mediaUrl = str_starts_with($src, 'http') ? $src : 'https://eitaa.com' .$src;
            $mediaType = 'video';$fileName = 'video.mp4';
        } elseif ($audioNode = $xpath->query(".//audio", $node)->item(0)) {
            if ($src =$audioNode->getAttribute('src')) {
                $mediaUrl = str_starts_with($src, 'http') ? $src : 'https://eitaa.com' .$src;
                $mediaType = 'audio';$fileName = 'audio.mp3';
            }
        } elseif ($photoNode = $xpath->query(".//a[contains(@class, 'etme_widget_message_photo_wrap')]", $node)->item(0)) {
            if (preg_match('/url\(\'?(.*?)\'?\)/', $photoNode->getAttribute('style'),$m)) {
                $mediaUrl = str_starts_with($m[1], 'http') ? $m[1] : 'https://eitaa.com' .$m[1];
                $mediaType = 'image';$fileName = 'photo.jpg';
            }
        } elseif ($docNode = $xpath->query(".//a[contains(@class, 'etme_widget_message_document_wrap')]", $node)->item(0)) {
            if ($href =$docNode->getAttribute('href')) {
                $mediaUrl = str_starts_with($href, 'http') ? $href : 'https://eitaa.com' .$href;
                $mediaType = 'document';
                $tNode =$xpath->query(".//div[contains(@class, 'etme_widget_message_document_title')]", $docNode)->item(0);$fileName = $tNode ? trim($tNode->textContent) : 'document.bin';
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
    $text      =$payload['text'] ?? '';
    $mediaUrl  =$payload['mediaUrl'] ?? null;
    $mediaType =$payload['mediaType'] ?? null;
    $fileName  =$payload['fileName'] ?? 'file.bin';

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
                    'success'  => false,
                    'deferred' => true,
                    'id'       => $msgId,
                    'error'    => 'MEDIA_DOWNLOAD_FAILED',
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
    $sent = dispatchToPlatforms($only, $text, $localFile, $mediaType, (string)$fileName);

    if ($localFile and file_exists($localFile)) {
        @unlink($localFile);
    }

    setLastSeenId($db, EITAA_CHANNEL_ID,$msgId);

    // پستی که منتشر شد (با یا بدون رسانه) دیگر در صف تعویق نمی‌ماند
    clearMediaFail($db, $msgId);

    $mediaOk = true;
    $mediaInfo = 'none';
    if ($mediaUrl) {
        $mediaOk = ($mediaReason === null);
        $mediaInfo = $mediaOk ? 'downloaded' : ('DROPPED_AFTER_' . MEDIA_MAX_RETRY . '_TRIES:' . (string)$mediaReason);
    }

    echo json_encode([
        'success' => true,
        'id'      => $msgId,
        'only'    => $only === [] ? ['bale', 'rubika', 'soroush', 'igap'] : array_values($only),
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
    $reportItems =$payload['report'] ?? [];

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
    $advance = !empty($payload['advance']);

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
        $fileName = (string)($m['fileName'] ?? 'file.bin');

        $localFile = null;
        $mediaReason = null;
        $mediaInfo = 'none';
        if ($mediaUrl) {
            $localFile = downloadMedia((string)$mediaUrl, $fileName !== '' ? $fileName : 'file.bin', $mediaReason);
            if (!$localFile) {
                sleep(2);
                $localFile = downloadMedia((string)$mediaUrl, $fileName !== '' ? $fileName : 'file.bin', $mediaReason);
            }
            $mediaInfo = $localFile ? 'downloaded' : ('FAILED:' . (string)$mediaReason);
        }

        $sent = dispatchToPlatforms($only, $text, $localFile, $mediaType, $fileName !== '' ? $fileName : 'file.bin');

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
        setLastSeenId($db, EITAA_CHANNEL_ID, $maxId);
    }

    echo json_encode([
        'success'    => true,
        'only'       => $only,
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
        .container { max-width: 900px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; background: #1e293b; padding: 20px; border-radius: 12px; border: 1px solid #334155; margin-bottom: 20px; }
        .header h1 { font-size: 1.25rem; margin: 0; color: #38bdf8; }
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
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div>
            <h1>همگام‌سازی خودکار کانال‌ها</h1>
            <small style="color: #94a3b8;">پلتفرم‌ها: بله، روبیکا، سروش‌پلاس، آیگپ</small>
        </div>
        <button id="startBtn" class="btn" onclick="startSync()">بررسی و شروع همگام‌سازی</button>
    </div>

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

<script>
    const SECURITY_KEY = '<?= SECURITY_KEY ?>';
    const MEDIA_MAX_RETRY_TXT = '<?= MEDIA_MAX_RETRY ?>';
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

    async function startSync() {
        const btn = document.getElementById('startBtn');
        btn.disabled = true;
        document.getElementById('cardsList').innerHTML = '';
        document.getElementById('statusText').innerText = 'در حال دریافت پست‌ها از ایتا...';
        log('در حال دریافت لیست پیام‌های جدید از کانال ایتا...');

        try {
            const res = await fetch(`sync_manual.php?action=get_pending&key=${SECURITY_KEY}`);
            const data = await res.json();

            if (!data.success) {
                log('خطا: ' + (data.error || 'عدم دسترسی به ایتا'), '#f87171');
                btn.disabled = false;
                return;
            }

            log(`آخرین پیام پردازش‌شده در دیتابیس: ID ${data.lastSeenId}`);
            pendingMessages = data.messages || [];

            if (pendingMessages.length === 0) {
                log('هیچ پیام جدیدی یافت نشد. وضعیت کانال‌ها به‌روز است.', '#38bdf8');
                document.getElementById('statusText').innerText = 'پایان: پیام جدیدی نیست';
                document.getElementById('progressFill').style.width = '100%';
                document.getElementById('percentText').innerText = '100%';
                btn.disabled = false;
                return;
            }

            log(`تعداد ${pendingMessages.length} پیام جدید برای پردازش مشخص شد.`);
            renderCards(pendingMessages);
            processQueue();

        } catch (e) {
            log('خطای غیرمنتظره ارتباط با سرور: ' + e.message, '#f87171');
            btn.disabled = false;
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
                    <span class="card-id">پست ID ${m.id} (${m.mediaType ? m.mediaType : 'متن'})</span>
                    <span class="card-desc">${desc}</span>
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
                const res = await fetch(`sync_manual.php?action=sync_single&key=${SECURITY_KEY}`, {
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
                    card.classList.remove('active');
                    continue;
                }

                if (result.success === false) {
                    ['bale', 'rubika', 'soroush', 'igap'].forEach(p => {
                        const b = document.getElementById(`${p}-${m.id}`);
                        if (b) { b.className = 'badge fail'; b.innerText = 'خطا'; }
                    });
                    log(`❌ پست ${m.id}: ${result.error || 'خطای نامشخص'}`, '#f87171');
                    card.classList.remove('active');
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
                const mark = r => (r?.ok === true ? '✅' : (r?.ok === null || /\bSKIPPED\b/.test(r?.info || '') ? '—' : '❌'));
                reportList.push(`🔹 پست ${m.id} [${mediaDesc}]:\n  بله: ${mark(result.bale)} | روبیکا: ${mark(result.rubika)}\n  سروش: ${mark(result.soroush)} | آیگپ: ${mark(result.igap)}\n  رسانه: ${mediaState}`);

                const sInfo = result.soroush?.info || '';
                const gInfo = result.igap?.info || '';
                const word = (ok, info) => (ok === true ? 'OK'
                    : (ok === null || /\bSKIPPED\b/.test(info) ? 'رد شد'
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
            }

            card.classList.remove('active');
        }

        document.getElementById('progressFill').style.width = '100%';
        document.getElementById('percentText').innerText = '100%';
        document.getElementById('statusText').innerText = 'تمام پیام‌ها با موفقیت توزیع شدند.';
        log('همه پیام‌ها پردازش شدند. ارسال گزارش مدیریتی به بله...', '#38bdf8');

        await fetch(`sync_manual.php?action=send_report&key=${SECURITY_KEY}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ report: reportList })
        });

        log('گزارش نهایی ارسال شد. عملیات کامل است.', '#4ade80');
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
        if (isOk === null || /\bSKIPPED\b/.test(detail)) {
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
</script>

</body>
</html>
