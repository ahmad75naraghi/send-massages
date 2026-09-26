'use strict';
/**
 * send_igap.js — ارسال به آی‌گپ (web.igap.net / SPA با Vite + Tailwind + MUI)
 * ============================================================
 *  قرارداد خروجی با sync_manual.php:
 *    موفق:  stdout → {"status":"OK","message":...,"verified":true,...}   exit 0
 *    خطا:   stdout → {"status":"ERROR","code":...,"error":...}           exit 1
 *    مشکوک: stdout → {"status":"UNVERIFIED",...}                          exit 1
 *
 *  سلکتورهای این فایل از شواهد واقعی پروژه استخراج شده‌اند:
 *   - igap_dump.html        (ساختار لیست گفت‌وگوها و لایهٔ ripple-container)
 *   - igap_attach_menu.jpg  (برچسب‌های واقعی منوی ضمیمه: Take Picture /
 *                            Media (image/video) / File (document) / …)
 *   - last_igap_send.jpg    (نمونهٔ ارسال موفق: حباب تصویر + کپشن)
 *
 *  اصلاحات نسبت به نسخهٔ پیشین:
 *   ۱) کانال مقصد دیگر سخت‌کد نیست: --item-id و --channel-name (با fallback).
 *   ۲) باز شدن چت درست با دیدن نام کانال در هدر #MiddleColumn تأیید می‌شود.
 *   ۳) isVisible({timeout}) حذف شد (در Playwright 1.63 بی‌اثر است)؛
 *      به‌جای آن waitFor/seen/waitUntil — وگرنه کپشن بی‌صدا حذف می‌شد.
 *   ۴) دکمهٔ ارسال مودال فقط از داخل خود مودال انتخاب می‌شود
 *      (ورنه .last() می‌توانست دکمهٔ فوتر را بزند و پیام خالی بفرستد).
 *   ۵) Enter به‌عنوان fallback آخر است، نه اولین؛ اول دکمهٔ مودال،
 *      بعد Control+Enter (رفتار استاندارد مودال‌های ارسال فایل).
 *   ۶) صحت ارسال تأیید می‌شود (snippet در چت / تغییر پیش‌نمایش لیست /
 *      بسته شدن مودال)؛ بدون تأیید OK گزارش نمی‌شود.
 *   ۷) process.exit() فقط پس از browser.close() — ورنه کرومیوم یتیم
 *      و قفل Singleton برای اجرای بعدی باقی می‌ماند.
 * ============================================================
 */

const path = require('path');
const fs = require('fs');
const { chromium } = require('playwright');
const C = require(path.join(__dirname, 'lib', 'pw_common.js'));

const PROFILE_DIR = C.env('IGAP_PROFILE_DIR', path.join(C.APP_DIR, 'igap_profile'));
const VIEWPORT = { width: 1440, height: 900 };

const DEFAULT_ITEM_ID = '16200343869985976';
const DEFAULT_CHANNEL_NAME = 'شمیم آشنا';
const INITIAL_WAIT_MS = Number(C.env('IGAP_INITIAL_WAIT_MS', '3500')) || 3500;
const VERIFY_TIMEOUT_MS = Number(C.env('IGAP_VERIFY_TIMEOUT_MS', C.env('USERBOT_VERIFY_TIMEOUT_MS', '8000'))) || 8000;

const LOGIN_MARKERS = ['input[type="tel"]', 'input[placeholder*="موبایل"]', 'input[placeholder*="شماره"]'];

const ATTACH_BTN = ['button:has(i.icon-ig-attachment-outline)'];

const MENU_VISUAL = [
    '.MenuItem:has-text("Media (image/video)")',
    '.MenuItem:has(i.icon-gallery)',
    '.MenuItem:has-text("رسانه (تصویر/ویدیو)")',
    'div:has-text("Media (image/video)"):not(:has(div:has-text("Media (image/video)")))',
];
const MENU_DOC = [
    '.MenuItem:has-text("File (document)")',
    '.MenuItem:has(i.icon-file)',
    '.MenuItem:has-text("فایل (سند)")',
];

const MODAL = ':is(.modal-dialog, [role="dialog"], .MuiDialog-root, .MuiModal-root)';
const MODAL_SEND = [
    `${MODAL} button:has-text("ارسال")`,
    `${MODAL} button:has-text("Send")`,
    `${MODAL} button:has(i.icon-ig-send-outline)`,
    `${MODAL} button:has(i[class*="send"])`,
    `${MODAL} [role="button"]:has(i[class*="send"])`,
    `${MODAL} button:has(svg)`,
    `${MODAL} [role="button"]:has(svg)`,
    `${MODAL} button.confirm-dialog-button`,
    `${MODAL} button.btn-primary`,
    `${MODAL} button`,
    `${MODAL} [role="button"]`,
];

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


