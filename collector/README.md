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
