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
const fs = require('fs');
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

const MODAL = ':is(.modal-dialog, .Modal, [role="dialog"])';
const MODAL_SEND = [
    `${MODAL} button:has-text("ارسال")`,
    `${MODAL} [role="button"]:has-text("ارسال")`,
    `${MODAL} button:has-text("Send")`,
    `${MODAL} [role="button"]:has-text("Send")`,
    `${MODAL} button.confirm-dialog-button`,
    `${MODAL} button.primary`,
    `${MODAL} button.btn-primary`,
    `${MODAL} button:has(i[class*="send"])`,
    `${MODAL} [role="button"]:has(i[class*="send"])`,
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
async function firstEnabled(page, selectors, { timeout = 20000, label = 'button' } = {}) {
    const deadline = Date.now() + timeout;
    let sawDisabled = false;
    do {
        for (const sel of selectors) {
            const loc = page.locator(sel).last();
            try {
                if (!(await loc.isVisible())) continue;
                if (await loc.isEnabled().catch(() => true)) return { locator: loc, selector: sel };
                sawDisabled = true;
            } catch (e) { /* try next selector */ }
        }
        await C.delay(350);
    } while (Date.now() < deadline);
    return sawDisabled ? { disabled: true, selector: label } : null;
}


async function clickModalSendBottomLeft(page, log, label = 'modal send bottom-left') {
    const box = await page.evaluate(() => {
        const selectors = ['[role="dialog"]', '.modal-dialog', '.Modal'];
        const nodes = [];
        for (const sel of selectors) nodes.push(...document.querySelectorAll(sel));
        const visible = nodes
            .map(el => ({ el, r: el.getBoundingClientRect(), text: (el.innerText || '').slice(0, 180) }))
            .filter(x => {
                const cs = getComputedStyle(x.el);
                return x.r.width > 220 && x.r.height > 180 && x.r.bottom > 0 && x.r.right > 0
                    && x.r.width < window.innerWidth * 0.9 && x.r.height < window.innerHeight * 0.95
                    && cs.visibility !== 'hidden' && cs.display !== 'none';
            });
        visible.sort((a, b) => {
            const as = /ارسال|Send|عکس|ویدیو|رسانه/i.test(a.text) ? 1 : 0;
            const bs = /ارسال|Send|عکس|ویدیو|رسانه/i.test(b.text) ? 1 : 0;
            return (bs - as) || ((b.r.width * b.r.height) - (a.r.width * a.r.height));
        });
        const x = visible[0];
        if (!x) return null;
        return { left: x.r.left, top: x.r.top, right: x.r.right, bottom: x.r.bottom, width: x.r.width, height: x.r.height, text: x.text };
    }).catch(() => null);
    if (!box) { log.step(`${label}: modal box not found`); return false; }
    // در مودال RTL سروش، دکمهٔ آبی «ارسال» پایینِ چپ است.
    const x = Math.min(box.right - 20, box.left + 45);
    const y = Math.max(box.top + 20, box.bottom - 28);
    await page.mouse.click(x, y);
    log.step(`${label}: clicked at ${Math.round(x)},${Math.round(y)} box=${Math.round(box.width)}x${Math.round(box.height)} text="${C.RunLog.brief(box.text, 50)}"`);
    return true;
}

function currentUrlHash(page) {
    try { return new URL(page.url()).hash || ''; } catch (e) { return ''; }
}

function isSoroushSideViewHash(hash) {
    return /_(?:pinned|comments|scheduled|discussion|replies)\b/i.test(String(hash || ''));
}

function isConcreteChatHash(hash, channel) {
    const h = String(hash || '').toLowerCase();
    const c = String(channel || '').replace(/^@/, '').toLowerCase();
    if (!h || h === '#') return false;
    if (isSoroushSideViewHash(h)) return false;
    // #@username is only the unresolved public route. After a real search-result click,
    // Soroush Web resolves channels to an internal numeric hash like #-1001243691.
    if (c && h === `#@${c}`) return false;
    return /^#-?\d+/.test(h);
}

async function waitForConcreteChatAfterClick(page, channel, beforeHash, log, label, timeout = 10000) {
    try {
        await C.waitUntil(() => {
            const h = currentUrlHash(page);
            if (!isConcreteChatHash(h, channel)) return false;
            // If we were already on some concrete chat, do not accept the stale chat
            // immediately after clicking a search result; wait until navigation changes it.
            if (beforeHash && h === beforeHash) return false;
            return true;
        }, { timeout, interval: 250, label });
        log.step(`${label}: resolved to ${page.url()}`);
        return true;
    } catch (e) {
        log.step(`${label}: concrete chat hash not observed after click; current url=${page.url()}`);
        return false;
    }
}

const NEW_POST_BUTTONS = [
    // اول فقط داخل ستون چت مقصد؛ برای جلوگیری از ارسال به چت اشتباه.
    '.MiddleColumn button:has-text("پیام جدید")',
    '.MiddleColumn [role="button"]:has-text("پیام جدید")',
    '.MiddleColumn a:has-text("پیام جدید")',
    '#MiddleColumn button:has-text("پیام جدید")',
    '#MiddleColumn [role="button"]:has-text("پیام جدید")',
    '#MiddleColumn a:has-text("پیام جدید")',
    '.MiddleColumn [class*="Button"]:has-text("پیام جدید")',
    '#MiddleColumn [class*="Button"]:has-text("پیام جدید")',
    '.MiddleColumn [class*="button"]:has-text("پیام جدید")',
    '#MiddleColumn [class*="button"]:has-text("پیام جدید")',
    '.MiddleColumn button:has-text("ارسال پیام")',
    '#MiddleColumn button:has-text("ارسال پیام")',
    '.MiddleColumn [role="button"]:has-text("ارسال پیام")',
    '#MiddleColumn [role="button"]:has-text("ارسال پیام")',
    '.MiddleColumn button:has-text("New Message")',
    '#MiddleColumn button:has-text("New Message")',
    '.MiddleColumn button:has-text("New Post")',
    '#MiddleColumn button:has-text("New Post")',
];

// fallback امن: بعضی نسخه‌های سروش دکمهٔ «پیام جدید» کانال را خارج از
// MiddleColumn رندر می‌کنند. فقط وقتی URL همین کانال به hash عددی واقعی resolve
// شده باشد، این دکمه‌های global را امتحان می‌کنیم و بعد از کلیک هم hash باید
// تغییر نکند؛ پس به shamimeashena1 یا چت دیگر نمی‌فرستیم.
const GLOBAL_NEW_POST_BUTTONS = [
    'button:has-text("پیام جدید")',
    '[role="button"]:has-text("پیام جدید")',
    'a:has-text("پیام جدید")',
    '[class*="Button"]:has-text("پیام جدید")',
    '[class*="button"]:has-text("پیام جدید")',
    'button:has-text("ارسال پیام")',
    '[role="button"]:has-text("ارسال پیام")',
];

async function ensureComposer(page, log, timeout = 12000) {
    const deadline = Date.now() + timeout;
    let clickedNewPost = false;
    let triedGlobalNewPost = false;
    let loggedMissing = false;

    while (Date.now() < deadline) {
        if (await C.seen(page.locator(COMPOSER).first(), 700)) return true;

        if (!clickedNewPost) {
            let newPost = await C.firstVisible(page, NEW_POST_BUTTONS, { timeout: 700, label: 'scoped new post button' });
            let globalFallback = false;
            const beforeHash = currentUrlHash(page);

            if (!newPost && !triedGlobalNewPost && /^#-?\d+/.test(beforeHash) && !isSoroushSideViewHash(beforeHash)) {
                // بعضی نسخه‌ها دکمهٔ ارسال پست کانال را خارج از MiddleColumn می‌گذارند.
                // فقط بعد از resolve شدن کانال به hash عددی واقعی اجازهٔ fallback global داریم.
                newPost = await C.firstVisible(page, GLOBAL_NEW_POST_BUTTONS, { timeout: 700, label: 'global new post button' });
                globalFallback = Boolean(newPost);
                triedGlobalNewPost = true;
            }

            if (newPost) {
                await newPost.locator.click({ force: true });
                clickedNewPost = true;
                log.step(`${globalFallback ? 'global ' : ''}new-post button clicked via ${newPost.selector}`);
                await C.delay(1500);
                const afterHash = currentUrlHash(page);
                if (afterHash && beforeHash && afterHash !== beforeHash) {
                    log.step(`new-post changed chat hash ${beforeHash} → ${afterHash}; refusing composer`);
                    return false;
                }
                if (isSoroushSideViewHash(afterHash)) {
                    log.step(`new-post opened side view ${afterHash}; refusing composer`);
                    return false;
                }
                continue;
            }
            if (!loggedMissing && Date.now() + 3500 < deadline) {
                log.step('new-post button not visible yet; waiting');
                loggedMissing = true;
            }
        }

        await C.delay(350);
    }

    if (clickedNewPost) log.step('new-post clicked but composer still not visible');
    else log.step('composer/new-post button not visible within timeout');
    return false;
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
            const beforeHash = currentUrlHash(page);
            await exact.click({ force: true });
            log.step(`openChatBySearch(strict): clicked exact username result for "${query}"`);
            if (!(await waitForConcreteChatAfterClick(page, channel, beforeHash, log, 'openChatBySearch(strict): exact result navigation', 10000))) {
                log.step('openChatBySearch(strict): exact result click did not resolve; refusing stale composer');
            } else if (await ensureComposer(page, log, 15000)) return true;
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
            const beforeHash = currentUrlHash(page);
            await item.click({ force: true });
            log.step(`openChatBySearch(strict): clicked result #${i + 1} for "${query}" (${status.reason}) text="${C.RunLog.brief(brief, 80)}"`);
            if (!(await waitForConcreteChatAfterClick(page, channel, beforeHash, log, `openChatBySearch(strict): result #${i + 1} navigation`, 10000))) {
                log.step(`openChatBySearch(strict): result #${i + 1} click did not resolve; refusing stale composer`);
                continue;
            }
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

/* ============================================================
   حالت Batch (صف پس‌زمینه):
   یک بار مرورگر باز می‌شود، یک بار کانال مقصد، و همهٔ پست‌ها
   پشت‌سرهم ارسال می‌شوند — به‌جای راه‌اندازی مجدد Chromium برای هر پست.
   قرارداد فایل --batch (JSON):
     { "items":[ {"id":123,"text":"...","file":"/tmp/x.jpg","type":"image",
                  "fileName":"photo.jpg"}, ... ],
       "progressFile":"/abs/path/progress.json" }
   progressFile پس از هر آیتم به‌صورت اتمیک بازنویسی می‌شود:
     { "platform":"soroush","items":[{"id":123,"status":"OK|ERROR|UNVERIFIED|NOT_ATTEMPTED","result":{...}}] }
   ترتیب آیتم‌ها حفظ می‌شود و با اولین آیتمِ ناموفق، بقیه NOT_ATTEMPTED می‌شوند.
   ============================================================ */

// تمپوی انتظارهای ثابت: single = مقادیر اثبات‌شدهٔ امروز (دست‌نخورده)؛
// batch = کمی کوتاه‌تر. درِ صحت، حلقهٔ verification با polling است نه این delayها.
const TEMPO_SINGLE = { afterAttach: 1000, modalShown: 1500, afterSendClick: 3000, afterRetry: 1800, afterSubmit: 5000, afterEnter: 2000 };
const TEMPO_BATCH  = { afterAttach: 800,  modalShown: 1200, afterSendClick: 1500, afterRetry: 1200, afterSubmit: 2000, afterEnter: 1500 };

/**
 * باز کردن چت مقصد با همان راهبردهای اثبات‌شدهٔ حالت تک‌پیام
 * (hash-strict → search-strict → …) + تأیید هدر.
 * خروجی: {ok:true, via, header} یا {ok:false, code, error}
 */
async function openTargetChannel(page, browser, opts, log) {
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

    for (const [label, run] of strategies) {
        const ok = await run();
        if (!ok) { log.step(`${label}: composer not visible → next strategy`); continue; }
        const hm = await headerMatches(page, opts.channelName, log);
        if (!hm.ok) { log.step(`${label}: chat opened but header mismatch → next strategy`); continue; }
        return { ok: true, via: label, header: hm.header };
    }

    const finalHash = currentUrlHash(page);
    const reachedTarget = isConcreteChatHash(finalHash, opts.channel);
    return {
        ok: false,
        code: reachedTarget ? 'COMPOSER_NOT_AVAILABLE' : 'CHANNEL_NOT_FOUND',
        error: reachedTarget
            ? `کانال "${opts.channel || opts.channelName}" باز شد (${page.url()}) ولی composer یا دکمهٔ ارسال پست داخل خود کانال دیده نشد. احتمالاً اکانت سروشِ این profile دسترسی ادمین/ارسال پست در کانال اصلی را ندارد.`
            : `کانال "${opts.channel || opts.channelName}" با هیچ‌یک از راهبردهای امن باز/تأیید نشد.`,
    };
}

/**
 * ارسال یک پیام (متن یا رسانه) در چتِ از قبل باز‌شده.
 * این همان جریان اثبات‌شدهٔ حالت تک‌پیام است که عیناً extract شده؛
 * تنها تفاوت: انتظارهای ثابت از tempo می‌آیند و نتیجه به‌جای exit،
 * به‌صورت شیء برگردانده می‌شود تا حلقهٔ batch هم بتواند مصرفش کند.
 * خروجی: {status:'OK'|'UNVERIFIED'|'ERROR', code?, message|error, verified, proof, sentVia}
 */
async function sendOneSoroush(page, item, log, tempo) {
    const text = String(item.text || '');
    const file = item.file || null;

    // ---------- سنجه‌های «پیش از ارسال» برای تأیید ----------
    const beforeCount = await countMessages(page);
    const beforePreview = await readActivePreview(page);
    const snippet = C.snippetOf(text);
    log.step(`item ${item.id ?? '-'}: before: messages=${beforeCount} preview="${C.RunLog.brief(beforePreview, 60)}"`);

    let sentVia = 'none';

    // ---------- مسیر رسانه ----------
    if (file) {
        const isVisual = (item.type === 'image' || item.type === 'video')
            || (!item.type && ['.jpg', '.jpeg', '.png', '.webp', '.gif', '.mp4'].includes(path.extname(file).toLowerCase()));

        const attach = await C.firstVisible(page, ATTACH_BTN, { timeout: 10000, label: 'attach button' });
        if (!attach) {
            await C.safeScreenshot(page, 'last_media_send.jpg', log);
            return { status: 'ERROR', code: 'ATTACH_BUTTON_NOT_FOUND', error: 'دکمهٔ ضمیمه در composer پیدا نشد', sentVia };
        }
        await attach.locator.click({ force: true });
        log.step(`attach button clicked via ${attach.selector}`);
        await C.delay(tempo.afterAttach);

        const menu = page.locator('.menu-container:not(.not-open), .AttachMenu .menu-container, .bubble.open').last();
        if (!(await C.seen(menu, 6000))) {
            await C.safeScreenshot(page, 'last_media_send.jpg', log);
            return { status: 'ERROR', code: 'ATTACH_MENU_NOT_OPEN', error: 'منوی ضمیمه باز نشد', sentVia };
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
            await chooser.setFiles(file);
            injected = true;
            log.step(`file injected via filechooser: ${file}`);
        } else {
            const fileInput = page.locator('input[type="file"]').last();
            await fileInput.setInputFiles(file, { timeout: 8000 })
                .then(() => { injected = true; log.step(`file injected via input[type=file]: ${file}`); })
                .catch(e => log.step('WARNING: input[type=file] fallback failed: ' + C.RunLog.brief(e.message, 120)));
        }
        if (!injected) {
            await C.safeScreenshot(page, 'last_media_send.jpg', log);
            return { status: 'ERROR', code: 'FILE_INJECT_FAILED', error: 'فایل به منوی ضمیمه تحویل داده نشد', sentVia };
        }

        // ---------- کپشن داخل مودال (با انتظار واقعی) ----------
        const modalLoc = page.locator(MODAL).last();
        const modalShown = await C.seen(modalLoc, 10000);
        log.step(`modal shown: ${modalShown}`);
        await C.delay(tempo.modalShown);

        if (text) {
            const caption = page.locator([
                `${MODAL} div[contenteditable="true"]`,
                `${MODAL} textarea`,
                '.media-preview-container div[contenteditable="true"]',
                '.media-preview div[contenteditable="true"]',
                '.media-preview-container textarea',
            ].join(', ')).last();
            if (await C.seen(caption, 6000)) {
                await caption.click({ force: true });
                await C.insertText(page, text, log);
            } else {
                log.step('WARNING: modal caption editor not found; caption will be lost');
            }
        }

        // ---------- ارسال مودال ----------
        const sendBtn = await firstEnabled(page, MODAL_SEND, { timeout: 12000, label: 'modal send' });
        if (sendBtn && !sendBtn.disabled) {
            await sendBtn.locator.click({ force: true });
            sentVia = 'modal-button:' + sendBtn.selector;
            await C.delay(tempo.afterSendClick);
            if (await page.locator(MODAL).last().isVisible().catch(() => false)) {
                log.step('modal still visible after selector send; trying bottom-left send button');
                const clicked = await clickModalSendBottomLeft(page, log, 'modal send fallback');
                if (clicked) sentVia += '+bottom-left-click';
            }
        } else {
            if (sendBtn && sendBtn.disabled) log.step('WARNING: modal send button stayed disabled; trying modal coordinate fallback');
            const clicked = await clickModalSendBottomLeft(page, log, 'modal send fallback');
            sentVia = clicked ? 'modal-bottom-left-click' : 'modal-fallback-missing';
        }
        await C.delay(tempo.afterRetry);
        const modalStill = await page.locator(MODAL).last().isVisible().catch(() => false);
        if (modalStill) {
            log.step('modal still visible after send clicks; trying Ctrl+Enter');
            await page.keyboard.press('Control+Enter');
            sentVia += '+ctrl+enter';
            await C.delay(tempo.afterRetry);
        }
        log.step(`media submit via ${sentVia}`);
        await C.delay(tempo.afterSubmit);
    }
    // ---------- مسیر متن ساده ----------
    else if (text) {
        const composer = page.locator(COMPOSER).last();
        await composer.click({ force: true });
        await C.insertText(page, text, log);
        await C.delay(500);
        await page.keyboard.press('Enter');
        sentVia = 'composer-enter';
        log.step('text submitted via Enter');
        await C.delay(tempo.afterEnter);
    } else {
        return { status: 'ERROR', code: 'EMPTY_PAYLOAD', error: 'هم text و هم file خالی است؛ چیزی برای ارسال نیست', sentVia };
    }

    // ---------- تأیید ارسال ----------
    let verified = false;
    let how = '';
    let acceptedButNotVisual = false;
    try {
        await C.waitUntil(async () => {
            const modalGone = !(await page.locator(MODAL).last().isVisible().catch(() => false));
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
            if (file && modalGone && sentVia !== 'none') { acceptedButNotVisual = true; how = 'modal-closed-accepted'; return true; }
            return false;
        }, { timeout: file ? 45000 : 20000, interval: 700, label: 'send verification' });
    } catch (e) {
        log.step('verification window elapsed without positive signal: ' + e.message);
    }
    log.step(`verified=${verified} accepted=${acceptedButNotVisual} how=${how || 'n/a'}`);

    if (verified || acceptedButNotVisual) {
        return {
            status: 'OK',
            message: `Sent to Soroush (${sentVia})`,
            verified,
            proof: how || (verified ? 'verified' : 'accepted'),
            sentVia,
        };
    }
    await C.safeScreenshot(page, 'last_media_send.jpg', log);
    return {
        status: 'UNVERIFIED',
        code: 'SEND_NOT_VERIFIED',
        message: 'فرایند ارسال انجام شد ولی صحت آن تأیید نشد؛ اسکرین‌شات last_media_send.jpg و لاگ را ببینید',
        verified: false,
        sentVia,
    };
}

(async () => {
    const opts = C.parseArgs(process.argv);
    // اولویت: آرگومان خط فرمان > .env (SOROUSH_*) > بدون مقدار
    // نام/شناسهٔ کانال پیش از ورود به سلکتور از کاراکترهای شکننده پاک می‌شود
    opts.channel = String(opts.channel || C.env('SOROUSH_CHANNEL_ID') || '').replace(/["\\]/g, '');
    opts.channelName = (opts.channelName || C.env('SOROUSH_CHANNEL_NAME') || null);
    opts.channelName = opts.channelName ? String(opts.channelName).replace(/["\\]/g, '') : null;
    opts.strictChannel = String(opts.raw('strict-channel', C.env('SOROUSH_STRICT_CHANNEL', '0')) || '0') === '1';
    const batchFile = opts.raw('batch', null);
    const log = new C.RunLog(batchFile ? 'soroush_batch' : 'soroush');
    log.step(`args: channel=${opts.channel || '-'} name=${opts.channelName || '-'} text=${opts.text.length}ch file=${opts.file || '-'} type=${opts.type || '-'} batch=${batchFile || '-'} env=${C.ENV_FILE || 'none'}`);

    let browser = null;
    let exitCode = 0;
    let result = null;

    // این try بیرونی فقط برای finally مشترک است: returnهای زودهنگامِ حالت
    // تک‌پیام هم باید از «بستن مرورگر + emit JSON» عبور کنند (مثل نسخهٔ قبل).
    try {

    // ==================== حالت Batch (صف پس‌زمینه) ====================
    if (batchFile) {
        const batch = C.readJsonFile(batchFile);
        const progressFile = (batch && typeof batch.progressFile === 'string' && batch.progressFile) || (batchFile + '.progress.json');
        const progress = { platform: 'soroush', startedAt: new Date().toISOString(), items: [] };
        const writeProgress = () => C.writeJsonFileAtomic(progressFile, progress);
        const stopRest = (fromIdx) => {
            for (let j = fromIdx; j < progress.items.length; j++) {
                if (progress.items[j].status === 'pending' || progress.items[j].status === 'RUNNING') progress.items[j].status = 'NOT_ATTEMPTED';
            }
        };

        const items = (batch && Array.isArray(batch.items)) ? batch.items : null;
        if (!items || items.length === 0) {
            result = { status: 'ERROR', code: 'BAD_BATCH', error: `فایل batch خالی یا نامعتبر است: ${batchFile}` };
            exitCode = 1;
        } else {
            progress.items = items.map(it => ({ id: it.id, status: 'pending' }));
            writeProgress();
            try {
                const launched = await C.launchBrowser({ chromium }, PROFILE_DIR, VIEWPORT, log);
                browser = launched.browser;
                const page = launched.page;

                // ---------- ۱) بارگذاری و بررسی session (یک بار برای کل صف) ----------
                await page.goto('https://web.splus.ir', { waitUntil: 'domcontentloaded', timeout: 60000 });
                await C.delay(5000);

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
                    stopRest(0);
                    progress.error = { code: 'SESSION_EXPIRED', message: `صفحهٔ ورود سروش‌پلاس دیده شد (${loginMarker}). با login_soroush.js دوباره وارد شوید.` };
                    writeProgress();
                    result = { status: 'ERROR', code: 'SESSION_EXPIRED', error: progress.error.message, log: log.file };
                    exitCode = 1;
                } else {
                    // ---------- ۲) باز کردن چت مقصد (یک بار برای کل صف) ----------
                    const opened = await openTargetChannel(page, browser, opts, log);
                    if (!opened.ok) {
                        await C.safeScreenshot(page, 'last_media_send.jpg', log);
                        stopRest(0);
                        progress.error = { code: opened.code, message: opened.error };
                        writeProgress();
                        result = { status: 'ERROR', code: opened.code, error: opened.error, log: log.file };
                        exitCode = 1;
                    } else {
                        log.step(`chat opened via ${opened.via}. header="${opened.header}"`);

                        // ---------- ۳) ارسال آیتم‌ها به ترتیب؛ توقف در اولین شکست ----------
                        let sent = 0;
                        let failed = 0;
                        let stoppedAt = -1;
                        for (let i = 0; i < items.length; i++) {
                            const it = items[i];
                            const pi = progress.items[i];

                            if (it.file && !fs.existsSync(it.file)) {
                                pi.status = 'ERROR';
                                pi.result = { status: 'ERROR', code: 'FILE_MISSING', error: `فایل وجود ندارد: ${it.file}`, log: log.file };
                                failed++;
                                stoppedAt = i;
                                stopRest(i + 1);
                                writeProgress();
                                break;
                            }

                            pi.status = 'RUNNING';
                            writeProgress();
                            try {
                                // اگر composer بعد از پیام قبلی مخفی شده باشد (چند پست پشت‌سرهم)، دوباره ظاهرش می‌کنیم
                                const composerReady = await ensureComposer(page, log, 15000);
                                if (!composerReady) {
                                    pi.status = 'ERROR';
                                    pi.result = { status: 'ERROR', code: 'COMPOSER_NOT_AVAILABLE', error: 'composer پس از پیام قبلی در دسترس نبود', log: log.file };
                                    failed++;
                                    stoppedAt = i;
                                    stopRest(i + 1);
                                    writeProgress();
                                    break;
                                }
                                const r = await sendOneSoroush(page, it, log, TEMPO_BATCH);
                                pi.status = r.status;
                                pi.result = Object.assign({ log: log.file }, r);
                                if (r.status === 'OK') {
                                    sent++;
                                } else {
                                    failed++;
                                    stoppedAt = i;
                                    stopRest(i + 1);
                                }
                                writeProgress();
                                if (stoppedAt === i) break;
                            } catch (err) {
                                const code = /Timeout|timeout/.test(err.message) ? 'TIMEOUT' : 'RUNTIME';
                                pi.status = 'ERROR';
                                pi.result = { status: 'ERROR', code, error: C.RunLog.brief(err.message, 400), log: log.file };
                                failed++;
                                stopRest(i + 1);
                                writeProgress();
                                break;
                            }
                        }
                        progress.finishedAt = new Date().toISOString();
                        writeProgress();
                        result = {
                            status: failed === 0 ? 'OK' : (sent > 0 ? 'PARTIAL' : 'ERROR'),
                            sent,
                            failed,
                            total: items.length,
                            stoppedAt: stoppedAt >= 0 ? items[stoppedAt].id : null,
                            log: log.file,
                        };
                        // exitCode عمداً 0 می‌ماند (حتی با PARTIAL): قضاوت نهایی با worker PHP
                        // از روی progressFile است؛ 1 فقط برای خطای مهلک راه‌اندازی.
                    }
                }
            } catch (err) {
                const code = /Timeout|timeout/.test(err.message) ? 'TIMEOUT' : 'RUNTIME';
                stopRest(0);
                progress.error = { code, message: C.RunLog.brief(err.message, 400) };
                writeProgress();
                result = { status: 'ERROR', code, error: C.RunLog.brief(err.message, 400), log: log.file };
                exitCode = 1;
                log.step('FATAL: ' + err.stack);
            }
        }
    }
    // ==================== حالت تک‌پیام (رفتار قبلی، دست‌نخورده) ====================
    else {
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
            if (opts.file && !fs.existsSync(opts.file)) {
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
            const opened = await openTargetChannel(page, browser, opts, log);
            if (!opened.ok) {
                await C.safeScreenshot(page, 'last_media_send.jpg', log);
                result = { status: 'ERROR', code: opened.code, error: opened.error, log: log.file };
                exitCode = 1;
                return;
            }
            log.step(`chat opened via ${opened.via}. header="${opened.header}"`);

            // ---------- ۳+۴) ارسال و تأیید ----------
            const r = await sendOneSoroush(page, opts, log, TEMPO_SINGLE);

            await C.safeScreenshot(page, 'last_media_send.jpg', log);

            if (r.status === 'OK') {
                result = { status: 'OK', message: r.message, verified: r.verified, proof: r.proof, header: opened.header, via: opened.via, log: log.file };
            } else if (r.status === 'UNVERIFIED') {
                result = { status: 'UNVERIFIED', code: 'SEND_NOT_VERIFIED', message: r.message, verified: false, header: opened.header, via: opened.via, log: log.file };
                exitCode = 1;
            } else {
                result = { status: 'ERROR', code: r.code, error: r.error, log: log.file };
                exitCode = 1;
            }
        } catch (err) {
            const code = /Timeout|timeout/.test(err.message) ? 'TIMEOUT' : 'RUNTIME';
            result = { status: 'ERROR', code, error: C.RunLog.brief(err.message, 400), log: log.file };
            exitCode = 1;
            log.step('FATAL: ' + err.stack);
        }
    }

    } finally {
        // ==================== خروج مشترک ====================
        try {
            await C.closeQuietly(browser, log);
        } finally {
            C.cleanSingletons(PROFILE_DIR, log);
            log.step(`RUN END status=${result ? result.status : 'NONE'} exit=${exitCode}`);
            C.emit(result || { status: 'ERROR', code: 'NO_RESULT', error: 'بدون نتیجه' });
            process.exitCode = exitCode;   // نه process.exit() — تا finally کامل اجرا شود
        }
    }
})();