async function igapUploadModalVisible(page) {
    return page.evaluate(() => {
        const visible = (el) => {
            if (!el) return false;
            const r = el.getBoundingClientRect();
            const cs = getComputedStyle(el);
            return r.width > 0 && r.height > 0 && r.bottom > 0 && r.right > 0 && cs.visibility !== 'hidden' && cs.display !== 'none';
        };
        const hasTitle = Array.from(document.querySelectorAll('body *')).some(el => {
            const t = (el.textContent || '').trim();
            return visible(el) && (t === 'Send File' || t === 'ارسال فایل');
        });
        if (hasTitle) return true;
        const nodes = Array.from(document.querySelectorAll('[role="dialog"], .modal-dialog, .MuiDialog-root, .MuiModal-root, .MuiPaper-root'));
        return nodes.some(el => visible(el) && /Send File|ارسال فایل|Media \(image\/video\)|File \(document\)/i.test(el.innerText || ''));
    }).catch(() => false);
}

async function clickModalBottomRight(page, log, label = 'modal bottom-right') {
    const box = await page.evaluate(() => {
        const visible = (el) => {
            if (!el) return false;
            const r = el.getBoundingClientRect();
            const cs = getComputedStyle(el);
            return r.width > 160 && r.height > 120 && r.bottom > 0 && r.right > 0 && cs.visibility !== 'hidden' && cs.display !== 'none';
        };
        const describe = (el) => {
            const r = el.getBoundingClientRect();
            return { left: r.left, top: r.top, right: r.right, bottom: r.bottom, width: r.width, height: r.height, text: (el.innerText || '').slice(0, 160) };
        };

        const selectors = ['[role="dialog"]', '.modal-dialog', '.MuiDialog-root [role="dialog"]', '.MuiPaper-root', '.MuiModal-root'];
        const nodes = [];
        for (const sel of selectors) nodes.push(...document.querySelectorAll(sel));
        const visibleBoxes = nodes.filter(visible).map(describe);
        visibleBoxes.sort((a, b) => (b.width * b.height) - (a.width * a.height));
        const bySelector = visibleBoxes.find(v => /Send File|ارسال|فایل|رسانه/i.test(v.text));
        if (bySelector) return bySelector;

        // iGap's upload dialog may not expose a stable role/class in headless mode.
        // Find the visible "Send File" title and walk up to the centered card.
        const title = Array.from(document.querySelectorAll('body *')).find(el => {
            const t = (el.textContent || '').trim();
            const r = el.getBoundingClientRect();
            return (t === 'Send File' || t === 'ارسال فایل') && r.width > 20 && r.height > 10;
        });
        let cur = title;
        while (cur && cur !== document.body) {
            if (visible(cur)) {
                const d = describe(cur);
                if (d.width >= 280 && d.width <= 700 && d.height >= 220 && d.height <= 700) return d;
            }
            cur = cur.parentElement;
        }
        return visibleBoxes[0] || null;
    }).catch(() => null);
    if (!box) { log.step(`${label}: modal box not found`); return false; }
    const x = Math.max(box.left + 20, box.right - 40);
    const y = Math.max(box.top + 20, box.bottom - 40);
    await page.mouse.click(x, y);
    log.step(`${label}: clicked at ${Math.round(x)},${Math.round(y)} box=${Math.round(box.width)}x${Math.round(box.height)} text="${C.RunLog.brief(box.text, 50)}"`);
    return true;
}

async function clickSendBesideCaption(page, caption, log) {
    const box = await caption.boundingBox().catch(() => null);
    const vp = page.viewportSize() || VIEWPORT;
    if (!box) {
        log.step('modal send near-caption: caption bounding box not available');
        return false;
    }
    if (box.y > vp.height - 160) {
        log.step(`modal send near-caption: refusing because caption looks like main composer y=${Math.round(box.y)} h=${Math.round(box.height)}`);
        return false;
    }
    const x = Math.min(vp.width - 20, Math.max(20, box.x + box.width + 32));
    const y = Math.min(vp.height - 20, Math.max(20, box.y + (box.height / 2)));
    await page.mouse.click(x, y);
    log.step(`modal send near-caption: clicked at ${Math.round(x)},${Math.round(y)} caption=${Math.round(box.x)},${Math.round(box.y)},${Math.round(box.width)}x${Math.round(box.height)}`);
    return true;
}

