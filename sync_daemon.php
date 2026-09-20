<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tehran');

// ==========================================
// تنظیمات اتصال
// ==========================================
require_once __DIR__ . '/config.php';

// همهٔ مقدارها از .env می‌آیند:
//   EITAA_CHANNEL_ID، CHECK_INTERVAL_SEC، BALE_BOT_TOKEN، BALE_CHANNEL_ID،
//   RUBIKA_BOT_TOKEN، RUBIKA_CHANNEL_ID، ENABLE_SOROUSH_BOT، SOROUSH_BOT_TOKEN، SOROUSH_CHAT_ID


// ==========================================
// پایگاه داده وضعیت (State Persistence)
// ==========================================
class StateStore
{
    private PDO $db;

    public function __construct(string $dbPath = __DIR__ . '/state.sqlite')
    {
        $this->db = new PDO("sqlite:{$dbPath}");
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS sync_state (
                channel TEXT PRIMARY KEY,
                last_msg_id INTEGER NOT NULL
            )
        ");
    }

    public function getLastSeenId(string $channel): int
    {
        $stmt = $this->db->prepare("SELECT last_msg_id FROM sync_state WHERE channel = :channel");
        $stmt->execute([':channel' => $channel]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (int)$val : 0;
    }

    public function setLastSeenId(string $channel, int $msgId): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO sync_state (channel, last_msg_id) 
            VALUES (:channel, :msg_id) 
            ON CONFLICT(channel) DO UPDATE SET last_msg_id = :msg_id
        ");
        $stmt->execute([':channel' => $channel, ':msg_id' => $msgId]);
    }
}

// ==========================================
// کلاس ساختار پیام
// ==========================================
class ChannelMessage
{
    public function __construct(
        public readonly int $id,
        public readonly string $text,
        public readonly ?string $mediaUrl = null,
        public readonly ?string $mediaType = null
    ) {}
}

// ==========================================
// واکشی پیام‌ها از ایتا
// ==========================================
class EitaaScraper
{
    public function __construct(private readonly string $channel) {}

    public function fetchMessages(): array
    {
        $url = "https://eitaa.com/{$this->channel}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]);

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($html)) {
            echo "[" . date('H:i:s') . "] Failed to fetch Eitaa channel (HTTP {$httpCode})\n";
            return [];
        }

        return $this->parseHtml($html);
    }

    private function parseHtml(string $html): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' etme_widget_message ')]");

        $messages = [];

        foreach ($nodes as $node) {
            $dataPost = $node->getAttribute('data-post');
            if (!$dataPost || !str_contains($dataPost, '/')) {
                continue;
            }

            $msgId = (int)substr(strrchr($dataPost, '/'), 1);

            $textNode = $xpath->query(".//div[contains(@class, 'etme_widget_message_text')]", $node)->item(0);
            $text = $textNode ? trim($textNode->textContent) : '';

            $mediaUrl = null;
            $mediaType = null;

            $photoNode = $xpath->query(".//a[contains(@class, 'etme_widget_message_photo_wrap')]", $node)->item(0);
            $videoNode = $xpath->query(".//video", $node)->item(0);

            if ($photoNode) {
                $style = $photoNode->getAttribute('style');
                if (preg_match('/url\(\'?(.*?)\'?\)/', $style, $matches)) {
                    $mediaUrl = 'https://eitaa.com' . $matches[1];
                    $mediaType = 'image';
                }
            } elseif ($videoNode) {
                $src = $videoNode->getAttribute('src');
                if ($src) {
                    $mediaUrl = 'https://eitaa.com' . $src;
                    $mediaType = 'video';
                }
            }

            if ($text !== '' || $mediaUrl !== null) {
                $messages[] = new ChannelMessage($msgId, $text, $mediaUrl, $mediaType);
            }
        }

        usort($messages, fn(ChannelMessage $a, ChannelMessage $b) => $a->id <=> $b->id);
        return $messages;
    }
}

// ==========================================
// مدیریت ارسال چندگانه
// ==========================================
class Broadcaster
{
    public function dispatch(ChannelMessage $msg): void
    {
        $localFile = null;

        if ($msg->mediaUrl && $msg->mediaType === 'image') {
            $localFile = $this->downloadMedia($msg->mediaUrl);
        }

        $this->sendToBale($msg, $localFile);
        $this->sendToRubika($msg, $localFile);

        if (ENABLE_SOROUSH_BOT) {
            $this->sendToSoroush($msg, $localFile);
        }

        if ($localFile && file_exists($localFile)) {
            @unlink($localFile);
        }
    }

    private function sendToBale(ChannelMessage $msg, ?string $localFile): void
    {
        $cleanToken = trim(BALE_BOT_TOKEN);
        if (str_starts_with($cleanToken, 'bot')) {
            $cleanToken = substr($cleanToken, 3);
        }

        if ($localFile && file_exists($localFile)) {
            $url = "https://tapi.bale.ai/bot{$cleanToken}/sendPhoto";
            $postData = [
                'chat_id' => BALE_CHANNEL_ID,
                'caption' => mb_substr($msg->text, 0, 1000),
                'photo'   => new CURLFile($localFile, 'image/jpeg', 'photo.jpg')
            ];
            $this->execCurl($url, $postData, true);
        } elseif (!empty($msg->text)) {
            $url = "https://tapi.bale.ai/bot{$cleanToken}/sendMessage";
            $payload = [
                'chat_id' => BALE_CHANNEL_ID,
                'text'    => $msg->text
            ];
            $this->execCurl($url, json_encode($payload), false, ['Content-Type: application/json']);
        }
    }

