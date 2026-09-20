#!/bin/bash
pkill -f chromium-browser
pkill -f chromium

/usr/bin/chromium-browser \
  --remote-debugging-port=9222 \
  --remote-debugging-address=0.0.0.0 \
  --user-data-dir=/home/file/public_html/s/soroush_profile \
  --no-sandbox \
  --disable-setuid-sandbox \
  --disable-gpu \
  --disable-dev-shm-usage \
  --headless=new \
  https://web.splus.ir &