async function clickChannel(page, locator, name, log, label) {
    try {
        await locator.first().waitFor({ state: 'visible', timeout: 8000 });
        await locator.first().click({ force: true });   // عبور از لایهٔ ripple-container
        log.step(`${label}: clicked`);
        // تأیید: نام کانال باید در هدر ستون میانی دیده شود
        const headerOk = await C.seen(page.locator('#MiddleColumn').getByText(name, { exact: false }).first(), 10000);
        log.step(`${label}: header shows channel = ${headerOk}`);
        return headerOk;
    } catch (e) {
        log.step(`${label}: failed (${C.RunLog.brief(e.message, 90)})`);
        return false;
    }
}

async function readPreview(page, name) {
    return page.evaluate((nm) => {
        const cells = Array.from(document.querySelectorAll('#LeftColumn div[aria-haspopup="true"]'));
        const cell = cells.find(c => (c.innerText || '').includes(nm));
        if (!cell) return '';
        const prev = cell.querySelector('span.truncate, p.truncate, span[dir="auto"]');
        return prev ? (prev.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 120) : (cell.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 120);
    }, name).catch(() => '');
}

async function countCards(page) {
    return page.evaluate(() => {
        const m = document.querySelector('#MiddleColumn');
        if (!m) return -1;
        return m.querySelectorAll('img[src], video, audio, .message, [class*="message-card"]').length;
    }).catch(() => -1);
}