    private function sendToRubika(ChannelMessage $msg, ?string $localFile): void
    {
        // در Bot API رسمی روبیکا، ارسال عکس نیازمند فایل چندبخشی با متد sendPhoto است
        if ($localFile && file_exists($localFile)) {
            $url = "https://botapi.rubika.ir/v3/" . RUBIKA_BOT_TOKEN . "/sendPhoto";
            $postData = [
                'chat_id' => RUBIKA_CHANNEL_ID,
                'caption' => mb_substr($msg->text, 0, 1000),
                'photo'   => new CURLFile($localFile, 'image/jpeg', 'photo.jpg')
            ];
            $this->execCurl($url, $postData, true);
        } elseif (!empty($msg->text)) {
            $url = "https://botapi.rubika.ir/v3/" . RUBIKA_BOT_TOKEN . "/sendMessage";
            $payload = [
                'chat_id' => RUBIKA_CHANNEL_ID,
                'text'    => $msg->text
            ];
            $this->execCurl($url, json_encode($payload), false, ['Content-Type: application/json']);
        }
    }

    private function sendToSoroush(ChannelMessage $msg, ?string $localFile): void
    {
        $cleanToken = trim(SOROUSH_BOT_TOKEN);
        if (str_starts_with($cleanToken, 'bot')) {
            $cleanToken = substr($cleanToken, 3);
        }

        if ($localFile && file_exists($localFile)) {
            $url = "https://api.splus.ir/bot{$cleanToken}/sendPhoto";
            $postData = [
                'chat_id' => SOROUSH_CHAT_ID,
                'caption' => mb_substr($msg->text, 0, 1000),
                'photo'   => new CURLFile($localFile, 'image/jpeg', 'photo.jpg')
            ];
            $this->execCurl($url, $postData, true);
        } elseif (!empty($msg->text)) {
            $url = "https://api.splus.ir/bot{$cleanToken}/sendMessage";
            $payload = [
                'chat_id' => SOROUSH_CHAT_ID,
                'text'    => $msg->text
            ];
            $this->execCurl($url, json_encode($payload), false, ['Content-Type: application/json']);
        }
    }

    private function downloadMedia(string $url): ?string
    {
        $tempPath = sys_get_temp_dir() . '/sync_' . uniqid('', true) . '.jpg';
        $fp = fopen($tempPath, 'w+');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_HTTPHEADER     => [
                'Referer: https://eitaa.com/' . EITAA_CHANNEL_ID,
                'Accept-Encoding: identity'
            ]
        ]);

        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($success && in_array($httpCode, [200, 206], true) && filesize($tempPath) > 500) {
            return $tempPath;
        }

        @unlink($tempPath);
        return null;
    }

    private function execCurl(string $url, mixed $data, bool $isMultipart = false, array $headers = []): void
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        if (!empty($headers) && !$isMultipart) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        echo "[" . date('H:i:s') . "] Sent to {$url} - Code: {$code}\n";
    }
}

// ==========================================
// حلقه اجرای نامحدود (Daemon Loop)
// ==========================================
$store = new StateStore();
$scraper = new EitaaScraper(EITAA_CHANNEL_ID);
$broadcaster = new Broadcaster();

$lastSeenId = $store->getLastSeenId(EITAA_CHANNEL_ID);

echo "[" . date('Y-m-d H:i:s') . "] Daemon started for channel: " . EITAA_CHANNEL_ID . "\n";

// در اولین اجرا، آخرین پست فعلی را ذخیره می‌کنیم تا پیام‌های گذشته تکرار نشوند
if ($lastSeenId === 0) {
    $initialMessages = $scraper->fetchMessages();
    if (!empty($initialMessages)) {
        $lastSeenId = end($initialMessages)->id;
        $store->setLastSeenId(EITAA_CHANNEL_ID, $lastSeenId);
        echo "[" . date('H:i:s') . "] Initialized last_msg_id to: {$lastSeenId}\n";
    }
}

while (true) {
    try {
        $messages = $scraper->fetchMessages();

        foreach ($messages as $msg) {
            if ($msg->id > $lastSeenId) {
                echo "[" . date('H:i:s') . "] New post detected (ID: {$msg->id}). Broadcasting...\n";
                $broadcaster->dispatch($msg);

                $lastSeenId = $msg->id;
                $store->setLastSeenId(EITAA_CHANNEL_ID, $lastSeenId);
            }
        }
    } catch (Throwable $e) {
        echo "[" . date('H:i:s') . "] Exception in main loop: " . $e->getMessage() . "\n";
    }

    sleep(CHECK_INTERVAL_SEC);
}