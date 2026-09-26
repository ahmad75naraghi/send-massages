# AI Handoff Notes for `send-massages`

این فایل برای چت‌ها/عامل‌های AI بعدی است تا تجربه‌ها، تصمیم‌ها و دام‌های مهم این پروژه را سریع ببینند. تاریخ ثبت: 2026-09-26.

## وضعیت نهایی تأییدشده

ارسال از داشبورد/پنل به مقصدهای اصلی تست و تأیید شد:

- بله: کانال اصلی `@shamimeashena` ✅
- روبیکا: کانال اصلی `@shamimeashena` ✅
- آی‌گپ: کانال اصلی `@shamimeashena` / `شمیم آشنا` ✅
- سروش‌پلاس: کانال اصلی `@shamimeashena` / `https://splus.ir/shamimeashena` / نمایش `شمیم آشنا` ✅

دکمه‌های اصلی داشبورد:

- `اجرای دستی (۵ پست اصلی)`
- `افزودن به صف پس‌زمینه (۵ پست اصلی)`

باید برای ۵ پست آخر ایتا به مقصدهای اصلی کار کنند. کانال‌های قبلی/تست باید فقط از مسیر پروفایل/دکمهٔ تست استفاده شوند.

## مقصدها و پروفایل‌ها

مقصدهای اصلی در `config.php` و `.env` با کلیدهای `MAIN_*` تعریف شده‌اند. مقصدهای تست/قبلی با `TEST_*` باقی مانده‌اند.

اصلی‌ها:

- Bale: `@shamimeashena`
- Rubika: `@shamimeashena`
- Soroush: `shamimeashena` با نام نمایشی `شمیم آشنا`
- iGap: `shamimeashena` با نام نمایشی `شمیم آشنا` و `IGAP_ITEM_ID=16200343869985976`

نکتهٔ حیاتی سروش: جست‌وجوی `@shamimeashena` در سروش دو نتیجه می‌آورد:

1. `shamimeashena1` — کانال تست/قبلی؛ هرگز نباید برای پروفایل اصلی انتخاب شود.
2. `شمیم آشنا` — کانال اصلی؛ پس از کلیک معمولاً URL به `https://web.splus.ir/#-1001243691` resolve می‌شود.

اگر کانال اصلی سروش با اطمینان باز نشود، باید fail کند؛ ارسال به `shamimeashena1` بدتر از fail است.

## سروش‌پلاس: نکات کشف‌شده

فایل اصلی: `send_soroush.js`

رفتارهای واقعی سروش وب:

- route مستقیم `https://web.splus.ir/#@shamimeashena` معمولاً به chat واقعی resolve نمی‌شود و به root برمی‌گردد؛ بنابراین search fallback لازم است.
- در حالت strict، نتیجه‌های near-match مثل `shamimeashena1` باید skip شوند.
- متن username اصلی همیشه در `innerText` نتیجهٔ جست‌وجو دیده نمی‌شود؛ برای کانال اصلی باید display name `شمیم آشنا` هم پذیرفته شود.
- پس از کلیک نتیجهٔ اصلی باید hash عددی واقعی دیده شود، مثل `#-1001243691`. hashهایی مثل `#@shamimeashena` یا side-viewهایی مثل `#-1001243691_pinned` قابل قبول نیستند.
- دکمه‌های کلی مثل `button[aria-label*="پیام"]` خطرناک‌اند؛ یک بار باعث کلیک روی «پیام سنجاق‌شده» و رفتن به `#-1001243691_pinned` شدند. از selectorهای broad برای پیام استفاده نکنید.
- بعضی وقت‌ها composer دائمی پایین صفحه نیست. اگر کانال به hash عددی واقعی resolve شده باشد، می‌توان دکمهٔ «پیام جدید» را امتحان کرد؛ اما بعد از کلیک hash نباید به چت دیگر یا side-view تغییر کند.
- برای ارسال رسانه، مودال `ارسال عکس` باز می‌شود. `MODAL` باید با `:is(.modal-dialog, .Modal, [role="dialog"])` scope شود؛ استفاده از comma بدون `:is` باعث selector اشتباه/کلیک روی container می‌شود.
- دکمهٔ مودال ارسال باید با متن `ارسال` یا fallback پایین-چپ مودال کلیک شود. در UI RTL سروش دکمهٔ آبی «ارسال» پایینِ چپ مودال است.
- بعد از ارسال رسانه، سروش همیشه DOM را طوری به‌روز نمی‌کند که snippet دیده شود. در تست واقعی، `modal-closed-accepted` همراه با مشاهدهٔ پیام در کانال معتبر بود.
- `header` سروش گاهی خوانده نمی‌شود؛ اگر strict search، skip near-match، display-name match، و hash عددی درست دیده شده باشند، header خالی به‌تنهایی خطا نیست.

