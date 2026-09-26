#!/usr/bin/env bash
# ============================================================
#  scheduler_tick.sh — تیکِ زمان‌بند صف پس‌زمینه (هر دقیقه)
#
#  نصب (فقط همین یک خط، یک‌بار برای همیشه — از cPanel → Cron Jobs
#  یا crontab -e در SSH):
#      * * * * * /home/file/public_html/s/scheduler_tick.sh
#
#  بعد از نصب، افزودن/حذف/غیرفعال‌کردن ساعت‌ها فقط از داشبورد
#  (پنل «زمان‌بندی خودکار») انجام می‌شود؛ این خط دیگر تغییر نمی‌کند.
#
#  کاری که می‌کند: ACTION=scheduler_tick را به cli_run.php می‌دهد.
#  خودِ تیک سبک است (چک ساعت + heartbeat)؛ اگر ساعتی سررسید باشد،
#  همان مسیر دکمهٔ «اجرای صف پس‌زمینه» را صدا می‌زند، یعنی همان
#  موتور، همان قفل‌ها (flock مشترک با cron_sync.sh) و همان پیشرفت
#  زنده در داشبورد. هر اسلات حداکثر یک‌بار در روز شلیک می‌شود.
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

# همان ترتیبِ تشخیص PHP در cron_sync.sh (اولویت: PHP_BIN از .env)
PHP_BIN="${PHP_BIN:-}"
if [[ -z "$PHP_BIN" ]]; then
  for c in ea-php83 ea-php82 ea-php81 php; do
    if command -v "$c" >/dev/null 2>&1; then PHP_BIN="$(command -v "$c")"; break; fi
  done
fi
if [[ -z "$PHP_BIN" && -x /opt/cpanel/ea-php81/root/usr/bin/php ]]; then
  PHP_BIN=/opt/cpanel/ea-php81/root/usr/bin/php
fi
if [[ -z "$PHP_BIN" ]]; then
  mkdir -p "$LOG_DIR"
  printf '[%s] scheduler_tick: هیچ باینری PHP پیدا نشد (PHP_BIN را در .env تنظیم کنید)\n' \
    "$(date '+%Y-%m-%d %H:%M:%S')" >>"$LOG_DIR/scheduler.log"
  exit 1
fi

mkdir -p "$LOG_DIR"
ACTION=scheduler_tick "$PHP_BIN" -f "$APP_DIR/cli_run.php" >>"$LOG_DIR/scheduler.log" 2>&1
exit $?
