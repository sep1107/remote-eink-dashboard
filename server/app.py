"""Remote E-Ink Dashboard: a small, static-frame personal dashboard."""
from __future__ import annotations

import calendar
import hmac
import io
import json
import os
import threading
import time
import urllib.parse
import urllib.request
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import Any
from zoneinfo import ZoneInfo

from flask import Flask, Response, jsonify, request
from PIL import Image, ImageDraw, ImageFont


FONT_PATHS = (
    "/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc",
    "/usr/share/fonts/truetype/noto/NotoSansCJK-Regular.ttc",
    "/System/Library/Fonts/PingFang.ttc",
)


@dataclass(frozen=True)
class Device:
    id: str
    token: str
    frame_width: int
    frame_height: int
    layout: str
    rotate: int

    @classmethod
    def from_dict(cls, value: dict[str, Any]) -> "Device":
        device = cls(
            id=str(value["id"]),
            token=str(value["token"]),
            frame_width=int(value["frame_width"]),
            frame_height=int(value["frame_height"]),
            layout=str(value.get("layout", "portrait")),
            rotate=int(value.get("rotate", 0)),
        )
        if not device.id or not device.token:
            raise ValueError("device id and token are required")
        if device.frame_width < 320 or device.frame_height < 320:
            raise ValueError(f"invalid frame size for {device.id}")
        if device.layout not in {"portrait", "landscape"}:
            raise ValueError(f"invalid layout for {device.id}")
        if device.rotate not in {0, 90, 180, 270}:
            raise ValueError(f"invalid rotation for {device.id}")
        return device


@dataclass(frozen=True)
class Config:
    timezone: str
    city: str
    latitude: float
    longitude: float
    ingest_token: str
    devices: dict[str, Device]
    data_dir: Path

    @classmethod
    def from_env(cls) -> "Config":
        try:
            raw_devices = json.loads(os.environ["DASHBOARD_DEVICES_JSON"])
        except KeyError as exc:
            raise RuntimeError("DASHBOARD_DEVICES_JSON is required") from exc
        except json.JSONDecodeError as exc:
            raise RuntimeError("DASHBOARD_DEVICES_JSON must be valid JSON") from exc
        if not isinstance(raw_devices, list):
            raise RuntimeError("DASHBOARD_DEVICES_JSON must be a list")
        devices = {device.id: device for device in map(Device.from_dict, raw_devices)}
        if not devices:
            raise RuntimeError("at least one device is required")
        return cls(
            timezone=os.environ.get("DASHBOARD_TIMEZONE", "Asia/Shanghai"),
            city=os.environ.get("DASHBOARD_CITY", "未配置城市"),
            latitude=float(os.environ.get("DASHBOARD_LATITUDE", "0")),
            longitude=float(os.environ.get("DASHBOARD_LONGITUDE", "0")),
            ingest_token=os.environ.get("DASHBOARD_INGEST_TOKEN", ""),
            devices=devices,
            data_dir=Path(os.environ.get("DASHBOARD_DATA_DIR", "/data")),
        )


class State:
    def __init__(self, path: Path):
        self.path = path
        self.lock = threading.Lock()
        self.data: dict[str, Any] = {
            "quota": {},
            "weather": {},
            "weather_updated_at": 0.0,
        }
        self._load()

    def _load(self) -> None:
        try:
            loaded = json.loads(self.path.read_text(encoding="utf-8"))
            if isinstance(loaded, dict):
                self.data.update(loaded)
        except FileNotFoundError:
            return
        except (OSError, json.JSONDecodeError):
            return

    def snapshot(self) -> dict[str, Any]:
        with self.lock:
            return json.loads(json.dumps(self.data))

    def update(self, key: str, value: Any) -> None:
        with self.lock:
            self.data[key] = value
            self._save()

    def update_quota_source(self, source: str, accounts: list[dict[str, str]], updated_at: str) -> None:
        """Replace one collector's contribution without erasing other collectors."""
        with self.lock:
            quota = self.data.get("quota")
            quota = dict(quota) if isinstance(quota, dict) else {}
            raw_sources = quota.get("sources")
            sources = dict(raw_sources) if isinstance(raw_sources, dict) else {}
            sources[source] = {"accounts": accounts, "updated_at": updated_at}
            quota["sources"] = sources
            quota["updated_at"] = updated_at
            self.data["quota"] = quota
            self._save()

    def _save(self) -> None:
            self.path.parent.mkdir(parents=True, exist_ok=True)
            temporary = self.path.with_suffix(".tmp")
            temporary.write_text(json.dumps(self.data, ensure_ascii=False), encoding="utf-8")
            temporary.replace(self.path)