لاگ خوب سروش باید چیزی شبیه این داشته باشد:

```text
skipped result #1 ... shamimeashena1
clicked result #2 ... شمیم آشنا
result #2 navigation: resolved to https://web.splus.ir/#-1001243691
attach button clicked ...
modal shown: true
insertText: ...
media submit via ... button:has-text("ارسال")
RUN END status=OK
```

## آی‌گپ: نکات کشف‌شده

فایل اصلی: `send_igap.js`

- کانال اصلی با `data-list-item-id=16200343869985976` درست باز می‌شود و header `شمیم آشنا` را نشان می‌دهد.
- مسیر متن مستقیم قبلاً OK بود.
- مشکل اصلی رسانه بود: مودال `Send File` باز می‌شد ولی کلیک روی send اشتباه بود یا OK کاذب می‌داد.
- selector مودال باید scope شود: `:is(.modal-dialog, [role="dialog"], .MuiDialog-root, .MuiModal-root)`.
- دکمهٔ سبز ارسال آی‌گپ کنار کادر caption است. روش درست: bounding box کادر caption داخل مودال را بگیر و کنار آن کلیک کن (`caption-neighbor-button`).
- کلیک پایین-راست کل viewport اشتباه است؛ یک بار `caption=0,0,1440x900` داد و پیام ارسال نشد.
- verification قوی: `snippet-in-chat` یا `left-preview-changed`.
- برای پست‌های رسانه‌ای بلند، گاهی snippet در DOM تشخیص داده نمی‌شود اما ارسال انجام شده؛ اگر دکمهٔ واقعی کنار caption کلیک شده و مودال بسته شده باشد، `caption-neighbor-modal-closed-accepted` به عنوان accepted استفاده می‌شود تا پنل قرمز کاذب ندهد.

لاگ خوب آی‌گپ:

```text
by data-list-item-id: header shows channel = true
file injected via filechooser
modal caption editor ready: true
insertText: ...
modal send near-caption: clicked at ... caption=...
media submit via caption-neighbor-button
verified=true ... how=snippet-in-chat
RUN END status=OK
```

## PHP / داشبورد / پنل

فایل اصلی: `sync_manual.php`

- `runUserbot()` باید stdout JSON نهایی اسکریپت Node را جدا از stderr بگیرد. قاطی کردن stderr با stdout (`2>&1`) باعث parse/mapping اشتباه و قرمز کاذب پنل می‌شد.
- `parseNodeJsonOutput()` آخرین JSON معتبر را از خروجی پیدا می‌کند.
- برای آی‌گپ `userbotAccepted()` اضافه شد تا در برابر خروجی‌های ترکیبی/legacy دفاع کند و OK واقعی را قرمز نشان ندهد.
- دکمهٔ دستی ۵ پست اصلی اکنون per-post از `sync_single` استفاده می‌کند تا timeout طولانی درخواست وب رخ ندهد.
- `background_sync` از `cli_run.php` و `sync_recent` استفاده می‌کند. اگر `shell_exec` در PHP غیرفعال باشد، background queue کار نمی‌کند.
- پروفایل test نباید `last_msg_id` اصلی را جلو ببرد.
- پنل «بررسی و شروع همگام‌سازی» برای جبران مقصدهای جاافتاده استفاده شود. برای جلوگیری از duplicate، مقصدهایی که قبلاً رفته‌اند را خاموش کنید.

## جلوگیری از ارسال تکراری

وقتی بله/روبیکا/آی‌گپ قبلاً ارسال شده‌اند و فقط سروش جا مانده، از دکمهٔ اصلی ۵ پست استفاده نکنید. از پنل جبران:

1. `بررسی پست‌ها`
2. انتخاب پست‌های مورد نظر
3. فقط مقصد جاافتاده را تیک بزنید
4. `advance` خاموش باشد مگر واقعاً می‌خواهید state جلو برود
5. `رسانهٔ پست هم ارسال شود` روشن باشد

## دستورات deploy/test در سرور تولید

مسیر تولید معمولاً:

```bash
cd /home/file/public_html/s
```

آپدیت:

```bash
git fetch origin arena/01a0dc31-send-massages
git merge --ff-only FETCH_HEAD
chown -R file:file /home/file/public_html/s
chmod 600 .env
chmod +x *.sh
[ -f state.sqlite ] && chmod 660 state.sqlite
```

