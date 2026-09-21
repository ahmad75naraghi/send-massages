#!/usr/bin/env bash
# ============================================================
#  restore_runtime.sh — بازیابی فایل‌های runtime که با git checkout پاک شده‌اند
#
#  چرا لازم است؟
#    در کامیت اولیهٔ ریپو، این‌ها «ردیابی‌شده» بودند:
#      node_modules/  soroush_profile/  igap_profile/
#      state.sqlite   soroush_session.json
#    در برنچ جدید هیچ‌کدام ردیابی نمی‌شوند (در .gitignore هستند).
#    پس `git checkout` به برنچ جدید آن‌ها را از دیسک **پاک می‌کند**:
#      node_modules   ⇒ MODULE_NOT_FOUND و از کار افتادن سروش/آی‌گپ
#      *_profile      ⇒ نابودی session ورود (نیاز به OTP مجدد)
#      state.sqlite   ⇒ گم شدن تاریخچهٔ انتشار و «انتشار تکراری»
#
#  این اسکریپت همان‌ها را از تاریخ Git برمی‌گرداند — بدون نیاز به اینترنت.
#  فقط چیزی را برمی‌گرداند که «وجود ندارد»؛ هرگز روی فایل موجود نمی‌نویسد.
#
#  استفاده:
#      bash restore_runtime.sh            # فقط گزارش + بازیابی موارد غایب
#      bash restore_runtime.sh --dry-run  # فقط گزارش، بدون تغییر
#      bash restore_runtime.sh --npm      # اگر بازیابی از git نشد، npm install
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

DRY=0; USE_NPM=0
for a in "$@"; do
  case "$a" in
    --dry-run) DRY=1 ;;
    --npm)     USE_NPM=1 ;;
    *) echo "آرگومان ناشناخته: $a" >&2; exit 2 ;;
  esac
done

TARGETS=(node_modules soroush_profile igap_profile state.sqlite soroush_session.json)
NODE_BIN="${NODE_BIN:-}"
if [[ -z "$NODE_BIN" ]]; then
  for c in node nodejs; do command -v "$c" >/dev/null 2>&1 && { NODE_BIN="$(command -v "$c")"; break; }; done
fi
[[ -z "$NODE_BIN" ]] && NODE_BIN="node"

echo "=== restore_runtime.sh — $APP_DIR ==="
[[ "$DRY" == 1 ]] && echo "(حالت dry-run: هیچ تغییری داده نمی‌شود)"

if ! git rev-parse --git-dir >/dev/null 2>&1; then
  echo "🔴 اینجا ریپوی Git نیست (.git پیدا نشد)."
  echo "   بدون Git نمی‌توان این فایل‌ها را بازیابی کرد؛ از پشتیبان یا npm install استفاده کنید."
  [[ "$USE_NPM" == 1 ]] || exit 1
fi

# کامیتی را پیدا کن که هنوز این مسیر را دارد (rev-list از جدید به قدیم است)
find_source_commit() {  # $1 = مسیر
  local c
  for c in $(git rev-list --all --max-count=60 2>/dev/null); do
    if [[ -n "$(git ls-tree --name-only "$c" "$1" 2>/dev/null)" ]]; then
      printf '%s' "$c"; return 0
    fi
  done
  return 1
}

restore_path() {  # $1 = مسیر
  local p="$1" src
  if [[ -e "$p" ]]; then
    printf '  ✅ %-22s موجود است — دست نمی‌زنیم\n' "$p"
    return 0
  fi
  if ! src="$(find_source_commit "$p")" || [[ -z "$src" ]]; then
    printf '  ⚠️  %-22s غایب است و در تاریخ Git هم پیدا نشد\n' "$p"
    return 1
  fi
  if [[ "$DRY" == 1 ]]; then
    printf '  🔎 %-22s غایب — قابل بازیابی از %s\n' "$p" "${src:0:7}"
    return 0
  fi
  printf '  ↩️  %-22s بازیابی از %s … ' "$p" "${src:0:7}"
  if git restore --source="$src" --worktree -- "$p" 2>/dev/null \
     || { git checkout "$src" -- "$p" 2>/dev/null && git reset -q HEAD -- "$p" 2>/dev/null; }; then
    echo "OK ✅"
    return 0
  fi
  echo "ناموفق ❌"
  return 1
}

failed=()
for t in "${TARGETS[@]}"; do
  restore_path "$t" || failed+=("$t")
done

# ---------- مالکیت و مجوز ----------
if [[ "$DRY" == 0 ]]; then
  OWNER="${SUDO_USER:-$(id -un)}"
  for d in soroush_profile igap_profile; do
    [[ -d "$d" ]] && { chmod 700 "$d"; find "$d" -type d -exec chmod 700 {} \; ; find "$d" -type f -exec chmod 600 {} \; ; }
  done
  [[ -f state.sqlite ]] && chmod 660 state.sqlite
  [[ -f soroush_session.json ]] && chmod 600 soroush_session.json
  echo "  🔒 مجوزها: پروفایل‌ها ۷۰۰/۶۰۰، state.sqlite ۶۶۰"
fi

# ---------- نصب با npm در صورت نیاز ----------
if [[ ! -d node_modules/playwright ]]; then
  if [[ "$USE_NPM" == 1 ]] && command -v npm >/dev/null 2>&1; then
    echo "  📦 node_modules/playwright نیست — نصب با npm …"
    PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 npm install --no-audit --no-fund >/dev/null 2>&1 \
      && echo "     نصب انجام شد ✅" || echo "     نصب ناموفق ❌ (شبکه/registry را چک کنید)"
  else
    echo "  ⚠️  node_modules/playwright هنوز نیست."
    echo "     راه‌حل: bash restore_runtime.sh --npm    یا    npm install --no-audit --no-fund"
  fi
fi

# ---------- تأیید نهایی ----------
echo "=== تأیید ==="
if [[ -x "$NODE_BIN" ]] || command -v "$NODE_BIN" >/dev/null 2>&1; then
  if (cd "$APP_DIR" && "$NODE_BIN" -e "require('playwright')" >/dev/null 2>&1); then
    echo "  ✅ playwright قابل require است"
  else
    echo "  ❌ playwright قابل require نیست — هنوز مشکل دارید"
  fi
else
  echo "  ⚠️  باینری Node پیدا نشد ($NODE_BIN) — NODE_BIN را در .env چک کنید"
fi
for d in soroush_profile igap_profile; do
  [[ -d "$d" ]] && echo "  ✅ $d موجود ($(du -sh "$d" 2>/dev/null | cut -f1))" || echo "  ❌ $d غایب — ورود مجدد لازم است (node login_${d%%_profile}.js)"
done
[[ -f state.sqlite ]] && echo "  ✅ state.sqlite موجود ($(du -h state.sqlite | cut -f1))" || echo "  ❌ state.sqlite غایب — همهٔ پست‌ها «جدید» شمرده می‌شوند"

if [[ ${#failed[@]} -gt 0 ]]; then
  echo
  echo "🔴 موارد حل‌نشده: ${failed[*]}"
  exit 1
fi
echo
echo "✅ بازیابی کامل شد. آزمون:  bash smoke_test.sh  و سپس  node send_soroush.js --text='تست'"
