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

SELF_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/dotenv.sh
[[ -r "$SELF_DIR/lib/dotenv.sh" ]] && source "$SELF_DIR/lib/dotenv.sh"
APP_DIR="${SYNC_APP_DIR:-}"
if [[ -z "$APP_DIR" ]]; then
  if declare -F dotenv_app_dir >/dev/null 2>&1; then APP_DIR="$(dotenv_app_dir)"
  else APP_DIR="$SELF_DIR"; fi
fi
LIVE="${1:-}"
export LANG="${LANG:-en_US.UTF-8}"
cd "$APP_DIR" || exit 1

PASS=0; FAIL=0; SKIP=0
t() {  # t "عنوان" "دستور شرط"
  printf '  %-46s ' "$1"
  if eval "$2" >/dev/null 2>&1; then echo "PASS ✅"; PASS=$((PASS+1));
  else echo "FAIL ❌"; FAIL=$((FAIL+1)); fi
}
ts() { # ts "عنوان" "شرطِ در دسترس بودن" "دستور شرط"
  printf '  %-46s ' "$1"
  if ! eval "$2" >/dev/null 2>&1; then echo "SKIP ⏭ (پیش‌نیاز نیست)"; SKIP=$((SKIP+1)); return 0; fi
  if eval "$3" >/dev/null 2>&1; then echo "PASS ✅"; PASS=$((PASS+1));
  else echo "FAIL ❌"; FAIL=$((FAIL+1)); fi
}

envval() {  # envval KEY → مقدار از محیط یا .env
  local k="$1" v=""
  v="${!k:-}"
  if [[ -z "$v" && -r "$APP_DIR/.env" ]]; then
    if declare -F dotenv_read >/dev/null 2>&1; then
      v="$(dotenv_read "$APP_DIR/.env" "$k")"
    else
      v="$(sed -nE "s/^(export[[:space:]]+)?${k}=([^\r]*)$/\2/p" "$APP_DIR/.env" | tail -1)"
      v="${v%\#*}"; v="$(printf '%s' "$v" | sed -E 's/[[:space:]]+$//; s/^"(.*)"$/\1/; s/^'\''(.*)'\''$/\1/')"
    fi
  fi
  printf '%s' "$v"
}

PHP_BIN="${PHP_BIN:-}"
if [[ -z "$PHP_BIN" ]]; then
  for c in ea-php83 ea-php82 ea-php81 php; do command -v "$c" >/dev/null 2>&1 && { PHP_BIN="$(command -v "$c")"; break; }; done
fi
if [[ -z "$PHP_BIN" ]]; then PHP_BIN="php"; fi
have_php() { command -v "$PHP_BIN" >/dev/null 2>&1 || [[ -x "$PHP_BIN" ]]; }

# باینری Node و کرومیوم: از .env، و اگر آن مسیر روی این ماشین نبود از PATH
NODE_BIN="$(envval NODE_BIN)"; NODE_BIN_CFG="$NODE_BIN"
if [[ -z "$NODE_BIN" || ! -x "$NODE_BIN" ]]; then
  for c in node nodejs; do command -v "$c" >/dev/null 2>&1 && { NODE_BIN="$(command -v "$c")"; break; }; done
fi
[[ -z "$NODE_BIN" ]] && NODE_BIN="node"
have_node() { command -v "$NODE_BIN" >/dev/null 2>&1 || [[ -x "$NODE_BIN" ]]; }

CHROME_BIN="$(envval SYNC_CHROMIUM_BIN)"; CHROME_BIN_CFG="$CHROME_BIN"
if [[ -z "$CHROME_BIN" || ! -x "$CHROME_BIN" ]]; then
  CHROME_BIN=""
  for c in /usr/bin/chromium-browser /usr/bin/chromium /usr/bin/google-chrome-stable /usr/bin/google-chrome; do
    [[ -x "$c" ]] && { CHROME_BIN="$c"; break; }
  done
