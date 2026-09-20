#!/usr/bin/env bash
# ============================================================
#  health_check.sh — بررسی سلامت زیرساخت + هشدار به مدیر در بله
#  اجرا: sudo -u file bash health_check.sh   (یا cron روزانه)
#  خروجی: logs/health.log  +  exit 0 (سالم) / 1 (مشکل)
# ============================================================
set -uo pipefail

APP_DIR="${SYNC_APP_DIR:-/home/file/public_html/s}"
LOG_FILE="$APP_DIR/logs/health.log"
export LANG="${LANG:-en_US.UTF-8}"
mkdir -p "$APP_DIR/logs"

PROBLEMS=()
chk() {  # chk "شرح" "شرط"
  if eval "$2" >/dev/null 2>&1; then
    printf '  [ OK ] %s\n' "$1" | tee -a "$LOG_FILE"
  else
    printf '  [FAIL] %s\n' "$1" | tee -a "$LOG_FILE"
    PROBLEMS+=("$1")
  fi
}

PHP_BIN=""
for c in ea-php83 ea-php82 ea-php81 php; do command -v "$c" >/dev/null 2>&1 && { PHP_BIN="$(command -v "$c")"; break; }; done

echo "=== $(date '+%F %T') بررسی سلامت ===" | tee -a "$LOG_FILE"

chk "باینری node"                 'test -x /usr/bin/node'
chk "باینری chromium-browser"     'test -x /usr/bin/chromium-browser'
chk "ماژول playwright"            'cd '"$APP_DIR"' && node -e "require(\"playwright\")"'
chk "اکستنشن pdo_sqlite"           '"$PHP_BIN" -m | grep -qx pdo_sqlite'
chk "اکستنشن curl"                 '"$PHP_BIN" -m | grep -qx curl'
chk "اکستنشن dom"                  '"$PHP_BIN" -m | grep -qx dom'
chk "اکستنشن mbstring"             '"$PHP_BIN" -m | grep -qx mbstring'
chk "اکستنشن fileinfo"             '"$PHP_BIN" -m | grep -qx fileinfo'
chk "shell_exec فعال است"          '! "$PHP_BIN" -i | grep -i disable_functions | grep -q shell_exec'
chk "سینتکس sync_manual.php"       '"$PHP_BIN" -l '"$APP_DIR"'/sync_manual.php | grep -q "No syntax errors"'
chk "سینتکس send_soroush.js"       'node --check '"$APP_DIR"'/send_soroush.js'
chk "سینتکس send_igap.js"          'node --check '"$APP_DIR"'/send_igap.js'
chk "state.sqlite خواندنی/نوشتنی"  'test -r '"$APP_DIR"'/state.sqlite && test -w '"$APP_DIR"'/state.sqlite'
chk "soroush_profile نوشتنی"       'test -w '"$APP_DIR"'/soroush_profile'
chk "igap_profile نوشتنی"          'test -w '"$APP_DIR"'/igap_profile'
chk "مالکیت پروفایل‌ها = کاربر وب"  'stat -c %U '"$APP_DIR"'/igap_profile | grep -qvx root'
chk "بدون قفل Singleton باقی‌مانده" 'test -z "$(find '"$APP_DIR"' -maxdepth 2 -name "Singleton*" -print -quit)"'
chk "فضای آزاد /home > 1GB"        'test "$(df -BM --output=avail /home | tail -1 | tr -dc 0-9)" -gt 1024'
chk "رم آزاد > 500MB"              'test "$(free -m | awk "/^Mem:/{print \$7}")" -gt 500'
chk "کرومیوم یتیم < 6 فرایند"      'test "$(pgrep -c -f chromium 2>/dev/null || echo 0)" -lt 6'
chk "دسترسی به ایتا"               'curl -s -o /dev/null --max-time 15 -w "%{http_code}" https://eitaa.com/shamimeashena | grep -q 200'
chk "دسترسی به API بله"            'curl -s -o /dev/null --max-time 15 https://tapi.bale.ai'
chk "دسترسی به API روبیکا"         'curl -s -o /dev/null --max-time 15 https://botapi.rubika.ir'
chk "دسترسی به web.splus.ir"       'curl -s -o /dev/null --max-time 20 -w "%{http_code}" https://web.splus.ir | grep -qE "200|301|302"'
chk "دسترسی به web.igap.net"       'curl -s -o /dev/null --max-time 20 -w "%{http_code}" https://web.igap.net | grep -qE "200|301|302"'
chk "session سروش تازه (<۳۰ روز)"  'test -n "$(find '"$APP_DIR"'/soroush_profile -name Cookies -mtime -30 -print -quit 2>/dev/null)"'
chk "session آی‌گپ تازه (<۳۰ روز)"  'test -n "$(find '"$APP_DIR"'/igap_profile -name Cookies -mtime -30 -print -quit 2>/dev/null)"'
chk "پورت دیباگ ۹۲۲ بسته است"     '! ss -lnt 2>/dev/null | grep -q ":9222 "'
chk ".htaccess موجود است"          'test -f '"$APP_DIR"'/.htaccess'
chk "get_pending پاسخ می‌دهد"       'ACTION=get_pending '"$PHP_BIN"' -f '"$APP_DIR"'/cli_run.php </dev/null | grep -q "\"success\""'

echo "-------------------------------------" | tee -a "$LOG_FILE"
if [[ ${#PROBLEMS[@]} -eq 0 ]]; then
  echo "RESULT: HEALTHY ✅" | tee -a "$LOG_FILE"
  exit 0
fi

echo "RESULT: ${#PROBLEMS[@]} مشکل ⚠️" | tee -a "$LOG_FILE"
printf '  - %s\n' "${PROBLEMS[@]}" | tee -a "$LOG_FILE"

# هشدار به مدیر در بله (از همان مسیر گزارش داشبورد)
MSG="⚠️ هشدار سلامت سیستم همگام‌سازی
زمان: $(date '+%F %T')
موارد ناموفق:
$(printf '• %s\n' "${PROBLEMS[@]}")"
BODY="$(jq -n --arg t "$MSG" '{report:[$t]}')"
TMPB="$(mktemp /tmp/hc_body.XXXXXX)"; printf '%s' "$BODY" >"$TMPB"
SYNC_BODY_FILE="$TMPB" ACTION=send_report "$PHP_BIN" -f "$APP_DIR/cli_run.php" >/dev/null 2>&1
rm -f "$TMPB"
exit 1
