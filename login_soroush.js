'use strict';
/**
 * login_soroush.js — ورود تعاملی به سروش‌پلاس و ساخت soroush_profile
 *
 * اجرا:
 *   SYNC_HEADED=1 node login_soroush.js   # اگر نمایشگر/SSH X-forward دارید
 *   node login_soroush.js                 # headless + اسکرین‌شات‌های مرحله‌ای
 */

const path = require('path');
const readline = require('readline').createInterface({ input: process.stdin, output: process.stdout });
const { chromium } = require('playwright');
const C = require(path.join(__dirname, 'lib', 'pw_common.js'));

const PROFILE_DIR = C.env('SOROUSH_PROFILE_DIR', path.join(C.APP_DIR, 'soroush_profile'));
const VIEWPORT = { width: 1280, height: 720 };
const question = (query) => new Promise(resolve => readline.question(query, resolve));

(async () => {
    const log = new C.RunLog('soroush_login');
    let browser = null;
    let ok = false;

    try {
        const launched = await C.launchBrowser({ chromium }, PROFILE_DIR, VIEWPORT, log);
        browser = launched.browser;
        const page = launched.page;

        console.log('[*] باز کردن https://web.splus.ir ...');
        await page.goto('https://web.splus.ir', { waitUntil: 'domcontentloaded', timeout: 60000 });
        await C.delay(7000);

        // اگر از قبل وارد شده‌ایم، همان پروفایل معتبر است.
        const alreadyIn = await C.seen(page.locator('.LeftColumn .ListItem, .chat-list .ListItem, .MiddleColumn div[contenteditable="true"]').first(), 8000);
        if (alreadyIn) {
            console.log('[+] session فعال است؛ ورود مجدد لازم نیست.');
            await C.safeScreenshot(page, 'soroush_login_step_done.jpg', log);
            ok = true;
            return;
        }

        // بستن پاپ‌آپ PWA («متوجه شدم») در صورت وجود
        const dismissBtn = page.locator('button:has-text("متوجه شدم"), button:has-text("Got it")').first();
        if (await C.seen(dismissBtn, 2500)) {
            await dismissBtn.click({ force: true });
            await C.delay(1000);
        }

        await C.safeScreenshot(page, 'soroush_login_step1.jpg', log);
        console.log('[!] اسکرین‌شات شروع ورود: ' + path.join(C.APP_DIR, 'soroush_login_step1.jpg'));

        const rawPhone = (await question('=> شماره موبایل (با یا بدون صفر، مثل 09123456789): ')).trim();
        const phone = rawPhone.replace(/^\+?98/, '').replace(/^0/, '');
        if (!/^9\d{9}$/.test(phone)) {
            console.error('[-] قالب شماره نامعتبر است.');
            return;
        }

        console.log('[*] تنظیم کشور روی Iran و وارد کردن شماره...');
        const countryCodeInput = page.locator('#sign-in-phone-code').first();
        if (await C.seen(countryCodeInput, 12000)) {
            await countryCodeInput.click({ force: true });
            await countryCodeInput.fill('');
            await countryCodeInput.fill('Iran');
            await C.delay(1500);
            const iranItem = page.locator('div[role="menuitem"]:has-text("Iran"), .MenuItem:has-text("Iran")').first();
            if (await C.seen(iranItem, 3000)) await iranItem.click({ force: true });
            else await page.keyboard.press('Enter');
            await page.keyboard.press('Escape').catch(() => {});
            await C.delay(500);
        }

        const phoneInput = page.locator('#sign-in-phone-number, input[type="tel"], input[placeholder*="شماره"], input').first();
        await phoneInput.waitFor({ state: 'visible', timeout: 15000 });
        await phoneInput.click({ force: true });
        await phoneInput.fill(phone);
        await C.delay(800);
        await C.safeScreenshot(page, 'soroush_login_step2_phone.jpg', log);
        console.log('[!] اسکرین‌شات فرم شماره: ' + path.join(C.APP_DIR, 'soroush_login_step2_phone.jpg'));

        console.log('[*] ارسال شماره...');
        const nextBtn = page.locator('button:has-text("بعدی"), button:has-text("ادامه"), button[type="submit"]').first();
        if (await C.seen(nextBtn, 5000)) await nextBtn.click({ force: true });
        else await phoneInput.press('Enter');

        await C.delay(5000);
        const telegramBtn = page.locator('button:has-text("ارسال کد به تلگرام"), button:has-text("Telegram")').first();
        if (await C.seen(telegramBtn, 2500)) {
            console.log('[!] گزینهٔ ارسال کد به تلگرام دیده شد؛ کلیک می‌شود...');
            await telegramBtn.click({ force: true });
            await C.delay(3000);
        }

        await C.safeScreenshot(page, 'soroush_login_step3_otp.jpg', log);
        console.log('[!] اسکرین‌شات مرحلهٔ کد: ' + path.join(C.APP_DIR, 'soroush_login_step3_otp.jpg'));
        console.log('[!] کد را از SMS یا تلگرام بردارید.');

        const otp = (await question('=> کد تأیید: ')).trim();
        const otpField = await C.firstVisible(page, [
            'input[type="number"]', 'input[placeholder*="کد"]', '#sign-in-code',
            'input[type="tel"]', 'input[type="text"]', 'input'
        ], { timeout: 12000, label: 'otp input' });
        if (!otpField) throw new Error('فیلد کد تأیید پیدا نشد (soroush_login_step3_otp.jpg را ببینید)');
        await otpField.locator.click({ force: true });
        await page.keyboard.type(otp, { delay: 100 });

        const submit = page.locator('button:has-text("تأیید"), button:has-text("ورود"), button:has-text("بعدی"), button[type="submit"]').first();
        if (await C.seen(submit, 3000)) await submit.click({ force: true });
        else await otpField.locator.press('Enter');

        console.log('[*] منتظر بارگذاری لیست گفت‌وگوها (تا ۳۰ ثانیه)...');
        ok = await C.seen(page.locator('.LeftColumn .ListItem, .chat-list .ListItem, .MiddleColumn div[contenteditable="true"]').first(), 30000);
        await C.safeScreenshot(page, 'soroush_login_step4_done.jpg', log);

        if (ok) {
            console.log('[+] ورود موفق. session در این مسیر ذخیره شد: ' + PROFILE_DIR);
            console.log('[+] پیشنهاد پشتیبان‌گیری:');
            console.log(`    cd ${C.APP_DIR} && mkdir -p backups && tar -czf backups/sessions_$(date +%F_%H%M).tar.gz soroush_profile igap_profile state.sqlite && chmod 600 backups/sessions_*.tar.gz`);
        } else {
            console.error('[-] پس از وارد کردن کد، لیست گفت‌وگوها بارگذاری نشد. اسکرین‌شات soroush_login_step4_done.jpg را بررسی کنید.');
        }
    } catch (err) {
        console.error('[-] خطا: ' + C.RunLog.brief((err && err.message) || String(err), 300));
        log.step('FATAL: ' + (err && err.stack ? err.stack : String(err)));
    } finally {
        await C.closeQuietly(browser, log);
        C.cleanSingletons(PROFILE_DIR, log);
        readline.close();
        process.exitCode = ok ? 0 : 1;
    }
})();
