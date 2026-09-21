<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// ==========================================
// تنظیمات اتصال
// ==========================================
require_once __DIR__ . '/config.php';

// مقدارها از .env می‌آیند (EITAA_CHANNEL_ID، BALE_BOT_TOKEN، BALE_CHANNEL_ID)
$missing = envMissing(['BALE_BOT_TOKEN', 'BALE_CHANNEL_ID']);
if ($missing) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => envMissingMessage($missing)], JSON_UNESCAPED_UNICODE);
    exit;
}


// 1. واکشی زنده آخرین پست از ایتا جهت دریافت معتبرترین Token مدیا
$channelUrl = "https://eitaa.com/" . EITAA_CHANNEL_ID;
$ch = curl_init($channelUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
]);
$html = curl_exec($ch);
curl_close($ch);

if (empty($html)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to reach Eitaa channel'], JSON_UNESCAPED_UNICODE);
    exit;
}

// پارس ساختار برای یافتن آخرین پست دارای تصویر
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
libxml_clear_errors();

$xpath = new DOMXPath($dom);
$messageNodes = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' etme_widget_message ')]");

$targetPost = null;
for ($i = $messageNodes->length - 1; $i >= 0; $i--) {
    $node = $messageNodes->item($i);
    $photoNode = $xpath->query(".//a[contains(@class, 'etme_widget_message_photo_wrap')]", $node)->item(0);
    
    if ($photoNode) {
        $style = $photoNode->getAttribute('style');
        if (preg_match('/url\(\'?(.*?)\'?\)/', $style, $matches)) {
            $textNode = $xpath->query(".//div[contains(@class, 'etme_widget_message_text')]", $node)->item(0);
            $targetPost = [
                'text'      => $textNode ? trim($textNode->textContent) : '',
                'media_url' => 'https://eitaa.com' . $matches[1]
            ];
            break;
        }
    }
}

if (!$targetPost) {
    echo json_encode(['status' => 'error', 'message' => 'No photo post found in recent messages'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. دانلود ایمن مدیا از CDN ایتا
$tempFilePath = sys_get_temp_dir() . '/eitaa_media_' . uniqid('', true) . '.jpg';
$fp = fopen($tempFilePath, 'w+');

$ch = curl_init($targetPost['media_url']);
curl_setopt_array($ch, [
    CURLOPT_FILE           => $fp,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    CURLOPT_HTTPHEADER     => [
        'Referer: https://eitaa.com/' . EITAA_CHANNEL_ID,
        'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
        'Accept-Encoding: identity' // جلوگیری از دریافت فشرده‌سازی کنترل‌نشده
    ]
]);

$downloadSuccess = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
fclose($fp);

$fileSize = file_exists($tempFilePath) ? filesize($tempFilePath) : 0;

// کدهای 200 و 206 در دانلود استریم مدیا وضعیت معتبر محسوب می‌شوند
if (!$downloadSuccess || !in_array($httpCode, [200, 206], true) || $fileSize < 1024) {
    @unlink($tempFilePath);
    echo json_encode([
        'status'    => 'error',
        'message'   => 'Failed to download valid image',
        'http_code' => $httpCode,
        'file_size' => $fileSize
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 3. ارسال مستقیم به بله (Multipart / SendPhoto)
$baleApiUrl = "https://tapi.bale.ai/bot" . BALE_BOT_TOKEN . "/sendPhoto";

// محدودیت کپشن در پلتفرم بله (حداکثر 1024 کاراکتر)
$caption = mb_substr($targetPost['text'], 0, 1000);

$postData = [
    'chat_id' => BALE_CHANNEL_ID,
    'caption' => $caption,
    'photo'   => new CURLFile($tempFilePath, 'image/jpeg', 'image.jpg')
];

$ch = curl_init($baleApiUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false
]);

$response = curl_exec($ch);
$baleHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// پاکسازی فایل موقت از دیسک
@unlink($tempFilePath);

echo json_encode([
    'status'         => 'finished',
    'download_code'  => $httpCode,
    'file_size_byte' => $fileSize,
    'bale_http_code' => $baleHttpCode,
    'bale_response'  => json_decode($response, true) ?? $response,
    'curl_error'     => $curlError
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);