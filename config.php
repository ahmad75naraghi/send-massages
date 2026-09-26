<?php
/**
 * config.php — لایهٔ پیکربندی متمرکز (یک فایل برای همهٔ اسکریپت‌ها)
 * ==================================================================
 *  همهٔ مقدارهای حساس و پیکربندی از فایل `.env` کنار برنامه خوانده می‌شوند.
 *  هیچ secret ای در کد باقی نمانده است؛ اگر `.env` نباشد، توکن‌ها «خالی» هستند
 *  و هر مسیر مربوطه با پیام صریحِ «پیکربندی نشده» شکست می‌خورد (نه ارسال اشتباه).
 *
 *  ساخت .env:      bash setup_env.sh          (از تاریخ Git مهاجرت می‌دهد)
 *  مجوز:           chmod 600 .env && chown file:file .env
 *  Git:            .env در .gitignore است؛ فقط .env.example ردیابی می‌شود
 *  وب:             .htaccess با «پیش‌فرض بسته» آن را مسدود می‌کند
 *
 *  اولویت مقدارها:  متغیر محیطی واقعی  >  .env  >  پیش‌فرض داخل این فایل
 *
 *  هیچ وابستگی خارجی ندارد (بدون vlucas/phpdotenv و بدون Composer).
 */

declare(strict_types=1);

/**
 * خواندن .env و ریختن آن در $_ENV و getenv().
 * پشتیبانی: `KEY=value`، `export KEY=value`، کوتیشن تکی/دوجمله‌ای، کامنت `#`،
 * مقدار خالی، و مقدارهای UTF-8/فارسی دارای فاصله.
 * متغیرهای محیطیِ از قبل موجود هرگز بازنویسی نمی‌شوند (تا systemd/cron بتواند override کند).
 */
function loadDotEnv(?string $path = null): string {
    static $loaded = '';
    if ($loaded !== '') {
        return $loaded;
    }

    $candidates = array_filter([
        $path,
        getenv('SYNC_ENV_FILE') ?: null,
        (defined('SYNC_APP_DIR') && SYNC_APP_DIR !== '' ? rtrim(SYNC_APP_DIR, '/') . '/.env' : null),
        __DIR__ . '/.env',
    ]);

    $file = '';
    foreach ($candidates as $c) {
        if (is_string($c) && $c !== '' && is_readable($c) && is_file($c)) {
            $file = $c;
            break;
        }
    }
    $loaded = $file;
    if ($file === '') {
        return '';
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return $file;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        // پشتیبانی واقعی از قالب `export KEY=value`: ابتدا export حذف می‌شود، سپس نام کلید اعتبارسنجی می‌شود.
        $key = preg_replace('/^export\s+/i', '', $key) ?? $key;
        if ($key === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
            continue;
        }

        // کامنت انتهایی فقط وقتی که مقدار کوتیشن ندارد
        if ($val === '' || ($val[0] !== '"' && $val[0] !== "'")) {
            $hash = strpos($val, ' #');
            if ($hash !== false) {
                $val = rtrim(substr($val, 0, $hash));
            }
        }
        // حذف کوتیشن جفت‌شده
        $len = strlen($val);
        if ($len >= 2 && (($val[0] === '"' && $val[$len - 1] === '"') || ($val[0] === "'" && $val[$len - 1] === "'"))) {
            $val = substr($val, 1, -1);
        }

        $existing = getenv($key);
        if ($existing === false || $existing === '') {
            putenv($key . '=' . $val);
        }
        if (!isset($_ENV[$key]) || $_ENV[$key] === '') {
            $_ENV[$key] = $val;
        }
    }

    return $file;
}

/** خواندن یک مقدار پیکربندی به‌صورت رشته (با پیش‌فرض) */
function env(string $key, string $default = ''): string {
    loadDotEnv();
    $v = getenv($key);
    if ($v === false || $v === '') {
        $v = isset($_ENV[$key]) && is_string($_ENV[$key]) ? $_ENV[$key] : $default;
    }
    return trim((string)$v);
}

/** خواندن یک مقدار عددی */
function envInt(string $key, int $default): int {
    $v = env($key, '');
    return ($v === '' || !is_numeric($v)) ? $default : (int)$v;
}

/** خواندن یک مقدار بولی (1/true/yes/on) */
function envBool(string $key, bool $default): bool {
    $v = strtolower(env($key, ''));
    if ($v === '') {
        return $default;
    }
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}

/** فهرست کلیدهایی که مقدارشان خالی است (برای پیام خطای قابل اقدام) */
function envMissing(array $keys): array {
    $missing = [];
    foreach ($keys as $k) {
        if (env($k) === '') {
            $missing[] = $k;
        }
    }
    return $missing;
}

/** پیام خطای یکنواخت برای پیکربندی ناقص */
function envMissingMessage(array $missing): string {
    return 'پیکربندی ناقص است؛ این کلیدها در .env مقدار ندارند: ' . implode(', ', $missing)
         . ' — برای ساخت خودکار .env دستور «bash setup_env.sh» را اجرا کنید.';
}

// ============================================================
//  تعریف ثابت‌ها — secret ها فقط از .env، بقیه با پیش‌فرض امن
// ============================================================
loadDotEnv();

$SYNC_APP_DIR_DEFAULT = __DIR__;

define('SYNC_APP_DIR', env('SYNC_APP_DIR', $SYNC_APP_DIR_DEFAULT));

// --- دسترسی وب ---
define('SECURITY_KEY', env('SECURITY_KEY', ''));            // ⚠️ فقط از .env
define('DASHBOARD_ALLOWED_IP', env('DASHBOARD_ALLOWED_IP', ''));

