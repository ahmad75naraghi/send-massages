#!/usr/bin/env bash
# ============================================================
#  setup_env.sh — ساخت و مهاجرت .env (یک‌بار در هر استقرار)
#
#    bash setup_env.sh              ساخت .env از .env.example + مهاجرت خودکار
#    bash setup_env.sh --force      بازنویسی .env موجود
#    bash setup_env.sh --show       فقط نمایش خلاصهٔ .env فعلی (مقدارها پوشیده)
#    bash setup_env.sh --check      بررسی اینکه کلیدهای ضروری مقدار دارند
#
#  چه می‌کند:
#   ۱) .env.example → .env (در همان دایرکتوری برنامه)
#   ۲) مقدارهای حساس را از درخت کاری و سپس از تاریخ Git بیرون می‌کشد
#      (تا مجبور نباشید توکن‌ها را دوباره تایپ کنید)
#   ۳) اگر SECURITY_KEY خالی بود، یک کلید تصادفی ۳۲ نویسه‌ای می‌سازد
#   ۴) مسیرها را با دایرکتوری واقعی برنامه هم‌تراز می‌کند
#   ۵) مجوز ۶۰۰ و مالکیت کاربر جاری را ست می‌کند
# ============================================================
set -uo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$APP_DIR/.env"
TEMPLATE="$APP_DIR/.env.example"
MODE="create"

case "${1:-}" in
  --force) MODE="force" ;;
  --show)  MODE="show" ;;
  --check) MODE="check" ;;
  "")      MODE="create" ;;
  *) echo "آرگومان ناشناخته: $1" >&2; exit 2 ;;
esac

KEYS=(
  SYNC_APP_DIR NODE_BIN SYNC_CHROMIUM_BIN PHP_BIN
  SECURITY_KEY DASHBOARD_ALLOWED_IP
  EITAA_CHANNEL_ID MAX_MESSAGES_LIMIT
  BALE_BOT_TOKEN BALE_CHANNEL_ID BALE_ADMIN_CHAT_ID
  RUBIKA_BOT_TOKEN RUBIKA_CHANNEL_ID RUBIKA_CHAT_ID_GUID RUBIKA_CHAT_ID_USER
  SOROUSH_CHANNEL_ID SOROUSH_CHANNEL_NAME SOROUSH_SCRIPT SOROUSH_PROFILE_DIR
  ENABLE_SOROUSH_BOT SOROUSH_BOT_TOKEN SOROUSH_CHAT_ID
  IGAP_CHANNEL_ID IGAP_CHANNEL_NAME IGAP_ITEM_ID IGAP_SCRIPT IGAP_PROFILE_DIR
  MAIN_BALE_CHANNEL_ID TEST_BALE_CHANNEL_ID MAIN_RUBIKA_CHANNEL_ID TEST_RUBIKA_CHANNEL_ID
  MAIN_SOROUSH_CHANNEL_ID MAIN_SOROUSH_CHANNEL_NAME TEST_SOROUSH_CHANNEL_ID TEST_SOROUSH_CHANNEL_NAME
  MAIN_IGAP_CHANNEL_ID MAIN_IGAP_CHANNEL_NAME MAIN_IGAP_ITEM_ID TEST_IGAP_CHANNEL_ID TEST_IGAP_CHANNEL_NAME TEST_IGAP_ITEM_ID
  USERBOT_TIMEOUT_SEC IGAP_INITIAL_WAIT_MS IGAP_VERIFY_TIMEOUT_MS MEDIA_MAX_RETRY SYNC_GAP_SEC CHECK_INTERVAL_SEC SYNC_BASE_URL
  SYNC_USER_AGENT SYNC_HEADED SYNC_DEBUG
  STATE_DB_PATH LOG_DIR
)
SECRET_KEYS=(SECURITY_KEY BALE_BOT_TOKEN BALE_ADMIN_CHAT_ID RUBIKA_BOT_TOKEN RUBIKA_CHAT_ID_GUID SOROUSH_BOT_TOKEN SOROUSH_CHAT_ID)
# این کلیدها هرگز از تاریخ Git مهاجرت داده نمی‌شوند:
#   SECURITY_KEY → مقدار قدیمی «1» بود و باید تصادفی و قوی ساخته شود
NO_MIGRATE=(SECURITY_KEY)

