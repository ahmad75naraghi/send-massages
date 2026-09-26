'use strict';
/**
 * lib/pw_common.js
 * ============================================================
 *  لایهٔ مشترک خودکارسازی UserBot (سروش‌پلاس و آی‌گپ)
 *
 *  چرا این ماژول وجود دارد؟
 *  هر دو اسکریپت ارسال پیش‌تر همین منطق را به‌صورت موازی و کمی متفاوت
 *  پیاده‌سازی کرده بودند و همین اختلاف‌های ریز (تایم‌اوت، ترتیب سلکتور،
 *  نحوهٔ خروج) باعث خطاهای «نمونه درست / تولید خراب» شده بود.
 *
 *  قراردادهای سخت این ماژول:
 *   ۱) stdout فقط و فقط یک خط JSON در پایان (قرارداد با PHP).
 *   ۲) همهٔ لاگ‌های مرحله‌ای روی stderr و فایل logs/ می‌روند.
 *   ۳) هرگز process.exit() داخل catch صدا زده نمی‌شود؛ exitCode ست می‌شود
 *      تا finally بتواند مرورگر را ببندد (وگرنه قفل Singleton می‌ماند).
 *   ۴) هیچ متدی بدون timeout صریح یا پیش‌فرضِ محدود اجرا نمی‌شود.
 * ============================================================
 */

const fs = require('fs');
const path = require('path');

const REPO_DIR = path.resolve(__dirname, '..');

/**
 * خواندن .env کنار برنامه (همان فایلی که config.php سمت PHP می‌خواند).
 * متغیرهای محیطیِ از قبل موجود هرگز بازنویسی نمی‌شوند، پس systemd/cron
 * می‌توانند هر مقدار را override کنند.
 */
function loadDotEnv(file) {
    try {
        if (!file || !fs.existsSync(file)) return false;
        const lines = fs.readFileSync(file, 'utf8').split(/\r?\n/);
        for (let raw of lines) {
            const line = raw.trim();
            if (!line || line.startsWith('#') || line.startsWith(';')) continue;
            const eq = line.indexOf('=');
            if (eq < 1) continue;
            let key = line.slice(0, eq).trim().replace(/^export\s+/, '');
            let val = line.slice(eq + 1).trim();
            if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(key)) continue;
            if (val && val[0] !== '"' && val[0] !== "'") {
                const hash = val.indexOf(' #');
                if (hash > -1) val = val.slice(0, hash).trim();
            }
            if (val.length >= 2 && ((val[0] === '"' && val.slice(-1) === '"') || (val[0] === "'" && val.slice(-1) === "'"))) {
                val = val.slice(1, -1);
            }
            const cur = process.env[key];
            if (cur === undefined || cur === '') process.env[key] = val;
        }
        return true;
    } catch (e) {
        return false;
    }
}

// .env از SYNC_ENV_FILE یا دایرکتوری ریپو خوانده می‌شود (و سپس از SYNC_APP_DIR)
const ENV_PATH = process.env.SYNC_ENV_FILE || path.join(REPO_DIR, '.env');
const ENV_FILE = loadDotEnv(ENV_PATH) ? ENV_PATH : null;

/** دسترسی یکنواخت به پیکربندی (محیط > .env > پیش‌فرض) */
function env(key, def = '') {
    const v = process.env[key];
    return (v === undefined || v === '') ? def : String(v);
}

// اگر .env وجود نداشت، پیش‌فرض باید همین checkout باشد؛ مسیر تولید فقط در .env.example پیشنهاد شده است.
const APP_DIR = env('SYNC_APP_DIR', REPO_DIR);
if (APP_DIR !== REPO_DIR) loadDotEnv(path.join(APP_DIR, '.env'));

const CHROMIUM_BIN = env('SYNC_CHROMIUM_BIN', '/usr/bin/chromium-browser');

/**
 * تحلیل آرگومان‌های --flag=value (مقدار می‌تواند شامل = باشد).
 *
 * دفاع در برابر باگ تاریخی پروژه: نسخه‌ای قدیمی از sync_manual.php با
 * sprintf('\%s') مقدارها را به‌شکل --channel='shamimeashena1' (با کوتیشن
 * واقعی) به shell می‌داد و در نتیجه آدرس #@'...' هرگز resolve نمی‌شد.
 * بنابراین کوتیشنِ جفت‌شدهٔ اطراف هر مقدار، پیش از مصرف، حذف می‌شود.
 * (نقل‌قول‌های فارسی «» و کوتیشن‌های تکی داخل متن دست‌نخورده می‌مانند.)
 */
function parseArgs(argv) {
    const args = argv.slice(2);
    const clean = (v) => {
        let s = String(v == null ? '' : v).trim();
        while (s.length >= 2 && ((s[0] === "'" && s[s.length - 1] === "'") || (s[0] === '"' && s[s.length - 1] === '"'))) {
            s = s.slice(1, -1).trim();
        }
        return s;
    };
    const get = (flag, def = null) => {
        const found = args.find(a => a.startsWith(`--${flag}=`));
        if (!found) return def;
        return clean(found.split('=').slice(1).join('='));
    };
    const channel = (get('channel', '') || '').replace(/^@/, '');
    return {
        channel,
        channelName: get('channel-name', null) || null,
        text: get('text', '') || '',
        file: get('file', null) || null,
        type: (get('type', '') || '').toLowerCase(),
        itemId: get('item-id', null) || null,
        /** برای اسکریپت‌های جانبی مثل dump_dom.js */
        raw: get,
    };
}

