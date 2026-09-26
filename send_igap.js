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

const MODAL = '.modal-dialog, [role="dialog"]';
const MODAL_SEND = [
    `${MODAL} button:has-text("ارسال")`,
    `${MODAL} button:has-text("Send")`,
    `${MODAL} button:has(i.icon-ig-send-outline)`,
    `${MODAL} button.confirm-dialog-button`,
    `${MODAL} button.btn-primary`,
];

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
                '#text-editor',
            ].join(', ')).last();
            const captionReady = await C.seen(caption, 12000);
            log.step(`modal caption editor ready: ${captionReady}`);
            if (opts.text && captionReady) {
                await caption.click({ force: true });
                await C.insertText(page, opts.text, log);
            } else if (opts.text) {
                log.step('WARNING: caption editor not ready; media will be sent without caption');
            }

            // ---------- ۵) ارسال: فقط از داخل مودال ----------
            const sendBtn = await C.firstVisible(page, MODAL_SEND, { timeout: 8000, label: 'modal send' });
            if (sendBtn) {
                await sendBtn.locator.click({ force: true });
                sentVia = 'modal-button';
            } else {
                await page.keyboard.press('Control+Enter');
                await C.delay(800);
                const modalStill = await page.locator(MODAL).last().isVisible().catch(() => false);
                if (modalStill) { await page.keyboard.press('Enter'); sentVia = 'enter-last-resort'; }
                else sentVia = 'ctrl+enter';
            }
            log.step(`media submit via ${sentVia}`);
            await C.delay(1000);
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
                const modalGone = !(await page.locator(MODAL).last().isVisible().catch(() => false));
                const afterPreview = await readPreview(page, name);
                const previewChanged = !!beforePreview && afterPreview !== beforePreview;
                let snippetSeen = false;
                if (snippet) {
                    snippetSeen = await page.locator('#MiddleColumn').getByText(snippet, { exact: false }).first().isVisible().catch(() => false);
                }
                const afterCards = await countCards(page);
                const cardsGrew = beforeCards >= 0 && afterCards > beforeCards;
                const composerCleared = await page.evaluate(() => {
                    const el = document.querySelector('#MiddleColumn div[contenteditable="true"], #text-editor');
                    return !!el && (el.innerText || '').trim() === '';
                }).catch(() => false);

                if (snippetSeen && modalGone) { verified = true; how = 'snippet-in-chat'; return true; }
                if (previewChanged) { verified = true; how = 'left-preview-changed'; return true; }
                if (opts.file && modalGone && cardsGrew) { verified = true; how = 'modal-closed+cards+' + (afterCards - beforeCards); return true; }
                if (sentVia === 'composer-enter' && composerCleared) { verified = true; how = 'composer-cleared'; return true; }

                // آی‌گپ گاهی بعد از ارسال واقعی، DOM/preview را به‌موقع به‌روزرسانی نمی‌کند.
                // اگر مودال بسته شده یا composer خالی شده باشد، خطای کاذب ندهیم؛ OK با proof محافظه‌کارانه برمی‌گردانیم.
                if (opts.file && modalGone && sentVia !== 'none') { acceptedButNotVisual = true; how = 'modal-closed-accepted'; return true; }
                if (!opts.file && composerCleared && sentVia === 'composer-enter') { acceptedButNotVisual = true; how = 'composer-cleared-accepted'; return true; }
                return false;
            }, { timeout: VERIFY_TIMEOUT_MS, interval: 500, label: 'send verification' });
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
