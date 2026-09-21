#!/usr/bin/env bash
# ============================================================
#  diagnose_userbot.sh — تشخیص چرا سروش‌پلاس / آی‌گپ ارسال نمی‌کنند
#
#    bash diagnose_userbot.sh              بررسی کامل + یک ارسال آزمایشی برای هر دو
#    bash diagnose_userbot.sh --no-send    فقط بررسی محیط (ارسالی انجام نمی‌شود)
#    bash diagnose_userbot.sh --platform=soroush   فقط سروش
#    bash diagnose_userbot.sh --platform=igap      فقط آی‌گپ
#
#  چه چیزی چاپ می‌کند:
#   ۱) پیکربندی واقعیِ خوانده‌شده از .env (مسیرها، باینری‌ها، نام کانال‌ها — بدون توکن)
#   ۲) بررسی‌های محیطی (Node، playwright، کرومیوم، پروفایل، مجوز، قفل، /dev/shm)
#   ۳) خروجی JSON اسکریپت ارسال + کد خطا + تشخیص معنای آن کد
#   ۴) ۲۵ خط آخر لاگ همان اجرا + مسیر اسکرین‌شات شاهد
#
#  نکته: این اسکریپت هیچ توکنی چاپ نمی‌کند.
# ============================================================
set -uo pipefail

SELF_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/dotenv.sh
[[ -r "$SELF_DIR/lib/dotenv.sh" ]] && source "$SELF_DIR/lib/dotenv.sh"

APP_DIR="${SYNC_APP_DIR:-}"
if [[ -z "$APP_DIR" ]]; then
  if declare -F dotenv_app_dir >/dev/null 2>&1; then APP_DIR="$(dotenv_app_dir)"; else APP_DIR="$SELF_DIR"; fi
fi
cd "$APP_DIR" || { echo "🔴 دایرکتوری برنامه پیدا نشد: $APP_DIR"; exit 1; }

SEND=1
PLATFORMS="soroush igap"
for a in "$@"; do
  case "$a" in
    --no-send) SEND=0 ;;
    --platform=soroush) PLATFORMS="soroush" ;;
    --platform=igap)    PLATFORMS="igap" ;;
    --platform=both)    PLATFORMS="soroush igap" ;;
    *) echo "آرگومان ناشناخته: $a" >&2; exit 2 ;;
  esac
done

if [[ -r "${SYNC_ENV_FILE:-$APP_DIR/.env}" ]]; then
  if declare -F load_dotenv >/dev/null 2>&1; then load_dotenv "${SYNC_ENV_FILE:-$APP_DIR/.env}"
  else set -a; . "${SYNC_ENV_FILE:-$APP_DIR/.env}"; set +a; fi
fi

NODE_BIN="${NODE_BIN:-node}"
CHROME_BIN="${SYNC_CHROMIUM_BIN:-/usr/bin/chromium-browser}"
[[ -x "$CHROME_BIN" ]] || for c in /usr/bin/chromium-browser /usr/bin/chromium /usr/bin/google-chrome-stable /usr/bin/google-chrome; do
  [[ -x "$c" ]] && { CHROME_BIN="$c"; break; }; done
SOROUSH_PROFILE_DIR="${SOROUSH_PROFILE_DIR:-$APP_DIR/soroush_profile}"
IGAP_PROFILE_DIR="${IGAP_PROFILE_DIR:-$APP_DIR/igap_profile}"
LOG_DIR="${LOG_DIR:-$APP_DIR/logs}"
RUN_USER="$(id -un)"
WEB_USER="${SUDO_USER:-$RUN_USER}"

ok()   { printf '  ✅ %s\n' "$*"; }
bad()  { printf '  ❌ %s\n' "$*"; }
warn() { printf '  ⚠️  %s\n' "$*"; }
info() { printf '  •  %s\n' "$*"; }
head1(){ printf '\n\033[1m%s\033[0m\n' "$*"; }

echo "============================================================"
echo " diagnose_userbot.sh — $APP_DIR"
echo " کاربر جاری: $RUN_USER   تاریخ: $(date '+%F %T')"
echo "============================================================"

# ---------- ۱) پیکربندی خوانده‌شده از .env ----------
head1 "۱) پیکربندی (از .env)"
if [[ -r "$APP_DIR/.env" ]]; then
  ok ".env خوانده شد ($(stat -c '%a %U:%G' "$APP_DIR/.env" 2>/dev/null))"
