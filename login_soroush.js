const { chromium } = require('playwright');
const readline = require('readline').createInterface({
    input: process.stdin,
    output: process.stdout
});

const question = (query) => new Promise(resolve => readline.question(query, resolve));
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));

(async () => {
    const userDataDir = '/home/file/public_html/s/soroush_profile';
    
    console.log('[*] Launching browser...');
    const browser = await chromium.launchPersistentContext(userDataDir, {
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
    
    console.log('[*] Loading Soroush Web...');
    await page.goto('https://web.splus.ir', { waitUntil: 'domcontentloaded', timeout: 60000 });
    await delay(7000);

    // ۱. بستن پاپ‌آپ PWA
    try {
        const dismissBtn = page.locator('button:has-text("متوجه شدم")');
        if (await dismissBtn.isVisible({ timeout: 2000 })) {
            await dismissBtn.click({ force: true });
            await delay(1000);
        }
    } catch (e) {}

    const rawPhone = await question('=> Enter your mobile number without zero (e.g. 9905367498): ');
    const phone = rawPhone.trim().replace(/^0/, '');

    console.log('[*] Setting country to Iran...');
    const countryCodeInput = page.locator('#sign-in-phone-code');
    
    // کلیک با force برای نادیده گرفتن باگ‌های گرافیکی
    await countryCodeInput.click({ force: true });
    await countryCodeInput.fill('');
    await countryCodeInput.fill('Iran');
    await delay(1500); // صبر برای جستجوی لیست

    // کلیک روی آیتم جستجو شده برای بسته شدن قطعی منو
    const iranItem = page.locator('div[role="menuitem"]:has-text("Iran")').first();
    if (await iranItem.isVisible()) {
        await iranItem.click({ force: true });
    } else {
        await page.keyboard.press('Enter');
    }
    await delay(1000);
    
    // زدن دکمه Escape در صورت گیر کردن منوی دراپ‌داون
    await page.keyboard.press('Escape');
    await delay(500);

    console.log('[*] Filling phone number...');
    const phoneInput = page.locator('#sign-in-phone-number');
    
    // پارامتر force: true باعث می‌شود کلیک تحت هر شرایطی انجام شود
    await phoneInput.click({ force: true });
    await phoneInput.fill(phone);
    await delay(1000);

    await page.screenshot({ path: '/home/file/public_html/s/step_filled.jpg' });
    console.log(`[!] Form screenshot: https://file.falnic.com/s/step_filled.jpg`);

    console.log('[*] Clicking next button...');
    const nextBtn = page.locator('button:has-text("بعدی")');
    
    if (await nextBtn.isEnabled()) {
        await nextBtn.click({ force: true });
    } else {
        await phoneInput.press('Enter');
    }
    
    console.log('[*] Waiting for OTP page or Telegram prompt...');
    await delay(5000);

    // مدیریت پاپ‌آپ ارسال به تلگرام در صورت بروز
    try {
        const telegramBtn = page.locator('button:has-text("ارسال کد به تلگرام")');
        if (await telegramBtn.isVisible({ timeout: 2000 })) {
            console.log('[!] Telegram pop-up detected! Clicking send to Telegram...');
            await telegramBtn.click({ force: true });
            await delay(3000);
        }
    } catch(e) {}

    await page.screenshot({ path: '/home/file/public_html/s/step2.jpg' });
    console.log(`\n[!] OTP Screen: https://file.falnic.com/s/step2.jpg`);
    console.log(`[!] (Check SMS or Telegram for the code)`);

    const otp = await question('=> Enter the verification code: ');
    
    // فیلد ورود کد
    const otpInput = page.locator('input').first();
    await otpInput.click({ force: true });
    await page.keyboard.type(otp.trim(), { delay: 100 });

    console.log('[*] Finalizing session and saving to disk...');
    await delay(12000);

    await page.screenshot({ path: '/home/file/public_html/s/step3.jpg' });
    console.log(`\n[!] Final Result: https://file.falnic.com/s/step3.jpg`);

    await browser.close();
    readline.close();
})();