const delay = (ms) => new Promise(r => setTimeout(r, ms));

const ts = () => {
    const d = new Date();
    const p = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}_${p(d.getHours())}${p(d.getMinutes())}${p(d.getSeconds())}`;
};

/** لاگر مرحله‌ای: stderr (دیباگ) + فایل ماندگار */
class RunLog {
    constructor(platform) {
        this.platform = platform;
        this.file = path.join(APP_DIR, 'logs', `send_${platform}_${ts()}_${process.pid}.log`);
        try {
            fs.mkdirSync(path.join(APP_DIR, 'logs'), { recursive: true });
        } catch (e) { /* اگر logs ساخته نشد، لاگ فقط روی stderr می‌رود */ }
        this.step(`RUN START pid=${process.pid} platform=${platform}`);
    }
    step(msg) {
        const line = `[${new Date().toISOString()}] ${msg}`;
        process.stderr.write(line + '\n');
        try { fs.appendFileSync(this.file, line + '\n'); } catch (e) {}
    }
    /** خلاصهٔ امن یک خط برای پیام خطا (بدون شکست خط JSON) */
    static brief(s, max = 220) {
        return String(s).replace(/\s+/g, ' ').trim().slice(0, max);
    }
}

/**
 * یافتن باینری کرومیوم. ترتیب: متغیر محیطی SYNC_CHROMIUM_BIN ← مسیر پیش‌فرض
 * AlmaLinux ← نام‌های رایج دیگر. اگر هیچ‌کدام نبود، همان پیش‌فرض برگردانده
 * می‌شود تا پیام خطا «مسیر واقعیِ ناموجود» را نشان دهد (نه یک مسیر تصادفی).
 */
function resolveChromium() {
    const envBin = env('SYNC_CHROMIUM_BIN');
    if (envBin) return envBin;
    const candidates = [CHROMIUM_BIN, '/usr/bin/chromium', '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable'];
    for (const c of candidates) {
        try {
            fs.accessSync(c, fs.constants.X_OK);
            return c;
        } catch (e) { /* بعدی */ }
    }
    return CHROMIUM_BIN;
}

/** حذف قفل‌های Singleton پیش از launch (دفاع در برابر اجرای قبلیِ crash‌شده) */
function cleanSingletons(profileDir, log) {
    for (const f of ['SingletonLock', 'SingletonCookie', 'SingletonSocket']) {
        const p = path.join(profileDir, f);
        try {
            if (fs.existsSync(p)) { fs.unlinkSync(p); log.step(`singleton removed: ${f}`); }
        } catch (e) {
            log.step(`singleton unlink FAILED ${f}: ${e.message}`);
        }
    }
}

/**
 * راه‌اندازی مرورگر با پروفایل پایدار.
 * نکتهٔ مهم: userAgent عمداً override نمی‌شود مگر با متغیر محیطی SYNC_USER_AGENT.
 * (نمونه‌های کاری پروژه — inspect_*.js — بدون override اجرا شده و موفق بودند.)
 */
async function launchBrowser(playwright, profileDir, viewport, log) {
    try { fs.mkdirSync(profileDir, { recursive: true, mode: 0o700 }); } catch (e) { log.step(`profile mkdir FAILED ${profileDir}: ${e.message}`); }
    cleanSingletons(profileDir, log);

    const browserBin = resolveChromium();
    log.step(`chromium binary: ${browserBin}`);

    const opts = {
        executablePath: browserBin,
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu',
            '--disable-features=IsolateOrigins,site-per-process',
            '--mute-audio',
        ],
        headless: true,
        viewport,
        locale: 'fa-IR',
        timezoneId: 'Asia/Tehran',
        ignoreHTTPSErrors: true,
    };
    const ua = env('SYNC_USER_AGENT');
    if (ua) opts.userAgent = ua;
    // برای ورود تعاملی روی ماشین دارای نمایشگر: SYNC_HEADED=1 node login_igap.js
    if (env('SYNC_HEADED') === '1') opts.headless = false;

    log.step(`launch persistent context: ${profileDir}`);
    const browser = await playwright.chromium.launchPersistentContext(profileDir, opts);

    const page = browser.pages()[0] || await browser.newPage();

    // کرانهٔ زمانی سراسری: هیچ انتظاری بیش از ۳۰ ثانیه بی‌پاسخ نمی‌ماند
    page.setDefaultTimeout(30000);
    page.setDefaultNavigationTimeout(60000);

    // دیالوگ‌های بومی (alert/confirm/beforeunload) هرگز جریان را متوقف نکنند
    page.on('dialog', d => { d.dismiss().catch(() => {}); });
    page.on('crash', () => log.step('WARNING: page crashed'));
    page.on('console', m => {
        if (m.type() === 'error') log.step('console.error: ' + RunLog.brief(m.text(), 160));
    });

    return { browser, page };
}

/** اسکرین‌شات شاهد؛ هرگز نباید نتیجهٔ اصلی را خراب کند */
async function safeScreenshot(page, name, log) {
    const file = path.join(APP_DIR, name);
    try {
        await page.screenshot({ path: file, timeout: 15000 });
        log.step(`screenshot: ${name}`);
        return file;
    } catch (e) {
        log.step(`screenshot FAILED ${name}: ${e.message}`);
        return null;
    }
}

/** درج متن بدون شبیه‌سازی کلید (Enter فیزیکی = ارسال زودهنگام؛ ممنوع) */
async function insertText(page, text, log) {
    await page.keyboard.insertText(text);
    log.step(`insertText: ${text.length} chars`);
}

/** انتظار شرطی عمومی با polling (جایگزین درست isVisible({timeout}) که نادیده گرفته می‌شود) */
async function waitUntil(fn, { timeout = 20000, interval = 500, label = 'condition' } = {}) {
    const started = Date.now();
    let lastErr = null;
    while (Date.now() - started < timeout) {
        try {
            const v = await fn();
            if (v) return v;
        } catch (e) { lastErr = e; }
        await delay(interval);
    }
    throw new Error(`waitUntil timeout: ${label}${lastErr ? ' (' + RunLog.brief(lastErr.message, 120) + ')' : ''}`);
}

/** آیا locator دیده می‌شود؟ (بدون پرتاب استثنا، بدون اتکا به isVisible({timeout})) */
async function seen(locator, timeout = 5000) {
    try {
        await locator.first().waitFor({ state: 'visible', timeout });
        return true;
    } catch (e) {
        return false;
    }
}

/**
 * اولین سلکتورِ «دیده‌شده» از یک لیست را برمی‌گرداند.
 * جایگزین الگوی شکنندهٔ isVisible() فوری + ایندکس موقعیتی.
 */
async function firstVisible(page, selectors, { timeout = 4000, label = 'element' } = {}) {
    const deadline = Date.now() + timeout;
    do {
        for (const sel of selectors) {
            const loc = page.locator(sel).first();
            try {
                if (await loc.isVisible()) return { locator: loc, selector: sel };
            } catch (e) { /* strict/invalid selector: رد شو */ }
        }
        await delay(300);
    } while (Date.now() < deadline);
    return null;
}

/** بستن ایمن مرورگر — هرگز استثنا پرتاب نمی‌کند */
async function closeQuietly(browser, log) {
    try {
        if (browser) await browser.close();
        log.step('browser closed');
    } catch (e) {
        log.step(`browser.close FAILED: ${e.message}`);
    }
}

/** خروجی استاندارد قرارداد با PHP (تنها چیزی که روی stdout می‌رود) */
function emit(result) {
    process.stdout.write(JSON.stringify(result) + '\n');
}

/**
 * خواندن JSON از فایل؛ اگر فایل نبود یا خراب بود، null برمی‌گرداند
 * (هرگز استثنا پرتاب نمی‌کند — progress فایل نباید جریان ارسال را بشکند).
 */
function readJsonFile(file) {
    try {
        return JSON.parse(fs.readFileSync(file, 'utf8'));
    } catch (e) {
        return null;
    }
}

/**
 * نوشتن اتمیک JSON: اول در فایل موقت کنار مقصد، بعد rename.
 * خوانندهٔ هم‌زمان (worker PHP) یا JSON کامل قدیمی را می‌بیند یا جدید را،
 * هرگز نیم‌کاره را — به همین دلیل progress پس‌زمینه با این تابع نوشته می‌شود.
 */
function writeJsonFileAtomic(file, data) {
    try {
        const tmp = `${file}.${process.pid}.tmp`;
        fs.writeFileSync(tmp, JSON.stringify(data), 'utf8');
        fs.renameSync(tmp, file);
        return true;
    } catch (e) {
        return false;
    }
}

/**
 * تشخیص صفحهٔ ورود (session منقضی) — به‌جای ۶۰ ثانیه timeout مبهم،
 * در چند ثانیه یک خطای قابل‌اقدام برمی‌گرداند.
 */
async function detectLoginPage(page, markers) {
    for (const m of markers) {
        try {
            if (await page.locator(m).first().isVisible()) return m;
        } catch (e) {}
    }
    return null;
}

/** برش امن متن برای استفاده در getMessage/تأیید (اولین خط، بدون کاراکتر کنترلی) */
function snippetOf(text, len = 32) {
    const firstLine = String(text || '').split('\n').map(s => s.trim()).filter(Boolean)[0] || '';
    return firstLine.slice(0, len);
}

module.exports = {
    APP_DIR, REPO_DIR, ENV_FILE, CHROMIUM_BIN, env, loadDotEnv, resolveChromium,
    parseArgs, delay, ts,
    RunLog, cleanSingletons, launchBrowser,
    safeScreenshot, insertText, waitUntil, seen, firstVisible,
    closeQuietly, emit, detectLoginPage, snippetOf,
    readJsonFile, writeJsonFileAtomic,
};
