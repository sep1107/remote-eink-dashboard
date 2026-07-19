#!/bin/sh
set -eu

BASE="$(cd "$(dirname "$0")" && pwd)"
CONF="$BASE/eink-dashboard.conf"
PIDFILE="$BASE/eink-dashboard.pid"
STOPFILE="$BASE/eink-dashboard.stop"
FETCHER="$BASE/eink-dashboard-fetch.lua"
FRAME="/tmp/remote-eink-dashboard.png"
NEXT_FRAME="/tmp/remote-eink-dashboard.next.png"
FBINK="/var/tmp/remote-eink-dashboard-fbink"
RTC_WAKE="/sys/class/rtc/rtc0/wakealarm"
POWER_STATE="/sys/power/state"

[ -f "$CONF" ] && . "$CONF"
: "${DASHBOARD_URL:?Set DASHBOARD_URL in eink-dashboard.conf}"
: "${DASHBOARD_INTERVAL:=900}"

fetch_frame() {
  if [ -x /mnt/us/koreader/luajit ] && [ -f "$FETCHER" ]; then
    (cd /mnt/us/koreader && ./luajit "$FETCHER" "$1" "$2")
  else
    wget -q -T 30 -O "$2" "$1"
  fi
}

paint_frame() {
  if [ -x /mnt/us/koreader/fbink ]; then
    cp -pf /mnt/us/koreader/fbink "$FBINK"
    chmod 755 "$FBINK"
    "$FBINK" -q -f -i "$FRAME" >/dev/null 2>&1 && return 0
  fi

  eips -c >/dev/null 2>&1 || true
  eips -f -g "$FRAME" >/dev/null 2>&1
}

fetch_and_paint() {
  BATTERY="$(lipc-get-prop com.lab126.powerd battLevel 2>/dev/null || true)"
  case "$BATTERY" in
    ''|*[!0-9]*) BATTERY="" ;;
  esac
  case "$DASHBOARD_URL" in
    *\?*) SEP='&' ;;
    *) SEP='?' ;;
  esac

  if fetch_frame "$DASHBOARD_URL${SEP}ts=$(date +%s)&battery=$BATTERY" "$NEXT_FRAME" >/dev/null 2>&1; then
    mv "$NEXT_FRAME" "$FRAME"
    paint_frame
  else
    rm -f "$NEXT_FRAME"
    return 1
  fi
}

enable_wifi() {
  lipc-set-prop com.lab126.cmd wirelessEnable 1 >/dev/null 2>&1 || true
  sleep 8
}

disable_wifi() {
  lipc-set-prop com.lab126.cmd wirelessEnable 0 >/dev/null 2>&1 || true
}

suspend_for_interval() {
  [ -w "$RTC_WAKE" ] && [ -w "$POWER_STATE" ] || return 1

  echo 0 > "$RTC_WAKE"
  echo "+$DASHBOARD_INTERVAL" > "$RTC_WAKE"
  ALARM="$(cat "$RTC_WAKE" 2>/dev/null || true)"
  sync

  echo mem > "$POWER_STATE" || return 1

  NOW="$(date +%s)"
  case "$ALARM" in
    ''|*[!0-9]*) ;;
    *)
      # An early power-button wake exits dashboard mode so the Kindle is usable.
      [ "$NOW" -ge "$((ALARM - 30))" ] || return 2
      ;;
  esac
  return 0
}

cleanup() {
  lipc-set-prop com.lab126.powerd preventScreenSaver 0 >/dev/null 2>&1 || true
  lipc-set-prop com.lab126.cmd wirelessEnable 1 >/dev/null 2>&1 || true
  rm -f "$PIDFILE" "$STOPFILE" "$FBINK"
}

run() {
  rm -f "$STOPFILE"
  echo "$$" > "$PIDFILE"
  lipc-set-prop com.lab126.powerd preventScreenSaver 1 2>/dev/null || true
  trap cleanup 0
  trap 'exit 0' 1 2 15
  sleep 3
  while [ ! -f "$STOPFILE" ]; do
    enable_wifi
    fetch_and_paint || true
    disable_wifi

    if suspend_for_interval; then
      sleep 5
    else
      RESULT=$?
      [ "$RESULT" -eq 2 ] && break
      lipc-set-prop com.lab126.powerd preventScreenSaver 0 >/dev/null 2>&1 || true
      sleep "$DASHBOARD_INTERVAL"
      lipc-set-prop com.lab126.powerd preventScreenSaver 1 >/dev/null 2>&1 || true
    fi
  done
}

case "${1:-start}" in
  start)
    if [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then exit 0; fi
    run >/dev/null 2>&1 &
    ;;
  refresh) (sleep 3; enable_wifi; fetch_and_paint) >/dev/null 2>&1 & ;;
  stop)
    : > "$STOPFILE"
    [ -f "$PIDFILE" ] && kill "$(cat "$PIDFILE")" 2>/dev/null || true
    lipc-set-prop com.lab126.powerd preventScreenSaver 0 >/dev/null 2>&1 || true
    lipc-set-prop com.lab126.cmd wirelessEnable 1 >/dev/null 2>&1 || true
    ;;
  *) echo "Usage: $0 {start|refresh|stop}" >&2; exit 2 ;;
esac
