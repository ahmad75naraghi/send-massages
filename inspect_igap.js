const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
    const browser = await chromium.launchPersistentContext('/home/file/public_html/s/igap_profile', {
        executablePath: '/usr/bin/chromium-browser',
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
        headless: true,
        viewport: { width: 1440, height: 900 }
    });

    try {
        const page = browser.pages()[0] || await browser.newPage();
        await page.goto('https://web.igap.net', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(6000);

        // کلیک روی کانال با force برای رد کردن لایه ripple
        const channel = page.locator('span:has-text("شمیم آشنا"), div[data-list-item-id="16200343869985976"]').first();
        await channel.waitFor({ state: 'visible', timeout: 15000 });
        await channel.click({ force: true });
        await page.waitForTimeout(4000);

        // استخراج کلیه دکمه‌ها و اینپوت‌های موجود در کانتینر فوتر و مجاورت ادیتور متن
        const composerData = await page.evaluate(() => {
            const ce = document.querySelector('#MiddleColumn [contenteditable="true"]');
            if (!ce) return { error: 'contenteditable editor not found in MiddleColumn' };

            // صعود تا پیدا کردن کانتینر فوتر چت‌باکس
            let container = ce.parentElement;
            while (container && container.id !== 'MiddleColumn' && container.querySelectorAll('button').length < 2) {
                container = container.parentElement;
            }

            const buttons = Array.from(container.querySelectorAll('button')).map((b, i) => ({
                index: i,
                tag: b.tagName,
                class: b.className,
                aria: b.getAttribute('aria-label') || '',
                innerHtml: b.innerHTML.trim()
            }));

            const inputs = Array.from(document.querySelectorAll('input[type="file"]')).map((inp, i) => ({
                index: i,
                id: inp.id,
                name: inp.name,
                class: inp.className,
                accept: inp.getAttribute('accept') || ''
            }));

            return { buttons, inputs };
        });

        console.log('=== COMPOSER FOOTER INSPECTION ===');
        console.log(JSON.stringify(composerData, null, 2));

        // کلیک تستی روی دکمه ضمیمه (غیر از ارسال و ایموجی) جهت بررسی پاپ‌آپ بازشده
        const ceHandle = page.locator('#MiddleColumn [contenteditable="true"]').first();
        const footerContainer = ceHandle.locator('xpath=ancestor::*[button][last()]');
        const footerBtns = footerContainer.locator('button');
        const count = await footerBtns.count();

        for (let i = 0; i < count; i++) {
            const btn = footerBtns.nth(i);
            const html = await btn.innerHTML();
            if (!html.includes('send') && !html.includes('smile') && !html.includes('emoji')) {
                await btn.click({ force: true });
                await page.waitForTimeout(1500);
                break;
            }
        }

        await page.screenshot({ path: '/home/file/public_html/s/igap_popup_opened.jpg' });

        const openedMenu = await page.evaluate(() => {
            return Array.from(document.querySelectorAll('*'))
                .filter(el => {
                    const text = el.innerText ? el.innerText.trim() : '';
                    return ['عکس', 'ویدیو', 'تصویر', 'فایل', 'گالری', 'Photo', 'File', 'Document'].includes(text);
                })
                .map(el => ({
                    tag: el.tagName,
                    class: el.className,
                    text: el.innerText.trim(),
                    parentTag: el.parentElement ? el.parentElement.tagName : '',
                    parentClass: el.parentElement ? el.parentElement.className : ''
                }));
        });

        console.log('=== OPENED POPUP ELEMENTS ===');
        console.log(JSON.stringify(openedMenu, null, 2));

    } catch (err) {
        console.error(err);
    } finally {
        await browser.close();
    }
})();