// --- مبدأ (ایتا) ---
define('EITAA_CHANNEL_ID', env('EITAA_CHANNEL_ID', 'shamimeashena'));
define('MAX_MESSAGES_LIMIT', envInt('MAX_MESSAGES_LIMIT', 7));

// --- بله ---
define('BALE_BOT_TOKEN', env('BALE_BOT_TOKEN', ''));        // ⚠️ فقط از .env
define('BALE_CHANNEL_ID', env('BALE_CHANNEL_ID', '@testforme'));
define('BALE_ADMIN_CHAT_ID', env('BALE_ADMIN_CHAT_ID', ''));

// --- روبیکا ---
define('RUBIKA_BOT_TOKEN', env('RUBIKA_BOT_TOKEN', ''));    // ⚠️ فقط از .env
define('RUBIKA_CHANNEL_ID', env('RUBIKA_CHANNEL_ID', '@shamimeashena1'));
define('RUBIKA_CHAT_ID_GUID', env('RUBIKA_CHAT_ID_GUID', ''));
define('RUBIKA_CHAT_ID_USER', env('RUBIKA_CHAT_ID_USER', RUBIKA_CHANNEL_ID));

// --- سروش‌پلاس: UserBot (مسیر اصلی) ---
define('SOROUSH_CHANNEL_ID', env('SOROUSH_CHANNEL_ID', 'shamimeashena1'));
define('SOROUSH_CHANNEL_NAME', env('SOROUSH_CHANNEL_NAME', 'شمیم آشنا'));
define('SOROUSH_SCRIPT', env('SOROUSH_SCRIPT', SYNC_APP_DIR . '/send_soroush.js'));
define('SOROUSH_PROFILE_DIR', env('SOROUSH_PROFILE_DIR', SYNC_APP_DIR . '/soroush_profile'));

// --- سروش‌پلاس: Bot API (مسیر جایگزین/تست) ---
define('SOROUSH_BOT_TOKEN', env('SOROUSH_BOT_TOKEN', ''));  // ⚠️ فقط از .env
define('SOROUSH_CHAT_ID', env('SOROUSH_CHAT_ID', ''));

// --- آی‌گپ ---
define('IGAP_CHANNEL_ID', env('IGAP_CHANNEL_ID', 'shamimeashena'));
define('IGAP_CHANNEL_NAME', env('IGAP_CHANNEL_NAME', 'شمیم آشنا'));
define('IGAP_ITEM_ID', env('IGAP_ITEM_ID', '16200343869985976'));
define('IGAP_SCRIPT', env('IGAP_SCRIPT', SYNC_APP_DIR . '/send_igap.js'));
define('IGAP_PROFILE_DIR', env('IGAP_PROFILE_DIR', SYNC_APP_DIR . '/igap_profile'));

// --- پروفایل‌های مقصد: main = کانال‌های اصلی، test = کانال‌های قبلی/آزمایشی ---
define('MAIN_BALE_CHANNEL_ID', env('MAIN_BALE_CHANNEL_ID', '@shamimeashena'));
define('TEST_BALE_CHANNEL_ID', env('TEST_BALE_CHANNEL_ID', BALE_CHANNEL_ID));
define('MAIN_RUBIKA_CHANNEL_ID', env('MAIN_RUBIKA_CHANNEL_ID', '@shamimeashena'));
define('TEST_RUBIKA_CHANNEL_ID', env('TEST_RUBIKA_CHANNEL_ID', RUBIKA_CHANNEL_ID));
define('MAIN_SOROUSH_CHANNEL_ID', env('MAIN_SOROUSH_CHANNEL_ID', 'shamimeashena'));
define('MAIN_SOROUSH_CHANNEL_NAME', env('MAIN_SOROUSH_CHANNEL_NAME', SOROUSH_CHANNEL_NAME));
define('TEST_SOROUSH_CHANNEL_ID', env('TEST_SOROUSH_CHANNEL_ID', SOROUSH_CHANNEL_ID));
define('TEST_SOROUSH_CHANNEL_NAME', env('TEST_SOROUSH_CHANNEL_NAME', SOROUSH_CHANNEL_NAME));
define('MAIN_IGAP_CHANNEL_ID', env('MAIN_IGAP_CHANNEL_ID', 'shamimeashena'));
define('MAIN_IGAP_CHANNEL_NAME', env('MAIN_IGAP_CHANNEL_NAME', IGAP_CHANNEL_NAME));
define('MAIN_IGAP_ITEM_ID', env('MAIN_IGAP_ITEM_ID', IGAP_ITEM_ID));
define('TEST_IGAP_CHANNEL_ID', env('TEST_IGAP_CHANNEL_ID', IGAP_CHANNEL_ID));
define('TEST_IGAP_CHANNEL_NAME', env('TEST_IGAP_CHANNEL_NAME', IGAP_CHANNEL_NAME));
define('TEST_IGAP_ITEM_ID', env('TEST_IGAP_ITEM_ID', IGAP_ITEM_ID));

// --- زمان اجرا و زمان‌بندی ---
define('NODE_BIN', env('NODE_BIN', '/usr/bin/node'));
define('USERBOT_TIMEOUT_SEC', envInt('USERBOT_TIMEOUT_SEC', 240));
define('MEDIA_MAX_RETRY', envInt('MEDIA_MAX_RETRY', 3));
define('SYNC_GAP_SEC', envInt('SYNC_GAP_SEC', 5));
define('CHECK_INTERVAL_SEC', envInt('CHECK_INTERVAL_SEC', 30));
define('ENABLE_SOROUSH_BOT', envBool('ENABLE_SOROUSH_BOT', false));

// --- مسیرهای داده ---
define('STATE_DB_PATH', env('STATE_DB_PATH', SYNC_APP_DIR . '/state.sqlite'));
define('LOG_DIR', env('LOG_DIR', SYNC_APP_DIR . '/logs'));
