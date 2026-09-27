#!/usr/bin/env bash
# ============================================================
#  manual_login.sh — ورود دستی به سروش‌پلاس / آی‌گپ از مرورگر «خودتان»
#  ============================================================
#  مشکل: سرور نمایشگر ندارد؛ login_*.js خودکار است و اگر فرم ورود
#  تغییر کند (مثل فرم جدید آی‌گپ) ممکن است گیر کند.
#  راه‌حل: کرومیوم روی سرور با همان پروفایلِ ارسال (igap_profile)
#  بالا می‌آید + تونل SSH؛ شما صفحهٔ همان مرورگرِ سرور را در
#  مرورگر کامپیوتر خودتان می‌بینید (DevTools Screencast) و لاگین را
#  «دستی» انجام می‌دهید — کد کشور، شماره، کد پیامکی، هر چیزی بود.
#  session دقیقاً روی همان پروفایل/همان IP سرور ساخته می‌شود؛ پس
#  بعدش send_*.js مستقیم از آن استفاده می‌کند.
#
#  استفاده (روی سرور):
#     sudo -u file bash manual_login.sh igap
#     sudo -u file bash manual_login.sh soroush
#     MANUAL_LOGIN_PORT=9333 sudo -u file bash manual_login.sh igap   # اگر 9222 اشغال بود
#
#  ⚠️ تا وقتی این اسکریپت باز است، صف/کران را اجرا نکنید (قفل پروفایل).
#     برای پایان: در همین ترمینال Ctrl+C بزنید — بسته‌شدن مرورگر و
#     پاک‌سازی قفل‌ها خودکار است.
# ============================================================
set -uo pipefail

SELF_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/dotenv.sh
[[ -r "$SELF_DIR/lib/dotenv.sh" ]] && source "$SELF_DIR/lib/dotenv.sh"
APP="${SYNC_APP_DIR:-}"
if [[ -z "$APP" ]]; then
  if declare -F dotenv_app_dir >/dev/null 2>&1; then APP="$(dotenv_app_dir)"
  else APP="$SELF_DIR"; fi
fi
if [[ -r "${SYNC_ENV_FILE:-$APP/.env}" ]] && declare -F load_dotenv >/dev/null 2>&1; then
  load_dotenv "${SYNC_ENV_FILE:-$APP/.env}"
fi

TARGET="${1:-igap}"
PORT="${MANUAL_LOGIN_PORT:-9222}"

case "$TARGET" in
  soroush) PROFILE="${SOROUSH_PROFILE_DIR:-$APP/soroush_profile}" ;;
  igap)    PROFILE="${IGAP_PROFILE_DIR:-$APP/igap_profile}" ;;
  *) echo "هدف نامعتبر: $TARGET (soroush|igap)" >&2; exit 2 ;;
esac

SERVER_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
[[ -z "$SERVER_IP" ]] && SERVER_IP="<IP-سرور>"

CLEANED=0
cleanup() {
  [[ "$CLEANED" == 1 ]] && return
  CLEANED=1
  echo
  echo "── بسته‌شدن مرورگر و پاک‌سازی قفل پروفایل…"
  pkill -f "user-data-dir=$PROFILE" 2>/dev/null || true
  sleep 1
  rm -f "$PROFILE"/Singleton* 2>/dev/null || true
  chown -R file:file "$PROFILE" 2>/dev/null || true
  echo "✔ تمام شد. session در همان پروفایل ذخیره شده است."
  echo
  echo "گام‌های بعدی:"
  echo "  ۱) تأیید (باید candidates > 0 باشد):"
  echo "       sudo -u file /usr/bin/node $SELF_DIR/dump_dom.js $TARGET"
  echo "  ۲) پشتیبان session (توصیهٔ شدید):"
  echo "       mkdir -p $APP/backups && tar czf $APP/backups/${TARGET}_profile_\$(date +%F).tgz -C \"$APP\" ${TARGET}_profile && chown -R file:file $APP/backups"
  echo "  ۳) اگر پستی جا مانده بود، جبران:"
  echo "       ACTION=resend SYNC_BODY_FILE=/tmp/fix.json \$(command -v ea-php83 || command -v php) -f $APP/cli_run.php"
}
trap cleanup EXIT INT TERM