mask() {
  local v="$1" n=${#1}
  if [[ "$n" -eq 0 ]]; then echo "(خالی)"; return; fi
  if [[ "$n" -le 6 ]]; then printf '%s' "******"; return; fi
  printf '%s…%s (%d نویسه)' "${v:0:3}" "${v: -3}" "$n"
}

is_secret() { local k="$1"; for s in "${SECRET_KEYS[@]}"; do [[ "$s" == "$k" ]] && return 0; done; return 1; }

no_migrate() { local k="$1"; for s in "${NO_MIGRATE[@]}"; do [[ "$s" == "$k" ]] && return 0; done; return 1; }

# مقدار معتبر است؟ (تکه‌کد PHP/JS نباشد)
sane_value() {
  local v="$1"
  [[ -z "$v" ]] && return 1
  case "$v" in *'<?'*|*'$'*|*'<?= '*) return 1 ;; esac
  return 0
}

read_env_value() {  # خواندن مقدار فعلی از .env (کوتیشن و کامنت انتهایی حذف می‌شود)
  [[ -r "$ENV_FILE" ]] || return 0
  local v
  v="$(sed -nE "s/^(export[[:space:]]+)?${1}=([^\r]*)$/\2/p" "$ENV_FILE" | tail -1)"
  v="${v%"${v##*[![:space:]]}"}"                       # trim راست
  case "$v" in
    '"'*'"')   v="${v:1:${#v}-2}" ;;                    # کوتیشن دوجمله‌ای
    "'"*"'" ) v="${v:1:${#v}-2}" ;;                     # کوتیشن تکی
    *)        v="${v%%[[:space:]]#*}"; v="${v%"${v##*[![:space:]]}"}" ;;
  esac
  printf '%s' "$v"
}

quote_if_needed() {  # اگر مقدار فاصله داشت، در کوتیشن دوجمله‌ای بپیچ
  # (نام کانال فارسی مثل «شمیم آشنا» فاصله دارد؛ بدون کوتیشن، `source .env`
  #  کلمهٔ دوم را دستور فرض می‌کند. لودرهای config.php / pw_common.js /
  #  lib/dotenv.sh کوتیشن را خودشان حذف می‌کنند.)
  local v="$1"
  case "$v" in
    '"'*'"')  printf '%s' "$v" ;;                       # قبلاً کوتیشن دارد
    *[[:space:]]*) printf '"%s"' "$v" ;;                 # فاصله دارد → بپیچ
    *)        printf '%s' "$v" ;;
  esac
}

set_env_value() {   # $1=کلید $2=مقدار
  local k="$1" v="$2"
  [[ -f "$ENV_FILE" ]] || return 1
  # مقدارهای دارای فاصله (مثل نام فارسی کانال) باید کوتیشن داشته باشند تا
  # حتی `source .env` هم نشکند؛ لودرهای config.php / pw_common.js / dotenv.sh
  # کوتیشن را خودشان حذف می‌کنند.
  v="$(quote_if_needed "$v")"
  if grep -qE "^${k}=" "$ENV_FILE"; then
    # استفاده از فایل موقت تا از sed -i با مقدارهای دارای کاراکتر خاص آسیب نبینیم
    local tmp; tmp="$(mktemp)"
    awk -v k="$k" -v v="$v" 'BEGIN{FS=OFS=""} { if ($0 ~ "^"k"=") print k"="v; else print $0 }' "$ENV_FILE" >"$tmp" && mv "$tmp" "$ENV_FILE"
  else
    printf '%s=%s\n' "$k" "$v" >>"$ENV_FILE"
  fi
}

