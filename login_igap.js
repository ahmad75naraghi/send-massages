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
const fs = require('fs');
const readline = require('readline').createInterface({ input: process.stdin, output: process.stdout });
const { chromium } = require('playwright');
const C = require(path.join(__dirname, 'lib', 'pw_common.js'));

const PROFILE_DIR = C.env('IGAP_PROFILE_DIR', path.join(C.APP_DIR, 'igap_profile'));
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
        // اسنپ‌شات متنی هر مرحله — دیباگ بدون نیاز به دیدن تصویر
        const snapText = async (name) => {
            try {
                const t = await page.locator('body').innerText({ timeout: 3000 });
                fs.writeFileSync(path.join(C.APP_DIR, `igap_login_${name}.txt`), t);
            } catch (e) {}
        };
        const bodyText = async () => {
            try { return ((await page.locator('body').innerText({ timeout: 3000 })) || '').replace(/\u200c/g, ' ').replace(/\s+/g, ' ').trim(); }
            catch (e) { return ''; }
        };
        const onPhoneStage = async () => /شماره\s*موبایل|کد\s*کشور/.test(await bodyText());
        const onOtpStage = async () => /کد\s*(تأیید|تایید|ارسالی)|verification code|verify/i.test(await bodyText()) && !(await onPhoneStage());

        const phoneInput = page.locator('input[type="tel"], input[inputmode="tel"], input[autocomplete="tel"], input[placeholder*="موبایل"], input[placeholder*="شماره"], input[name*="phone"], input[name*="mobile"], input').first();
        await phoneInput.waitFor({ state: 'visible', timeout: 15000 });

        // ---------- ۱-ب) انتخاب کشور — فرم جدید بدون آن submit نمی‌شود ----------
        try {
            const sel = page.locator('select').first();
            if (await C.seen(sel, 1500)) {
                const opts = await sel.locator('option').allInnerTextValues().catch(() => []);
                const iran = opts.findIndex(t => /ایران|Iran|\+?\s*98/.test(String(t || '')));
                if (iran >= 0) {
                    await sel.selectOption({ index: iran });
                    log.step('country (native select): ' + String(opts[iran] || '').trim());
                    await C.delay(500);
                }
            } else {
                const opener = page.locator('[class*="country" i], [aria-haspopup="listbox"]').first();
                if (await C.seen(opener, 1500)) {
                    await opener.click({ force: true });
                    await C.delay(800);
                    const opt = page.locator('li:has-text("ایران"), [role="option"]:has-text("ایران"), li:has-text("+98"), [role="option"]:has-text("98"), li:has-text("Iran")').first();
                    if (await C.seen(opt, 2500)) {
                        await opt.click({ force: true });
                        log.step('country (custom dropdown): ایران');
                        await C.delay(500);
                    } else {
                        await page.keyboard.press('Escape');
                        log.step('country dropdown opened but Iran option not found');
                    }
                }
            }
        } catch (e) { log.step('country select best-effort failed: ' + e.message); }

        await phoneInput.click({ force: true });
        await phoneInput.fill(phone);
        await C.delay(800);
        await snapText('step2');
        await C.safeScreenshot(page, 'igap_login_step2.jpg', log);

        // ---------- ۲) ارسال فرم — همهٔ راه‌ها، با تأیید واقعی رفتن به مرحلهٔ کد ----------
        // فرم جدید آی‌گپ دکمهٔ «ادامه» با متن ندارد؛ پس همهٔ کاندیدهای منطقی را
        // می‌آزماییم و بعد از هر کلیک چک می‌کنیم صفحه واقعاً به مرحلهٔ کد رفته یا نه.
        const submitCandidates = [
            'button:has-text("ادامه")', 'button:has-text("ورود")', 'button:has-text("Next")',
            'button:has-text("Log in")', 'button:has-text("Sign in")', 'button:has-text("تأیید")',
            'button[type="submit"]', 'input[type="submit"]',
            'form button:not([disabled])', 'button:not([disabled])',
        ];
        const submitPhoneOnce = async () => {
            for (const cand of submitCandidates) {
                const btn = page.locator(cand).first();
                if (await C.seen(btn, 1200)) {
                    try {
                        await btn.click({ force: true });
                        log.step('submit attempt via ' + cand);
                        await C.delay(2500);
                        if (await onOtpStage()) return true;
                        await page.keyboard.press('Escape');   // اگر منویی باز شده بود ببند
                        await C.delay(400);
                    } catch (e) { /* کاندید بعدی */ }
                }
            }
            await phoneInput.press('Enter');
            log.step('submit attempt via Enter');
            await C.delay(2500);
            return await onOtpStage();
        };

        let reached = await submitPhoneOnce();
        if (!reached) {
            const intl = '+98' + phone.replace(/^0/, '');
            log.step('phone format retry → ' + intl);
            await phoneInput.fill(intl);
            await C.delay(600);
            reached = await submitPhoneOnce();
        }

        if (!reached) {
            // صادقانه بایست: اگر فرم نرفته، یعنی SMS اصلاً درخواست نشده —
            // منتظر کد نباش که هرگز نمی‌آید.
            await snapText('step3_stuck');
            await C.safeScreenshot(page, 'igap_login_step3_stuck.jpg', log);
            const t = await bodyText();
            console.error('[-] فرم شماره به مرحلهٔ کد نرفت — پیامکی درخواست نشده است.');
            console.error('    متن صفحه: «' + t.slice(0, 300) + '»');
            console.error('    ذخیره شد: igap_login_step3_stuck.txt و .jpg — این دو را بفرستید تا سلکتور دقیق فرم را ببندم.');
            return;
        }

        console.log('[+] صفحهٔ کد تأیید آمد — یعنی درخواست پیامک ارسال شده است.');
        console.log('[*] اگر پیامک دیر آمد چند دقیقه صبر کنید؛ اگر نیامد، شاید شماره محدود شده — ۱۵ دقیقه بعد دوباره.');
        await snapText('step3');
        await C.safeScreenshot(page, 'igap_login_step3.jpg', log);
        console.log('[!] اسکرین‌شات مرحلهٔ کد: ' + path.join(C.APP_DIR, 'igap_login_step3.jpg'));

        // ---------- ۳) کد تأیید ----------
        const otp = (await question('=> کد تأیید دریافتی: ')).trim();
        const otpField = await C.firstVisible(page, [
            'input[autocomplete="one-time-code"]', 'input[type="number"]', 'input[inputmode="numeric"]',
            'input[placeholder*="کد"]', '#auth_code',
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
        await snapText('step4');

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
