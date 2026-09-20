<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// شناسه کانال عمومی ایتا بدون @ (از کوئری استرینگ یا مقدار پیش‌فرض)
$channel = isset($_GET['ch']) ? trim((string)$_GET['ch']) : 'eitaa';
$channel = ltrim($channel, '@');

if ($channel === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Channel parameter missing'], JSON_UNESCAPED_UNICODE);
    exit;
}

$targetUrl = "https://eitaa.com/{$channel}";

// پیکربندی cURL با شبیه‌سازی مرورگر
$ch = curl_init($targetUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    CURLOPT_HTTPHEADER     => [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: fa,en;q=0.9',
    ]
]);

$html = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($httpCode !== 200 || empty($html)) {
    http_response_code(502);
    echo json_encode([
        'status'     => 'error',
        'http_code'  => $httpCode,
        'curl_error' => $curlError,
        'url'        => $targetUrl
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// پارس کردن ساختار DOM
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
libxml_clear_errors();

$xpath = new DOMXPath($dom);
$messageNodes = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' etme_widget_message ')]");

$parsedMessages = [];

foreach ($messageNodes as $node) {
    $dataPost = $node->getAttribute('data-post');
    if (!$dataPost || !str_contains($dataPost, '/')) {
        continue;
    }

    $msgId = (int)substr(strrchr($dataPost, '/'), 1);

    // استخراج متن
    $textNode = $xpath->query(".//div[contains(@class, 'etme_widget_message_text')]", $node)->item(0);
    $text = $textNode ? trim($textNode->textContent) : '';

    // استخراج مدیا
    $mediaUrl = null;
    $mediaType = null;

    $photoNode = $xpath->query(".//a[contains(@class, 'etme_widget_message_photo_wrap')]", $node)->item(0);
    $videoNode = $xpath->query(".//video", $node)->item(0);

    if ($photoNode) {
        $style = $photoNode->getAttribute('style');
        if (preg_match('/url\(\'?(.*?)\'?\)/', $style, $matches)) {
            $mediaUrl = $matches[1];
            $mediaType = 'photo';
        }
    } elseif ($videoNode) {
        $src = $videoNode->getAttribute('src');
        if ($src) {
            $mediaUrl = $src;
            $mediaType = 'video';
        }
    }

    if ($text !== '' || $mediaUrl !== null) {
        $parsedMessages[] = [
            'id'        => $msgId,
            'text'      => $text,
            'media_url' => $mediaUrl,
            'media_type'=> $mediaType
        ];
    }
}

// مرتب‌سازی از جدیدترین به قدیمی‌ترین
usort($parsedMessages, fn($a, $b) => $b['id'] <=> $a['id']);

echo json_encode([
    'status'        => 'success',
    'channel'       => $channel,
    'total_fetched' => count($parsedMessages),
    'latest_post_id'=> $parsedMessages[0]['id'] ?? null,
    'messages'      => $parsedMessages
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
