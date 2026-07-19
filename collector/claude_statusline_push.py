#!/usr/bin/env python3
"""Claude Code statusLine adapter that also pushes a non-secret quota summary.

Claude Code sends a JSON object through stdin.  Configure this script as the
statusLine command after EINK_DASHBOARD_URL and EINK_DASHBOARD_INGEST_TOKEN are
available in the command environment.  The script does not read Claude login
files or transmit Claude credentials.
"""
from __future__ import annotations

import json
import os
import sys
import urllib.error
import urllib.request
from typing import Any


def _percent(value: Any) -> int | None:
    if not isinstance(value, dict):
        return None
    percent = value.get("used_percentage")
    if not isinstance(percent, (int, float)):
        return None
    return max(0, min(100, round(percent)))


def _metric(value: Any) -> dict[str, int] | None:
    used = _percent(value)
    if used is None:
        return None
    metric = {"used": used}
    resets_at = value.get("resets_at")
    if isinstance(resets_at, (int, float)) and resets_at > 0:
        metric["reset_at"] = round(resets_at)
    return metric


def _post(summary: str, metrics: dict[str, dict[str, int]]) -> None:
    dashboard_url = os.environ.get("EINK_DASHBOARD_URL", "").rstrip("/")
    ingest_token = os.environ.get("EINK_DASHBOARD_INGEST_TOKEN")
    if not dashboard_url or not ingest_token:
        return
    account = {"name": "Claude Code", "summary": summary}
    account.update(metrics)
    body = json.dumps({
        "source": "claude",
        "accounts": [account],
    }).encode("utf-8")
    request = urllib.request.Request(
        f"{dashboard_url}/v1/ingest/quota",
        data=body,
        headers={"Authorization": f"Bearer {ingest_token}", "Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(request, timeout=5):
        pass


def main() -> int:
    try:
        payload = json.load(sys.stdin)
    except (json.JSONDecodeError, OSError):
        return 0
    limits = payload.get("rate_limits") if isinstance(payload, dict) else None
    limits = limits if isinstance(limits, dict) else {}
    five_hour_metric = _metric(limits.get("five_hour"))
    seven_day_metric = _metric(limits.get("seven_day"))
    fable_metric = _metric(limits.get("fable"))
    five_hour = five_hour_metric["used"] if five_hour_metric else None
    seven_day = seven_day_metric["used"] if seven_day_metric else None
    fable = fable_metric["used"] if fable_metric else None
    parts = []
    if five_hour is not None:
        parts.append(f"5h left {100 - five_hour}%")
    if seven_day is not None:
        parts.append(f"week left {100 - seven_day}%")
    if fable is not None:
        parts.append(f"Fable left {100 - fable}%")
    if not parts:
        return 0
    summary = " · ".join(parts)
    print(f"Claude Code {summary}")
    metrics = {}
    if five_hour_metric:
        metrics["five_hour"] = five_hour_metric
    if seven_day_metric:
        metrics["seven_day"] = seven_day_metric
    if fable_metric:
        metrics["fable"] = fable_metric
    try:
        _post(summary, metrics)
    except (OSError, TimeoutError, urllib.error.HTTPError, urllib.error.URLError):
        pass
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
