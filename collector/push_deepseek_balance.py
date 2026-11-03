#!/usr/bin/env python3
"""Read the official DeepSeek account balance and push only a display summary.

Required environment variables:
  DEEPSEEK_API_KEY
  EINK_DASHBOARD_URL          e.g. https://dashboard.example.com
  EINK_DASHBOARD_INGEST_TOKEN

The API key is used only for the official DeepSeek request.  It is never sent
to the dashboard service or written to disk by this program.
"""
import json
import os
import sys
import urllib.error
import urllib.request
from decimal import Decimal, InvalidOperation
from typing import Any, Dict, Optional


def _money(value: Any) -> str:
    try:
        return f"{Decimal(str(value)).quantize(Decimal('0.01')):,.2f}"
    except (InvalidOperation, ValueError):
        return "--"


def _summary(payload: Dict[str, Any]) -> str:
    balances = payload.get("balance_infos")
    if not payload.get("is_available", True):
        return "Unavailable"
    if not isinstance(balances, list) or not balances:
        return "Balance --"
    parts = []
    symbols = {"CNY": "¥", "USD": "$"}
    for balance in balances[:2]:
        if not isinstance(balance, dict):
            continue
        currency = str(balance.get("currency") or "")
        parts.append(f"{symbols.get(currency, currency + ' ')}{_money(balance.get('total_balance'))}")
    return "Balance " + (" / ".join(parts) if parts else "--")


def _request_json(url: str, headers: Dict[str, str], payload: Optional[Dict[str, Any]] = None) -> Dict[str, Any]:
    data = json.dumps(payload).encode("utf-8") if payload is not None else None
    request_headers = {"User-Agent": "curl/7.29.0"}
    request_headers.update(headers)
    request = urllib.request.Request(url, data=data, headers=request_headers, method="POST" if data else "GET")
    with urllib.request.urlopen(request, timeout=12) as response:
        parsed = json.loads(response.read().decode("utf-8"))
    if not isinstance(parsed, dict):
        raise ValueError("unexpected JSON response")
    return parsed


def main() -> int:
    api_key = os.environ.get("DEEPSEEK_API_KEY")
    dashboard_url = os.environ.get("EINK_DASHBOARD_URL", "").rstrip("/")
    ingest_token = os.environ.get("EINK_DASHBOARD_INGEST_TOKEN")
    if not api_key or not dashboard_url or not ingest_token:
        print("Missing DeepSeek or dashboard environment variables.", file=sys.stderr)
        return 2
    try:
        balance = _request_json(
            "https://api.deepseek.com/user/balance",
            {"Authorization": f"Bearer {api_key}", "Accept": "application/json"},
        )
        _request_json(
            f"{dashboard_url}/v1/ingest/quota",
            {"Authorization": f"Bearer {ingest_token}", "Content-Type": "application/json"},
            {"source": "deepseek", "accounts": [{"name": "DeepSeek", "summary": _summary(balance)}]},
        )
    except (OSError, TimeoutError, ValueError, json.JSONDecodeError, urllib.error.HTTPError, urllib.error.URLError):
        print("DeepSeek balance update failed.", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
