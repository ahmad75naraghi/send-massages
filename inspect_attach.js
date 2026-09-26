'use strict';
/** inspect_attach.js — بازرسی منوی ضمیمهٔ آی‌گپ با مسیرهای پیکربندی‌شده */

const path = require('path');
const { chromium } = require('playwright');
const C = require(path.join(__dirname, 'lib', 'pw_common.js'));

(async () => {
    const profile = C.env('IGAP_PROFILE_DIR', path.join(C.APP_DIR, 'igap_profile'));
    const name = C.env('IGAP_CHANNEL_NAME', 'شمیم آشنا').replace(/["\\]/g, '');
    const itemId = C.env('IGAP_ITEM_ID', '16200343869985976').replace(/["\\]/g, '');
    const log = new C.RunLog('inspect_attach');
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
        await C.delay(3000);

        const attachBtn = page.locator('button:has(i.icon-ig-attachment-outline)').first();
        await attachBtn.waitFor({ state: 'visible', timeout: 10000 });
        await attachBtn.click({ force: true });
        await C.delay(2000);

        await C.safeScreenshot(page, 'igap_attach_menu.jpg', log);

        const result = await page.evaluate(() => {
            const inputs = Array.from(document.querySelectorAll('input[type="file"]')).map(inp => ({
                id: inp.id,
                class: typeof inp.className === 'string' ? inp.className : '',
                accept: inp.getAttribute('accept') || '',
                outerHtml: inp.outerHTML
            }));

            const menuOptions = Array.from(document.querySelectorAll('button, div, li, span'))
                .filter(el => {
                    const t = el.innerText ? el.innerText.trim() : '';
                    return el.children.length <= 2 && t.length > 0 && t.length < 40;
                })
                .slice(-25)
                .map(el => ({
                    tag: el.tagName,
                    class: typeof el.className === 'string' ? el.className : '',
                    text: el.innerText.trim(),
                    html: el.innerHTML.slice(0, 140)
                }));

            return { inputs, menuOptions };
        });

        C.emit({ status: 'OK', screenshot: path.join(C.APP_DIR, 'igap_attach_menu.jpg'), result });
    } catch (err) {
        log.step('FATAL: ' + (err && err.stack ? err.stack : String(err)));
        C.emit({ status: 'ERROR', code: 'RUNTIME', error: C.RunLog.brief(err && err.message, 300), log: log.file });
        process.exitCode = 1;
    } finally {
        await C.closeQuietly(browser, log);
        C.cleanSingletons(profile, log);
    }
})();