fi
[[ -z "$CHROME_BIN" ]] && CHROME_BIN="${CHROME_BIN_CFG:-/usr/bin/chromium-browser}"
have_chrome() { [[ -x "$CHROME_BIN" ]]; }


echo; echo "=== ۰) پیکربندی (.env) ==="
t ".env وجود دارد"                     'test -f "$APP_DIR/.env"'
t ".env مجوز ۶۰۰ دارد"                 'test "$(stat -c %a "$APP_DIR/.env" 2>/dev/null)" = "600"'
t ".env در .gitignore است"              'git -C "$APP_DIR" check-ignore -q .env 2>/dev/null || grep -qx ".env" "$APP_DIR/.gitignore"'
t "SECURITY_KEY مقدار دارد"             'test -n "$(envval SECURITY_KEY)"'
t "BALE_BOT_TOKEN مقدار دارد"           'test -n "$(envval BALE_BOT_TOKEN)"'
t "BALE_ADMIN_CHAT_ID مقدار دارد"       'test -n "$(envval BALE_ADMIN_CHAT_ID)"'
t "RUBIKA_BOT_TOKEN مقدار دارد"         'test -n "$(envval RUBIKA_BOT_TOKEN)"'
t "نام کانال سروش مقدار دارد"            'test -n "$(envval SOROUSH_CHANNEL_NAME)"'
t "نام کانال آی‌گپ مقدار دارد"            'test -n "$(envval IGAP_CHANNEL_NAME)"'
t "هیچ توکنی در کد PHP نمانده"           'test -z "$(grep -rhoE "const[[:space:]]+(BALE|RUBIKA|SOROUSH)_BOT_TOKEN" --include="*.php" "$APP_DIR" 2>/dev/null)"'
ts "config.php قابل require است"        'have_php' '"$PHP_BIN" -r "require \"$APP_DIR/config.php\"; echo function_exists(\"env\") ? \"ok\" : \"no\";" | grep -q ok'
if [[ ! -f "$APP_DIR/.env" ]]; then
  echo "    → برای ساخت خودکار: bash setup_env.sh"
fi

echo; echo "=== ۱) محیط زمان اجرا ==="
echo "  (node: $NODE_BIN | chromium: $CHROME_BIN | php: $PHP_BIN)"
if [[ -n "$NODE_BIN_CFG" && "$NODE_BIN" != "$NODE_BIN_CFG" ]]; then
  echo "  ⚠️ NODE_BIN در .env ($NODE_BIN_CFG) روی این ماشین نیست؛ از $NODE_BIN استفاده شد"
fi
if [[ -n "$CHROME_BIN_CFG" && "$CHROME_BIN" != "$CHROME_BIN_CFG" ]]; then
  echo "  ⚠️ SYNC_CHROMIUM_BIN در .env ($CHROME_BIN_CFG) روی این ماشین نیست؛ از $CHROME_BIN استفاده شد"
fi
t "node v20+ پیدا شد"                 'have_node && "$NODE_BIN" -v | grep -qE "v(2[0-9]|[3-9][0-9])"'
ts "chromium موجود است"               'true' 'have_chrome'
ts "وابستگی‌های کرومیوم کامل است"      'have_chrome' 'test "$(ldd "$CHROME_BIN" 2>/dev/null | grep -c "not found")" -eq 0'
t "فونت فارسی نصب است"                'fc-list :lang=fa 2>/dev/null | grep -qi . '
ts "ماژول playwright"                 'have_node' '"$NODE_BIN" -e "require(\"playwright\")"'
t "PHP CLI پیدا شد"                   'have_php'
ts "pdo_sqlite"                       'have_php' '"$PHP_BIN" -m | grep -qx pdo_sqlite'
ts "curl ext"                         'have_php' '"$PHP_BIN" -m | grep -qx curl'
ts "dom ext"                          'have_php' '"$PHP_BIN" -m | grep -qx dom'
ts "mbstring"                         'have_php' '"$PHP_BIN" -m | grep -qx mbstring'
ts "fileinfo"                         'have_php' '"$PHP_BIN" -m | grep -qx fileinfo'
ts "gd (برای test_img)"               'have_php' '"$PHP_BIN" -m | grep -qx gd'
ts "shell_exec غیرفعال نیست"          'have_php' '! "$PHP_BIN" -i | grep -i disable_functions | grep -q shell_exec'
t "jq موجود است"                      'command -v jq'

