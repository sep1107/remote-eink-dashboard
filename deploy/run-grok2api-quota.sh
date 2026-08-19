#!/bin/sh
set -eu

read_env_value() {
    value=$(sed -n "s/^$1=//p" "$2" | tail -n 1)
    value=${value#\"}
    value=${value%\"}
    value=${value#\'}
    value=${value%\'}
    printf '%s' "$value"
}

dashboard_env=${DASHBOARD_ENV_FILE:-/www/wwwroot/ai.hpqq.fun/.dashboard.env}
dashboard_url=${EINK_DASHBOARD_URL:-https://dashboard.example.com}
ingest_token=$(read_env_value DASHBOARD_INGEST_TOKEN "$dashboard_env")

[ -n "$ingest_token" ]

exec env \
    EINK_DASHBOARD_URL="$dashboard_url" \
    EINK_DASHBOARD_INGEST_TOKEN="$ingest_token" \
    GROK2API_SQLITE_BIN="${GROK2API_SQLITE_BIN:-/opt/remote-eink-dashboard/bin/sqlite3}" \
    GROK2API_DB_PATH="${GROK2API_DB_PATH:-/var/lib/docker/volumes/grok2api_migrated_20260726/_data/backend.db}" \
    python3 /opt/remote-eink-dashboard/collector/push_grok2api_quota.py
