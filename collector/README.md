# AI quota collectors

Collectors submit display-only quota summaries to the dashboard. Credentials stay on the machine that runs each collector and are never included in the request body.

Set these variables before running a collector:

```sh
EINK_DASHBOARD_URL=https://dashboard.example.com
EINK_DASHBOARD_INGEST_TOKEN=replace-with-your-ingest-token
```

## DeepSeek

`push_deepseek_balance.py` queries the official balance endpoint using `DEEPSEEK_API_KEY`, then sends only the formatted balance to the dashboard.

```sh
DEEPSEEK_API_KEY=replace-with-your-api-key \
python3 push_deepseek_balance.py
```

Set `EINK_DASHBOARD_SOURCE=company_deepseek` and `EINK_DASHBOARD_ACCOUNT_NAME` when sending a company account separately from an existing DeepSeek source.

## Alibaba Cloud account balance

`push_aliyun_account_balance.py` queries the Alibaba Cloud BSS `QueryAccountBalance` operation through the official `aliyun` CLI and submits only the account currency and `AvailableAmount`. It needs a RAM AccessKey with read permission for `bssapi:QueryAccountBalance`, passed through `ALIBABA_CLOUD_ACCESS_KEY_ID` and `ALIBABA_CLOUD_ACCESS_KEY_SECRET`. A DashScope API Key does not provide account-level balance access.

The CLI profile is ignored for this job. Keep the AccessKey in a root-managed mode-0600 environment file and load it through systemd; never put it in this repository or dashboard payload. `deploy/configure-company-aliyun-balance.py` provides a no-echo prompt that verifies the read-only request before writing that file.

## Claude Code

Two independent options; use whichever fits.

`claude_statusline_push.py` reads rate-limit fields from Claude Code's `statusLine` stdin JSON. It does not read Claude login files or transmit subscription credentials. Only pushes while Claude Code is running and only if the build emits `rate_limits`.

`push_cockpit_claude.mjs` reads the current Claude account's quota from Cockpit Tools' local encrypted cache — the same mechanism as the Codex collector, so it can share the Codex collector's scheduler. It picks Cockpit's currently selected account, skips Gateway/API accounts that have no 5H/7D window, and retains only quota percentages, reset times, and an anonymised plan label; OAuth, cookies, email, and raw usage blobs are discarded before serialization. Refreshes on a schedule regardless of whether Claude Code is running.

```sh
node push_cockpit_claude.mjs --check   # summarise without sending
node push_cockpit_claude.mjs --json    # print the exact push payload
```

## Codex / Cockpit Tools

`push_cockpit_codex.mjs` reads quota fields from Cockpit Tools' local encrypted cache. Decryption happens locally; OAuth fields are discarded before serialization.

Validate local access without sending data:

```sh
node push_cockpit_codex.mjs --check
```

The push payload uses anonymous labels such as `Codex 1` and contains only plan badges, percentages, and reset times.

## grok2api

`push_grok2api_quota.py` runs a read-only aggregate query against a co-located grok2api SQLite database. Its availability predicate matches grok2api's Build/Web account summary, including disabled, reauthentication, cooldown, recovery, and exhausted Web weekly-window states. The query selects only provider names and aggregate counts; it never reads account identity or credential columns.

Use a recent SQLite CLI because older system SQLite releases cannot parse grok2api's current schema:

```sh
GROK2API_SQLITE_BIN=/path/to/sqlite3 \
GROK2API_DB_PATH=/path/to/backend.db \
python3 push_grok2api_quota.py --check
```

The normal mode also requires the dashboard variables shown above. It sends the Build/Web available percentage together with the available and total counts.
