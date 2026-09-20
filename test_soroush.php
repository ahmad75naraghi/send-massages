<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

const SOROUSH_BOT_TOKEN = '70022669:QMFqHjA8guTKl6WuUOyPp2f1PFAPp8nrV_4';
const SOROUSH_CHAT_ID   = '10024428989'; // یا نام کاربری عمومی مثل @channel

class SoroushClient
{
    private string $baseUrl;

    public function __construct(string $token)
    {
        $cleanToken = trim($token);
        if (str_starts_with($cleanToken, 'bot')) {
            $cleanToken = substr($cleanToken, 3);
        }
        $this->baseUrl = "https://api.splus.ir/bot{$cleanToken}";
    }

    public function getMe(): array
    {
        return $this->request('/getMe', null, false);
    }

    public function sendMessage(string $chatId, string $text): array
    {
        $payload = [
            'chat_id' => $chatId,
            'text'    => $text
        ];
        return $this->request('/sendMessage', json_encode($payload), false, ['Content-Type: application/json']);
    }

    private function request(string $endpoint, mixed $data, bool $isPost = true, array $headers = []): array
    {
        $ch = curl_init($this->baseUrl . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);

        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'http_code' => $httpCode,
            'response'  => json_decode((string)$response, true) ?? $response,
            'curl_err'  => $error
        ];
    }
}

$client = new SoroushClient(SOROUSH_BOT_TOKEN);

echo json_encode([
    'get_me'       => $client->getMe(),
    'send_message' => $client->sendMessage(SOROUSH_CHAT_ID, "تست اتصال ربات سروش‌پلاس\n" . date('Y-m-d H:i:s'))
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);