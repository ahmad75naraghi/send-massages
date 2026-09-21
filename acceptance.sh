#!/usr/bin/env bash
# ============================================================
#  acceptance.sh — پوشش جدول آزمون‌های T-1..T-18
#  (جدول آزمون در DEPLOYMENT.md §۱۲)
#
#  این فایل عمداً منطق تکراری ندارد: همان smoke_test.sh را اجرا می‌کند
#  که سوپرست همان بررسی‌هاست. نگاشت آزمون‌ها:
#
#    T-1  باینری‌ها (node/chromium)        → §۱ smoke_test
#    T-2  ماژول playwright + وابستگی‌ها    → §۱
#    T-3  اکستنشن‌های PHP                  → §۱
#    T-4  نوشتنی بودن sqlite/profile/logs  → §۳
#    T-5  سینتکس PHP و JS                  → §۲
#    T-18 .htaccess / .gitignore           → §۲
#    T-6..T-17 (نیازمند اعتبارنامه/تعامل)  → دستی طبق جدول DEPLOYMENT
#
#  استفاده:
#    sudo -u file bash acceptance.sh          (بدون ارسال)
#    sudo -u file bash acceptance.sh --live   (+ ارسال زندهٔ تستی)
# ============================================================
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
exec bash "$HERE/smoke_test.sh" "$@"
