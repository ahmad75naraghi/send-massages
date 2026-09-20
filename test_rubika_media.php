<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

// مقدارها از .env می‌آیند (RUBIKA_BOT_TOKEN، RUBIKA_CHANNEL_ID)
$missing = envMissing(['RUBIKA_BOT_TOKEN', 'RUBIKA_CHANNEL_ID']);
if ($missing) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => envMissingMessage($missing)], JSON_UNESCAPED_UNICODE);
    exit;
}


// ایجاد یا استفاده از تصویر تستی
$testImgPath = __DIR__ . '/test_img.jpg';
if (!file_exists($testImgPath)) {
    $im = imagecreatetruecolor(400, 300);
    $bg = imagecolorallocate($im, 30, 144, 255);
    imagefill($im, 0, 0, $bg);
    imagejpeg($im, $testImgPath);
    imagedestroy($im);
}

$base = "https://botapi.rubika.ir/v3/" . RUBIKA_BOT_TOKEN . "/";

// -----------------------------------------------------------
// گام ۱: درخواست مجوز آپلود و دریافت upload_url
// -----------------------------------------------------------
$ch = curl_init($base . 'requestSendFile');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['type' => 'Image']),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json']
]);
$rawReqRes = curl_exec($ch);
curl_close($ch);

$reqRes = json_decode((string)$rawReqRes, true);

if (($reqRes['status'] ?? '') !== 'OK') {
    die(json_encode([
        'stage'    => 'requestSendFile',
        'response' => $reqRes ?? $rawReqRes
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// استخراج upload_url (بسته به ساختار data می‌تواند رشته یا آبجکت باشد)
$uploadUrl = is_array($reqRes['data']) ? ($reqRes['data']['upload_url'] ?? '') : (string)$reqRes['data'];

// -----------------------------------------------------------
// گام ۲: آپلود فیزیکی فایل به سرور روبیکا و گرفتن file_id
// -----------------------------------------------------------
$cFile = new CURLFile($testImgPath, 'image/jpeg', 'test_img.jpg');
$ch = curl_init($uploadUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => ['file' => $cFile],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false
]);
$rawUploadRes = curl_exec($ch);
curl_close($ch);

$uploadRes = json_decode((string)$rawUploadRes, true);
$fileId = is_array($uploadRes['data'] ?? null) 
    ? ($uploadRes['data']['file_id'] ?? null) 
    : ($uploadRes['data'] ?? ($uploadRes['file_id'] ?? null));

if (!$fileId) {
    die(json_encode([
        'stage'      => 'upload_file',
        'upload_url' => $uploadUrl,
        'response'   => $uploadRes ?? $rawUploadRes
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// -----------------------------------------------------------
// گام ۳: ارسال نهایی فایل به کانال با متد sendFile
// -----------------------------------------------------------
$sendPayload = [
    'chat_id'   => RUBIKA_CHANNEL_ID,
    'file_id'   => (string)$fileId,
    'type'      => 'Image',
    'text'      => 'تست ارسال موفق تصویر با پایپ‌لاین رسمی روبیکا',
    'file_name' => 'photo.jpg'
];

$ch = curl_init($base . 'sendFile');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($sendPayload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json']
]);
$rawFinalRes = curl_exec($ch);
curl_close($ch);

echo json_encode([
    'request_send_file' => $reqRes,
    'upload_response'   => $uploadRes ?? $rawUploadRes,
    'send_file_result'  => json_decode((string)$rawFinalRes, true) ?? $rawFinalRes
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);