تست آی‌گپ رسانه:

```bash
sudo -u file bash -lc 'cd /home/file/public_html/s && node send_igap.js --channel=shamimeashena --channel-name="شمیم آشنا" --item-id=16200343869985976 --text="تست آی‌گپ $(date +%H:%M:%S)" --file=/tmp/userbot_probe.png --type=image'
```

تست سروش رسانه:

```bash
sudo -u file bash -lc 'cd /home/file/public_html/s && node send_soroush.js --channel=shamimeashena --channel-name="شمیم آشنا" --strict-channel=1 --text="تست سروش $(date +%H:%M:%S)" --file=/tmp/userbot_probe.png --type=image'
```

اگر `/tmp/userbot_probe.png` نبود:

```bash
base64 -d > /tmp/userbot_probe.png <<'EOF'
iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=
EOF
chown file:file /tmp/userbot_probe.png
```

## لاگ‌ها و تشخیص خطا

آخرین لاگ آی‌گپ:

```bash
cd /home/file/public_html/s
LATEST=$(ls -t logs/send_igap_*.log | head -1)
echo "$LATEST"
tail -n 280 "$LATEST"
ls -lh last_igap_send.jpg
```

آخرین لاگ سروش:

```bash
cd /home/file/public_html/s
LATEST=$(ls -t logs/send_soroush_*.log | head -1)
echo "$LATEST"
tail -n 320 "$LATEST"
ls -lh last_media_send.jpg
```

اگر پنل هنوز نتیجهٔ قدیمی نشان می‌دهد ولی فایل‌ها آپدیت شده‌اند، احتمال OPcache/PHP-FPM وجود دارد:

```bash
systemctl reload ea-php83-php-fpm 2>/dev/null || systemctl reload ea-php82-php-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true
```

## validation محلی

در sandbox معمولاً PHP نصب نیست، بنابراین PHP lint ممکن است ممکن نباشد. validation قابل انجام:

```bash
git diff --check
npm test
bash -n *.sh lib/*.sh
```

`npm test` فقط `node --check` اجرا می‌کند و runtime مرورگر را اثبات نمی‌کند؛ تست تولید با profile واقعی لازم است.

## آخرین commitهای مهم این کار

- `36e49cd` — جدا کردن stdout JSON از stderr در userbot runner PHP
- `4976edc` — قبول ارسال‌های رسانه‌ای آی‌گپ با شواهد معتبر
- `5204605` — scope صحیح selectorهای مودال آی‌گپ
- `584e584` — جلوگیری از کلیک روی کنترل‌های pinned سروش
- `9ece887` — scope صحیح دکمهٔ ارسال مودال سروش

## صف پس‌زمینه (background_sync / background_run) — دانش انتقال

این بخش توسط بازنویسی «سرعت + دکمهٔ صف پس‌زمینه» (2026-09) اضافه شد.

### چرا دکمه قبلاً فوراً «پایان» می‌داد
نسخهٔ قدیمی باینری PHP را با `command -v php` پیدا می‌کرد (روی cPanel اغلب به php.fpm یا نسخهٔ اشتباه می‌رسید)، worker را با `nohup` ساده (بدون `setsid`) می‌زداشت که با پایان Apache می‌مرد، و پیشرفتی هم برای داشبورد نمی‌نوشت. هر سه رفع شده‌اند.

### اجزا
- `background_sync` (وب): `resolvePhpCliBinary()` باینری را **اعتبارسنجی** می‌کند (is_executable + `-v`≥8 + اکستنشن‌های pdo_sqlite/curl/dom/mbstring + `php -l` روی sync_manual.php؛ نتیجه static-cache). سپس `logs/background_body_<ts>.json` و `logs/background_launcher.sh` (0700) می‌سازد و با `setsid nohup bash launcher … &` اجرا می‌کند. PID worker توسط خود launcher در `logs/sync_worker.pid` نوشته می‌شود.
- `background_run` (CLI-only؛ از وب 403): موتور صف — scrape پست‌های جدید (id > last_msg_id، قدیمی‌ترین اول، سقف `BACKGROUND_MAX_POSTS`)، ردِ پلتفرم‌های موفقِ قبلی از جدول `delivery`، دانلود گروهی رسانه، ارسال موازی (دو runner Node batch + دو worker HTTP بله/روبیکا)، تلاش مجدد تا `BACKGROUND_MAX_PASSES`، گزارش مدیریتی بله (`sendAdminReportLines`، chunk ≤3500)، جلو بردن `last_msg_id` فقط تا آخرین پست پیوستهٔ موفق.
- `queue_status` (وب): وضعیت worker + محتوای `logs/background_progress.json` + دم لاگ‌ها.

