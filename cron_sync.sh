#!/usr/bin/env bash
# ============================================================
#  cron_sync.sh — رانندهٔ خودکار همگام‌سازی (بدون مرورگر اپراتور)
#
#  دو حالت اجرا:
#    الف) CLI (پیش‌فرض و توصیه‌شده): مستقیم با PHP CLI و cli_run.php
#       → بدون محدودیت ProxyTimeout/FastCGI، بدون نیاز به کلید وب
#    ب) HTTP: اگر SYNC_BASE_URL تنظیم شده باشد، با curl به داشبورد می‌زند
#
#  تنظیم یک‌باره (فقط برای حالت HTTP):
#    echo 'SYNC_BASE_URL=https://your-domain/s' > /home/file/public_html/s/.cron_env
#    chmod 600 /home/file/public_html/s/.cron_env
#
#  اجرا:  sudo -u file bash cron_sync.sh
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
LOG_DIR="${LOG_DIR:-$APP_DIR/logs}"
LOG_FILE="$LOG_DIR/cron_sync.log"
LOCK_FILE="/tmp/cron_sync.lock"
ENV_FILE="$APP_DIR/.cron_env"

export HOME="${HOME:-/home/file}"
export LANG="${LANG:-en_US.UTF-8}"
export LC_ALL="${LC_ALL:-en_US.UTF-8}"
export SYNC_APP_DIR="$APP_DIR"

mkdir -p "$LOG_DIR"
log()  { printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >>"$LOG_FILE"; }
both() { printf '%s\n' "$*"; log "$*"; }

# ---------- قفل اجرای یکتا ----------
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  log "SKIP: اجرای قبلی هنوز در جریان است"
  exit 0
fi

# ---------- پیکربندی از .env (و سپس .cron_env برای سازگاری با نسخهٔ قبل) ----------
# .env همان فایلی است که config.php و lib/pw_common.js هم می‌خوانند.
DOT_ENV="${SYNC_ENV_FILE:-$APP_DIR/.env}"
if [[ -r "$DOT_ENV" ]]; then
  # هرگز `source .env` نکنید: مقدارهای فارسیِ دارای فاصله (مثل نام کانال)
  # باعث «command not found» می‌شوند. لودر خط‌به‌خط امن است.
  if declare -F load_dotenv >/dev/null 2>&1; then
    load_dotenv "$DOT_ENV"
  else
    set -a; . "$DOT_ENV"; set +a
  fi
  log "config: $DOT_ENV"
else
  log "WARN: فایل .env پیدا نشد ($DOT_ENV) — از مقدارهای پیش‌فرض و محیط استفاده می‌شود"
fi
if [[ -r "$ENV_FILE" ]]; then
  if declare -F load_dotenv >/dev/null 2>&1; then
    load_dotenv "$ENV_FILE"
  else
    set -a; . "$ENV_FILE"; set +a
  fi
fi
BASE_URL="${SYNC_BASE_URL:-}"

# ---------- انتخاب باینری PHP ----------
# اگر در .env مقدار PHP_BIN ست شده باشد، همان اولویت دارد (پس از load_dotenv خوانده شده).
PHP_BIN="${PHP_BIN:-}"
if [[ -z "$PHP_BIN" ]]; then
  for c in ea-php83 ea-php82 ea-php81 php php83 php82 php81; do
    if command -v "$c" >/dev/null 2>&1; then PHP_BIN="$(command -v "$c")"; break; fi
  done
fi
if [[ -z "$PHP_BIN" && -x /opt/cpanel/ea-php81/root/usr/bin/php ]]; then
  PHP_BIN=/opt/cpanel/ea-php81/root/usr/bin/php
fi

# ---------- توابع فراخوانی action ----------
call_cli() {  # $1=action  $2=json|''
  local body_file=""
  if [[ -n "${2:-}" ]]; then
    body_file="$(mktemp /tmp/sync_body.XXXXXX)"
    printf '%s' "$2" >"$body_file"
    SYNC_BODY_FILE="$body_file" ACTION="$1" "$PHP_BIN" -f "$APP_DIR/cli_run.php"
    local rc=$?
    rm -f "$body_file"
    return $rc
  fi
  ACTION="$1" "$PHP_BIN" -f "$APP_DIR/cli_run.php" </dev/null
}

