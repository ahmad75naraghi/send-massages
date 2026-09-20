const { chromium } = require('playwright');

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

        // ۱. کلیک روی کانال
        const channel = page.locator('span:has-text("شمیم آشنا"), div[data-list-item-id="16200343869985976"]').first();
        await channel.waitFor({ state: 'visible', timeout: 15000 });
        await channel.click({ force: true });
        await page.waitForTimeout(3000);

        // ۲. کلیک دقیق روی دکمه ضمیمه (ایندکس ۱۳)
        const attachBtn = page.locator('button:has(i.icon-ig-attachment-outline)').first();
        await attachBtn.waitFor({ state: 'visible', timeout: 10000 });
        await attachBtn.click({ force: true });
        await page.waitForTimeout(2000);

        // ذخیره اسکرین‌شات از منوی بازشده
        await page.screenshot({ path: '/home/file/public_html/s/igap_attach_menu.jpg' });

        // بررسی اینپوت‌های فایل جدید یا گزینه‌های منو
        const result = await page.evaluate(() => {
            const inputs = Array.from(document.querySelectorAll('input[type="file"]')).map(inp => ({
                id: inp.id,
                class: inp.className,
                accept: inp.getAttribute('accept') || '',
                outerHtml: inp.outerHTML
            }));

            const menuOptions = Array.from(document.querySelectorAll('button, div, li, span'))
                .filter(el => {
                    const t = el.innerText ? el.innerText.trim() : '';
                    return el.children.length <= 2 && t.length > 0 && t.length < 25;
                })
                .slice(-15)
                .map(el => ({
                    tag: el.tagName,
                    class: el.className,
                    text: el.innerText.trim(),
                    html: el.innerHTML.slice(0, 100)
                }));

            return { inputs, menuOptions };
        });

        console.log('=== ATTACH MENU INSPECTION ===');
        console.log(JSON.stringify(result, null, 2));

    } catch (err) {
        console.error(err);
    } finally {
        await browser.close();
    }
})();
