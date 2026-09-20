const fs = require('fs');
const { chromium } = require('playwright');

(async () => {
    const sessionFile = '/home/file/public_html/s/soroush_session.json';
    const userDataDir = '/home/file/public_html/s/soroush_profile';

    if (!fs.existsSync(sessionFile)) {
        console.error('[-] Error: soroush_session.json not found!');
        process.exit(1);
    }

    console.log('[*] Reading session backup...');
    const sessionData = JSON.parse(fs.readFileSync(sessionFile, 'utf8'));

    console.log('[*] Launching Chromium persistent context...');
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
    
    console.log('[*] Opening Soroush Web to create domain contexts...');
    await page.goto('https://web.splus.ir', { waitUntil: 'domcontentloaded', timeout: 60000 });
    await new Promise(r => setTimeout(r, 4000));

    console.log('[*] Restoring LocalStorage & IndexedDB...');
    await page.evaluate(async (data) => {
        // ۱. تزریق LocalStorage
        for (const k in data.localStorage) {
            localStorage.setItem(k, data.localStorage[k]);
        }
        // ۲. تزریق SessionStorage
        for (const k in data.sessionStorage) {
            sessionStorage.setItem(k, data.sessionStorage[k]);
        }

        // ۳. تزریق دیتابیس‌های IndexedDB
        for (const dbName in data.indexedDB) {
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
                                if (keys && keys[i] !== undefined) {
                                    store.put(values[i], keys[i]);
                                } else {
                                    store.put(values[i]);
                                }
                            }
                            await new Promise(r => tx.oncomplete = r);
                        } catch (e) {}
                    }
                    db.close();
                    resolve();
                };
                req.onerror = () => resolve();
            });
        }
    }, sessionData);

    console.log('[*] Reloading page to initialize authorized state...');
    await page.reload({ waitUntil: 'domcontentloaded' });
    await new Promise(r => setTimeout(r, 10000));

    await page.screenshot({ path: '/home/file/public_html/s/step_restored.jpg' });
    console.log('\n[+] RESTORE FINISHED! Check: https://file.falnic.com/s/step_restored.jpg');

    await browser.close();
})();