call_http() {  # $1=action  $2=json|''
  local key=""
  key="$(cat "$APP_DIR/.cron_key" 2>/dev/null || true)"
  [[ -z "$key" ]] && key="${SECURITY_KEY:-}"
  if [[ -z "$key" ]]; then
    both "FATAL: حالت HTTP انتخاب شده ولی کلید پیدا نشد (.cron_key یا SECURITY_KEY در .env)"
    exit 1
  fi
  if [[ -n "${2:-}" ]]; then
    curl -sS --max-time 300 -X POST -H 'Content-Type: application/json' \
         --data "$2" "${BASE_URL}/sync_manual.php?action=$1&key=${key}"
  else
    curl -sS --max-time 60 "${BASE_URL}/sync_manual.php?action=$1&key=${key}"
  fi
}

MODE="cli"
if [[ -n "$BASE_URL" ]]; then MODE="http"; fi
if [[ "$MODE" == "cli" && -z "$PHP_BIN" ]]; then
  both "FATAL: باینری PHP پیدا نشد؛ SYNC_BASE_URL را تنظیم کنید یا PHP CLI نصب کنید"
  exit 1
fi

# ---------- پیش‌بررسی ----------
if [[ "$MODE" == "cli" ]]; then
  if ! "$PHP_BIN" -l "$APP_DIR/sync_manual.php" >/dev/null 2>&1; then
    both "FATAL: خطای سینتکس PHP در sync_manual.php — اجرا متوقف شد"
    "$PHP_BIN" -l "$APP_DIR/sync_manual.php" 2>&1 | tail -3 | while read -r l; do log "  $l"; done
    exit 1
  fi
fi

both "START mode=$MODE php=${PHP_BIN:-curl}"

# ---------- ۱) دریافت صف ----------
call_cli_or_http_get_pending() {
  if [[ "$MODE" == "cli" ]]; then call_cli get_pending ""; else call_http get_pending ""; fi
}
PENDING="$(call_cli_or_http_get_pending)"

if [[ -z "$PENDING" ]]; then
  both "ERROR: پاسخ خالی از get_pending"
  exit 1
fi
if ! printf '%s' "$PENDING" | jq -e . >/dev/null 2>&1; then
  both "ERROR: خروجی get_pending JSON نیست: $(printf '%s' "$PENDING" | head -c 200)"
  exit 1
fi
if [[ "$(jq -r '.success // false' <<<"$PENDING")" != "true" ]]; then
  both "ERROR: get_pending → $(jq -r '.error // "نامشخص"' <<<"$PENDING")"
  exit 1
fi

COUNT="$(jq -r '.count // 0' <<<"$PENDING")"
if [[ "$COUNT" -eq 0 ]]; then
  log "OK: پیام جدیدی نیست (lastSeenId=$(jq -r '.lastSeenId' <<<"$PENDING"))"
  exit 0
fi
both "QUEUE: $COUNT پست جدید (lastSeenId=$(jq -r '.lastSeenId' <<<"$PENDING"))"