def _font(size: int) -> ImageFont.FreeTypeFont | ImageFont.ImageFont:
    for path in FONT_PATHS:
        if os.path.exists(path):
            return ImageFont.truetype(path, size=size)
    return ImageFont.load_default()


def _weather_label(code: Any) -> str:
    labels = {
        0: "晴", 1: "晴间多云", 2: "多云", 3: "阴", 45: "雾", 48: "雾凇",
        51: "毛毛雨", 53: "毛毛雨", 55: "毛毛雨", 61: "小雨", 63: "中雨",
        65: "大雨", 71: "小雪", 73: "中雪", 75: "大雪", 80: "阵雨",
        81: "阵雨", 82: "暴雨", 95: "雷雨",
    }
    return labels.get(code, "--")


def _format_reset(value: Any, now: datetime) -> str:
    if not value:
        return "--"
    try:
        reset = datetime.fromisoformat(str(value).replace("Z", "+00:00")).astimezone(now.tzinfo)
    except ValueError:
        return "--"
    seconds = int((reset - now).total_seconds())
    if seconds <= 0:
        return "即将刷新"
    hours, remainder = divmod(seconds, 3600)
    minutes = remainder // 60
    return f"{hours}小时{minutes}分后" if hours else f"{minutes}分后"


def _bar(draw: ImageDraw.ImageDraw, box: tuple[int, int, int, int], percent: Any) -> None:
    x1, y1, x2, y2 = box
    draw.rounded_rectangle(box, radius=max(3, (y2 - y1) // 2), outline=40, width=max(1, (y2 - y1) // 7))
    try:
        progress = max(0, min(100, int(percent)))
    except (TypeError, ValueError):
        progress = 0
    if progress:
        fill_x = x1 + (x2 - x1) * progress // 100
        draw.rounded_rectangle((x1, y1, max(x1 + 1, fill_x), y2), radius=max(3, (y2 - y1) // 2), fill=30)


def _quota_accounts(quota: dict[str, Any]) -> list[dict[str, str]]:
    """Flatten separately submitted collector payloads in a predictable order."""
    sources = quota.get("sources")
    if not isinstance(sources, dict):
        accounts = quota.get("accounts")
        return accounts if isinstance(accounts, list) else []

    flattened: list[dict[str, str]] = []
    ordered_sources = [name for name in ("claude", "deepseek", "codex", "grok2api") if name in sources]
    ordered_sources.extend(name for name in sources if name not in ordered_sources)
    codex_count = 0
    for source in ordered_sources:
        entry = sources.get(source)
        accounts = entry.get("accounts") if isinstance(entry, dict) else None
        if not isinstance(accounts, list):
            continue
        for account in accounts:
            if isinstance(account, dict):
                if source == "codex":
                    codex_count += 1
                    if codex_count > 2:
                        continue
                display_account = dict(account)
                display_account["source"] = source
                flattened.append(display_account)
    return flattened[:5]


def _quota_display_name(account: dict[str, str]) -> str:
    return "Grok" if account.get("source") == "grok2api" else str(account.get("name") or "账号")


def _calendar(draw: ImageDraw.ImageDraw, box: tuple[int, int, int, int], now: datetime, scale: float) -> None:
    x1, y1, x2, y2 = box
    title_font = _font(max(17, int(24 * scale)))
    day_font = _font(max(14, int(19 * scale)))
    small_font = _font(max(11, int(14 * scale)))
    draw.rounded_rectangle(box, radius=max(8, int(12 * scale)), outline=60, width=max(1, int(2 * scale)))
    draw.text((x1 + 18 * scale, y1 + 14 * scale), f"{now.year} 年 {now.month} 月", font=title_font, fill=0)
    top = y1 + 62 * scale
    cell_w = (x2 - x1 - 24 * scale) / 7
    cell_h = (y2 - top - 14 * scale) / 7
    for index, label in enumerate("一二三四五六日"):
        tx = x1 + 12 * scale + index * cell_w + cell_w / 2
        draw.text((tx, top), label, font=small_font, fill=70, anchor="ma")
    for row, week in enumerate(calendar.Calendar(firstweekday=0).monthdayscalendar(now.year, now.month)):
        for column, day in enumerate(week):
            if not day:
                continue
            cx = x1 + 12 * scale + column * cell_w + cell_w / 2
            cy = top + cell_h * (row + 1) + cell_h / 2
            if day == now.day:
                radius = int(min(cell_w, cell_h) * 0.33)
                draw.ellipse((cx - radius, cy - radius, cx + radius, cy + radius), fill=0)
                fill = 255
            else:
                fill = 0 if column < 5 else 55
            draw.text((cx, cy), str(day), font=day_font, fill=fill, anchor="mm")


def render_dashboard(device: Device, state: dict[str, Any], now: datetime) -> bytes:
    rotated = device.layout == "landscape" and device.rotate in {90, 270}
    width, height = (device.frame_height, device.frame_width) if rotated else (device.frame_width, device.frame_height)
    image = Image.new("L", (width, height), 255)
    draw = ImageDraw.Draw(image)
    scale = min(width / 800, height / 600)
    margin = max(18, int(26 * scale))
    title_font = _font(max(20, int(32 * scale)))
    body_font = _font(max(16, int(23 * scale)))
    small_font = _font(max(12, int(16 * scale)))
    draw.text((margin, margin), "墨水看板", font=title_font, fill=0)
    draw.text((width - margin, margin + 6 * scale), now.strftime("%H:%M"), font=body_font, fill=0, anchor="ra")
    draw.line((margin, margin + 48 * scale, width - margin, margin + 48 * scale), fill=80, width=max(1, int(scale)))

    weather = state.get("weather") or {}
    quota = state.get("quota") or {}
    updated = datetime.fromtimestamp(float(state.get("weather_updated_at") or 0), now.tzinfo) if state.get("weather_updated_at") else None

    if device.layout == "portrait":
        calendar_box = (margin, int(height * 0.12), width - margin, int(height * 0.57))
        weather_box = (margin, int(height * 0.60), width - margin, int(height * 0.74))
        quota_box = (margin, int(height * 0.77), width - margin, height - margin)
    else:
        calendar_box = (margin, int(height * 0.14), int(width * 0.58), height - margin)
        weather_box = (int(width * 0.61), int(height * 0.17), width - margin, int(height * 0.48))
        quota_box = (int(width * 0.61), int(height * 0.53), width - margin, height - margin)

    _calendar(draw, calendar_box, now, scale)
    draw.rounded_rectangle(weather_box, radius=max(8, int(12 * scale)), outline=60, width=max(1, int(2 * scale)))
    wx1, wy1, wx2, wy2 = weather_box
    draw.text((wx1 + 18 * scale, wy1 + 14 * scale), weather.get("city", "天气"), font=body_font, fill=0)
    temperature = weather.get("temperature")
    temperature_text = f"{round(float(temperature))}°" if isinstance(temperature, (int, float)) else "--°"
    draw.text((wx1 + 18 * scale, wy1 + 52 * scale), temperature_text, font=title_font, fill=0)
    draw.text((wx1 + 150 * scale, wy1 + 61 * scale), _weather_label(weather.get("code")), font=body_font, fill=0)
    high = weather.get("high")
    low = weather.get("low")
    range_text = f"{round(float(low))}–{round(float(high))}°" if isinstance(high, (int, float)) and isinstance(low, (int, float)) else "--"
    draw.text((wx1 + 18 * scale, wy2 - 30 * scale), f"今日 {range_text}  湿度 {weather.get('humidity', '--')}%", font=small_font, fill=40)

    draw.rounded_rectangle(quota_box, radius=max(8, int(12 * scale)), outline=60, width=max(1, int(2 * scale)))
    qx1, qy1, qx2, qy2 = quota_box
    accounts = _quota_accounts(quota)
    if accounts:
        draw.text((qx1 + 18 * scale, qy1 + 14 * scale), "AI 额度", font=body_font, fill=0)
        rows = accounts[:5]
        row_height = max(25 * scale, (qy2 - qy1 - 58 * scale) / max(1, len(rows)))
        for index, account in enumerate(rows):
            if not isinstance(account, dict):
                continue
            y = qy1 + 55 * scale + index * row_height
            name = _quota_display_name(account)[:14]
            summary = str(account.get("summary") or "--")[:38]
            draw.text((qx1 + 18 * scale, y), name, font=small_font, fill=0)
            draw.text((qx2 - 18 * scale, y), summary, font=small_font, fill=0, anchor="ra")
    else:
        draw.text((qx1 + 18 * scale, qy1 + 14 * scale), "Codex 额度", font=body_font, fill=0)
        five = quota.get("five_used_percent")
        week = quota.get("week_used_percent")
        first_y = qy1 + 58 * scale
        second_y = qy1 + 112 * scale
        draw.text((qx1 + 18 * scale, first_y), f"5 小时  {five if isinstance(five, int) else '--'}%", font=small_font, fill=0)
        _bar(draw, (int(qx1 + 145 * scale), int(first_y), qx2 - int(18 * scale), int(first_y + 17 * scale)), five)
        draw.text((qx1 + 18 * scale, second_y), f"本周    {week if isinstance(week, int) else '--'}%", font=small_font, fill=0)
        _bar(draw, (int(qx1 + 145 * scale), int(second_y), qx2 - int(18 * scale), int(second_y + 17 * scale)), week)
        draw.text((qx1 + 18 * scale, qy2 - 30 * scale), f"5小时 {_format_reset(quota.get('five_reset_at'), now)}", font=small_font, fill=40)
    footer = f"天气更新 {updated.strftime('%H:%M') if updated else '--:--'}"
    draw.text((width - margin, height - max(6, int(8 * scale))), footer, font=small_font, fill=90, anchor="rs")

    if device.rotate:
        image = image.rotate(device.rotate, expand=True, fillcolor=255)
    output = io.BytesIO()
    image.save(output, format="PNG", optimize=True)
    return output.getvalue()


def _fetch_weather(config: Config) -> dict[str, Any]:
    if not (-90 <= config.latitude <= 90 and -180 <= config.longitude <= 180) or (config.latitude == 0 and config.longitude == 0):
        return {}
    parameters = urllib.parse.urlencode({
        "latitude": config.latitude,
        "longitude": config.longitude,
        "current": "temperature_2m,relative_humidity_2m,weather_code",
        "daily": "temperature_2m_max,temperature_2m_min",
        "timezone": config.timezone,
    })
    request_url = f"https://api.open-meteo.com/v1/forecast?{parameters}"
    with urllib.request.urlopen(request_url, timeout=12) as response:
        payload = json.loads(response.read().decode("utf-8"))
    current = payload.get("current") or {}
    daily = payload.get("daily") or {}
    return {
        "city": config.city,
        "temperature": current.get("temperature_2m"),
        "humidity": current.get("relative_humidity_2m"),
        "code": current.get("weather_code"),
        "high": (daily.get("temperature_2m_max") or [None])[0],
        "low": (daily.get("temperature_2m_min") or [None])[0],
    }


def create_app(config: Config | None = None) -> Flask:
    config = config or Config.from_env()
    timezone = ZoneInfo(config.timezone)
    state = State(config.data_dir / "state.json")
    app = Flask(__name__)

    def refresh_weather() -> None:
        snapshot = state.snapshot()
        if time.time() - float(snapshot.get("weather_updated_at") or 0) < 900:
            return
        try:
            weather = _fetch_weather(config)
        except (OSError, TimeoutError, ValueError, json.JSONDecodeError):
            return
        if weather:
            state.update("weather", weather)
            state.update("weather_updated_at", time.time())

    @app.get("/health")
    def health() -> Response:
        return jsonify({"ok": True, "devices": sorted(config.devices)})

    @app.get("/frame/<device_id>/<token>.png")
    def frame(device_id: str, token: str) -> Response:
        device = config.devices.get(device_id)
        if not device or not hmac.compare_digest(device.token, token):
            return Response(status=404)
        refresh_weather()
        now = datetime.now(timezone)
        png = render_dashboard(device, state.snapshot(), now)
        return Response(png, content_type="image/png", headers={"Cache-Control": "no-store, max-age=0"})

    @app.post("/v1/ingest/quota")
    def ingest_quota() -> Response:
        authorization = request.headers.get("Authorization", "")
        if not config.ingest_token or not hmac.compare_digest(authorization, f"Bearer {config.ingest_token}"):
            return Response(status=401)
        payload = request.get_json(silent=True)
        if not isinstance(payload, dict):
            return jsonify({"error": "JSON object required"}), 400
        accounts = payload.get("accounts")
        if accounts is not None:
            if not isinstance(accounts, list) or not 1 <= len(accounts) <= 5:
                return jsonify({"error": "accounts must contain 1 to 5 entries"}), 400
            source = payload.get("source", "default")
            if (
                not isinstance(source, str)
                or not source
                or len(source) > 32
                or any(character not in "abcdefghijklmnopqrstuvwxyz0123456789_-" for character in source)
            ):
                return jsonify({"error": "source must use lowercase letters, digits, _ or -"}), 400
            cleaned = []
            for account in accounts:
                if not isinstance(account, dict):
                    return jsonify({"error": "each account must be an object"}), 400
                name = account.get("name")
                summary = account.get("summary")
                if not isinstance(name, str) or not name.strip() or not isinstance(summary, str) or not summary.strip():
                    return jsonify({"error": "each account requires a name and summary"}), 400
                cleaned.append({"name": name.strip()[:32], "summary": summary.strip()[:80]})
            state.update_quota_source(source, cleaned, datetime.now(timezone).isoformat())
            return jsonify({"ok": True})

        quota: dict[str, Any] = {}
        for field in ("five_used_percent", "week_used_percent"):
            value = payload.get(field)
            if not isinstance(value, int) or not 0 <= value <= 100:
                return jsonify({"error": f"{field} must be an integer from 0 to 100"}), 400
            quota[field] = value
        for field in ("five_reset_at", "week_reset_at"):
            value = payload.get(field)
            if value is not None and not isinstance(value, str):
                return jsonify({"error": f"{field} must be an ISO datetime or null"}), 400
            quota[field] = value
        snapshot = state.snapshot()
        existing = snapshot.get("quota")
        if isinstance(existing, dict) and isinstance(existing.get("sources"), dict):
            quota["sources"] = existing["sources"]
        quota["updated_at"] = datetime.now(timezone).isoformat()
        state.update("quota", quota)
        return jsonify({"ok": True})

    return app


app = create_app()
