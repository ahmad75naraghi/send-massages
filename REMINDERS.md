# ⚠️ یادآوری‌های خیلی مهم عملیاتی — اول این فایل را بخوان ⚠️

> **این فایل برای روز بد است.** هر وقت چیزی کار نکرد (پیام نرفت، دکمه گیر کرد، آی‌گپ ✕ گرفت،
> پیامک نیامد، صفحه سفید بود) — **قبل از هر کاری** بخش مربوطهٔ همین فایل را بخوان.
> همهٔ این‌ها در تولید واقعاً رخ داده‌اند و راه‌حلشان همین‌جا تست‌شده است.

---

## 🔴 ۱) session پیام‌رسان‌ها روزی لاگ‌اوت می‌شود — این مهم‌ترین مهارت شماست

سروش‌پلاس و آی‌گپ با «پروفایل مرورگر» لاگین نگه داشته می‌شوند (`soroush_profile/` و `igap_profile/`).
اگر ایتاها session را باطل کنند، همه‌چیز سالم به‌نظر می‌رسد ولی ارسال به آن پلتفرم ✕ می‌گیرد.

**علائم:** آی‌گپ/سروش ✕ در کارت‌ها؛ در گزارش، `SESSION_EXPIRED`؛ یا `APP_NOT_LOADED` همراه با
متنِ صفحه که فرم ورود را لو می‌دهد («شماره موبایل… کد کشور…»).

**🔴 راه‌حل تست‌شده (ورود دستی از مرورگر خودتان — ۲۰۲۶-۰۹-۲۷ دقیقاً همین‌طور حل شد):**

```bash
# روی سرور:
cd /home/file/public_html/s
sudo -u file bash manual_login.sh igap        # یا soroush
# این ترمینال را باز نگه دارید! باید ببینید: «✔ … CDP جواب می‌دهد»

# روی کامپیوتر خودتان (ترمینال جدا، باز نگه دارید) — تونل:
ssh -L 9222:127.0.0.1:9222 root@<IP-سرور>
#   اگر ویندوز خطای bind/Permission denied داد، پورت محلی دیگر: -L 39222:127.0.0.1:9222

# در Chrome/Edge خودتان:  chrome://inspect/#devices  ▸ Configure ▸ افزودن 127.0.0.1:9222
#   (یا پورت محلی تونل، مثل 39222) ▸ زیر Remote Target روی inspect ▸ آیکون گوشی (Screencast)
# روی تصویر زندهٔ صفحه، خودتان لاگین کنید: کشور (ایران) → شماره → کد پیامکی.
# بعد از دیدن لیست گفت‌وگوها → به ترمینال سرور برگردید → Ctrl+C
```

**تأیید اینکه session برگشته:** `sudo -u file /usr/bin/node dump_dom.js igap` → باید `candidates` بیشتر از صفر شود.

**🔴 بلافاصله بعد از هر لاگین موفق، پشتیبان بگیرید** (دفعهٔ بعد بدون پیامک برمی‌گردد):

```bash
cd /home/file/public_html/s
sudo -u file mkdir -p backups
sudo -u file tar czf backups/igap_profile_$(date +%F).tgz igap_profile      # و همین برای soroush_profile
```

**بازگردانی از پشتیبان:** `sudo -u file tar xzf backups/igap_profile_<تاریخ>.tgz` در ریشهٔ برنامه (وقتی صف اجرا نیست).

> ⚠️ تا وقتی `manual_login.sh` باز است، صف/کران را اجرا نکنید (قفل پروفایل).
> ⚠️ صفحهٔ `http://127.0.0.1:9222/` در کروم‌های جدید «سفید» است — این طبیعی است؛ راه درست `chrome://inspect` است.

---

## 🔴 ۲) کران زمان‌بند — بدون این خط، ساعت‌ها شلیک نمی‌کنند

```bash
# نصب (یک‌بار برای همیشه):
(crontab -u file -l 2>/dev/null | grep -v scheduler_tick; echo '* * * * * /home/file/public_html/s/scheduler_tick.sh') | crontab -u file -

# بررسی نصب:
crontab -u file -l | grep scheduler_tick

# بررسی تیک (باید هر دقیقه یک خط "tick ok" باشد):
tail -5 /home/file/public_html/s/logs/scheduler.log
```

- داشبورد اگر بج «⏱ کران نصب نیست» گفت → همین خط را نصب کنید (خط دقیق در خود پنل هم نمایش داده می‌شود).
- ساعت‌ها با منطقهٔ سرور تفسیر می‌شوند؛ اگر فرق داشت: `SCHEDULE_TIMEZONE=Asia/Tehran` در `.env`.

---

## 🔴 ۳) وقتی چیزی کار نکرد — اول همین سه لاگ

```bash
cd /home/file/public_html/s
tail -30 logs/background_sync.log     # خطای راه‌اندازی صف (PHP غلط، START_FAILED و…)
tail -30 logs/scheduler.log           # تیک زمان‌بند و نتیجهٔ شلیک اسلات‌ها
ls -t logs/send_igap_batch_*.log | head -1 | xargs tail -40   # قدم‌به‌قدم مرورگر آی‌گپ
ls -t logs/send_soroush_*.log | head -1 | xargs tail -40      # قدم‌به‌قدم مرورگر سروش
```

**نکتهٔ طلایی تشخیص:** اگر لاگ مرورگر بین `launch persistent context` و `screenshot` **هیچ قدمی نداشت**،
یعنی اپ اصلاً بالا نیامده — برو سراغ بخش ۱ همین فایل (session) یا متن صفحه را در خودِ پیام خطا ببینید.

**کدام پست به کجا رفته (دفتر تحویل):**

