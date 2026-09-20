const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const getArg = (flag) => {
    const found = args.find(a => a.startsWith(`--${flag}=`));
    return found ? found.split('=').slice(1).join('=') : null;
};

const targetChannel = (getArg('channel') || '').replace(/^@/, '');
const postText = getArg('text') || '';
const mediaFile = getArg('file');

const delay = ms => new Promise(resolve => setTimeout(resolve, ms));

(async () => {
    const userDataDir = '/home/file/public_html/s/igap_profile';

    // پاکسازی قفل‌های احتمالی مرورگر
    ['SingletonLock', 'SingletonCookie', 'SingletonSocket'].forEach(f => {
        const p = path.join(userDataDir, f);
        if (fs.existsSync(p)) {
            try { fs.unlinkSync(p); } catch (e) {}
        }
    });

    let browser;
    try {
        browser = await chromium.launchPersistentContext(userDataDir, {
            executablePath: '/usr/bin/chromium-browser',
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu'
            ],
            headless: true,
            viewport: { width: 1440, height: 900 },
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        });

        const page = browser.pages()[0] || await browser.newPage();

        await page.goto('https://web.igap.net', { waitUntil: 'domcontentloaded', timeout: 60000 });
        await delay(6000);

        // ۱. انتخاب کانال شمیم آشنا
        const channelItem = page.locator('span:has-text("شمیم آشنا"), div[data-list-item-id="16200343869985976"]').first();
        await channelItem.waitFor({ state: 'visible', timeout: 15000 });
        await channelItem.click({ force: true });
        await delay(3500);

        // ۲. ارسال مدیا به همراه کپشن
        if (mediaFile && fs.existsSync(mediaFile)) {
            const ext = path.extname(mediaFile).toLowerCase();
            const isVisual = ['.jpg', '.jpeg', '.png', '.webp', '.gif', '.mp4'].includes(ext);

            // کلیک روی دکمه باز کردن منوی ضمیمه
            const attachBtn = page.locator('button:has(i.icon-ig-attachment-outline)').first();
            await attachBtn.waitFor({ state: 'visible', timeout: 10000 });
            await attachBtn.click({ force: true });
            await delay(1200);

            // انتخاب سلکتور متناسب با نوع فایل
            const targetMenuItem = isVisual
                ? page.locator('.MenuItem:has-text("Media (image/video)"), .MenuItem:has(i.icon-gallery)').first()
                : page.locator('.MenuItem:has-text("File (document)"), .MenuItem:has(i.icon-file)').first();

            await targetMenuItem.waitFor({ state: 'visible', timeout: 5000 });

            // ثبت لیسنر قبل از کلیک برای جلوگیری از Race Condition
            const [fileChooser] = await Promise.all([
                page.waitForEvent('filechooser', { timeout: 15000 }),
                targetMenuItem.click({ force: true })
            ]);

            await fileChooser.setFiles(mediaFile);

            // انتظار برای رندر مودال پیش‌نمایش تصویر
            await delay(4500);

            // درج متن در کپشن مودال
            if (postText) {
                const captionInput = page.locator('.modal-dialog div[contenteditable="true"], [role="dialog"] div[contenteditable="true"], div[contenteditable="true"]:visible').last();
                if (await captionInput.isVisible({ timeout: 5000 }).catch(() => false)) {
                    await captionInput.click({ force: true });
                    await page.keyboard.insertText(postText);
                    await delay(500);
                }
            }

            // کلیک روی دکمه ارسال درون مودال
            const sendModalBtn = page.locator([
                '.modal-dialog button:has-text("ارسال")',
                '.modal-dialog button:has-text("Send")',
                '[role="dialog"] button:has-text("ارسال")',
                '[role="dialog"] button:has-text("Send")',
                'button:has(i.icon-ig-send-outline)',
                'button:has(i.icon-send)',
                'button.confirm-dialog-button',
                'button.btn-primary:visible'
            ].join(', ')).last();

            if (await sendModalBtn.isVisible({ timeout: 5000 }).catch(() => false)) {
                await sendModalBtn.click({ force: true });
            } else {
                await page.keyboard.press('Enter');
            }

            // مهلت زمانی برای اتمام آپلود و ارسال روی سرور آیگپ
            await delay(10000);
        }
        // ۳. ارسال متن خام (در صورت عدم وجود مدیا)
        else if (postText) {
            const chatBox = page.locator('#MiddleColumn div[contenteditable="true"], #text-editor, div[contenteditable="true"]:visible').last();
            await chatBox.waitFor({ state: 'visible', timeout: 10000 });
            await chatBox.click({ force: true });
            await page.keyboard.insertText(postText);
            await delay(500);
            await page.keyboard.press('Enter');
            await delay(3000);
        }

        await page.screenshot({ path: '/home/file/public_html/s/last_igap_send.jpg' });
        console.log(JSON.stringify({ status: 'OK', message: 'Media post dispatched successfully' }));
    } catch (err) {
        console.error(JSON.stringify({ status: 'ERROR', error: err.message }));
        process.exit(1);
    } finally {
        if (browser) await browser.close();
    }
})();