else
  bad ".env خوانده نشد — bash setup_env.sh را اجرا کنید"
fi
info "NODE_BIN            = $NODE_BIN"
info "SYNC_CHROMIUM_BIN   = $CHROME_BIN"
info "SYNC_APP_DIR        = $APP_DIR"
info "SOROUSH_CHANNEL_ID  = ${SOROUSH_CHANNEL_ID:-«خالی»}"
info "SOROUSH_CHANNEL_NAME= ${SOROUSH_CHANNEL_NAME:-«خالی»}"
info "SOROUSH_PROFILE_DIR = $SOROUSH_PROFILE_DIR"
info "IGAP_CHANNEL_ID     = ${IGAP_CHANNEL_ID:-«خالی»}"
info "IGAP_CHANNEL_NAME   = ${IGAP_CHANNEL_NAME:-«خالی»}"
info "IGAP_ITEM_ID        = ${IGAP_ITEM_ID:-«خالی»}"
info "IGAP_PROFILE_DIR    = $IGAP_PROFILE_DIR"
info "USERBOT_TIMEOUT_SEC = ${USERBOT_TIMEOUT_SEC:-240}"
if [[ -z "${SECURITY_KEY:-}" ]]; then
  bad "SECURITY_KEY خالی است ⇒ داشبورد بالا نمی‌آید"
elif [[ "${#SECURITY_KEY}" -lt 16 || "$SECURITY_KEY" == "1" ]]; then
  bad "SECURITY_KEY ضعیف است («${SECURITY_KEY}») ⇒ هر کسی می‌تواند در کانال‌های شما پست بگذارد"
  info "راه‌حل: NEWKEY=\$(openssl rand -hex 16); sed -i \"s/^SECURITY_KEY=.*/SECURITY_KEY=\$NEWKEY/\" .env; printf '%s\\n' "\$NEWKEY" > .cron_key; chmod 600 .env .cron_key"
else
  ok "SECURITY_KEY تنظیم شده (${#SECURITY_KEY} نویسه)"
fi
[[ -z "${BALE_ADMIN_CHAT_ID:-}" ]] && warn "BALE_ADMIN_CHAT_ID خالی است ⇒ گزارش مدیریتی به بله ارسال نمی‌شود"

# ---------- ۲) بررسی‌های محیطی ----------
head1 "۲) محیط زمان اجرا"

if command -v "$NODE_BIN" >/dev/null 2>&1 || [[ -x "$NODE_BIN" ]]; then
  ok "Node: $("$NODE_BIN" -v 2>/dev/null) در $NODE_BIN"
else
  bad "Node در مسیر پیکربندی‌شده نیست: $NODE_BIN"
  info "راه‌حل: command -v node   و سپس اصلاح NODE_BIN در .env"
fi

if (cd "$APP_DIR" && "$NODE_BIN" -e "require('playwright')" >/dev/null 2>&1); then
  ok "ماژول playwright قابل require است"
else
  bad "ماژول playwright پیدا نشد (node_modules/playwright غایب یا ناقص)"
  info "راه‌حل: bash restore_runtime.sh      (بازیابی از تاریخ Git، بدون اینترنت)"
  info "     یا: PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 npm install --no-audit --no-fund"
fi

if [[ -x "$CHROME_BIN" ]]; then
  ok "کرومیوم: $CHROME_BIN ($("$CHROME_BIN" --version 2>/dev/null | head -1))"
  MISS="$(ldd "$CHROME_BIN" 2>/dev/null | grep -c 'not found')"
  if [[ "$MISS" == "0" ]]; then ok "وابستگی‌های کتابخانه‌ای کرومیوم کامل است"
  else bad "$MISS کتابخانهٔ کرومیوم پیدا نشد:"; ldd "$CHROME_BIN" 2>/dev/null | grep 'not found' | sed 's/^/       /'; fi
else
  bad "کرومیوم اجراشدنی نیست: $CHROME_BIN"
  info "راه‌حل: ls /usr/bin/chromium* /usr/bin/google-chrome*   و اصلاح SYNC_CHROMIUM_BIN در .env"
fi

