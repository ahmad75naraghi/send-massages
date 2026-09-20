<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tehran');
ini_set('display_errors', '1');
error_reporting(E_ALL);

const SECURITY_KEY       = '1';
const EITAA_CHANNEL_ID   = 'shamimeashena';
const MAX_MESSAGES_LIMIT = 7;

// تنظیمات بله
const BALE_BOT_TOKEN     = '74580067:QneNhGu2LENUy5RatIeEiSzugYztXyGYmLs';
const BALE_CHANNEL_ID    = '@testforme';
const BALE_ADMIN_CHAT_ID = '1598432451';

// تنظیمات روبیکا
const RUBIKA_BOT_TOKEN   = 'CEJCFE0FCBZKUIGMNMOODEZXQAVAFDOLNFHMSBDFUVDAQMAFIYQDUMJWDODELSWZ';
const RUBIKA_CHANNEL_ID  = '@shamimeashena1';

// تنظیمات سروش‌پلاس
const SOROUSH_CHANNEL_ID = 'shamimeashena1';
const SOROUSH_SCRIPT     = '/home/file/public_html/s/send_soroush.js';

// تنظیمات آیگپ
const IGAP_CHANNEL_ID    = 'shamimeashena';
const IGAP_SCRIPT        = '/home/file/public_html/s/send_igap.js';

const NODE_BIN           = '/usr/bin/node';

// اعتبارسنجی توکن دسترسی
if (($_REQUEST['key'] ?? '') !== SECURITY_KEY and php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("<h3 style='color:red;'>Access Denied</h3>");
}

// پایگاه داده وضعیت
$dbPath = __DIR__ . '/state.sqlite';$db = new PDO("sqlite:{$dbPath}");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);$db->exec("CREATE TABLE IF NOT EXISTS sync_state (channel TEXT PRIMARY KEY, last_msg_id INTEGER NOT NULL)");

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