# ---------- ۲) پردازش ترتیبی ----------
FAIL=0
DEFER=0
STOP=0
PROCESSED=0
REPORT=""
for ((i = 0; i < COUNT; i++)); do
  PAYLOAD="$(jq -c ".messages[$i]" <<<"$PENDING")"
  MID="$(jq -r '.id' <<<"$PAYLOAD")"
  MTYPE="$(jq -r '.mediaType // "text"' <<<"$PAYLOAD")"

  if [[ "$MODE" == "cli" ]]; then RESULT="$(call_cli sync_single "$PAYLOAD")"
  else                            RESULT="$(call_http sync_single "$PAYLOAD")"; fi

  SUCCESS="$(jq -r '.success // false' <<<"$RESULT" 2>/dev/null)"
  DEFERRED="$(jq -r '.deferred // false' <<<"$RESULT" 2>/dev/null)"

  # حالت تعویق: رسانه دانلود نشد → چیزی منتشر نشده و last_msg_id جلو نرفته است
  if [[ "$SUCCESS" != "true" && "$DEFERRED" == "true" ]]; then
    both "DEFER: پست $MID منتشر نشد — $(jq -r '.message // .reason // "?"' <<<"$RESULT" | head -c 200)"
    REPORT+="⏸ پست ${MID} [${MTYPE}]: رسانه دانلود نشد → منتشر نشد (صف متوقف شد تا last_msg_id از روی آن نپرد)"$'\n'
    DEFER=$((DEFER + 1))
    STOP=1
    break
  fi

  if [[ "$SUCCESS" != "true" ]]; then
    both "ERROR: پاسخ نامعتبر/ناموفق برای پست $MID: $(jq -r '.message // .error // "نامشخص"' <<<"$RESULT" 2>/dev/null | head -c 240)"
    REPORT+="❌ پست ${MID} [${MTYPE}]: ارسال ناموفق؛ صف متوقف شد"$'\n'
    FAIL=$((FAIL + 1))
    STOP=1
    break
  fi

  PROCESSED=$((PROCESSED + 1))

  B="$(jq -r '.bale.ok'    <<<"$RESULT")"
  R="$(jq -r '.rubika.ok'  <<<"$RESULT")"
  S="$(jq -r '.soroush.ok' <<<"$RESULT")"
  G="$(jq -r '.igap.ok'    <<<"$RESULT")"
  M="$(jq -r '.media.ok // true' <<<"$RESULT")"

  log "POST $MID [$MTYPE] → media=$M bale=$B rubika=$R soroush=$S igap=$G"
  [[ "$M" != "true" ]] && log "   ⚠️ رسانه: $(jq -r '.media.info // ""' <<<"$RESULT" | head -c 200)"
  [[ "$S" != "true" ]] && log "   soroush: $(jq -r '.soroush.info // ""' <<<"$RESULT" | head -c 240)"
  [[ "$G" != "true" ]] && log "   igap:    $(jq -r '.igap.info    // ""' <<<"$RESULT" | head -c 240)"
  [[ "$B$R$S$G" != *"true"* ]] && FAIL=$((FAIL + 1))

  mark() { [[ "$1" == "true" ]] && echo "✅" || echo "❌"; }
  REPORT+="🔹 پست ${MID} [${MTYPE}]: بله $(mark "$B") | روبیکا $(mark "$R") | سروش $(mark "$S") | آی‌گپ $(mark "$G")"
  [[ "$M" != "true" ]] && REPORT+=" | رسانه ❌"
  REPORT+=$'\n'

  # فاصلهٔ ایمن بین پست‌ها (نرخ‌محدودسازی پلتفرم‌ها)
  sleep "${SYNC_GAP_SEC:-5}"
done

# ---------- ۳) گزارش مدیریتی ----------
if [[ -n "$REPORT" ]]; then
  BODY="$(jq -n --arg r "$REPORT" '{report: [$r]}')"
  if [[ "$MODE" == "cli" ]]; then call_cli send_report "$BODY" >>"$LOG_FILE" 2>&1
  else                            call_http send_report "$BODY" >>"$LOG_FILE" 2>&1; fi
fi

if [[ "$STOP" -eq 1 ]]; then
  both "STOP: صف پس از $PROCESSED/$COUNT پست متوقف شد تا ترتیب state حفظ شود"
fi
both "DONE: $PROCESSED از $COUNT پست پردازش شد، $FAIL مورد با شکست کامل، $DEFER مورد تعویق‌شده"
# کد خروج: 0 = همه سبز، 3 = شکست ارسال، 4 = تعویق/توقف برای تلاش بعدی
if [[ "$FAIL" -gt 0 ]]; then exit 3; fi
[[ "$DEFER" -gt 0 || "$STOP" -gt 0 ]] && exit 4
exit 0