### قرارداد batch دو sender (`send_*.js --batch <file>`)
- batch: `{items:[{id,text,file,type,fileName}…], progressFile:"/abs"}` → progress اتمیک per-item با `writeJsonFileAtomic` (rename)؛ آیتم‌ها **به‌ترتیب**، اولین شکست → ادامه NOT_ATTEMPTED؛ stdout آخرین خط JSON `{status:OK|PARTIAL|ERROR,sent,failed,total,stoppedAt,log}`؛ exit 0 مگر خطای راه‌اندازی مهلک.
- کدهای مهلک (worker را متوقف می‌کنند): SESSION_EXPIRED, CHANNEL_NOT_FOUND, COMPOSER_NOT_AVAILABLE, APP_NOT_LOADED, NODE_DEPS_MISSING, BAD_BATCH, SHELL_EXEC_DISABLED, BATCH_WRITE_FAILED, LAUNCH_FAILED.
- نکتهٔ ساختاری مهم: خروج مشترک (بستن مرورگر + emit + exitCode) باید در `finally` یک try بیرونی باشد؛ returnهای زودهنگام حالت تک‌پیام باید از آن عبور کنند. (این قبلاً شکسته بود و با smoke test با Playwright قلابی گرفته شد.)

### دفتر تحویل و idempotency
جدول `delivery` (PK: msg_id+platform+profile) در SQLite؛ `markDelivery()` upsert می‌کند و `deliveredOkPlatforms()` پلتفرم‌های موفقِ قبلی را برمی‌گرداند. `dispatchToPlatforms($only,$text,$localFile,$mediaType,$fileName,$profile,$db,$msgId)` خودش در پایان ثبت می‌کند. اجرای دوبارهٔ صف هیچ پست/پلتفرم موفق را دوباره نمی‌فرستد.

### تست آفلاین بدون Playwright
در sandbox node_modules نیست؛ یک fake ماژول playwright در `/tmp/fakepw/node_modules/playwright` ساخته شد (chromium.launchPersistentContext قلابی؛ evaluate() با تطبیق متن source جواب می‌دهد). اجرا: `NODE_PATH=/tmp/fakepw/node_modules node send_soroush.js --batch …`. توجه: NODE_PATH باید خودِ دایرکتوری node_modules باشد، نه والدش.

### زمان‌بند خودکار (اضافه‌شده ۲۰۲۶-۰۹، همراه صف پس‌زمینه)
- جداول `schedule` / `schedule_runs` (PK: schedule_id+day = mutex روزانه) / `schedule_meta` (heartbeat تیک).
- `scheduler_tick` (CLI-only): heartbeat همیشه؛ اسلات سررسید با پنجرهٔ تحمل ۲ دقیقه (now, -1m, -2m)؛ شلیک = اجرای `ACTION=background_sync` از CLI با body موقت (بدون refactor موتور)؛ نتیجه در schedule_runs (fired/skipped_busy/error) + logs/scheduler.log.
- نصب = فقط یک خط crontab به scheduler_tick.sh؛ افزودن/حذف ساعت‌ها فقط از داشبورد (اکشن‌های schedule_list/add/remove/toggle). داشبورد با heartbeat نصب بودن کران را تشخیص می‌دهد و خط crontab لازم را نمایش می‌دهد.
- `SCHEDULE_TIMEZONE` (خالی = منطقهٔ سرور) هم تیک هم ساعت نمایشی داشبورد.
- تست آفلاین زنجیره: mock php با پورت python همین منطق روی sqlite واقعی + لانچر واقعی (در /tmp/schedtest — در sandbox ماندگار نیست).

## هشدارهای مهم برای عامل‌های بعدی

- روی کانال/branch دیگری کار نکنید مگر سیاست session اجازه بدهد.
- اطلاعات حساس `.env` و key داشبورد را در docs یا chat تکرار نکنید.
- برای سروش، هیچ‌وقت نتیجهٔ `shamimeashena1` را برای profile اصلی قبول نکنید.
- برای آی‌گپ، OK بدون شواهد واقعی یا accepted دقیق نسازید؛ false-positive قبلاً رخ داده است.
- اگر پستی واقعاً ارسال شده ولی پنل قرمز داده، ابتدا لاگ userbot و mapping PHP را بررسی کنید؛ دوباره resend کردن ممکن است duplicate بسازد.