if fc-list :lang=fa 2>/dev/null | grep -qi .; then ok "فونت فارسی نصب است"
else warn "فونت فارسی نصب نیست — متن فارسی در مرورگر مربع/ناخوانا می‌شود (ارسال ممکن است هنوز کار کند)"; fi

SHM="$(df -k /dev/shm 2>/dev/null | awk 'NR==2{print int($2/1024)}')"
[[ -n "$SHM" ]] && { [[ "$SHM" -ge 512 ]] && ok "/dev/shm = ${SHM}MB" || warn "/dev/shm فقط ${SHM}MB — کرومیوم headless ممکن است crash کند (راه‌حل: --disable-dev-shm-usage یا افزایش آن)"; }

# ---------- ۳) پروفایل‌ها، مجوزها و قفل‌ها ----------
head1 "۳) پروفایل مرورگر (session) و مجوزها"

check_profile() {  # $1=نام $2=مسیر $3=اسکریپت ورود
  local name="$1" dir="$2" login="$3"
  printf '\n  [%s] %s\n' "$name" "$dir"
  if [[ ! -d "$dir" ]]; then
    bad "دایرکتوری پروفایل وجود ندارد ⇒ session نیست"
    info "راه‌حل: bash restore_runtime.sh   (اگر با git checkout پاک شده)"
    info "     یا: $NODE_BIN $login   (ورود مجدد با شماره و کد OTP)"
    return
  fi
  info "اندازه: $(du -sh "$dir" 2>/dev/null | cut -f1) | مجوز: $(stat -c '%a %U:%G' "$dir" 2>/dev/null)"
  if [[ -w "$dir" ]]; then ok "برای کاربر جاری ($RUN_USER) نوشتنی است"
  else bad "نوشتنی نیست ⇒ کرومیوم «Permission denied (13)» می‌دهد"; 
       info "راه‌حل: chown -R $WEB_USER:$WEB_USER \"$dir\" && chmod 700 \"$dir\""; fi
  if [[ -n "$(find "$dir" -maxdepth 1 -name 'Singleton*' -print -quit 2>/dev/null)" ]]; then
    warn "قفل Singleton باقی مانده (اجرای قبلی crash شده) — پیش از هر اجرا پاک می‌شود:"
    find "$dir" -maxdepth 1 -name 'Singleton*' -printf '       %p → %u:%g\n' 2>/dev/null
    info "راه‌حل دستی: find \"$dir\" -maxdepth 1 -name 'Singleton*' -delete"
  else
    ok "قفل Singleton باقی‌مانده ندارد"
  fi
  # نشانه‌های وجود session واقعی
  local cookies=0
  [[ -f "$dir/Default/Cookies" ]] && cookies=1
  [[ -f "$dir/Default/Network/Cookies" ]] && cookies=1
  if [[ "$cookies" == "1" ]]; then ok "فایل Cookies وجود دارد (نشانهٔ session)"
  else warn "فایل Cookies پیدا نشد ⇒ احتمالاً هرگز ورود انجام نشده یا پروفایل خالی است"; 
       info "راه‌حل: $NODE_BIN $login"; fi
  # آخرین تغییر پروفایل = نشانهٔ تازگی session
  info "آخرین تغییر پروفایل: $(find "$dir" -type f -printf '%T@ %TY-%Tm-%Td %TH:%TM\n' 2>/dev/null | sort -rn | head -1 | cut -d' ' -f2-)"
}

for p in $PLATFORMS; do
  if [[ "$p" == "soroush" ]]; then check_profile "سروش‌پلاس" "$SOROUSH_PROFILE_DIR" "login_soroush.js"
  else check_profile "آی‌گپ" "$IGAP_PROFILE_DIR" "login_igap.js"; fi
done

# فرایندهای رقیب: دو اجرا روی یک پروفایل = شکست
head1 "۴) فرایندهای رقیب"
N_CHROME=0
for pdir in /proc/[0-9]*; do
  exe="$(readlink -f "$pdir/exe" 2>/dev/null)" || continue
  case "$exe" in *chromium*|*chrome*|*headless_shell*) N_CHROME=$((N_CHROME+1));; esac
