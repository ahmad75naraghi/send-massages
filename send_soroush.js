'use strict';
/**
 * send_soroush.js — ارسال به سروش‌پلاس (web.splus.ir / کلون Telegram WebZ)
 * ============================================================
 *  قرارداد خروجی با sync_manual.php:
 *    موفق:  stdout → {"status":"OK","message":...,"verified":true,...}   exit 0
 *    خطا:   stdout → {"status":"ERROR","code":...,"error":...}           exit 1
 *    مشکوک: stdout → {"status":"UNVERIFIED",...}                          exit 1
 *
 *  تفاوت‌های این نسخه با نسخهٔ پیشین (ریشهٔ «نمونه درست / تولید خراب»):
 *   ۱) ناوبری کانال دیگر فقط به hash route تکیه نمی‌کند؛ سه راهبرد با
 *      «تأیید باز شدن چت درست» اجرا می‌شود (لیست ← جست‌وجو ← hash).
 *   ۲) composer از `.MiddleColumn` اسکوپ می‌شود تا strict mode violation
 *      و تایپ در عنصر اشتباه رخ ندهد.
 *   ۳) هیچ‌جا isVisible({timeout}) استفاده نمی‌شود (در Playwright 1.63
 *      تایم‌اوت آن deprecated و بی‌اثر است)؛ به‌جایش waitFor/waitUntil.
 *   ۴) انتخاب آیتم منوی ضمیمه با آیکون/متن (fa+en) است، نه ایندکس موقعیتی.
 *   ۵) پس از ارسال، صحت ارسال تأیید می‌شود؛ بدون تأیید، OK گزارش نمی‌شود.
 *   ۶) process.exit() فقط پس از browser.close() اجرا می‌شود
 *      (ورنه کرومیوم یتیم و قفل Singleton برای اجرای بعدی می‌ماند).
 * ============================================================
 */

const path = require('path');
const { chromium } = require('playwright');
const C = require(path.join(__dirname, 'lib', 'pw_common.js'));

const PROFILE_DIR = C.env('SOROUSH_PROFILE_DIR', path.join(C.APP_DIR, 'soroush_profile'));
const VIEWPORT = { width: 1280, height: 720 };

const LOGIN_MARKERS = ['#sign-in-phone-number', '#sign-in-phone-code', 'button:has-text("دریافت کد")'];

// سلکتورهای دکمهٔ ضمیمه (سنجاقک) — ترتیب = اولویت
const ATTACH_BTN = [
    '.AttachMenu button',
    'button.attach-file',
    '.attach-file',
    'button:has(i.icon-attach)',
    'button[aria-label*="Attach"]',
    'button[title*="Attach"]',
];

// آیتم «تصویر/ویدیو» در منوی ضمیمه — آیکون، متن انگلیسی، متن فارسی، و در نهایت ایندکس
const MENU_VISUAL = [
    '.MenuItem:has(i.icon-attach-photo-or-video)',
    '.MenuItem:has(i.icon-photo)',
    '.MenuItem:has-text("Photo or Video")',
    '.MenuItem:has-text("Photo or video")',
    '.MenuItem:has-text("عکس یا ویدیو")',
    '.MenuItem:has-text("تصویر یا ویدیو")',
];
// آیتم «سند/فایل»
const MENU_DOC = [
    '.MenuItem:has(i.icon-document)',
    '.MenuItem:has-text("Document")',
    '.MenuItem:has-text("سند")',
    '.MenuItem:has-text("فایل")',
];

const MODAL = '.modal-dialog, .Modal, [role="dialog"]';
const MODAL_SEND = [
    `${MODAL} button.confirm-dialog-button`,
    `${MODAL} button.primary`,
    `${MODAL} button.btn-primary`,
    `${MODAL} button:has-text("ارسال")`,
    `${MODAL} button:has-text("Send")`,
];