echo "══════════════════════════════════════════════════════════════"
echo "  ورود دستی به $TARGET — از مرورگر کامپیوتر خودتان"
echo "══════════════════════════════════════════════════════════════"
echo
echo "گام ۱) در یک ترمینالِ «کامپیوتر خودتان» تونل SSH باز کنید و باز نگه دارید:"
echo
echo "       ssh -L $PORT:127.0.0.1:$PORT root@$SERVER_IP"
echo
echo "     (کاربر ویندوز با PuTTY: Connection ▸ SSH ▸ Tunnels ▸"
echo "      Source port: $PORT  و  Destination: 127.0.0.1:$PORT ▸ Add ▸ Open)"
echo
echo "گام ۲) در مرورگر کامپیوتر خودتان (Chrome یا Edge) این آدرس را باز کنید:"
echo
echo "       http://127.0.0.1:$PORT"
echo
echo "گام ۳) روی صفحهٔ سایت ($TARGET) کلیک کنید؛ پنجرهٔ DevTools باز می‌شود"
echo "     و «تصویر زندهٔ صفحه» را می‌بینید. اگر تصویر نبود، در پنل Elements"
echo "     روی آیکون صفحه‌نمایش/گوشی (بالا-چپ) کلیک کنید تا Screencast روشن شود."
echo
echo "گام ۴) روی همان تصویر، ورود را دستی انجام دهید:"
echo "     انتخاب کشور → شماره موبایل → دکمهٔ ارسال → کد پیامکی → تأیید."
echo "     هرچه در UI بود خودتان می‌بینید و انجام می‌دهید."
echo
echo "گام ۵) وقتی لیست گفت‌وگوها را دیدید، به همین ترمینال سرور برگردید و"
echo "     Ctrl+C بزنید تا مرورگر بسته و session ذخیره شود."
echo "══════════════════════════════════════════════════════════════"
echo

# اگر پورت اشغال است، زودتر بگو (قبل از بالا آمدن کرومیوم)
if command -v ss >/dev/null 2>&1 && ss -ltn 2>/dev/null | grep -q "127.0.0.1:$PORT\b\|:$PORT\b"; then
  echo "⚠️ پورت $PORT روی سرور اشغال به نظر می‌رسد. اگر صفحه باز نشد، با پورت دیگری اجرا کنید:"
  echo "     MANUAL_LOGIN_PORT=9333 sudo -u file bash $0 $TARGET"
  echo "   (و همان عدد را در هر دو گام ۱ و ۲ بگذارید)"
  echo
fi

export REMOTE_DEBUG_PORT="$PORT"
bash "$SELF_DIR/start_browser.sh" "$TARGET" &
CHILD=$!
sleep 3
if ! kill -0 "$CHILD" 2>/dev/null; then
  echo "✖ کرومیوم بالا نیامد؛ خروجی بالا را ببینید." >&2
  exit 1
fi

# ---------- آماده‌بودن CDP را «اثبات» کن، نه حدس ----------
# صفحهٔ سفید در مرورگر کاربر تقریباً همیشه یعنی اینجا چیزی روی پورت نیست.
echo "[*] بررسی اتصال CDP روی 127.0.0.1:$PORT …"
CDP_OK=0
for i in $(seq 1 20); do
  VER="$(curl -s --max-time 2 "http://127.0.0.1:$PORT/json/version" 2>/dev/null || true)"
  if [[ -n "$VER" ]]; then CDP_OK=1; break; fi
  if ! kill -0 "$CHILD" 2>/dev/null; then
    echo "✖ کرومیوم وسط راه بسته شد! علت احتمالی: قفل پروفایل (صف/کران در حال اجرا؟)" >&2
    echo "   بررسی:   ss -ltnp | grep $PORT   و   ps aux | grep -i chrom" >&2
    exit 1
  fi
  sleep 1
done
if [[ "$CDP_OK" == 1 ]]; then
  echo "✔ مرورگر روی سرور بالا آمد و CDP جواب می‌دهد: ${VER:0:120}"
  echo "  یادآوری: پورتِ «محلیِ کامپیوتر خودتان» را باز کنید — اگر در تونل مثلاً 39222 گذاشته‌اید، همان: http://127.0.0.1:39222"
else
  echo "⚠️ پس از ۲۰ ثانیه پورت $PORT جواب نمی‌دهد." >&2
  echo "   بررسی کنید:  ss -ltnp | grep $PORT" >&2
  echo "   اگر خالی بود، کرومیوم پورت دیباگ را باز نکرده؛ لاگ بالا را ببینید." >&2
fi
echo "منتظر لاگین شما…  (پایان: Ctrl+C)"
wait "$CHILD"
