# Remote E-Ink Dashboard

A self-hosted dashboard for Kindle and Android e-ink devices. It renders low-frequency grayscale PNG frames with a lunar calendar, weather, AI quota summaries, device battery, and optional multi-server status.

## Features

- Native layouts for 1072×1448 portrait, 1440×1080 landscape, and 720×1440 Android e-ink screens.
- Open-Meteo weather, air quality, UV index, sunrise, sunset, and forecasts.
- Token-protected frame, viewer, widget, and quota-ingest endpoints.
- Optional collectors for Claude Code, Codex via Cockpit Tools, the official DeepSeek balance API, and local or remote Linux server status.
- Android 4.2+ client with orientation-specific offline caches.
- Kindle KUAL extension with timed Wi-Fi wake, download, display, and suspend.
- Scriptable widgets and a responsive calendar/weather page.

## Screenshots

Current production frames captured on 2026-07-22.

### KO1 / Android 4.2 (landscape)

<p align="center">
  <img src="docs/screenshots/landscape.png" alt="KO1 Android 4.2 landscape dashboard frame" width="720">
</p>

### KPW3 (portrait)

<p align="center">
  <img src="docs/screenshots/portrait.png" alt="KPW3 portrait dashboard frame" width="360">
</p>

## Privacy model

- Device and viewer tokens are supplied through `.dashboard.env`; populated environment files are ignored by Git.
- Collectors submit only percentages, reset timestamps, plan badges, and formatted balances.
- Remote server collectors submit CPU, load, memory, disk, and optional Docker summaries through a dedicated ingest token.
- OAuth tokens and provider API keys stay on the collector host.
- Frame and viewer routes should not be written to access logs because their tokens are path components.
- Runtime state, fonts, build output, APKs, private keys, and personal deployment notes are excluded by `.gitignore`. `docs/screenshots/` is the intentional exception: it contains user-authorized real device frames.

Before publishing a fork, run your own secret scanner and review every staged file.

## PHP deployment

Requirements: PHP 7.4+, GD, Intl, cURL, Nginx, and a CJK TrueType font.

1. Copy the repository to `/var/www/remote-eink-dashboard`.
2. Copy `.env.example` to `php/.dashboard.env` and replace every token placeholder.
3. Place your licensed font at `php/.dashboard-font.ttf`.
4. Point Nginx at `php/index.php`; examples are in `deploy/`.
5. Keep `.dashboard.env`, `.dashboard-data/`, and `.dashboard-font.ttf` inaccessible over HTTP.

Device URLs follow this pattern:

```text
https://dashboard.example.com/frame/DEVICE_ID/DEVICE_TOKEN.png
https://dashboard.example.com/viewer/DEVICE_ID/DEVICE_TOKEN
```

The quota ingest endpoint is:

```text
POST /v1/ingest/quota
Authorization: Bearer YOUR_INGEST_TOKEN
```

To show more than one server on the responsive viewer, set `DASHBOARD_SERVERS_JSON`. The first entry uses the dashboard host's local `server-status.json`; every additional entry is written by the authenticated remote-status endpoint:

```dotenv
DASHBOARD_SERVERS_JSON=[{"id":"local","label":"Primary Server"},{"id":"server-2","label":"Secondary Server"}]
DASHBOARD_SERVER_STATUS_TOKEN=REPLACE_WITH_ANOTHER_64_HEX_CHARACTERS
```

Install `php/collect_server_status.php` locally to collect the primary server. On each remote Linux server, install the same collector together with the example environment, service, and timer from `deploy/remote-eink-dashboard-status.*`. Use a dedicated unprivileged account, keep the populated environment file root-managed with read access limited to the collector group, and give each remote server a unique ID that also appears in `DASHBOARD_SERVERS_JSON`. The timer submits one small status summary per minute:

```text
POST /v1/ingest/server-status/SERVER_ID
Authorization: Bearer YOUR_SERVER_STATUS_TOKEN
```

## Container deployment

The repository also includes a smaller Python/Gunicorn renderer:

```sh
cp .env.example .env
docker compose up -d --build
```

It listens on `127.0.0.1:8486` by default. Put HTTPS in front of it before configuring a device.

## Clients

- `android/`: open with Android Studio and build with JDK 11+. Long-press the image to set portrait and landscape frame URLs.
- `kindle/kual/`: copy to `extensions/remote-eink-dashboard/`, create `eink-dashboard.conf`, then start it from KUAL.
- `scriptable/`: the hosted public widget targets `ai.hpqq.fun` and installs from `https://ai.hpqq.fun/install`; self-hosted deployments should replace that domain before installing the widgets.
- `collector/`: configure credentials as environment variables on the collector host; never place them in this repository.

## Tests

```sh
python3 -m pytest
```

## License

Source code is available under the MIT License. Provider logos and trademarks in `php/assets/` belong to their respective owners and are not relicensed by this repository.