const escapeRegex = (s) => String(s || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const normalizeBrief = (s) => String(s || '').replace(/[‌‏‪-‮]/g, ' ').replace(/\s+/g, ' ').trim().toLowerCase();

function hasExactChannelToken(text, channel) {
    const c = String(channel || '').replace(/^@/, '').toLowerCase();
    if (!c) return false;
    return new RegExp(`(^|[^A-Za-z0-9_])@?${escapeRegex(c)}($|[^A-Za-z0-9_])`, 'i').test(normalizeBrief(text));
}

function hasWrongChannelVariant(text, channel) {
    const c = String(channel || '').replace(/^@/, '').toLowerCase();
    if (!c) return false;
    const n = normalizeBrief(text);
    // نمونهٔ خطرناک واقعی: جست‌وجوی @shamimeashena نتیجهٔ shamimeashena1 را هم می‌آورد.
    return new RegExp(`(^|[^A-Za-z0-9_@])@?${escapeRegex(c)}[A-Za-z0-9_]+`, 'i').test(n)
        && !hasExactChannelToken(n, c);
}

function strictCandidateStatus(brief, channel, expectedName) {
    const name = normalizeBrief(expectedName || '');
    const text = normalizeBrief(brief);
    if (hasWrongChannelVariant(text, channel)) {
        return { ok: false, reason: 'near-match username is not exact target' };
    }
    if (name && text.includes(name)) {
        return { ok: true, reason: 'display-name match' };
    }
    if (hasExactChannelToken(text, channel)) {
        return { ok: true, reason: 'exact username match' };
    }
    return { ok: false, reason: 'no exact username/display-name evidence' };
}

const COMPOSER = '.MiddleColumn .input-message-input, .MiddleColumn div[contenteditable="true"], #MiddleColumn .input-message-input, #MiddleColumn div[contenteditable="true"]';
const NEW_POST_BUTTONS = [
    '.MiddleColumn button:has-text("پیام جدید")',
    '.MiddleColumn [role="button"]:has-text("پیام جدید")',
    '.MiddleColumn a:has-text("پیام جدید")',
    '#MiddleColumn button:has-text("پیام جدید")',
    '#MiddleColumn [role="button"]:has-text("پیام جدید")',
    '#MiddleColumn a:has-text("پیام جدید")',
    '.MiddleColumn button:has-text("ارسال پیام")',
    '#MiddleColumn button:has-text("ارسال پیام")',
    '.MiddleColumn button:has-text("New Message")',
    '#MiddleColumn button:has-text("New Message")',
    '.MiddleColumn button:has-text("New Post")',
    '#MiddleColumn button:has-text("New Post")',
];

async function ensureComposer(page, log, timeout = 12000) {
    if (await C.seen(page.locator(COMPOSER).first(), 1200)) return true;
    const newPost = await C.firstVisible(page, NEW_POST_BUTTONS, { timeout: 3500, label: 'new post button' });
    if (newPost) {
        await newPost.locator.click({ force: true });
        log.step(`new-post button clicked via ${newPost.selector}`);
        await C.delay(1200);
        if (await C.seen(page.locator(COMPOSER).first(), timeout)) return true;
        log.step('new-post clicked but composer still not visible');
    }
    return await C.seen(page.locator(COMPOSER).first(), 1000);
}

async function openChatByName(page, name, log) {
    if (!name) return false;
    const item = page.locator(`.LeftColumn .ListItem:has-text("${name}"), .LeftColumn .chat-list .ListItem:has-text("${name}")`).first();
    if (!(await C.seen(item, 4000))) return false;
    await item.click({ force: true });
    log.step(`openChatByName: clicked list item "${name}"`);
    return await ensureComposer(page, log, 8000);
}

async function openChatBySearch(page, channel, log, strict = false, expectedName = null) {
    if (!channel) return false;
    const query = strict ? `@${channel}` : channel;
    const boxSel = '#search-input, .SearchInput input, input[type="search"], #telegram-search-input';
    const resultSel = '.LeftSearch .ListItem, .search-results .ListItem, .LeftColumn .ListItem';

    const runSearch = async () => {
        const box = page.locator(boxSel).first();
        if (!(await C.seen(box, 5000))) { log.step('openChatBySearch: search box not found'); return false; }
        await box.click({ force: true });
        await box.fill('');
        await box.fill(query);
        await C.delay(2500);
        return true;
    };

    if (!(await runSearch())) return false;

    if (strict) {
        // اول exact username در متن نتیجه؛ اگر سروش username را در innerText پنهان کرده بود،
        // همهٔ نتایج همین query دقیق @username را یکی‌یکی امتحان می‌کنیم تا نتیجه‌ای که composer دارد پیدا شود.
        const exactUser = new RegExp(`@${escapeRegex(channel)}(?![A-Za-z0-9_])`, 'i');
        const exact = page.locator(resultSel).filter({ hasText: exactUser }).first();
        if (await C.seen(exact, 2500)) {
            await exact.click({ force: true });
            log.step(`openChatBySearch(strict): clicked exact username result for "${query}"`);
            if (await ensureComposer(page, log, 15000)) return true;
            log.step('openChatBySearch(strict): exact result opened but composer not visible');
        } else {
            log.step('openChatBySearch(strict): exact username text not visible; iterating visible results for exact @query');
        }

        const maxTry = Math.min(6, await page.locator(resultSel).count().catch(() => 0));
        for (let i = 0; i < maxTry; i++) {
            if (i > 0 && !(await runSearch())) return false;
            const item = page.locator(resultSel).nth(i);
            if (!(await C.seen(item, 3000))) continue;
            const brief = await item.innerText({ timeout: 1000 }).catch(() => '');
            const status = strictCandidateStatus(brief, channel, expectedName);
            if (!status.ok) {
                log.step(`openChatBySearch(strict): skipped result #${i + 1} for "${query}" (${status.reason}) text="${C.RunLog.brief(brief, 80)}"`);
                continue;
            }
            await item.click({ force: true });
            log.step(`openChatBySearch(strict): clicked result #${i + 1} for "${query}" (${status.reason}) text="${C.RunLog.brief(brief, 80)}"`);
            if (await ensureComposer(page, log, 15000)) return true;
            log.step(`openChatBySearch(strict): result #${i + 1} opened but composer not visible`);
        }
        return false;
    }

    const result = page.locator('.LeftSearch .ListItem, .search-results .ListItem, .LeftColumn .ListItem:has-text("' + channel + '")').first();
    if (!(await C.seen(result, 6000))) { log.step('openChatBySearch: no result for ' + channel); return false; }
    await result.click({ force: true });
    log.step(`openChatBySearch: clicked result for "${query}"`);
    return await ensureComposer(page, log, 8000);
}

async function openChatByHash(page, browser, channel, log) {
    if (!channel) return false;
    const route = /^-?\d+$/.test(String(channel)) ? `#${channel}` : `#@${channel}`;
    await page.goto(`https://web.splus.ir/${route}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await C.delay(6000);
    const url = page.url();
    let hash = '';
    try { hash = new URL(url).hash; } catch (e) {}
    if (route.startsWith('#@') && (!hash || hash.toLowerCase() === route.toLowerCase())) {
        log.step(`openChatByHash: ${route} → url=${url} did not resolve to a concrete chat hash`);
        return false;
    }
    const ok = await ensureComposer(page, log, 15000);
    log.step(`openChatByHash: ${route} → url=${page.url()} composer ${ok ? 'visible' : 'NOT visible'}`);
    return ok;
}

async function readHeader(page) {
    try {
        const t = await page.locator('.MiddleColumn .peer-title, .MiddleColumn .chat-info .title, .MiddleColumn .top .title, .MiddleColumn h3').first().textContent({ timeout: 4000 });
        return (t || '').trim();
    } catch (e) { return ''; }
}

/**
 * تأیید اینکه چتِ باز شده واقعاً همان کانال مقصد است.
 * composer مرئی بودن به‌تنهایی کافی نیست: اگر :has-text روی چند چت مطابقت
 * کند، .first() چت اشتباهی را باز می‌کند و composer هم مرئی است.
 * اگر هدر خوانده نشود (خالی)، نتیجه «نامشخص» است و با هشدار پذیرفته می‌شود.
 */
async function headerMatches(page, name, log) {
    if (!name) return { ok: true, header: '' };
    const h = await readHeader(page);
    if (!h) { log.step('WARNING: header not readable; cannot confirm target chat'); return { ok: true, header: '' }; }
    const ok = h.includes(name) || name.includes(h);
    if (!ok) log.step(`header mismatch: got "${h}" want "${name}"`);
    return { ok, header: h };
}

/** شمارش حباب‌های پیام در ستون میانی (سیگنال تأیید ارسال) */
async function countMessages(page) {
    return page.evaluate(() => document.querySelectorAll('.MiddleColumn .Message, .MiddleColumn .message, .MiddleColumn .bubble').length).catch(() => -1);
}

/** متن پیش‌نمایش چت فعال در لیست سمت چپ (سیگنال تأیید ارسال) */
async function readActivePreview(page) {
    return page.evaluate(() => {
        const el = document.querySelector('.LeftColumn .ListItem.active, .LeftColumn .ListItem.selected, .LeftColumn [aria-selected="true"], .LeftColumn .chat-list .ListItem:first-child');
        return el ? (el.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 120) : '';
    }).catch(() => '');
}

(async () => {
    const opts = C.parseArgs(process.argv);
    // اولویت: آرگومان خط فرمان > .env (SOROUSH_*) > بدون مقدار
    // نام/شناسهٔ کانال پیش از ورود به سلکتور از کاراکترهای شکننده پاک می‌شود
    opts.channel = String(opts.channel || C.env('SOROUSH_CHANNEL_ID') || '').replace(/["\\]/g, '');
    opts.channelName = (opts.channelName || C.env('SOROUSH_CHANNEL_NAME') || null);
    opts.channelName = opts.channelName ? String(opts.channelName).replace(/["\\]/g, '') : null;
    opts.strictChannel = String(opts.raw('strict-channel', C.env('SOROUSH_STRICT_CHANNEL', '0')) || '0') === '1';
    const log = new C.RunLog('soroush');
    log.step(`args: channel=${opts.channel || '-'} name=${opts.channelName || '-'} text=${opts.text.length}ch file=${opts.file || '-'} type=${opts.type || '-'} env=${C.ENV_FILE || 'none'}`);

    let browser = null;
    let exitCode = 0;
    let result = null;

    try {
        if (!opts.text && !opts.file) {
            result = { status: 'ERROR', code: 'EMPTY_PAYLOAD', error: 'هم text و هم file خالی است؛ چیزی برای ارسال نیست' };
            exitCode = 1;
            return;
        }
        if (!opts.channel && !opts.channelName) {
            result = { status: 'ERROR', code: 'NO_CHANNEL', error: 'کانال مقصد مشخص نیست؛ --channel یا --channel-name بدهید یا SOROUSH_CHANNEL_ID/SOROUSH_CHANNEL_NAME را در .env تنظیم کنید' };
            exitCode = 1;
            return;
        }
        if (opts.file && !require('fs').existsSync(opts.file)) {
            result = { status: 'ERROR', code: 'FILE_MISSING', error: `فایل وجود ندارد: ${opts.file}` };
            exitCode = 1;
            return;
        }

        const launched = await C.launchBrowser({ chromium }, PROFILE_DIR, VIEWPORT, log);
        browser = launched.browser;
        const page = launched.page;

        // ---------- ۱) بارگذاری و بررسی session ----------
        await page.goto('https://web.splus.ir', { waitUntil: 'domcontentloaded', timeout: 60000 });
        await C.delay(5000);

        // بستن پاپ‌آپ PWA («متوجه شدم») که در نمونهٔ ورود لازم بود
        try {
            const dismiss = page.locator('button:has-text("متوجه شدم"), button:has-text("Got it")').first();
            if (await C.seen(dismiss, 1500)) {
                await dismiss.click({ force: true });
                log.step('PWA popup dismissed');
            }
        } catch (e) {}

        const loginMarker = await C.detectLoginPage(page, LOGIN_MARKERS);
        if (loginMarker) {
            await C.safeScreenshot(page, 'last_media_send.jpg', log);
            result = { status: 'ERROR', code: 'SESSION_EXPIRED', error: `صفحهٔ ورود سروش‌پلاس دیده شد (${loginMarker}). با login_soroush.js دوباره وارد شوید.` };
            exitCode = 1;
            return;
        }

        // ---------- ۲) باز کردن چت مقصد (سه راهبرد + تأیید) ----------
        // هر راهبرد باید دو شرط را بگذراند: composer مرئی + تطابق هدر با نام کانال
        const strategies = [];
        // اگر strict-channel روشن باشد، فقط نتیجهٔ exact username قابل قبول است؛
        // این جلوی ارسال اشتباهی به کانال تست با نام نمایشی مشابه را می‌گیرد.
        if (opts.strictChannel) {
            // hash route uses the exact username and avoids the duplicate display-name problem.
            strategies.push(['by-hash-strict', () => openChatByHash(page, browser, opts.channel, log)]);
            strategies.push(['by-search-strict', () => openChatBySearch(page, opts.channel, log, true, opts.channelName)]);
        } else {
            strategies.push(['by-hash', () => openChatByHash(page, browser, opts.channel, log)]);
            strategies.push(['by-search', () => openChatBySearch(page, opts.channel, log, false)]);
            if (opts.channelName) strategies.push(['by-name', () => openChatByName(page, opts.channelName, log)]);
        }

        let opened = false;
        let openedVia = '';
        let header = '';
        for (const [label, run] of strategies) {
            const ok = await run();
            if (!ok) { log.step(`${label}: composer not visible → next strategy`); continue; }
            const hm = await headerMatches(page, opts.channelName, log);
            if (!hm.ok) { log.step(`${label}: chat opened but header mismatch → next strategy`); continue; }
            opened = true; openedVia = label; header = hm.header;
            break;
        }

        if (!opened) {
            await C.safeScreenshot(page, 'last_media_send.jpg', log);
            result = {
                status: 'ERROR', code: 'CHANNEL_NOT_FOUND',
                error: `کانال "${opts.channel || opts.channelName}" با هیچ‌یک از سه راهبرد باز/تأیید نشد. نام نمایشی دقیق را با --channel-name بدهید.`,
                log: log.file
            };
            exitCode = 1;
            return;
        }
        log.step(`chat opened via ${openedVia}. header="${header}"`);

        const composer = page.locator(COMPOSER).last();

        // ---------- ۳) سنجه‌های «پیش از ارسال» برای تأیید ----------
        const beforeCount = await countMessages(page);
        const beforePreview = await readActivePreview(page);
        const snippet = C.snippetOf(opts.text);
        log.step(`before: messages=${beforeCount} preview="${C.RunLog.brief(beforePreview, 60)}"`);

        let sentVia = 'none';

        // ---------- ۴) مسیر رسانه ----------
        if (opts.file) {
            const isVisual = (opts.type === 'image' || opts.type === 'video')
                || (!opts.type && ['.jpg', '.jpeg', '.png', '.webp', '.gif', '.mp4'].includes(path.extname(opts.file).toLowerCase()));

            const attach = await C.firstVisible(page, ATTACH_BTN, { timeout: 10000, label: 'attach button' });
            if (!attach) {
                await C.safeScreenshot(page, 'last_media_send.jpg', log);
                result = { status: 'ERROR', code: 'ATTACH_BUTTON_NOT_FOUND', error: 'دکمهٔ ضمیمه در composer پیدا نشد', log: log.file };
                exitCode = 1;
                return;
            }
            await attach.locator.click({ force: true });
            log.step(`attach button clicked via ${attach.selector}`);
            await C.delay(1000);

            const menu = page.locator('.menu-container:not(.not-open), .AttachMenu .menu-container, .bubble.open').last();
            if (!(await C.seen(menu, 6000))) {
                await C.safeScreenshot(page, 'last_media_send.jpg', log);
                result = { status: 'ERROR', code: 'ATTACH_MENU_NOT_OPEN', error: 'منوی ضمیمه باز نشد', log: log.file };
                exitCode = 1;
                return;
            }

            // انتخاب آیتم با آیکون/متن؛ در نهایت ایندکس (رفتار نسخهٔ قبل)
            let target = await C.firstVisible(page, isVisual ? MENU_VISUAL : MENU_DOC, { timeout: 5000, label: 'attach menu item' });
            if (!target) {
                const visibleItems = menu.locator('.MenuItem:visible, [role="menuitem"]:visible');
                target = { locator: isVisual ? visibleItems.first() : visibleItems.nth(1), selector: 'positional-fallback' };
                log.step('WARNING: menu item by icon/text not found; using positional fallback');
            } else {
                log.step(`menu item matched: ${target.selector}`);
            }

            // دو مسیر تزریق فایل: رویداد filechooser (رفتار معمول WebZ) و در
            // صورت عدم وقوع آن، input[type="file"] پنهان داخل منو/مودال.
            const chooserPromise = page.waitForEvent('filechooser', { timeout: 12000 }).catch(() => null);
            await target.locator.click({ force: true });
            const chooser = await chooserPromise;

            let injected = false;
            if (chooser) {
                await chooser.setFiles(opts.file);
                injected = true;
                log.step(`file injected via filechooser: ${opts.file}`);
            } else {
                const fileInput = page.locator('input[type="file"]').last();
                await fileInput.setInputFiles(opts.file, { timeout: 8000 })
                    .then(() => { injected = true; log.step(`file injected via input[type=file]: ${opts.file}`); })
                    .catch(e => log.step('WARNING: input[type=file] fallback failed: ' + C.RunLog.brief(e.message, 120)));
            }
            if (!injected) {
                await C.safeScreenshot(page, 'last_media_send.jpg', log);
                result = { status: 'ERROR', code: 'FILE_INJECT_FAILED', error: 'فایل به منوی ضمیمه تحویل داده نشد', log: log.file };
                exitCode = 1;
                return;
            }

            // ---------- ۵) کپشن داخل مودال (با انتظار واقعی) ----------
            const modalLoc = page.locator(MODAL).last();
            const modalShown = await C.seen(modalLoc, 10000);
            log.step(`modal shown: ${modalShown}`);
            await C.delay(1500);

            if (opts.text) {
                const caption = page.locator([
                    `${MODAL} div[contenteditable="true"]`,
                    `${MODAL} textarea`,
                    '.media-preview-container div[contenteditable="true"]',
                    '.media-preview div[contenteditable="true"]',
                    '.media-preview-container textarea',
                ].join(', ')).last();
                if (await C.seen(caption, 6000)) {
                    await caption.click({ force: true });
                    await C.insertText(page, opts.text, log);
                } else {
                    log.step('WARNING: modal caption editor not found; caption will be lost');
                }
            }

            // ---------- ۶) ارسال مودال ----------
            const sendBtn = await C.firstVisible(page, MODAL_SEND, { timeout: 6000, label: 'modal send' });
            if (sendBtn) {
                await sendBtn.locator.click({ force: true });
                sentVia = 'modal-button:' + sendBtn.selector;
            } else {
                await page.keyboard.press('Control+Enter');
                await C.delay(800);
                sentVia = 'ctrl+enter';
            }
            log.step(`media submit via ${sentVia}`);
            await C.delay(3000);
        }
        // ---------- ۷) مسیر متن ساده ----------
        else if (opts.text) {
            await composer.click({ force: true });
            await C.insertText(page, opts.text, log);
            await C.delay(500);
            await page.keyboard.press('Enter');
            sentVia = 'composer-enter';
            log.step('text submitted via Enter');
            await C.delay(2000);
        }

        // ---------- ۸) تأیید ارسال ----------
        let verified = false;
        let how = '';
        try {
            await C.waitUntil(async () => {
                const afterCount = await countMessages(page);
                const afterPreview = await readActivePreview(page);
                let snippetSeen = false;
                if (snippet) {
                    snippetSeen = await page.locator('.MiddleColumn').getByText(snippet, { exact: false }).first().isVisible().catch(() => false);
                }
                const composerCleared = await page.evaluate(() => {
                    const el = document.querySelector('.MiddleColumn .input-message-input') || document.querySelector('.MiddleColumn div[contenteditable="true"]') || document.querySelector('#MiddleColumn .input-message-input') || document.querySelector('#MiddleColumn div[contenteditable="true"]');
                    return !!el && (el.innerText || '').trim() === '';
                }).catch(() => false);
                const previewChanged = !!beforePreview && afterPreview !== beforePreview;
                const countGrew = beforeCount >= 0 && afterCount > beforeCount;

                if (snippetSeen) { verified = true; how = 'snippet-in-chat'; return true; }
                if (countGrew) { verified = true; how = 'message-count+' + (afterCount - beforeCount); return true; }
                if (previewChanged) { verified = true; how = 'left-preview-changed'; return true; }
                if (sentVia === 'composer-enter' && composerCleared) { verified = true; how = 'composer-cleared'; return true; }
                return false;
            }, { timeout: 20000, interval: 700, label: 'send verification' });
        } catch (e) {
            log.step('verification window elapsed without positive signal: ' + e.message);
        }
        log.step(`verified=${verified} how=${how || 'n/a'}`);

        await C.safeScreenshot(page, 'last_media_send.jpg', log);

        if (verified) {
            result = { status: 'OK', message: `Sent to Soroush (${sentVia})`, verified: true, proof: how, header, via: openedVia, log: log.file };
        } else {
            result = { status: 'UNVERIFIED', code: 'SEND_NOT_VERIFIED', message: 'فرایند ارسال انجام شد ولی صحت آن تأیید نشد؛ اسکرین‌شات last_media_send.jpg و لاگ را ببینید', verified: false, header, via: openedVia, log: log.file };
            exitCode = 1;
        }
    } catch (err) {
        const code = /Timeout|timeout/.test(err.message) ? 'TIMEOUT' : 'RUNTIME';
        result = { status: 'ERROR', code, error: C.RunLog.brief(err.message, 400), log: log.file };
        exitCode = 1;
        log.step('FATAL: ' + err.stack);
    } finally {
        await C.closeQuietly(browser, log);
        C.cleanSingletons(PROFILE_DIR, log);
        log.step(`RUN END status=${result ? result.status : 'NONE'} exit=${exitCode}`);
        C.emit(result || { status: 'ERROR', code: 'NO_RESULT', error: 'بدون نتیجه' });
        process.exitCode = exitCode;   // نه process.exit() — تا finally کامل اجرا شود
    }
})();