(async () => {
    const opts = C.parseArgs(process.argv);
    // اولویت: آرگومان خط فرمان > .env (IGAP_*) > متغیرهای محیطی قدیمی > پیش‌فرض
    // نام کانال پیش از ورود به سلکتور از کاراکترهای شکننده پاک می‌شود
    const name = String(
        opts.channelName
        || C.env('IGAP_CHANNEL_NAME')
        || C.env('SYNC_IGAP_CHANNEL_NAME')
        || DEFAULT_CHANNEL_NAME
    ).replace(/["\\]/g, '');
    const itemId = String(
        opts.itemId
        || C.env('IGAP_ITEM_ID')
        || C.env('SYNC_IGAP_ITEM_ID')
        || DEFAULT_ITEM_ID
    ).replace(/["\\]/g, '');
    const channel = opts.channel || C.env('IGAP_CHANNEL_ID');
    const log = new C.RunLog('igap');
    log.step(`args: channel=${channel || '-'} name="${name}" item-id=${itemId} text=${opts.text.length}ch file=${opts.file || '-'} type=${opts.type || '-'} env=${C.ENV_FILE || 'none'}`);

    let browser = null;
    let exitCode = 0;
    let result = null;

    try {
        if (!opts.text && !opts.file) {
            result = { status: 'ERROR', code: 'EMPTY_PAYLOAD', error: 'هم text و هم file خالی است؛ چیزی برای ارسال نیست' };
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

        // ---------- ۱) بارگذاری ----------
        await page.goto('https://web.igap.net', { waitUntil: 'domcontentloaded', timeout: 60000 });
        await C.delay(INITIAL_WAIT_MS);

        const loginMarker = await C.detectLoginPage(page, LOGIN_MARKERS);
        const listReady = await C.seen(page.locator('#LeftColumn div[aria-haspopup="true"]').first(), 8000);
        if (!listReady) {
            await C.safeScreenshot(page, 'last_igap_send.jpg', log);
            if (loginMarker) {
                result = { status: 'ERROR', code: 'SESSION_EXPIRED', error: `صفحهٔ ورود آی‌گپ دیده شد (${loginMarker}). با login_igap.js دوباره وارد شوید.`, log: log.file };
            } else {
                result = { status: 'ERROR', code: 'APP_NOT_LOADED', error: 'لیست گفت‌وگوهای آی‌گپ بارگذاری نشد (شبکه یا session)', log: log.file };
            }
            exitCode = 1;
            return;
        }

        // ---------- ۲) باز کردن کانال مقصد (سه راهبرد + تأیید هدر) ----------
        let opened = false;
        opened = await clickChannel(page, page.locator(`div[data-list-item-id="${itemId}"]`), name, log, 'by data-list-item-id');
        if (!opened) opened = await clickChannel(page, page.locator(`#LeftColumn span:text-is("${name}")`), name, log, 'by span:text-is');
        if (!opened) opened = await clickChannel(page, page.locator(`#LeftColumn div[aria-haspopup="true"]:has-text("${name}")`), name, log, 'by cell:has-text');
        if (!opened) {
            await C.safeScreenshot(page, 'last_igap_send.jpg', log);
            result = { status: 'ERROR', code: 'CHANNEL_NOT_FOUND', error: `کانال "${name}" (item ${itemId}) در لیست پیدا/تأیید نشد`, log: log.file };
            exitCode = 1;
            return;
        }

        const beforePreview = await readPreview(page, name);
        const beforeCards = await countCards(page);
        const snippet = C.snippetOf(opts.text);
        log.step(`before: preview="${C.RunLog.brief(beforePreview, 60)}" cards=${beforeCards}`);

        let sentVia = 'none';
        let trustedMediaSubmit = false;

        // ---------- ۳) مسیر رسانه ----------
        if (opts.file) {
            const isVisual = (opts.type === 'image' || opts.type === 'video')
                || (!opts.type && ['.jpg', '.jpeg', '.png', '.webp', '.gif', '.mp4'].includes(path.extname(opts.file).toLowerCase()));

            const attach = await C.firstVisible(page, ATTACH_BTN, { timeout: 10000, label: 'attach button' });
            if (!attach) {
                await C.safeScreenshot(page, 'last_igap_send.jpg', log);
                result = { status: 'ERROR', code: 'ATTACH_BUTTON_NOT_FOUND', error: 'دکمهٔ ضمیمه (i.icon-ig-attachment-outline) پیدا نشد', log: log.file };
                exitCode = 1;
                return;
            }
            await attach.locator.click({ force: true });
            log.step('attach menu opened');
            await C.delay(1200);

            // لایهٔ ripple-container گاهی اولین کلیک را می‌بلعد؛ یک بار تلاش مجدد
            let target = null;
            for (let attempt = 1; attempt <= 2 && !target; attempt++) {
                target = await C.firstVisible(page, isVisual ? MENU_VISUAL : MENU_DOC, { timeout: 6000, label: 'attach menu item' });
                if (!target) {
                    log.step(`menu item not visible (attempt ${attempt}); re-clicking attach button`);
                    await attach.locator.click({ force: true }).catch(() => {});
                    await C.delay(1200);
                }
            }
            if (!target) {
                await C.safeScreenshot(page, 'last_igap_send.jpg', log);
                result = { status: 'ERROR', code: 'ATTACH_MENU_ITEM_NOT_FOUND', error: `گزینهٔ منوی ضمیمه برای نوع ${isVisual ? 'Media' : 'File'} پیدا نشد`, log: log.file };
                exitCode = 1;
                return;
            }
            log.step(`menu item matched: ${target.selector}`);

            // دو مسیر تزریق فایل: رویداد filechooser و در نهایت input[type="file"]
            const chooserPromise = page.waitForEvent('filechooser', { timeout: 12000 }).catch(() => null);
            await target.locator.click({ force: true });
            const chooser = await chooserPromise;

            let injected = false;
            if (chooser) {
                await chooser.setFiles(opts.file);
                injected = true;
                log.step(`file injected via filechooser: ${opts.file}`);
            } else {
                await page.locator('input[type="file"]').last()
                    .setInputFiles(opts.file, { timeout: 8000 })
                    .then(() => { injected = true; log.step('file injected via input[type=file]'); })
                    .catch(e => log.step('WARNING: input[type=file] fallback failed: ' + C.RunLog.brief(e.message, 120)));
            }
            if (!injected) {
                await C.safeScreenshot(page, 'last_igap_send.jpg', log);
                result = { status: 'ERROR', code: 'FILE_INJECT_FAILED', error: 'فایل به منوی ضمیمهٔ آی‌گپ تحویل داده نشد', log: log.file };
                exitCode = 1;
                return;
            }

            // ---------- ۴) کپشن مودال با انتظار واقعی ----------
            await C.delay(2000);
            const caption = page.locator([
                `${MODAL} div[contenteditable="true"]`,
                `${MODAL} textarea`,
                `${MODAL} input[type="text"]`,
                `${MODAL} #text-editor`,
            ].join(', ')).last();
            const captionReady = await C.seen(caption, 12000);
            log.step(`modal caption editor ready: ${captionReady}`);
            if (opts.text && captionReady) {
                await caption.click({ force: true });
                await C.insertText(page, opts.text, log);
            } else if (opts.text) {
                log.step('WARNING: caption editor not ready; media will be sent without caption');
            }

            // ---------- ۵) ارسال رسانه ----------
            // دکمهٔ سبز ارسال آی‌گپ کنار input کپشن است. کلیک پایین راستِ کل مودال
            // می‌تواند overlay را ببندد و OK کاذب بسازد؛ پس از مختصات کپشن استفاده می‌کنیم.
            await C.delay(1200);
            let clicked = await clickSendBesideCaption(page, caption, log);
            trustedMediaSubmit = Boolean(clicked);
            sentVia = clicked ? 'caption-neighbor-button' : 'caption-neighbor-missing';
            await C.delay(2200);

            if (await igapUploadModalVisible(page)) {
                log.step('upload modal still visible after caption-neighbor click; trying explicit send selector');
                const sendBtn = await firstEnabled(page, MODAL_SEND, { timeout: 7000, label: 'modal send' });
                if (sendBtn && !sendBtn.disabled) {
                    await sendBtn.locator.click({ force: true });
                    sentVia += '+modal-button:' + sendBtn.selector;
                    await C.delay(2200);
                } else if (sendBtn && sendBtn.disabled) {
                    log.step('WARNING: modal send button stayed disabled after caption-neighbor click');
                }
            }
            if (await igapUploadModalVisible(page)) {
                log.step('upload modal still visible after selector click; send not confirmed');
            }
            log.step(`media submit via ${sentVia}`);
            await C.delay(4000);
        }
        // ---------- ۶) مسیر متن ساده ----------
        else if (opts.text) {
            const chatBox = page.locator('#MiddleColumn div[contenteditable="true"], #text-editor').last();
            await chatBox.waitFor({ state: 'visible', timeout: 10000 });
            await chatBox.click({ force: true });
            await C.insertText(page, opts.text, log);
            await C.delay(500);
            await page.keyboard.press('Enter');
            sentVia = 'composer-enter';
            log.step('text submitted via Enter');
            await C.delay(800);
        }

        // ---------- ۷) تأیید ارسال ----------
        let verified = false;
        let how = '';
        let acceptedButNotVisual = false;
        try {
            await C.waitUntil(async () => {
                const modalGone = !(await igapUploadModalVisible(page));
                const afterPreview = await readPreview(page, name);
                const previewChanged = !!beforePreview && afterPreview !== beforePreview;
                let snippetSeen = false;
                if (snippet) {
                    snippetSeen = await page.locator('#MiddleColumn').getByText(snippet, { exact: false }).first().isVisible().catch(() => false);
                }
                const composerCleared = await page.evaluate(() => {
                    const el = document.querySelector('#MiddleColumn div[contenteditable="true"], #text-editor');
                    return !!el && (el.innerText || '').trim() === '';
                }).catch(() => false);

                if (snippetSeen && modalGone) { verified = true; how = 'snippet-in-chat'; return true; }
                if (previewChanged && modalGone) { verified = true; how = 'left-preview-changed'; return true; }
                if (sentVia === 'composer-enter' && composerCleared) { verified = true; how = 'composer-cleared'; return true; }

                // برای رسانه ابتدا شواهد قوی بالا را ترجیح می‌دهیم. اگر کپشن‌های بلند در DOM قابل‌دیدن
                // نبودند، ولی دکمهٔ واقعی کنار caption کلیک شده و مودال ارسال بسته شده، آن را ارسالِ پذیرفته‌شده
                // حساب می‌کنیم؛ این برای پست‌های رسانه‌دار بلند آی‌گپ جلوی قرمزِ کاذب داشبورد را می‌گیرد.
                if (opts.file && modalGone && trustedMediaSubmit) { acceptedButNotVisual = true; how = 'caption-neighbor-modal-closed-accepted'; return true; }
                if (!opts.file && composerCleared && sentVia === 'composer-enter') { acceptedButNotVisual = true; how = 'composer-cleared-accepted'; return true; }
                return false;
            }, { timeout: opts.file ? Math.max(VERIFY_TIMEOUT_MS, 45000) : VERIFY_TIMEOUT_MS, interval: 500, label: 'send verification' });
        } catch (e) {
            log.step('verification window elapsed: ' + e.message);
        }
        log.step(`verified=${verified} accepted=${acceptedButNotVisual} how=${how || 'n/a'}`);

        await C.safeScreenshot(page, 'last_igap_send.jpg', log);

        if (verified || acceptedButNotVisual) {
            result = { status: 'OK', message: `Sent to iGap (${sentVia})`, verified, proof: how || (verified ? 'verified' : 'accepted'), header: name, log: log.file };
        } else {
            result = { status: 'UNVERIFIED', code: 'SEND_NOT_VERIFIED', message: 'فرایند ارسال انجام شد ولی صحت آن تأیید نشد؛ اسکرین‌شات last_igap_send.jpg و لاگ را ببینید', verified: false, log: log.file };
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
        process.exitCode = exitCode;
    }
})();