done
if [[ "$N_CHROME" -eq 0 ]]; then ok "کرومیومی در حال اجرا نیست (رقابتی روی پروفایل نیست)"
else warn "$N_CHROME فرایند کرومیوم در حال اجراست — اگر sync دیگری فعال است، هم‌زمان روی یک پروفایل نروند"; 
     info "اگر یتیم است: pkill -f chromium-browser"; fi
if pgrep -f 'cron_sync.sh|sync_daemon.php' >/dev/null 2>&1; then
  warn "یک زمان‌بند (cron_sync/sync_daemon) در حال اجراست — ممکن است با آزمون شما رقابت کند"
else
  ok "زمان‌بندی در حال اجرا نیست"
fi

if [[ ! -d "$LOG_DIR" ]]; then
  mkdir -p "$LOG_DIR" 2>/dev/null && ok "logs/ ساخته شد: $LOG_DIR" || bad "logs/ وجود ندارد و ساخته هم نشد ⇒ لاگ اجرا ثبت نمی‌شود"
elif [[ -w "$LOG_DIR" ]]; then
  ok "logs/ نوشتنی است: $LOG_DIR"
else
  bad "logs/ نوشتنی نیست ⇒ لاگ اجرا ثبت نمی‌شود"
  info "راه‌حل: chown -R $WEB_USER:$WEB_USER "$LOG_DIR" && chmod 750 "$LOG_DIR""
fi

# ---------- ۵) ارسال آزمایشی ----------
if [[ "$SEND" == "0" ]]; then
  head1 "۵) ارسال آزمایشی"
  info "--no-send داده شد؛ ارسال انجام نشد."
  exit 0
fi

diagnose_code() {  # $1 = کد خطا
  case "$1" in
    NODE_DEPS_MISSING)   echo "       ⇒ وابستگی Node نیست. رفع: bash restore_runtime.sh" ;;
    SESSION_EXPIRED)     echo "       ⇒ session پریده/منقضی است. رفع: ورود مجدد با login_soroush.js یا login_igap.js" ;;
    CHANNEL_NOT_FOUND)   echo "       ⇒ کانال باز یا تأیید نشد. رفع: نام نمایشی دقیق کانال را در .env بگذارید (SOROUSH_CHANNEL_NAME/IGAP_CHANNEL_NAME) و برای آی‌گپ IGAP_ITEM_ID را از data-list-item-id سل کانال بردارید" ;;
    ATTACH_BUTTON_NOT_FOUND|ATTACH_MENU_NOT_OPEN|ATTACH_MENU_ITEM_NOT_FOUND|FILE_INJECT_FAILED)
                         echo "       ⇒ وب‌کلاینت عوض شده یا رسانه تزریق نشد. رفع: اسکرین‌شات شاهد را ببینید و dump_dom.js بگیرید" ;;
    SEND_NOT_VERIFIED)   echo "       ⇒ ارسال شد ولی هدر کانال تأیید نشد ⇒ احتمالاً به چت اشتباه رفته. کانال را چک کنید" ;;
    TIMEOUT)             echo "       ⇒ اجرا از کرانهٔ زمانی گذشت. رفع: USERBOT_TIMEOUT_SEC را در .env بالا ببرید و شبکه/کندی وب‌کلاینت را بررسی کنید" ;;
    NO_CHANNEL)          echo "       ⇒ شناسه/نام کانال در .env خالی است" ;;
    EMPTY_PAYLOAD)       echo "       ⇒ متن و فایل هر دو خالی به اسکریپت رسیده‌اند" ;;
    FILE_MISSING)        echo "       ⇒ مسیر فایل رسانه وجود ندارد" ;;
    RUNTIME)             echo "       ⇒ خطای اجرا (معمولاً نبودِ کرومیوم، مجوز پروفایل یا crash). متن خطا و لاگ را ببینید" ;;
    *)                   echo "       ⇒ کد ناشناخته؛ لاگ کامل را ببینید" ;;
  esac
}

