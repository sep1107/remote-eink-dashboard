import importlib.util
import sqlite3
from pathlib import Path


MODULE_PATH = Path(__file__).parents[1] / "collector" / "push_grok2api_quota.py"
SPEC = importlib.util.spec_from_file_location("push_grok2api_quota", MODULE_PATH)
collector = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(collector)


def test_pool_query_and_payload_match_grok2api_availability_rules(tmp_path):
    database = tmp_path / "grok2api.db"
    connection = sqlite3.connect(database)
    connection.executescript("""
        CREATE TABLE provider_accounts (id INTEGER PRIMARY KEY, provider TEXT, enabled INTEGER, auth_status TEXT, cooldown_until TEXT);
        CREATE TABLE account_quota_recovery (account_id INTEGER, status TEXT);
        CREATE TABLE account_quota_windows (account_id INTEGER, mode TEXT, remaining INTEGER);
        INSERT INTO provider_accounts VALUES
            (1, 'grok_build', 1, 'active', NULL),
            (2, 'grok_build', 1, 'reauthRequired', NULL),
            (3, 'grok_web', 1, 'active', NULL),
            (4, 'grok_web', 1, 'active', NULL);
        INSERT INTO account_quota_windows VALUES (3, 'weekly', 8), (4, 'weekly', 0);
    """)
    rows = connection.execute(collector.POOL_SQL).fetchall()
    pools = {provider: {"total": total, "available": available} for provider, total, available in rows}
    payload = collector._payload(pools)

    assert payload["accounts"][0]["summary"] == "Build 1/2 · Web 1/2"
    assert payload["accounts"][0]["five_hour"] == {"used": 50, "available": 1, "total": 2}
    assert payload["accounts"][0]["seven_day"] == {"used": 50, "available": 1, "total": 2}
