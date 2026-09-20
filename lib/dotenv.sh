#!/usr/bin/env bash
# ============================================================
#  lib/dotenv.sh — خواندن امن فایل .env در Bash
#
#  چرا این فایل؟ دستور `source .env` روی مقدارهای بدون کوتیشن که
#  فاصله دارند (مثل SOROUSH_CHANNEL_NAME=شمیم آشنا) خطا می‌دهد:
#  bash فقط کلمهٔ اول را به متغیر می‌دهد و بقیه را «دستور» فرض می‌کند
#  (command not found) و با `set -e` کل اسکریپت می‌میرد.
#  این لودر خط‌به‌خط می‌خواند و هیچ دستوری را اجرا نمی‌کند.
#
#  قواعد (یکسان با config.php و lib/pw_common.js):
#    - `KEY=value` و `export KEY=value`
#    - کوتیشن تکی/دوجمله‌ای حذف می‌شود
#    - کامنت `#` در ابتدای خط نادیده گرفته می‌شود
#    - کامنت انتهایی فقط در مقدارِ بدون کوتیشن جدا می‌شود
#    - متغیر محیطیِ از قبل ست‌شده هرگز بازنویسی نمی‌شود
#      (تا systemd / cron بتواند override کند)
#
#  استفاده:
#      source "$(dirname "${BASH_SOURCE[0]}")/lib/dotenv.sh"
#      APP_DIR="$(dotenv_app_dir)"          # دایرکتوری برنامه
#      load_dotenv "$APP_DIR/.env"          # صدور همهٔ کلیدها
#      TOKEN="$(dotenv_get BALE_BOT_TOKEN)" # خواندن یک کلید (بدون صدور)
# ============================================================

# مقدار یک کلید از یک فایل .env (خروجی: رشته؛ اگر نبود، خالی)
dotenv_read() {
  local file="$1" key="$2" line k v
  [[ -r "$file" ]] || return 0
  while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line%$'\r'}"
    # حذف فاصله‌های ابتدایی
    line="${line#"${line%%[![:space:]]*}"}"
    [[ -z "$line" || "$line" == '#'* || "$line" == ';'* ]] && continue
    line="${line#export }"
    [[ "$line" != *=* ]] && continue
    k="${line%%=*}"
    v="${line#*=}"
    k="${k%"${k##*[![:space:]]}"}"          # trim راست کلید
    [[ "$k" == "$key" ]] || continue
    v="${v%"${v##*[![:space:]]}"}"          # trim راست مقدار
    if [[ ${#v} -ge 2 && ( ( "$v" == '"'* && "$v" == *'"' ) || ( "$v" == "'"* && "$v" == *"'" ) ) ]]; then
      v="${v:1:${#v}-2}"                     # حذف کوتیشن جفت‌شده
    else
      v="${v%%[[:space:]]#*}"                # حذف کامنت انتهایی
      v="${v%"${v##*[![:space:]]}"}"
    fi
    printf '%s' "$v"
    return 0
  done < "$file"
  return 0
}

# صدور همهٔ کلیدهای یک فایل .env به محیط (بدون اجرای هیچ دستوری)
load_dotenv() {
  local file="${1:-.env}" line k v
  [[ -r "$file" ]] || return 0
  while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line%$'\r'}"
    line="${line#"${line%%[![:space:]]*}"}"
    [[ -z "$line" || "$line" == '#'* || "$line" == ';'* ]] && continue
    line="${line#export }"
    [[ "$line" != *=* ]] && continue
    k="${line%%=*}"
    k="${k%"${k##*[![:space:]]}"}"
    [[ "$k" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || continue
    v="$(dotenv_read "$file" "$k")"
    # متغیر محیطی واقعی اولویت دارد
    if [[ -n "${!k+x}" ]]; then continue; fi
    export "$k=$v"
  done < "$file"
  DOTENV_FILE="$file"
  return 0
}

# دایرکتوری برنامه: محیط > SYNC_APP_DIR در .env > دایرکتوری خود اسکریپت‌ها
dotenv_app_dir() {
  local self d envfile v
  self="${BASH_SOURCE[1]:-${BASH_SOURCE[0]}}"
  d="$(cd -- "$(dirname -- "$self")" 2>/dev/null && pwd)" || d="$PWD"
  # اگر اسکریپت داخل lib/ است، یک سطح بالا برو
  [[ "$(basename -- "$d")" == "lib" ]] && d="$(dirname -- "$d")"
  envfile="${SYNC_ENV_FILE:-$d/.env}"
  if [[ -n "${SYNC_APP_DIR:-}" ]]; then
    printf '%s' "$SYNC_APP_DIR"; return 0
  fi
  v="$(dotenv_read "$envfile" SYNC_APP_DIR)"
  if [[ -n "$v" && -d "$v" ]]; then printf '%s' "$v"; return 0; fi
  printf '%s' "$d"
}

# خلاصهٔ وضعیت یک کلید برای لاگ/تشخیص — هرگز مقدار را چاپ نمی‌کند
dotenv_status() {
  local key="$1" v="${2-}"
  if [[ -z "$v" ]]; then v="${!key:-}"; fi
  if [[ -z "$v" ]]; then printf '%s: EMPTY' "$key"; return 0; fi
  printf '%s: SET(len=%d)' "$key" "${#v}"
}
