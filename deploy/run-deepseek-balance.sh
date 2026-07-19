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

deepseek_env=${DEEPSEEK_ENV_FILE:-/etc/remote-eink-dashboard/deepseek.env}
dashboard_env=${DASHBOARD_ENV_FILE:-/var/www/remote-eink-dashboard/.dashboard.env}
dashboard_url=${EINK_DASHBOARD_URL:-https://dashboard.example.com}

deepseek_key=$(read_env_value DEEPSEEK_API_KEY "$deepseek_env")
ingest_token=$(read_env_value DASHBOARD_INGEST_TOKEN "$dashboard_env")

[ -n "$deepseek_key" ]
[ -n "$ingest_token" ]

exec env \
    DEEPSEEK_API_KEY="$deepseek_key" \
    EINK_DASHBOARD_URL="$dashboard_url" \
    EINK_DASHBOARD_INGEST_TOKEN="$ingest_token" \
    python3 /opt/remote-eink-dashboard/collector/push_deepseek_balance.py