```bash
python3 - <<'PY'
import sqlite3
db = sqlite3.connect('state.sqlite')
for r in db.execute("SELECT msg_id, platform, status FROM delivery WHERE msg_id >= <از-این-شناسه> ORDER BY msg_id, platform"):
    print(r)
PY
```

---

## 🔴 ۴) جبران پست‌های جا‌مانده — بدون تکراری شدن

دفتر تحویل (`delivery`) جلوی ارسال دوباره را می‌گیرد؛ برای پست‌هایی که به پلتفرمی نرفته‌اند:

```bash
cd /home/file/public_html/s
PHP=$(command -v ea-php83 || command -v ea-php82 || command -v ea-php81 || command -v php)
printf '{"from":<شناسه-اول>,"to":<شناسه-آخر>,"only":["igap"]}' > /tmp/fix.json
ACTION=resend SYNC_BODY_FILE=/tmp/fix.json "$PHP" -f cli_run.php; rm -f /tmp/fix.json
```

فقط پلتفرم(های) لازم را در `only` بگذارید؛ بقیه `SKIPPED` می‌شوند و **هیچ‌چیز دوباره نمی‌رود**.
(مثال واقعی همین هفته: ۱۱ پست ۷۴۲۵۴ تا ۷۴۲۶۴ فقط با `only:["igap"]` جبران شدند.)

---

## 🟡 ۵) چک‌لیست بعد از هر تغییر/به‌روزرسانی روی سرور

```bash
cd /home/file/public_html/s
sudo -u file git fetch origin arena/01a0de62-send-massages && sudo -u file git merge --ff-only FETCH_HEAD
bash health_check.sh                       # همه باید سبز باشند
ACTION=queue_status  $(command -v ea-php83 || command -v php) -f cli_run.php | head -c 300; echo
ACTION=schedule_list $(command -v ea-php83 || command -v php) -f cli_run.php | head -c 300; echo
```

- **همه‌چیز را با کاربر `file` اجرا کنید** (`sudo -u file …`). اگر اشتباهاً با root اجرا کردید و
  پروفایل خراب شد: `chown -R file:file igap_profile soroush_profile` (این هفته واقعاً رخ داد).
- بعد از هر آپدیت، یک پست تستی در ایتا بگذارید و دکمهٔ «اجرای صف پس‌زمینه» را بزنید؛
  انتظار: هر ۴ سلول سبز + گزارش بله.
- اگر سرور کم‌رمز است و آی‌گپ در صف جا می‌ماند: `SYNC_PARALLEL_DISPATCH=0` در `.env`
  (ارسال ترتیبی؛ کمی کندتر ولی تک‌مرورگره). پیش‌فرض‌های فعلی: فاصلهٔ ۱۲ ثانیه بین دو مرورگر
  (`BACKGROUND_STAGGER_SEC`) و مهلت ۲۰ ثانیهٔ آی‌گپ — با همین‌ها مشکل موازی حل شد.

---

## 🟡 ۶) چند حقیقت که باید بدانید (که سردرگم نشوید)

- در گزارش‌های ارسال، معیار `ok:true` است؛ کد `RUNTIME` که گاهی داخل متن info می‌آید فقط
  دنبالهٔ لاگ است و به‌خودی‌خود یعنی خطا.
- آی‌گپ: «ارسال شد ولی تأیید بصری نشد» (`caption-neighbor-modal-closed-accepted`) طبق spec
  پروژه **معتبر** است — مدرک بستن مودال پس از ارسال.
- اگر پیامک ورود نیامد در حالت دستی: دکمه را خودتان در Screencast می‌زنید، پس اگر نیامود یعنی
  سمت ایتا شماره را موقتاً محدود کرده — چند ساعت صبر کنید، نه اینکه پشت‌سرهم تلاش کنید.
- `resend` رسانه را با **scrape تازه** می‌گیرد (لینک‌های ایتا زمان‌دارند) — لینک قدیمی را استفاده نمی‌کند.
- گاهی «اجرای متروک» در داشبورد یعنی worker وسط کار مرده (مثلاً OOM)؛ اجرای دوبارهٔ صف از همان‌جا
  ادامه می‌یابد. برای شروع تازه: `rm logs/background_progress.json logs/sync_worker.pid`.

---

## نقشهٔ سریع: علامت → کجا بروم

| علامت | اولین اقدام |
|---|---|
| آی‌گپ یا سروش ✕ در کارت‌ها | بخش ۳ (لاگ مرورگر) → بخش ۱ (session) |
| `SESSION_EXPIRED` در گزارش | بخش ۱ — ورود دستی |
| `APP_NOT_LOADED` + متن فرم ورود در پیام | همان بخش ۱ — session رفته |
| پیامک ورود نمی‌آید | بخش ۱ — محدودیت سمت ایتا؛ صبر |
| صفحهٔ سفید در `127.0.0.1:9222` | طبیعی است؛ از `chrome://inspect` برو (بخش ۱) |
| `bind: Permission denied` موقع تونل | پورت محلی دیگر (39222) — بخش ۱ |
| دکمهٔ صف فوراً «پایان» | `tail logs/background_sync.log` → TROUBLESHOOTING §۸.۱۰ |
| زمان‌بند شلیک نمی‌کند | بخش ۲ همین فایل |
| `tar: Permission denied` موقع پشتیبان | `chown -R file:file` → بخش ۵ |

> مستندات کامل: [README.md](README.md) (معماری و اندپوینت‌ها) · [TROUBLESHOOTING.md](TROUBLESHOOTING.md)
> (عیب‌یابی چهاربخشی) · [ARCHITECTURE.md](ARCHITECTURE.md) · [DEPLOYMENT.md](DEPLOYMENT.md)
