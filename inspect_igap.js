'use strict';
/** inspect_igap.js — استخراج ساختار composer/footer آی‌گپ با مسیرهای پیکربندی‌شده */

const path = require('path');
const { chromium } = require('playwright');
const C = require(path.join(__dirname, 'lib', 'pw_common.js'));

(async () => {
    const profile = C.env('IGAP_PROFILE_DIR', path.join(C.APP_DIR, 'igap_profile'));
    const name = C.env('IGAP_CHANNEL_NAME', 'شمیم آشنا').replace(/["\\]/g, '');
    const itemId = C.env('IGAP_ITEM_ID', '16200343869985976').replace(/["\\]/g, '');
    const log = new C.RunLog('inspect_igap');
    let browser = null;

    try {
        const launched = await C.launchBrowser({ chromium }, profile, { width: 1440, height: 900 }, log);
        browser = launched.browser;
        const page = launched.page;

        await page.goto('https://web.igap.net', { waitUntil: 'domcontentloaded', timeout: 60000 });
        await C.delay(6000);

        const channel = page.locator(`span:has-text("${name}"), div[data-list-item-id="${itemId}"]`).first();
        await channel.waitFor({ state: 'visible', timeout: 15000 });
        await channel.click({ force: true });
        await C.delay(4000);

        const composerData = await page.evaluate(() => {
            const ce = document.querySelector('#MiddleColumn [contenteditable="true"], #text-editor');
            if (!ce) return { error: 'contenteditable editor not found in MiddleColumn' };

            let container = ce.parentElement;
            while (container && container.id !== 'MiddleColumn' && container.querySelectorAll('button').length < 2) {
                container = container.parentElement;
            }
            if (!container) return { error: 'composer container not found' };

            const buttons = Array.from(container.querySelectorAll('button')).map((b, i) => ({
                index: i,
                tag: b.tagName,
                class: typeof b.className === 'string' ? b.className : '',
                aria: b.getAttribute('aria-label') || '',
                title: b.getAttribute('title') || '',
                innerHtml: b.innerHTML.trim().slice(0, 300)
            }));

            const inputs = Array.from(document.querySelectorAll('input[type="file"]')).map((inp, i) => ({
                index: i,
                id: inp.id,
                name: inp.name,
                class: typeof inp.className === 'string' ? inp.className : '',
                accept: inp.getAttribute('accept') || ''
            }));

            return { buttons, inputs };
        });

        const attachBtn = page.locator('button:has(i.icon-ig-attachment-outline)').first();
        if (await C.seen(attachBtn, 5000)) {
            await attachBtn.click({ force: true });
            await C.delay(1500);
        }

        await C.safeScreenshot(page, 'igap_popup_opened.jpg', log);

        const openedMenu = await page.evaluate(() => {
            const words = ['عکس', 'ویدیو', 'ویدئو', 'تصویر', 'فایل', 'گالری', 'Photo', 'File', 'Document', 'Media'];
            return Array.from(document.querySelectorAll('button, div, li, span'))
                .filter(el => {
                    const text = el.innerText ? el.innerText.trim() : '';
                    return words.some(w => text.includes(w)) && text.length < 80;
                })
                .slice(-60)
                .map(el => ({
                    tag: el.tagName,
                    class: typeof el.className === 'string' ? el.className : '',
                    text: el.innerText.trim(),
                    parentTag: el.parentElement ? el.parentElement.tagName : '',
                    parentClass: el.parentElement && typeof el.parentElement.className === 'string' ? el.parentElement.className : ''
                }));
        });

        C.emit({
            status: 'OK',
            composerData,
            openedMenu,
            screenshot: path.join(C.APP_DIR, 'igap_popup_opened.jpg'),
        });
    } catch (err) {
        log.step('FATAL: ' + (err && err.stack ? err.stack : String(err)));
        C.emit({ status: 'ERROR', code: 'RUNTIME', error: C.RunLog.brief(err && err.message, 300), log: log.file });
        process.exitCode = 1;
    } finally {
        await C.closeQuietly(browser, log);
        C.cleanSingletons(profile, log);
    }
})();