function downloadMedia(string $url, string$targetFilename): ?string {
    $tmpPath = sys_get_temp_dir() . '/sync_' . uniqid('', true) . '_' . $targetFilename;
    $fp = fopen($tmpPath, 'w+');
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
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    if ($res and in_array($code, [200, 206], true) and filesize($tmpPath) > 100) {
        return $tmpPath;
    }
    @unlink($tmpPath);
    return null;
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

function sendToBale(string $text, ?string $file, ?string $type, string $fileName): array {$cleanToken = preg_replace('/^bot/i', '', trim(BALE_BOT_TOKEN));
    $base = "https://tapi.bale.ai/bot{$cleanToken}/";
    if ($file and file_exists($file)) {$cFile = new CURLFile($file, '',$fileName);
        $caption = mb_substr($text, 0, 1000);
        $method = match($type) { 'video' => 'sendVideo', 'audio' => 'sendAudio', 'document' => 'sendDocument', default => 'sendPhoto' };
        $param  = match($type) { 'video' => 'video', 'audio' => 'audio', 'document' => 'document', default => 'photo' };
        return callApi($base . $method, ['chat_id' => BALE_CHANNEL_ID, 'caption' =>$caption, $param =>$cFile], true);
    }
    return callApi($base . 'sendMessage', json_encode(['chat_id' => BALE_CHANNEL_ID, 'text' =>$text]), false, ['Content-Type: application/json']);
}

function sendToRubika(string $text, ?string $file, ?string $type, string $fileName): array {$base = "https://botapi.rubika.ir/v3/" . RUBIKA_BOT_TOKEN . "/";
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

function sendToSoroush(string $channel, string $text = '', ?string $filePath = null): array {
    $cleanChannel = ltrim($channel, '@');
    $profileDir   = '/home/file/public_html/s/soroush_profile';
    @array_map('unlink', glob($profileDir . '/Singleton*') ?: []);

    $cmd = escapeshellarg(NODE_BIN) . ' ' . escapeshellarg(SOROUSH_SCRIPT) . ' --channel=' . escapeshellarg($cleanChannel);
    if ($text !== '') {
        $cmd .= ' --text=' . escapeshellarg($text);
    }
    if ($filePath and file_exists($filePath)) {
        $cmd .= ' --file=' . escapeshellarg($filePath);
    }

    $output = shell_exec($cmd . ' 2>&1');
    @array_map('unlink', glob($profileDir . '/Singleton*') ?: []);

    $result = parseNodeJsonOutput((string)$output);
    return (isset($result['status']) and $result['status'] === 'OK')
        ? ['success' => true, 'message' => $result['message'] ?? 'OK']
        : ['success' => false, 'message' => $result['error'] ?? ($output !== null && trim((string)$output) !== '' ? trim((string)$output) : 'Fail')];
}

function sendToIgap(string $channel, string $text = '', ?string $filePath = null): array {
    $cleanChannel = ltrim($channel, '@');
    $profileDir   = '/home/file/public_html/s/igap_profile';
    @array_map('unlink', glob($profileDir . '/Singleton*') ?: []);

    $cmd = escapeshellarg(NODE_BIN) . ' ' . escapeshellarg(IGAP_SCRIPT) . ' --channel=' . escapeshellarg($cleanChannel);
    if ($text !== '') {
        $cmd .= ' --text=' . escapeshellarg($text);
    }
    if ($filePath and file_exists($filePath)) {
        $cmd .= ' --file=' . escapeshellarg($filePath);
    }

    $output = shell_exec($cmd . ' 2>&1');
    @array_map('unlink', glob($profileDir . '/Singleton*') ?: []);

    $result = parseNodeJsonOutput((string)$output);
    return (isset($result['status']) and $result['status'] === 'OK')
        ? ['success' => true, 'message' => $result['message'] ?? 'OK']
        : ['success' => false, 'message' => $result['error'] ?? ($output !== null && trim((string)$output) !== '' ? trim((string)$output) : 'Fail')];
}

$action =$_GET['action'] ?? '';

// ۱. لیست پیام‌های جدید ایتا
if ($action === 'get_pending') {
    header('Content-Type: application/json; charset=utf-8');
    $lastSeenId = getLastSeenId($db, EITAA_CHANNEL_ID);

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
        echo json_encode(['success' => false, 'error' => 'عدم دسترسی به ایتا']);
        exit;
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

    usort($allMessages, fn($a, $b) =>$a['id'] <=> $b['id']);$newMessages = array_values(array_filter($allMessages, fn($m) => $m['id'] >$lastSeenId));
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

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!$payload or empty($payload['id'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid payload']);
        exit;
    }

    $msgId     = (int)$payload['id'];
    $text      =$payload['text'] ?? '';
    $mediaUrl  =$payload['mediaUrl'] ?? null;
    $mediaType =$payload['mediaType'] ?? null;
    $fileName  =$payload['fileName'] ?? 'file.bin';

    $localFile =$mediaUrl ? downloadMedia($mediaUrl,$fileName) : null;

    $bale = sendToBale($text, $localFile,$mediaType, $fileName);$baleOk = ($bale['code'] === 200 and ($bale['res']['ok'] ?? false));

    $rubika = sendToRubika($text, $localFile,$mediaType, $fileName);$rubikaOk = ($rubika['code'] === 200 and (($rubika['res']['status'] ?? '') === 'OK'));

    $soroush = sendToSoroush(SOROUSH_CHANNEL_ID, $text,$localFile);
    $soroushOk = ($soroush['success'] === true);

    $igap = sendToIgap(IGAP_CHANNEL_ID, $text,$localFile);
    $igapOk = ($igap['success'] === true);

    if ($localFile and file_exists($localFile)) {
        @unlink($localFile);
    }

    setLastSeenId($db, EITAA_CHANNEL_ID,$msgId);

    echo json_encode([
        'success' => true,
        'id'      => $msgId,
        'bale'    => ['ok' => $baleOk, 'info' =>$bale['code'] ?? 'ERR'],
        'rubika'  => ['ok' => $rubikaOk, 'info' =>$rubika['code'] ?? 'ERR'],
        'soroush' => ['ok' => $soroushOk, 'info' =>$soroush['message'] ?? 'ERR'],
        'igap'    => ['ok' => $igapOk, 'info' =>$igap['message'] ?? 'ERR'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ۳. ارسال گزارش پایانی
if ($action === 'send_report') {
    header('Content-Type: application/json; charset=utf-8');
    $payload = json_decode(file_get_contents('php://input'), true);
    $reportItems =$payload['report'] ?? [];

    if (!empty(BALE_ADMIN_CHAT_ID) and !empty($reportItems)) {
        $reportText = "📊 گزارش همگام‌سازی ۴ کانال:\nزمان: " . date('Y-m-d H:i:s') . "\nتعداد پست‌ها: " . count($reportItems) . "\n\n" . implode("\n\n", $reportItems);$adminUrl = "https://tapi.bale.ai/bot" . preg_replace('/^bot/i', '', trim(BALE_BOT_TOKEN)) . "/sendMessage";
        callApi($adminUrl, json_encode(['chat_id' => BALE_ADMIN_CHAT_ID, 'text' =>$reportText]), false, ['Content-Type: application/json']);
    }

    echo json_encode(['success' => true]);
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

                updateBadge(`bale-${m.id}`, result.bale?.ok, 'بله');
                updateBadge(`rubika-${m.id}`, result.rubika?.ok, 'روبیکا');
                updateBadge(`soroush-${m.id}`, result.soroush?.ok, 'سروش');
                updateBadge(`igap-${m.id}`, result.igap?.ok, 'آیگپ');

                const mediaDesc = m.mediaType ? m.mediaType : 'متن';
                reportList.push(`🔹 پست ${m.id} [${mediaDesc}]:\n  بله: ${result.bale?.ok ? "✅" : "❌"} | روبیکا: ${result.rubika?.ok ? "✅" : "❌"}\n  سروش: ${result.soroush?.ok ? "✅" : "❌"} | آیگپ: ${result.igap?.ok ? "✅" : "❌"}`);

                log(`پست ID ${m.id} ارسال شد. (سروش: ${result.soroush?.ok ? 'OK' : 'خطا'} | آیگپ: ${result.igap?.ok ? 'OK' : 'خطا'})`);

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

    function updateBadge(elId, isOk, name) {
        const el = document.getElementById(elId);
        if (isOk) {
            el.className = 'badge ok';
            el.innerText = `${name} ✓`;
        } else {
            el.className = 'badge fail';
            el.innerText = `${name} ✕`;
        }
    }
</script>

</body>
</html>
