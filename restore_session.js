'use strict';
/**
 * restore_session.js — بازگردانی نسخهٔ قدیمی soroush_session.json به پروفایل پایدار
 *
 * توجه: روش ترجیحی امروز، نگه‌داری کل پوشهٔ soroush_profile است. این ابزار فقط
 * برای پشتیبان‌های قدیمی LocalStorage/SessionStorage/IndexedDB باقی مانده است.
 */

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const C = require(path.join(__dirname, 'lib', 'pw_common.js'));

(async () => {
    const sessionFile = C.env('SOROUSH_SESSION_FILE', path.join(C.APP_DIR, 'soroush_session.json'));
    const userDataDir = C.env('SOROUSH_PROFILE_DIR', path.join(C.APP_DIR, 'soroush_profile'));
    const log = new C.RunLog('soroush_restore');
    let browser = null;

    try {
        if (!fs.existsSync(sessionFile)) {
            console.error('[-] فایل session پیدا نشد: ' + sessionFile);
            process.exitCode = 1;
            return;
        }

        console.log('[*] خواندن session backup: ' + sessionFile);
        const sessionData = JSON.parse(fs.readFileSync(sessionFile, 'utf8'));

        console.log('[*] راه‌اندازی Chromium persistent context...');
        const launched = await C.launchBrowser({ chromium }, userDataDir, { width: 1280, height: 720 }, log);
        browser = launched.browser;
        const page = launched.page;

        console.log('[*] باز کردن Soroush Web برای ساخت context دامنه...');
        await page.goto('https://web.splus.ir', { waitUntil: 'domcontentloaded', timeout: 60000 });
        await C.delay(4000);

        console.log('[*] تزریق LocalStorage / SessionStorage / IndexedDB ...');
        await page.evaluate(async (data) => {
            for (const k in (data.localStorage || {})) localStorage.setItem(k, data.localStorage[k]);
            for (const k in (data.sessionStorage || {})) sessionStorage.setItem(k, data.sessionStorage[k]);

            for (const dbName in (data.indexedDB || {})) {
                const stores = data.indexedDB[dbName];
                const req = indexedDB.open(dbName);
                await new Promise((resolve) => {
                    req.onsuccess = async () => {
                        const db = req.result;
                        for (const storeName in stores) {
                            if (!db.objectStoreNames.contains(storeName)) continue;
                            try {
                                const tx = db.transaction(storeName, 'readwrite');
                                const store = tx.objectStore(storeName);
                                const { keys, values } = stores[storeName];
                                for (let i = 0; i < values.length; i++) {
                                    if (keys && keys[i] !== undefined) store.put(values[i], keys[i]);
                                    else store.put(values[i]);
                                }
                                await new Promise(r => { tx.oncomplete = r; tx.onerror = r; });
                            } catch (e) { /* store ناسازگار را رد کن */ }
                        }
                        db.close();
                        resolve();
                    };
                    req.onerror = () => resolve();
                    req.onupgradeneeded = () => resolve();
                });
            }
        }, sessionData);

        console.log('[*] بارگذاری مجدد برای فعال شدن session...');
        await page.reload({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {});
        await C.delay(10000);
        await C.safeScreenshot(page, 'soroush_restore_done.jpg', log);
        console.log('[+] پایان restore. اسکرین‌شات: ' + path.join(C.APP_DIR, 'soroush_restore_done.jpg'));
    } catch (err) {
        console.error('[-] خطا: ' + C.RunLog.brief((err && err.message) || String(err), 300));
        log.step('FATAL: ' + (err && err.stack ? err.stack : String(err)));
        process.exitCode = 1;
    } finally {
        await C.closeQuietly(browser, log);
        C.cleanSingletons(userDataDir, log);
    }
})();