run_one() {  # $1=soroush|igap
  local p="$1" script name shot log
  if [[ "$p" == "soroush" ]]; then
    script="${SOROUSH_SCRIPT:-$APP_DIR/send_soroush.js}"
    [[ "$script" != /* ]] && script="$APP_DIR/$script"
    name="سروش‌پلاس"; shot="$APP_DIR/last_media_send.jpg"
  else
    script="${IGAP_SCRIPT:-$APP_DIR/send_igap.js}"
    [[ "$script" != /* ]] && script="$APP_DIR/$script"
    name="آی‌گپ"; shot="$APP_DIR/last_igap_send.jpg"
  fi

  head1 "۵) ارسال آزمایشی — $name"
  if [[ ! -f "$script" ]]; then bad "اسکریپت پیدا نشد: $script"; return; fi
  info "دستور: $NODE_BIN $(basename "$script") --text=\"diagnose …\""

  local out stamp
  stamp="$(date +%H%M%S)"
  if [[ "$p" == "igap" && -n "${IGAP_ITEM_ID:-}" ]]; then
    out="$(timeout "$(( ${USERBOT_TIMEOUT_SEC:-240} + 30 ))" "$NODE_BIN" "$script" --text="تشخیص خودکار $stamp" --item-id="${IGAP_ITEM_ID}" 2>/dev/null | tail -1)"
  else
    out="$(timeout "$(( ${USERBOT_TIMEOUT_SEC:-240} + 30 ))" "$NODE_BIN" "$script" --text="تشخیص خودکار $stamp" 2>/dev/null | tail -1)"
  fi

  if [[ -z "$out" ]]; then
    bad "خروجی JSON دریافت نشد (احتمالاً Node پیش از چاپ خطا مرده است)"
  else
    printf '  خروجی: %s\n' "$out"
    local st code
    st="$(printf '%s' "$out"   | sed -nE 's/.*"status"[[:space:]]*:[[:space:]]*"([^"]*)".*/\1/p')"
    code="$(printf '%s' "$out" | sed -nE 's/.*"code"[[:space:]]*:[[:space:]]*"([^"]*)".*/\1/p')"
    if [[ "$st" == "OK" ]]; then
      ok "status=OK — ارسال موفق"
      if printf '%s' "$out" | grep -q '"verified"[[:space:]]*:[[:space:]]*true'; then
        ok "verified=true — هدر کانال هم تأیید شد (به چت درست رفته)"
      else
        warn "verified نیست ⇒ «رفته ولی تأیید نشده»؛ کانال را چشمی چک کنید"
      fi
    else
      bad "status=${st:-نامشخص} code=${code:-—}"
      diagnose_code "${code:-RUNTIME}"
    fi
  fi

  log="$(ls -1t "$LOG_DIR"/send_"$p"_*.log 2>/dev/null | head -1)"
  if [[ -n "$log" ]]; then
    printf '\n  📄 لاگ: %s\n' "$log"
    echo "  --- ۲۵ خط آخر ---"
    tail -25 "$log" | sed 's/^/  | /'
  else
    warn "لاگی در $LOG_DIR پیدا نشد"
  fi
  if [[ -f "$shot" ]]; then
    local age now
    now="$(date +%s)"; age=$(( now - $(stat -c %Y "$shot" 2>/dev/null || echo "$now") ))
    if [[ "$age" -lt 600 ]]; then
      info "اسکرین‌شات شاهد (همین اجرا، ${age} ثانیه پیش): $shot"
      info "  با scp یا SSH ببینیدش — معمولاً ریشهٔ مشکل در همین عکس پیدا است"
    else
      warn "اسکرین‌شات $shot مربوط به $(( age / 60 )) دقیقه پیش است (نه این اجرا) ⇒ یعنی این اجرا تا مرحلهٔ صفحه جلو نرفته"
    fi
  else
    info "اسکرین‌شات شاهدی ساخته نشده ($shot) ⇒ اجرا پیش از باز شدن صفحه متوقف شده است"
  fi
}

for p in $PLATFORMS; do run_one "$p"; done

head1 "جمع‌بندی"
echo "  اگر status=OK ولی در کانال چیزی نیست ⇒ verified/هدر را چک کنید (ارسال به چت اشتباه)."
echo "  اگر SESSION_EXPIRED ⇒ ورود مجدد لازم است؛ پروفایلِ بازیابی‌شده از Git ممکن است قدیمی باشد."
echo "  اگر RUNTIME با «Permission denied (13)» ⇒ مالکیت پروفایل: chown -R $WEB_USER:$WEB_USER"
echo "  اگر RUNTIME با «Executable doesn't exist» ⇒ مسیر کرومیوم در .env غلط است."
echo "  کل شواهد یکجا:  bash collect_diagnostics.sh"