legacy_value() {    # بیرون کشیدن مقدار یک const از درخت کاری یا تاریخ Git
  local key="$1" v=""
  # الف) درخت کاری فعلی (پیش از مهاجرت، const ها داخل خود فایل‌ها بودند)
  v=$(grep -rhoE "const[[:space:]]+${key}[[:space:]]*=[[:space:]]*'[^']*'" --include='*.php' "$APP_DIR" 2>/dev/null \
      | sed -nE "s/.*'([^']*)'.*/\1/p" | grep -v '^$' | grep -vF '<?' | grep -vF '$' | head -1)
  # ب) تاریخ Git
  if [[ -z "$v" ]] && git -C "$APP_DIR" rev-parse --git-dir >/dev/null 2>&1; then
    while read -r rev; do
      [[ -z "$rev" ]] && continue
      v=$(git -C "$APP_DIR" grep -hE "const[[:space:]]+${key}[[:space:]]*=[[:space:]]*'[^']*'" "$rev" -- '*.php' 2>/dev/null \
          | sed -nE "s/.*'([^']*)'.*/\1/p" | grep -v '^$' | grep -vF '<?' | grep -vF '$' | head -1)
      [[ -n "$v" ]] && break
    done < <(git -C "$APP_DIR" rev-list --all --max-count=25 2>/dev/null)
  fi
  printf '%s' "$v"
}

random_key() {
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -hex 16
  else
    head -c 48 /dev/urandom | tr -dc 'A-Za-z0-9' | head -c 32
  fi
}

