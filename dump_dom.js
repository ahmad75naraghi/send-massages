#!/usr/bin/env node
/**
 * dump_dom.js — دامپ زندهٔ وب‌کلاینت برای یافتن سلکتورهای جایگزین
 * ==================================================================
 *  وقتی وب‌کلاینت سروش‌پلاس یا آی‌گپ به‌روزرسانی می‌شود و اسکریپت‌های ارسال
 *  می‌شکنند، این ابزار DOM را ذخیره می‌کند و «کاندیدهای سلکتور» را بر اساس
 *  کلمات کلیدی فارسی/انگلیسی فهرست می‌کند تا سلکتور جدید سریع پیدا شود.
 *
 *  استفاده:
 *    sudo -u file /usr/bin/node dump_dom.js igap
 *    sudo -u file /usr/bin/node dump_dom.js soroush --chat --channel-name=شمیم آشنا
 *
 *  خروجی:
 *    <target>_dump.html   — DOM کامل
 *    <target>_dump.jpg    — اسکرین‌شات
 *    stdout               — یک خط JSON (طبق قرارداد lib/pw_common.js)
 *    stderr               — لاگ مرحله‌ای + فهرست کاندیدها
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const C = require('./lib/pw_common');

const TARGETS = {
    igap: {
        url: 'https://web.igap.net',
        profile: 'igap_profile',
        loginMarkers: ['input[type="tel"]', 'input[placeholder*="شماره"]', '#auth_number'],
        keywords: ['پیوست', 'ضمیمه', 'Attach', 'عکس', 'تصویر', 'ویدیو', 'ویدئو', 'سند', 'فایل',
            'ارسال', 'Send', 'Media', 'File', 'caption', 'توضیح']
    },
    soroush: {
        url: 'https://web.splus.ir',
        profile: 'soroush_profile',
        loginMarkers: ['input[type="tel"]', 'input[placeholder*="شماره"]', '#sign-in'],
        keywords: ['پیوست', 'ضمیمه', 'Attach', 'عکس', 'ویدیو', 'سند', 'فایل', 'ارسال', 'Send',
            'Photo', 'Video', 'Document', 'File', 'متوجه شدم', 'caption']
    }
};

const VIEWPORT = { width: 1440, height: 900 };

(async () => {
    const target = String(process.argv[2] || 'igap').toLowerCase();
    const cfg = TARGETS[target];
    const log = new C.RunLog('dump_' + target);

    if (!cfg) {
        log.step('هدف نامعتبر؛ یکی از: ' + Object.keys(TARGETS).join(', '));
        C.emit({ status: 'ERROR', code: 'BAD_TARGET', error: 'هدف نامعتبر: ' + target });
        process.exitCode = 2;
        return;
    }

    const opts = C.parseArgs(process.argv);
    const openChat = process.argv.includes('--chat');
    const htmlPath = path.join(__dirname, target + '_dump.html');
    const shotPath = path.join(__dirname, target + '_dump.jpg');
    let browser = null;

    try {
        const launched = await C.launchBrowser({ chromium }, path.join(__dirname, cfg.profile), VIEWPORT, log);
        browser = launched.browser;
        const page = launched.page;

        log.step('goto ' + cfg.url);
        await page.goto(cfg.url, { waitUntil: 'domcontentloaded', timeout: 60000 });
        await page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {});
        await page.waitForTimeout(6000);

        const marker = await C.detectLoginPage(page, cfg.loginMarkers);
        if (marker) log.step('WARNING: صفحهٔ ورود دیده شد (' + marker + ') — session منقضی است؛ login_' + target + '.js را اجرا کنید');

        if (openChat && (opts.channel || opts.channelName)) {
            log.step('تلاش برای باز کردن چت: ' + (opts.channel || opts.channelName));
            if (target === 'soroush' && opts.channel) {
                await page.goto(cfg.url + '/#@' + opts.channel, { waitUntil: 'domcontentloaded' }).catch(() => {});
                await page.waitForTimeout(5000);
            }
            if (opts.channelName) {
                const hit = page.locator('span, div[role="listitem"], a').filter({ hasText: opts.channelName }).first();
                await hit.click({ timeout: 8000 }).catch(() => log.step('چت پیدا نشد؛ دامپ از لیست اصلی گرفته می‌شود'));
                await page.waitForTimeout(5000);
            }
        }

        const html = await page.content();
        fs.writeFileSync(htmlPath, html, 'utf8');
        await C.safeScreenshot(page, target + '_dump.jpg', log);
        log.step('saved ' + htmlPath + ' (' + html.length + ' bytes)');

        // ---- استخراج کاندیدهای سلکتور ----
        const found = await page.evaluate((keywords) => {
            const out = [];
            const pick = (el, note) => {
                out.push({
                    tag: el.tagName.toLowerCase(),
                    id: el.id || '',
                    cls: (typeof el.className === 'string' ? el.className : '').slice(0, 90),
                    txt: note !== undefined ? note : (el.textContent || '').trim().slice(0, 60),
                    editable: el.getAttribute('contenteditable'),
                    type: el.getAttribute('type') || ''
                });
            };
            document.querySelectorAll('button, [role="button"], a, li, .MenuItem, .menu-item, div[class*="menu"], div[class*="Menu"]')
                .forEach((el) => {
                    const txt = (el.textContent || '').trim();
                    const cls = typeof el.className === 'string' ? el.className : '';
                    if (keywords.some(k => txt.includes(k) || cls.includes(k) || (el.id || '').includes(k))) pick(el);
                });
            document.querySelectorAll('[contenteditable="true"], input[type="file"], textarea')
                .forEach((el) => pick(el, '<' + el.tagName.toLowerCase() + ' editable/' + (el.getAttribute('type') || '') + '>'));
            return out.slice(0, 80);
        }, cfg.keywords);

        log.step('--- کاندیدهای سلکتور (' + found.length + ' مورد) ---');
        found.forEach((f) => log.step('  ' + f.tag
            + (f.id ? '#' + f.id : '')
            + (f.cls ? '.' + f.cls.split(/\s+/).join('.') : '')
            + '  «' + f.txt + '»'));

        C.emit({
            status: 'OK',
            target,
            url: page.url(),
            bytes: html.length,
            candidates: found.length,
            html: htmlPath,
            screenshot: shotPath,
            loginPageDetected: Boolean(marker)
        });
    } catch (err) {
        log.step('FATAL: ' + (err && err.stack ? err.stack : String(err)));
        C.emit({ status: 'ERROR', code: 'RUNTIME', error: C.RunLog.brief(err && err.message, 300), html: htmlPath });
        process.exitCode = 1;
    } finally {
        await C.closeQuietly(browser, log);
        log.step('DUMP END exit=' + (process.exitCode || 0));
    }
})();
