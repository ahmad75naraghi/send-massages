#!/usr/bin/env bash
# ============================================================
#  smoke_test.sh — آزمون پذیرش پیش از تست واقعی
#
#    bash smoke_test.sh            فقط بررسی محیط (بدون ارسال)
#    bash smoke_test.sh --live     + ارسال یک پیام تستی به هر ۴ مقصد
#
#  خروجی: جدول PASS/FAIL و کد خروج 0 = همه سبز
# ============================================================
set -uo pipefail

APP_DIR="${SYNC_APP_DIR:-/home/file/public_html/s}"
LIVE="${1:-}"
export LANG="${LANG:-en_US.UTF-8}"
cd "$APP_DIR" || exit 1

PASS=0; FAIL=0
t() {  # t "عنوان" "دستور شرط"
  printf '  %-46s ' "$1"
  if eval "$2" >/dev/null 2>&1; then echo "PASS ✅"; PASS=$((PASS+1));
  else echo "FAIL ❌"; FAIL=$((FAIL+1)); fi
}

PHP_BIN=""
for c in ea-php83 ea-php82 ea-php81 php; do command -v "$c" >/dev/null 2>&1 && { PHP_BIN="$(command -v "$c")"; break; }; done

echo "=== ۱) محیط زمان اجرا ==="
t "node v20+ در /usr/bin/node"        'test -x /usr/bin/node && /usr/bin/node -v | grep -qE "v(2[0-9])"'
t "chromium-browser موجود است"        'test -x /usr/bin/chromium-browser'
t "وابستگی‌های کرومیوم کامل است"       'test "$(ldd /usr/bin/chromium-browser 2>/dev/null | grep -c "not found")" -eq 0'
t "فونت فارسی نصب است"                'fc-list :lang=fa 2>/dev/null | grep -qi . '
t "ماژول playwright"                  'node -e "require(\"playwright\")"'
t "PHP CLI پیدا شد"                   'test -n "$PHP_BIN"'
t "pdo_sqlite"                        '"$PHP_BIN" -m | grep -qx pdo_sqlite'
t "curl ext"                          '"$PHP_BIN" -m | grep -qx curl'
t "dom ext"                           '"$PHP_BIN" -m | grep -qx dom'
t "mbstring"                          '"$PHP_BIN" -m | grep -qx mbstring'
t "fileinfo"                          '"$PHP_BIN" -m | grep -qx fileinfo'
t "gd (برای test_img)"                '"$PHP_BIN" -m | grep -qx gd'
t "shell_exec غیرفعال نیست"           '! "$PHP_BIN" -i | grep -i disable_functions | grep -q shell_exec'
t "jq موجود است"                      'command -v jq'

echo; echo "=== ۲) سینتکس و یکپارچگی کد ==="
t "php -l sync_manual.php"             '"$PHP_BIN" -l sync_manual.php | grep -q "No syntax errors"'
t "php -l cli_run.php"                 '"$PHP_BIN" -l cli_run.php | grep -q "No syntax errors"'
t "node --check send_soroush.js"       'node --check send_soroush.js'
t "node --check send_igap.js"          'node --check send_igap.js'
t "node --check login_soroush.js"      'node --check login_soroush.js'
t "node --check login_igap.js"         'node --check login_igap.js'
t "node --check lib/pw_common.js"      'node --check lib/pw_common.js'
t "بدون sprintf با backslash خام"      'test "$(grep -c "sprintf('"'"'\\\\%" sync_manual.php)" -eq 0'
t ".htaccess موجود است"                'test -f .htaccess'
t ".gitignore موجود است"               'test -f .gitignore'

echo; echo "=== ۳) مجوزها و وضعیت runtime ==="
t "state.sqlite نوشتنی"                'test -w state.sqlite'
t "soroush_profile نوشتنی"             'test -w soroush_profile'
t "igap_profile نوشتنی"                'test -w igap_profile'
t "logs/ نوشتنی است"                   'test -w logs'
t "بدون Singleton باقی‌مانده"           'test -z "$(find . -maxdepth 2 -name "Singleton*" -print -quit)"'
t "کرومیوم یتیم در حال اجرا نیست"       'test "$(pgrep -c -f chromium 2>/dev/null || echo 0)" -lt 3'

echo; echo "=== ۴) شبکهٔ خروجی ==="
t "eitaa.com"                          'curl -s -o /dev/null --max-time 15 -w "%{http_code}" https://eitaa.com/shamimeashena | grep -q 200'
t "tapi.bale.ai"                       'curl -s -o /dev/null --max-time 15 https://tapi.bale.ai'
t "botapi.rubika.ir"                  'curl -s -o /dev/null --max-time 15 https://botapi.rubika.ir'
t "web.splus.ir"                       'curl -s -o /dev/null --max-time 20 -w "%{http_code}" https://web.splus.ir | grep -qE "200|301|302"'
t "web.igap.net"                       'curl -s -o /dev/null --max-time 20 -w "%{http_code}" https://web.igap.net | grep -qE "200|301|302"'