# ---------- حالت نمایش / بررسی ----------
if [[ "$MODE" == "show" || "$MODE" == "check" ]]; then
  if [[ ! -r "$ENV_FILE" ]]; then
    echo "❌ فایل .env وجود ندارد: $ENV_FILE"
    echo "   اجرا کنید:  bash setup_env.sh"
    exit 1
  fi
  echo "=== $ENV_FILE ==="
  missing=0
  for k in "${KEYS[@]}"; do
    v="$(read_env_value "$k")"
    if is_secret "$k"; then printf '  %-24s %s\n' "$k" "$(mask "$v")"; else printf '  %-24s %s\n' "$k" "${v:-(خالی)}"; fi
    if [[ -z "$v" ]]; then
      case "$k" in
        BALE_BOT_TOKEN|RUBIKA_BOT_TOKEN|BALE_ADMIN_CHAT_ID) missing=$((missing+1)); echo "      ⚠️ این کلید ضروری است" ;;
      esac
    fi
  done
  if [[ "$MODE" == "check" ]]; then
    sk="$(read_env_value SECURITY_KEY)"
    if [[ -z "$sk" ]]; then echo "❌ SECURITY_KEY خالی است — داشبورد بالا نمی‌آید"; exit 1; fi
    if [[ ${#sk} -lt 16 ]]; then echo "⚠️ SECURITY_KEY کوتاه است (${#sk} نویسه)؛ پیشنهاد: حداقل ۳۲ نویسه"; fi
    perm="$(stat -c '%a' "$ENV_FILE" 2>/dev/null || echo '?')"
    [[ "$perm" != "600" && "$perm" != "400" ]] && echo "⚠️ مجوز .env برابر $perm است؛ باید 600 باشد"
    [[ "$missing" -gt 0 ]] && { echo "❌ $missing کلید ضروری خالی است"; exit 1; }
    echo "✅ .env معتبر است"
  fi
  exit 0
fi

# ---------- ساخت .env ----------
if [[ -f "$ENV_FILE" && "$MODE" != "force" ]]; then
  echo "ℹ️  فایل .env از قبل وجود دارد؛ برای بازنویسی: bash setup_env.sh --force"
  echo "    نمایش خلاصه:  bash setup_env.sh --show"
  exit 0
fi

if [[ ! -r "$TEMPLATE" ]]; then
  echo "❌ الگوی .env.example پیدا نشد: $TEMPLATE" >&2
  exit 1
fi

[[ -f "$ENV_FILE" ]] && cp -f "$ENV_FILE" "$ENV_FILE.bak.$(date +%F_%H%M%S)"
cp -f "$TEMPLATE" "$ENV_FILE"
chmod 600 "$ENV_FILE"
echo "[+] ساخته شد: $ENV_FILE (از .env.example)"

# ---------- هم‌ترازی مسیرها با دایرکتوری واقعی ----------
set_env_value SYNC_APP_DIR       "$APP_DIR"
set_env_value SOROUSH_SCRIPT     "$APP_DIR/send_soroush.js"
set_env_value SOROUSH_PROFILE_DIR "$APP_DIR/soroush_profile"
set_env_value IGAP_SCRIPT        "$APP_DIR/send_igap.js"
set_env_value IGAP_PROFILE_DIR   "$APP_DIR/igap_profile"
set_env_value STATE_DB_PATH      "$APP_DIR/state.sqlite"
set_env_value LOG_DIR            "$APP_DIR/logs"
mkdir -p "$APP_DIR/logs"

# ---------- مهاجرت مقدارها ----------
migrated=0; prompted=0
for k in "${KEYS[@]}"; do
  current="$(read_env_value "$k")"
  [[ -n "$current" ]] && continue
  if no_migrate "$k"; then continue; fi
  v="$(legacy_value "$k")"
  if ! sane_value "$v"; then v=""; fi
  if [[ -n "$v" ]]; then
    set_env_value "$k" "$v"
    migrated=$((migrated+1))
    printf '  ✓ %-24s %s\n' "$k" "$(mask "$v")"
    continue
  fi
  # کلیدهای حیاتی: پرسش تعاملی (اگر ترمینال در دسترس است)
  if [[ -t 0 ]] && { is_secret "$k" || [[ "$k" == "BALE_CHANNEL_ID" ]]; }; then
    printf '  ? %-24s مقدار را وارد کنید (خالی = رد شدن): ' "$k"
    read -r answer
    if [[ -n "$answer" ]]; then
      set_env_value "$k" "$answer"; prompted=$((prompted+1))
      printf '    ✓ ثبت شد (%s)\n' "$(mask "$answer")"
    fi
  fi
done

# ---------- SECURITY_KEY تصادفی در صورت نبود ----------
sk="$(read_env_value SECURITY_KEY)"
if [[ -z "$sk" ]]; then
  sk="$(random_key)"
  set_env_value SECURITY_KEY "$sk"
  echo "  ✓ SECURITY_KEY تصادفی ساخته شد: $(mask "$sk")"
fi

# ---------- مجوزها ----------
chmod 600 "$ENV_FILE"
chown "$(id -u):$(id -g)" "$ENV_FILE" 2>/dev/null || true

# ---------- فایل کلید برای حالت HTTP در cron ----------
if [[ ! -f "$APP_DIR/.cron_key" ]]; then
  printf '%s\n' "$sk" >"$APP_DIR/.cron_key"
  chmod 600 "$APP_DIR/.cron_key"
  echo "[+] ساخته شد: .cron_key (برای حالت HTTP در cron_sync.sh)"
fi

echo
echo "-----------------------------------------"
echo "  مهاجرت خودکار: $migrated کلید | ورودی دستی: $prompted کلید"
echo "  نمایش خلاصه:   bash setup_env.sh --show"
echo "  اعتبارسنجی:     bash setup_env.sh --check"
echo
echo "⚠️  دو نکتهٔ امنیتی:"
echo "   ۱) این توکن‌ها در تاریخ Git نیز وجود دارند؛ پس از استقرار حتماً آنها را"
echo "      در پنل هر پیام‌رسان «چرخش» (revoke + ساخت مجدد) کنید."
echo "   ۲) برای حذف کامل از تاریخ: git filter-repo یا BFG (پس از آن force push)."
echo
echo "گام بعدی:  php -l config.php && php -l sync_manual.php"
echo "           ACTION=get_pending php cli_run.php"
