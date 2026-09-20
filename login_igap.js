'use strict';
/**
 * login_igap.js — ورود تعاملی به آی‌گپ و ساخت igap_profile
 * ============================================================
 *  اجرا (با کاربر وب‌سرور، در یک ترمینال تعاملی):
 *    sudo -u file /usr/bin/node login_igap.js
 *
 *  این اسکریپت همان الگوی login_soroush.js را برای web.igap.net پیاده می‌کند.
 *  پس از ورود موفق، session در igap_profile/ ذخیره می‌شود و send_igap.js
 *  از آن استفاده می‌کند. در پایان، دستور پشتیبان‌گیری چاپ می‌شود.
 */

const path = require('path');
const readline = require('readline').createInterface({ input: process.stdin, output: process.stdout });
const { chromium } = require('playwright');
const C = require(path.join(__dirname, 'lib', 'pw_common.js'));

const PROFILE_DIR = path.join(C.APP_DIR, 'igap_profile');
const VIEWPORT = { width: 1440, height: 900 };

const question = (q) => new Promise(r => readline.question(q, r));

(async () => {
    const log = new C.RunLog('igap_login');
    let browser = null;
    let ok = false;

    try {
        const launched = await C.launchBrowser({ chromium }, PROFILE_DIR, VIEWPORT, log);
        browser = launched.browser;
        const page = launched.page;

        console.log('[*] باز کردن https://web.igap.net ...');
        await page.goto('https://web.igap.net', { waitUntil: 'domcontentloaded', timeout: 60000 });
        await C.delay(7000);

        // اگر از قبل وارد شده‌ایم، نیازی به ورود مجدد نیست
        const alreadyIn = await C.seen(page.locator('#LeftColumn div[aria-haspopup="true"]').first(), 8000);
        if (alreadyIn) {
            console.log('[+] session فعال است؛ ورود مجدد لازم نیست.');
            await C.safeScreenshot(page, 'igap_login_step4.jpg', log);
            ok = true;
            return;
        }

        await C.safeScreenshot(page, 'igap_login_step1.jpg', log);
        console.log('[!] اسکرین‌شات صفحهٔ ورود: ' + path.join(C.APP_DIR, 'igap_login_step1.jpg'));

        // ---------- ۱) شماره موبایل ----------
        const phone = (await question('=> شماره موبایل (مثلاً 09123456789): ')).trim();
        if (!/^0?9\d{9}$/.test(phone)) {
            console.error('[-] قالب شماره نامعتبر است.');
            return;
        }
        const phoneInput = page.locator('input[type="tel"], input[placeholder*="موبایل"], input[placeholder*="شماره"], input').first();
        await phoneInput.waitFor({ state: 'visible', timeout: 15000 });
        await phoneInput.click({ force: true });
        await phoneInput.fill(phone);
        await C.delay(800);
        await C.safeScreenshot(page, 'igap_login_step2.jpg', log);

        // ---------- ۲) دکمهٔ ادامه (+ تلاش مجدد با قالب بین‌المللی) ----------
        const nextBtn = page.locator('button:has-text("ادامه"), button:has-text("ورود"), button[type="submit"]').first();
        const submitPhone = async () => {
            if (await C.seen(nextBtn, 5000)) await nextBtn.click({ force: true });
            else await phoneInput.press('Enter');
        };
        const otpStage = async () => {
            const txt = await page.locator('body').innerText().catch(() => '');
            return /کد تأیید|کد تایید|کد ارسالی|verification code/i.test(txt);
        };

        await submitPhone();
        const reached = await C.waitUntil(otpStage, { timeout: 12000, interval: 1000, label: 'otp stage' }).catch(() => false);
        if (!reached) {
            const intl = '+98' + phone.replace(/^0/, '');
            log.step('phone format retry → ' + intl);
            await phoneInput.fill(intl);
            await C.delay(600);
            await submitPhone();
        }

        console.log('[*] منتظر صفحهٔ کد تأیید...');
        await C.delay(6000);
        await C.safeScreenshot(page, 'igap_login_step3.jpg', log);
        console.log('[!] اسکرین‌شات مرحلهٔ کد: ' + path.join(C.APP_DIR, 'igap_login_step3.jpg'));

        // ---------- ۳) کد تأیید ----------
        const otp = (await question('=> کد تأیید دریافتی: ')).trim();
        const otpField = await C.firstVisible(page, [
            'input[type="number"]', 'input[placeholder*="کد"]', '#auth_code',
            'input[type="tel"]', 'input[type="text"]', 'input'
        ], { timeout: 10000, label: 'otp input' });
        if (!otpField) throw new Error('فیلد کد تأیید پیدا نشد (اسکرین‌شات igap_login_step3.jpg را ببینید)');
        log.step('otp input selector: ' + otpField.selector);
        await otpField.locator.click({ force: true });
        await page.keyboard.type(otp, { delay: 120 });
        await C.delay(2000);
        const submit = page.locator('button:has-text("تأیید"), button:has-text("ورود"), button:has-text("ادامه"), button[type="submit"]').first();
        if (await C.seen(submit, 3000)) await submit.click({ force: true });
        else await otpField.locator.press('Enter');

        console.log('[*] منتظر بارگذاری لیست گفت‌وگوها (تا ۳۰ ثانیه)...');
        ok = await C.seen(page.locator('#LeftColumn div[aria-haspopup="true"]').first(), 30000);
        await C.safeScreenshot(page, 'igap_login_step4.jpg', log);

        if (ok) {
            console.log('[+] ورود موفق. لیست گفت‌وگوها بارگذاری شد.');
            console.log('[+] پشتیبان‌گیری فوری:');
            console.log(`    cd ${C.APP_DIR} && tar -czf backups/sessions_$(date +%F_%H%M).tar.gz soroush_profile igap_profile state.sqlite && chmod 600 backups/sessions_*.tar.gz`);
        } else {
            console.error('[-] پس از وارد کردن کد، لیست گفت‌وگوها بارگذاری نشد. اسکرین‌شات igap_login_step4.jpg را بررسی کنید.');
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
