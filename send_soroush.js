const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const getArg = (flag) => {
    const found = args.find(a => a.startsWith(`--${flag}=`));
    return found ? found.split('=').slice(1).join('=') : null;
};

const targetChannel = (getArg('channel') || 'shamimeashena1').replace(/^@/, '');
const postText = getArg('text') || '';
const mediaFile = getArg('file');

const delay = ms => new Promise(resolve => setTimeout(resolve, ms));

(async () => {
    const userDataDir = '/home/file/public_html/s/soroush_profile';

    // پاکسازی فایل‌های قفل Singleton مرورگر
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
            viewport: { width: 1280, height: 720 },
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        });

        const page = browser.pages()[0] || await browser.newPage();

        await page.goto(`https://web.splus.ir/#@${targetChannel}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
        await delay(5000);

        // اطمینان از لود شدن محیط چت کانال
        const chatInput = page.locator('div[contenteditable="true"], .input-message-input');
        if (!await chatInput.isVisible()) {
            const searchInput = page.locator('#telegram-search-input, input[type="search"], input').first();
            if (await searchInput.isVisible()) {
                await searchInput.click();
                await searchInput.fill(targetChannel);
                await delay(2000);
                await page.keyboard.press('Enter');
                await delay(2000);
                const firstResult = page.locator('.chat-list .ListItem, .chat-item').first();
                if (await firstResult.isVisible()) {
                    await firstResult.click();
                    await delay(2000);
                }
            }
        }

        // سناریوی ۱: ارسال مدیا همراه با کپشن
        if (mediaFile && fs.existsSync(mediaFile)) {
            const ext = path.extname(mediaFile).toLowerCase();
            const isVisual = ['.jpg', '.jpeg', '.png', '.webp', '.gif', '.mp4'].includes(ext);

            // کلیک روی سنجاقک ضمیمه
            const attachBtn = page.locator('.AttachMenu button, button.attach-file, button[aria-label*="Attach"], button[title*="Attach"], .attach-file').first();
            await attachBtn.waitFor({ state: 'visible', timeout: 10000 });
            await attachBtn.click({ force: true });
            await delay(1000);

            // رصد منوی بازشده سنجاقک
            const openMenu = page.locator('.menu-container:not(.not-open), .AttachMenu .menu-container, .bubble.open').last();
            await openMenu.waitFor({ state: 'visible', timeout: 5000 });

            // انتخاب آیتم‌های فقط مرئی (Visible) درون همان منوی باز شده
            const visibleItems = openMenu.locator('.MenuItem:visible, [role="menuitem"]:visible');
            const targetItem = isVisual ? visibleItems.first() : visibleItems.nth(1);

            // تحویل فایل از طریق هندلر filechooser کرومیوم
            const fileChooserPromise = page.waitForEvent('filechooser', { timeout: 10000 });
            await targetItem.click({ force: true });
            const fileChooser = await fileChooserPromise;
            await fileChooser.setFiles(mediaFile);

            // انتظار برای رندر مودال پیش‌نمایش مدیا
            await delay(4000);

            // ثبت متن در فیلد کپشن پنجره مودال
            if (postText) {
                const modalCaption = page.locator('.modal-dialog div[contenteditable="true"], .media-preview div[contenteditable="true"], div[contenteditable="true"]').last();
                if (await modalCaption.isVisible({ timeout: 4000 })) {
                    await modalCaption.click({ force: true });
                    await page.keyboard.insertText(postText);
                    await delay(500);
                }
            }

            // ارسال پیام حاوی مدیا
            const sendModalBtn = page.locator('.modal-dialog button.confirm-dialog-button, .modal-dialog button.primary, .modal-dialog button:has-text("ارسال"), .modal-dialog button:has-text("Send")').last();
            if (await sendModalBtn.isVisible({ timeout: 5000 })) {
                await sendModalBtn.click({ force: true });
            } else {
                await page.keyboard.press('Enter');
            }

            // مهلت زمانی جهت بارگذاری مدیا روی سرور سروش
            await delay(10000);
        }
        // سناریوی ۲: فقط متن ساده
        else if (postText) {
            const editable = page.locator('div[contenteditable="true"], .input-message-input').first();
            await editable.click();
            await page.keyboard.insertText(postText);
            await delay(500);
            await page.keyboard.press('Enter');
            await delay(3000);
        }

        await page.screenshot({ path: '/home/file/public_html/s/last_media_send.jpg' });
        console.log(JSON.stringify({ status: 'OK', message: 'Media post dispatched successfully' }));
    } catch (err) {
        console.error(JSON.stringify({ status: 'ERROR', error: err.message }));
        process.exit(1);
    } finally {
        if (browser) await browser.close();
    }
})();