echo; echo "=== ۲) سینتکس و یکپارچگی کد ==="
ts "php -l sync_manual.php"             'have_php' '"$PHP_BIN" -l sync_manual.php | grep -q "No syntax errors"'
ts "php -l cli_run.php"                 'have_php' '"$PHP_BIN" -l cli_run.php | grep -q "No syntax errors"'
ts "php -l config.php"                 'have_php' '"$PHP_BIN" -l config.php | grep -q "No syntax errors"'
ts "php -l sync_daemon.php"             'have_php' '"$PHP_BIN" -l sync_daemon.php | grep -q "No syntax errors"'
t "bash -n setup_env.sh"               'bash -n setup_env.sh'
ts "node --check send_soroush.js"   'have_node' '"$NODE_BIN" --check send_soroush.js'
ts "node --check send_igap.js"          'have_node' '"$NODE_BIN" --check send_igap.js'
ts "node --check login_soroush.js"      'have_node' '"$NODE_BIN" --check login_soroush.js'
ts "node --check login_igap.js"         'have_node' '"$NODE_BIN" --check login_igap.js'
ts "node --check lib/pw_common.js"      'have_node' '"$NODE_BIN" --check lib/pw_common.js'
t "بدون sprintf با backslash خام"      'test "$(grep -c "sprintf('"'"'\\\\%" sync_manual.php)" -eq 0'
t ".htaccess موجود است"                'test -f .htaccess'
t ".gitignore موجود است"               'test -f .gitignore'