echo; echo "=== ۵) لایهٔ scraping (بدون ارسال) ==="
t "get_pending از CLI جواب می‌دهد"     'ACTION=get_pending "$PHP_BIN" -f cli_run.php </dev/null | jq -e .success'
COUNT=$(ACTION=get_pending "$PHP_BIN" -f cli_run.php </dev/null 2>/dev/null | jq -r '.count // -1' 2>/dev/null)
LAST=$(ACTION=get_pending "$PHP_BIN" -f cli_run.php </dev/null 2>/dev/null | jq -r '.lastSeenId // -1' 2>/dev/null)
echo "    lastSeenId=$LAST  pending=$COUNT"

if [[ "$LIVE" == "--live" ]]; then
  echo; echo "=== ۶) ارسال زندهٔ تستی ==="
  STAMP="$(date '+%H:%M:%S')"

  BTOKEN=$(grep -oP "const BALE_BOT_TOKEN\s*=\s*'\K[^']+" sync_manual.php | head -1)
  BCHAT=$(grep -oP "const BALE_CHANNEL_ID\s*=\s*'\K[^']+" sync_manual.php | head -1)
  printf '  %-46s ' "بله: sendMessage زنده"
  if [[ -n "$BTOKEN" ]] && curl -s -X POST "https://tapi.bale.ai/bot${BTOKEN}/sendMessage" \
       -H 'Content-Type: application/json' -d "{\"chat_id\":\"${BCHAT}\",\"text\":\"smoke $STAMP\"}" | jq -e .ok >/dev/null 2>&1; then
    echo "PASS ✅"; PASS=$((PASS+1)); else echo "FAIL ❌"; FAIL=$((FAIL+1)); fi

  RTOKEN=$(grep -oP "const RUBIKA_BOT_TOKEN\s*=\s*'\K[^']+" sync_manual.php | head -1)
  RCHAT=$(grep -oP "const RUBIKA_CHANNEL_ID\s*=\s*'\K[^']+" sync_manual.php | head -1)
  printf '  %-46s ' "روبیکا: sendMessage زنده"
  if [[ -n "$RTOKEN" ]] && curl -s -X POST "https://botapi.rubika.ir/v3/${RTOKEN}/sendMessage" \
       -H 'Content-Type: application/json' -d "{\"chat_id\":\"${RCHAT}\",\"text\":\"smoke $STAMP\"}" | jq -e '.status == "OK"' >/dev/null 2>&1; then
    echo "PASS ✅"; PASS=$((PASS+1)); else echo "FAIL ❌"; FAIL=$((FAIL+1)); fi

  SCH=$(grep -oP "const SOROUSH_CHANNEL_ID\s*=\s*'\K[^']+" sync_manual.php | head -1)
  SNAME=$(grep -oP "const SOROUSH_CHANNEL_NAME\s*=\s*'\K[^']+" sync_manual.php | head -1)
  printf '  %-46s ' "سروش‌پلاس: ارسال متن زنده"
  OUT=$(sudo -u "${SUDO_USER:-file}" /usr/bin/node send_soroush.js --channel="$SCH" --channel-name="$SNAME" --text="smoke $STAMP" 2>/dev/null | tail -1)
  if jq -e '.status == "OK"' <<<"$OUT" >/dev/null 2>&1; then echo "PASS ✅ (${OUT})"; PASS=$((PASS+1));
  else echo "FAIL ❌ (${OUT:0:160})"; FAIL=$((FAIL+1)); fi

  ICH=$(grep -oP "const IGAP_CHANNEL_ID\s*=\s*'\K[^']+" sync_manual.php | head -1)
  INAME=$(grep -oP "const IGAP_CHANNEL_NAME\s*=\s*'\K[^']+" sync_manual.php | head -1)
  printf '  %-46s ' "آی‌گپ: ارسال متن زنده"
  OUT=$(sudo -u "${SUDO_USER:-file}" /usr/bin/node send_igap.js --channel="$ICH" --channel-name="$INAME" --text="smoke $STAMP" 2>/dev/null | tail -1)
  if jq -e '.status == "OK"' <<<"$OUT" >/dev/null 2>&1; then echo "PASS ✅ (${OUT})"; PASS=$((PASS+1));
  else echo "FAIL ❌ (${OUT:0:160})"; FAIL=$((FAIL+1)); fi

  if [[ -f test_img.jpg ]]; then
    printf '  %-46s ' "آی‌گپ: ارسال رسانه زنده"
    OUT=$(sudo -u "${SUDO_USER:-file}" /usr/bin/node send_igap.js --channel="$ICH" --channel-name="$INAME" --text="smoke media $STAMP" --file="$APP_DIR/test_img.jpg" --type=image 2>/dev/null | tail -1)
    if jq -e '.status == "OK"' <<<"$OUT" >/dev/null 2>&1; then echo "PASS ✅ (${OUT})"; PASS=$((PASS+1));
    else echo "FAIL ❌ (${OUT:0:160})"; FAIL=$((FAIL+1)); fi
  fi
fi

echo; echo "-----------------------------------------"
echo "  PASS=$PASS  FAIL=$FAIL"
[[ "$FAIL" -eq 0 ]] && echo "  نتیجه: آمادهٔ تست ✅" || echo "  نتیجه: موارد قرمز را برطرف کنید ❌"
[[ "$FAIL" -eq 0 ]] && exit 0 || exit 1
