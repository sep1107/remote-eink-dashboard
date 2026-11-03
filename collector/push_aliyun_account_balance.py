#!/usr/bin/env python3
"""Read Alibaba Cloud account balance through the official CLI and push a summary.

The RAM AccessKey stays in the collector environment and is never sent to the
dashboard. The CLI profile is ignored so this job uses only its dedicated
environment credentials.
"""
import json
import os
import shutil
import subprocess
import sys
import urllib.error
import urllib.request
from decimal import Decimal, InvalidOperation
from typing import Any, Dict


def _money(value: Any) -> str:
    try:
        return f"{Decimal(str(value)).quantize(Decimal('0.01')):,.2f}"
    except (InvalidOperation, ValueError):
        raise ValueError("invalid account balance")


def _account_balance() -> str:
    if not os.environ.get("ALIBABA_CLOUD_ACCESS_KEY_ID") or not os.environ.get("ALIBABA_CLOUD_ACCESS_KEY_SECRET"):
        raise ValueError("Alibaba Cloud account credentials are not configured")
    aliyun = shutil.which("aliyun")
    if not aliyun:
        raise ValueError("Alibaba Cloud CLI is unavailable")
    environment = os.environ.copy()
    environment["ALIBABA_CLOUD_IGNORE_PROFILE"] = "TRUE"
    result = subprocess.run(
        [aliyun, "bssopenapi", "QueryAccountBalance", "--region", "cn-hangzhou"],
        capture_output=True,
        text=True,
        timeout=20,
        env=environment,
    )
    if result.returncode != 0:
        raise ValueError("Alibaba Cloud account balance query failed")
    payload = json.loads(result.stdout)
    data = payload.get("Data") if isinstance(payload, dict) else None
    if not isinstance(data, dict) or payload.get("Success") is not True:
        raise ValueError("unexpected Alibaba Cloud account balance response")
    amount = data.get("AvailableAmount")
    currency = str(data.get("Currency") or "")
    if not isinstance(amount, (str, int, float)) or currency not in {"CNY", "USD", "JPY"}:
        raise ValueError("unexpected Alibaba Cloud account balance fields")
    symbol = {"CNY": "¥", "USD": "$", "JPY": "¥"}[currency]
    return "Balance " + symbol + _money(amount)


def _post(url: str, token: str, payload: Dict[str, Any]) -> None:
    request = urllib.request.Request(
        url.rstrip("/") + "/v1/ingest/quota",
        data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        headers={
            "Authorization": "Bearer " + token,
            "Content-Type": "application/json",
            "User-Agent": "curl/7.29.0",
        },
        method="POST",
    )
    with urllib.request.urlopen(request, timeout=20) as response:
        if not 200 <= response.status < 300:
            raise ValueError("dashboard rejected Alibaba Cloud balance")


def main() -> int:
    dashboard_url = os.environ.get("EINK_DASHBOARD_URL", "").rstrip("/")
    ingest_token = os.environ.get("EINK_DASHBOARD_INGEST_TOKEN")
    if not dashboard_url or not ingest_token:
        print("Missing dashboard environment variables.", file=sys.stderr)
        return 2
    if not os.environ.get("ALIBABA_CLOUD_ACCESS_KEY_ID") or not os.environ.get("ALIBABA_CLOUD_ACCESS_KEY_SECRET"):
        return 0
    try:
        summary = _account_balance()
        _post(
            dashboard_url,
            ingest_token,
            {"source": "company_aliyun", "accounts": [{"name": "阿里云账户", "summary": summary}]},
        )
    except (OSError, TimeoutError, ValueError, json.JSONDecodeError, urllib.error.HTTPError, urllib.error.URLError, subprocess.SubprocessError):
        print("Alibaba Cloud balance update failed.", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