echo; echo "=== ۳) مجوزها و وضعیت runtime ==="
t "state.sqlite نوشتنی"                'test -w state.sqlite'
t "soroush_profile نوشتنی"             'test -w soroush_profile'
t "igap_profile نوشتنی"                'test -w igap_profile'
t "logs/ نوشتنی است"                   'test -w logs'
t "بدون Singleton باقی‌مانده"           'test -z "$(find . -maxdepth 2 -name "Singleton*" -print -quit)"'
count_chromium() {  # فقط فرایندهایی که واقعاً باینری کرومیوم دارند
  local n=0 pdir exe
  for pdir in /proc/[0-9]*; do
    exe="$(readlink -f "$pdir/exe" 2>/dev/null)" || continue
    case "$exe" in *chromium*|*chrome*|*headless_shell*) n=$((n+1));; esac
  done
  printf '%s' "$n"
}
printf '  %-46s ' "کرومیوم یتیم در حال اجرا نیست"
N_CHROME="$(count_chromium)"
if [[ "$N_CHROME" -eq 0 ]]; then echo "PASS ✅"; PASS=$((PASS+1));
else echo "FAIL ❌ ($N_CHROME فرایند — اگر sync در جریان نیست: pkill -f chromium-browser)"; FAIL=$((FAIL+1)); fi

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

  BTOKEN="$(envval BALE_BOT_TOKEN)"
  BTOKEN="$(printf '%s' "$BTOKEN" | sed -E 's/^[Bb][Oo][Tt]//')"
  BCHAT="$(envval BALE_CHANNEL_ID)"
  [[ -z "$BCHAT" ]] && BCHAT="@testforme"
  printf '  %-46s ' "بله: sendMessage زنده"
  if [[ -n "$BTOKEN" ]] && curl -s -X POST "https://tapi.bale.ai/bot${BTOKEN}/sendMessage" \
       -H 'Content-Type: application/json' -d "{\"chat_id\":\"${BCHAT}\",\"text\":\"smoke $STAMP\"}" | jq -e .ok >/dev/null 2>&1; then
    echo "PASS ✅"; PASS=$((PASS+1)); else echo "FAIL ❌"; FAIL=$((FAIL+1)); fi

  RTOKEN="$(envval RUBIKA_BOT_TOKEN)"
  RCHAT="$(envval RUBIKA_CHANNEL_ID)"
  printf '  %-46s ' "روبیکا: sendMessage زنده"
  if [[ -n "$RTOKEN" ]] && curl -s -X POST "https://botapi.rubika.ir/v3/${RTOKEN}/sendMessage" \
       -H 'Content-Type: application/json' -d "{\"chat_id\":\"${RCHAT}\",\"text\":\"smoke $STAMP\"}" | jq -e '.status == "OK"' >/dev/null 2>&1; then
    echo "PASS ✅"; PASS=$((PASS+1)); else echo "FAIL ❌"; FAIL=$((FAIL+1)); fi

  SCH="$(envval SOROUSH_CHANNEL_ID)"
  SNAME="$(envval SOROUSH_CHANNEL_NAME)"
  printf '  %-46s ' "سروش‌پلاس: ارسال متن زنده"
  OUT=$(sudo -u "${SUDO_USER:-file}" "$NODE_BIN" send_soroush.js --channel="$SCH" --channel-name="$SNAME" --text="smoke $STAMP" 2>/dev/null | tail -1)
  if jq -e '.status == "OK"' <<<"$OUT" >/dev/null 2>&1; then echo "PASS ✅ (${OUT})"; PASS=$((PASS+1));
  else echo "FAIL ❌ (${OUT:0:160})"; FAIL=$((FAIL+1)); fi

  ICH="$(envval IGAP_CHANNEL_ID)"
  INAME="$(envval IGAP_CHANNEL_NAME)"
  IITEM="$(envval IGAP_ITEM_ID)"
  [[ -n "$IITEM" ]] && IGAP_EXTRA="--item-id=$IITEM" || IGAP_EXTRA=""
  printf '  %-46s ' "آی‌گپ: ارسال متن زنده"
  OUT=$(sudo -u "${SUDO_USER:-file}" "$NODE_BIN" send_igap.js --channel="$ICH" --channel-name="$INAME" $IGAP_EXTRA --text="smoke $STAMP" 2>/dev/null | tail -1)
  if jq -e '.status == "OK"' <<<"$OUT" >/dev/null 2>&1; then echo "PASS ✅ (${OUT})"; PASS=$((PASS+1));
  else echo "FAIL ❌ (${OUT:0:160})"; FAIL=$((FAIL+1)); fi

  if [[ -f test_img.jpg ]]; then
    printf '  %-46s ' "آی‌گپ: ارسال رسانه زنده"
    OUT=$(sudo -u "${SUDO_USER:-file}" "$NODE_BIN" send_igap.js --channel="$ICH" --channel-name="$INAME" $IGAP_EXTRA --text="smoke media $STAMP" --file="$APP_DIR/test_img.jpg" --type=image 2>/dev/null | tail -1)
    if jq -e '.status == "OK"' <<<"$OUT" >/dev/null 2>&1; then echo "PASS ✅ (${OUT})"; PASS=$((PASS+1));
    else echo "FAIL ❌ (${OUT:0:160})"; FAIL=$((FAIL+1)); fi
  fi
fi

echo; echo "-----------------------------------------"
echo "  PASS=$PASS  FAIL=$FAIL  SKIP=$SKIP"
if [[ "$SKIP" -gt 0 ]]; then
  echo "  ⏭ SKIP = تست‌هایی که پیش‌نیازشان (PHP CLI / Node / Chromium) روی این ماشین نصب نیست."
  echo "    روی سرور اصلی باید همهٔ این‌ها PASS شوند؛ SKIP یعنی محیط ناقص است."
fi
[[ "$FAIL" -eq 0 ]] && echo "  نتیجه: آمادهٔ تست ✅" || echo "  نتیجه: موارد قرمز را برطرف کنید ❌"
[[ "$FAIL" -eq 0 ]] && exit 0 || exit 1
