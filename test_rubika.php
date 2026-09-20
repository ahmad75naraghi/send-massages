<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

const RUBIKA_BOT_TOKEN = 'CEJCFE0FCBZKUIGMNMOODEZXQAVAFDOLNFHMSBDFUVDAQMAFIYQDUMJWDODELSWZ';
const RUBIKA_CHAT_ID_GUID = 'c0EDtHu01b27726937fae4b43ee17905'; // شناسه یکتا
const RUBIKA_CHAT_ID_USER = '@shamimeashena1'; // نام کاربری عمومی کانال روبیکا

// اندپوینت فعال (همان که قبلا 200 داد)
$url = "https://botapi.rubika.ir/v3/" . RUBIKA_BOT_TOKEN . "/sendMessage";

$payloads = [
    'test_guid' => [
        'chat_id' => RUBIKA_CHAT_ID_GUID,
        'text'    => "تست ارسال با GUID\n" . date('Y-m-d H:i:s')
    ],
    'test_username' => [
        'chat_id' => RUBIKA_CHAT_ID_USER,
        'text'    => "تست ارسال با Username\n" . date('Y-m-d H:i:s')
    ]
];

$results = [];

foreach ($payloads as $key => $payload) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $results[$key] = [
        'http_code' => $httpCode,
        'response'  => json_decode((string)$response, true) ?? $response,
        'curl_err'  => $curlError
    ];
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);