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

`claude_statusline_push.py` reads rate-limit fields from Claude Code's `statusLine` stdin JSON. It does not read Claude login files or transmit subscription credentials.

## Codex / Cockpit Tools

`push_cockpit_codex.mjs` reads quota fields from Cockpit Tools' local encrypted cache. Decryption happens locally; OAuth fields are discarded before serialization.

Validate local access without sending data:

```sh
node push_cockpit_codex.mjs --check
```

The push payload uses anonymous labels such as `Codex 1` and contains only plan badges, percentages, and reset times.
