#!/usr/bin/env bash
# ============================================================
#  start_browser.sh — اجرای کرومیوم با پروفایل پایدار برای ورود/بازرسی
#
#    bash start_browser.sh soroush        # پروفایل سروش‌پلاس
#    bash start_browser.sh igap           # پروفایل آی‌گپ
#    bash start_browser.sh igap --headed  # پنجرهٔ قابل دیدن (نیاز به DISPLAY)
#
#  ⚠️ تفاوت حیاتی با نسخهٔ پیشین: پورت دیباگ فقط روی 127.0.0.1 باز می‌شود.
#     نسخهٔ قبلی --remote-debugging-address=0.0.0.0 داشت؛ یعنی هر کسی که
#     به پورت 9222 دسترسی داشت می‌توانست با session کاربر وارد شود.
# ============================================================
set -euo pipefail

APP="${SYNC_APP_DIR:-/home/file/public_html/s}"
TARGET="${1:-soroush}"
MODE="${2:-headless}"

case "$TARGET" in
  soroush) URL="https://web.splus.ir";  PROFILE="$APP/soroush_profile" ;;
  igap)    URL="https://web.igap.net"; PROFILE="$APP/igap_profile" ;;
  *) echo "هدف نامعتبر: $TARGET (soroush|igap)" >&2; exit 2 ;;
esac

HEADLESS="--headless=new"
[[ "$MODE" == "--headed" ]] && HEADLESS=""

# فقط کرومیوم‌های یتیمِ همین پروفایل بسته می‌شوند (نه همهٔ فرایندها)
pkill -f "user-data-dir=$PROFILE" 2>/dev/null || true
sleep 1
rm -f "$PROFILE"/Singleton* 2>/dev/null || true

exec /usr/bin/chromium-browser \
  --remote-debugging-port=9222 \
  --remote-debugging-address=127.0.0.1 \
  --user-data-dir="$PROFILE" \
  --no-sandbox \
  --disable-setuid-sandbox \
  --disable-gpu \
  --disable-dev-shm-usage \
  $HEADLESS \
  "$URL"
