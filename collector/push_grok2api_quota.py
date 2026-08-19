#!/usr/bin/env python3
"""Push display-only Grok Build/Web pool availability to the dashboard."""
import json
import os
import subprocess
import sys
import urllib.error
import urllib.request
from typing import Any, Dict


POOL_SQL = r"""
SELECT provider, COUNT(*) AS total,
       SUM(CASE
           WHEN enabled = 1
            AND auth_status = 'active'
            AND NOT EXISTS (
                SELECT 1 FROM account_quota_recovery recovery
                WHERE recovery.account_id = provider_accounts.id
                  AND recovery.status IN ('exhausted', 'probing')
            )
            AND (cooldown_until IS NULL OR datetime(cooldown_until) <= datetime('now'))
            AND NOT (
                provider = 'grok_web' AND (
                    (
                        EXISTS (SELECT 1 FROM account_quota_windows quota WHERE quota.account_id = provider_accounts.id AND quota.mode = 'weekly')
                        AND NOT EXISTS (SELECT 1 FROM account_quota_windows quota WHERE quota.account_id = provider_accounts.id AND quota.mode = 'weekly' AND quota.remaining > 0)
                    ) OR (
                        NOT EXISTS (SELECT 1 FROM account_quota_windows quota WHERE quota.account_id = provider_accounts.id AND quota.mode = 'weekly')
                        AND EXISTS (SELECT 1 FROM account_quota_windows quota WHERE quota.account_id = provider_accounts.id)
                        AND NOT EXISTS (SELECT 1 FROM account_quota_windows quota WHERE quota.account_id = provider_accounts.id AND quota.remaining > 0)
                    )
                )
            )
           THEN 1 ELSE 0 END) AS available
FROM provider_accounts
WHERE provider IN ('grok_build', 'grok_web')
GROUP BY provider
ORDER BY CASE provider WHEN 'grok_build' THEN 0 ELSE 1 END;
"""


def _query_pool(sqlite_bin: str, database_path: str) -> Dict[str, Dict[str, int]]:
    result = subprocess.run(
        [sqlite_bin, "-readonly", "-batch", "-noheader", "-separator", "\t", database_path, POOL_SQL],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        universal_newlines=True,
        timeout=15,
        check=True,
    )
    pools = {"grok_build": {"available": 0, "total": 0}, "grok_web": {"available": 0, "total": 0}}
    for line in result.stdout.splitlines():
        parts = line.split("\t")
        if len(parts) != 3 or parts[0] not in pools:
            raise ValueError("unexpected grok2api quota row")
        total = max(0, int(parts[1]))
        available = max(0, min(total, int(parts[2])))
        pools[parts[0]] = {"available": available, "total": total}
    return pools


def _metric(pool: Dict[str, int]) -> Dict[str, int]:
    total = pool["total"]
    available = pool["available"]
    percent = int(round(available * 100.0 / total)) if total else 0
    return {"used": percent, "available": available, "total": total}


def _payload(pools: Dict[str, Dict[str, int]]) -> Dict[str, Any]:
    build = pools["grok_build"]
    web = pools["grok_web"]
    return {
        "source": "grok2api",
        "accounts": [{
            "name": "grok2api",
            "summary": "Build {}/{} · Web {}/{}".format(build["available"], build["total"], web["available"], web["total"]),
            "plan": "账号池",
            "five_hour": _metric(build),
            "seven_day": _metric(web),
        }],
    }


def _post(url: str, token: str, payload: Dict[str, Any]) -> None:
    request = urllib.request.Request(
        url,
        data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        headers={"Authorization": "Bearer " + token, "Content-Type": "application/json", "User-Agent": "remote-eink-dashboard-grok2api/1"},
        method="POST",
    )
    with urllib.request.urlopen(request, timeout=12) as response:
        parsed = json.loads(response.read().decode("utf-8"))
    if not isinstance(parsed, dict) or parsed.get("ok") is not True:
        raise ValueError("dashboard rejected grok2api quota")


def main() -> int:
    sqlite_bin = os.environ.get("GROK2API_SQLITE_BIN", "/opt/remote-eink-dashboard/bin/sqlite3")
    database_path = os.environ.get("GROK2API_DB_PATH", "/var/lib/docker/volumes/grok2api_migrated_20260726/_data/backend.db")
    try:
        payload = _payload(_query_pool(sqlite_bin, database_path))
        if "--check" in sys.argv:
            print(payload["accounts"][0]["summary"])
            return 0
        if "--json" in sys.argv:
            print(json.dumps(payload, ensure_ascii=False))
            return 0
        dashboard_url = os.environ.get("EINK_DASHBOARD_URL", "").rstrip("/")
        ingest_token = os.environ.get("EINK_DASHBOARD_INGEST_TOKEN", "")
        if not dashboard_url or not ingest_token:
            print("Missing dashboard environment variables.", file=sys.stderr)
            return 2
        _post(dashboard_url + "/v1/ingest/quota", ingest_token, payload)
    except (OSError, subprocess.SubprocessError, ValueError, urllib.error.HTTPError, urllib.error.URLError):
        print("grok2api quota update failed.", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
