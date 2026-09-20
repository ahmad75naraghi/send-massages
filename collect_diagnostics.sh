#!/usr/bin/env bash
# ============================================================
#  collect_diagnostics.sh — جمع‌آوری یکجای شواهد برای گزارش خطا
#  اجرا: sudo -u file bash collect_diagnostics.sh
#  خروجی: logs/diag_<timestamp>.txt  (متن) + کپی شواهد بصری
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
OUT="$APP/logs/diag_$(date +%F_%H%M%S).txt"
mkdir -p "$APP/logs"
exec > >(tee -a "$OUT") 2>&1

if [[ -r "${SYNC_ENV_FILE:-$APP/.env}" ]]; then
  if declare -F load_dotenv >/dev/null 2>&1; then load_dotenv "${SYNC_ENV_FILE:-$APP/.env}"
  else set -a; . "${SYNC_ENV_FILE:-$APP/.env}"; set +a; fi
fi
PHP_BIN="${PHP_BIN:-}"
if [[ -z "$PHP_BIN" ]]; then
  for c in ea-php83 ea-php82 ea-php81 php; do command -v "$c" >/dev/null 2>&1 && { PHP_BIN="$(command -v "$c")"; break; }; done
fi
[[ -z "$PHP_BIN" ]] && PHP_BIN="php"

echo "=========== $(date '+%F %T') ==========="

echo "--- ۱) سیستم ---"
uname -a; cat /etc/redhat-release 2>/dev/null
echo "uptime: $(uptime -p 2>/dev/null) | load: $(cat /proc/loadavg)"
free -m; df -h /home /tmp /dev/shm 2>/dev/null
echo "getenforce: $(getenforce 2>/dev/null || echo n/a)"

echo; echo "--- ۲) زمان اجرا ---"
for b in /usr/bin/node /usr/bin/chromium-browser; do
  printf '%-32s ' "$b"
  [[ -x "$b" ]] && "$b" --version 2>&1 | head -1 || echo "MISSING/NOT-EXECUTABLE"
done
printf '%-32s ' "playwright"
(cd "$APP" && node -e 'console.log(require("playwright/package.json").version)' 2>/dev/null) || echo MISSING
"$PHP_BIN" -v 2>/dev/null | head -1 || echo "PHP NOT FOUND"
"$PHP_BIN" -m 2>/dev/null | grep -Ei 'pdo_sqlite|^curl$|^dom$|libxml|mbstring|fileinfo|^gd$' | tr '\n' ' '; echo
"$PHP_BIN" -i 2>/dev/null | grep -i 'disable_functions' | head -1

echo; echo "--- ۳) مالکیت و مجوز ---"
ls -ld "$APP" "$APP"/soroush_profile "$APP"/igap_profile "$APP"/logs "$APP"/lib 2>&1
ls -l "$APP"/state.sqlite "$APP"/sync_manual.php "$APP"/.htaccess "$APP"/cli_run.php 2>&1
echo "Singleton باقی‌مانده:"; find "$APP" -maxdepth 2 -name 'Singleton*' -ls 2>/dev/null || echo "  (هیچ)"
echo "مالکیت نادرست (غیر file):"; find "$APP" -maxdepth 2 ! -user file -printf '%u:%g %p\n' 2>/dev/null | head -20

echo; echo "--- ۴) فرایندها ---"
ps -eo pid,ppid,user,etime,rss,cmd | grep -E 'chromium|send_(soroush|igap)|sync_daemon|cli_run' | grep -v grep || echo "  (هیچ)"
echo "تعداد chromium: $(pgrep -c -f chromium 2>/dev/null || echo 0)"

echo; echo "--- ۵) state ---"
sqlite3 "$APP/state.sqlite" "SELECT channel, last_msg_id FROM sync_state;" 2>&1 || \
  "$PHP_BIN" -r '$p=new PDO("sqlite:'"$APP"'/state.sqlite"); foreach($p->query("SELECT * FROM sync_state") as $r) echo implode(" | ",$r),"\n";' 2>&1
ls -l "$APP"/state.sqlite* 2>&1

echo; echo "--- ۶) شبکه ---"
for h in eitaa.com tapi.bale.ai botapi.rubika.ir web.splus.ir web.igap.net; do
  printf '%-22s ' "$h"
  curl -s -o /dev/null -w '%{http_code} %{time_total}s\n' --max-time 12 "https://$h" 2>&1 || echo FAIL
done
echo "پورت‌های باز مرتبط:"; ss -lntp 2>/dev/null | grep -E '9222|:80 |:443 ' || true

echo; echo "--- ۷) آخرین خطاها ---"
for f in "$APP/error_log" "$APP/logs/cron_sync.log" "$APP/logs/health.log" "$APP/logs/systemd.log"; do
  echo ">>> $(basename "$f") (۱۵ خط آخر):"
  tail -15 "$f" 2>/dev/null || echo "  (موجود نیست)"
done
echo ">>> آخرین لاگ اجراهای ارسال:"
ls -t "$APP"/logs/send_*.log 2>/dev/null | head -2 | while read -r f; do
  echo "    --- $f ---"; tail -25 "$f"
done
echo ">>> dmesg kill (۱۰ خط):"
dmesg -T 2>/dev/null | grep -iE 'killed process|oom' | tail -10 || echo "  (دسترسی نیست)"

echo; echo "--- ۸) شواهد بصری ---"
ls -lt "$APP"/*.jpg 2>/dev/null | head -10
DIAGDIR="$APP/logs/diag_assets_$(date +%F_%H%M%S)"
mkdir -p "$DIAGDIR"
cp -f "$APP"/last_media_send.jpg "$APP"/last_igap_send.jpg "$APP"/last_send_status.jpg "$DIAGDIR"/ 2>/dev/null
cp -f "$APP"/igap_dump.html "$DIAGDIR"/ 2>/dev/null
ls -t "$APP"/logs/send_*.log 2>/dev/null | head -3 | xargs -r cp -f -t "$DIAGDIR"/ 2>/dev/null
echo "شواهد بصری کپی شد در: $DIAGDIR"

echo; echo "--- ۹) پیکربندی (.env) ---"
if [[ -f "$APP/.env" ]]; then
  echo "مسیر: $APP/.env | مجوز: $(stat -c '%a %U:%G' "$APP/.env" 2>/dev/null)"
  # فقط نام کلیدها و وضعیت پر/خالی بودن؛ مقدارهای حساس هرگز چاپ نمی‌شوند
  awk -F= '/^[A-Za-z_][A-Za-z0-9_]*=/ { printf "  %-24s %s\n", $1, (length($2) > 0 ? "SET" : "EMPTY") }' "$APP/.env"
else
  echo "  ❌ .env وجود ندارد (bash setup_env.sh)"
fi
echo "توکن hard-code در PHP:"; grep -rlE "const[[:space:]]+(BALE|RUBIKA|SOROUSH)_BOT_TOKEN" --include='*.php' "$APP" 2>/dev/null || echo "  (هیچ — پاک است)"

echo; echo "--- ۱۰) خودآزمون سریع ---"
echo ">>> get_pending:"
ACTION=get_pending "$PHP_BIN" -f "$APP/cli_run.php" </dev/null 2>&1 | head -c 600; echo

echo; echo "گزارش ذخیره شد در: $OUT"
echo "برای ارسال: tar czf diag.tar.gz -C $APP/logs $(basename "$OUT") $(basename "$DIAGDIR")"
