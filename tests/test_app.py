import io
import json
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo

from PIL import Image

from server.app import Config, Device, create_app, render_dashboard


def config(tmp_path: Path) -> Config:
    device = Device("kpw3", "frame-token", 1072, 1448, "portrait", 0)
    return Config("Asia/Shanghai", "测试城市", 0, 0, "ingest-token", {device.id: device}, tmp_path)


def test_frame_requires_device_token(tmp_path):
    client = create_app(config(tmp_path)).test_client()
    assert client.get("/frame/kpw3/wrong.png").status_code == 404
    response = client.get("/frame/kpw3/frame-token.png")
    assert response.status_code == 200
    assert response.content_type == "image/png"
    image = Image.open(io.BytesIO(response.data))
    assert image.size == (1072, 1448)
    assert image.mode == "L"


def test_quota_ingest_uses_bearer_token(tmp_path):
    client = create_app(config(tmp_path)).test_client()
    payload = {"five_used_percent": 42, "week_used_percent": 10, "five_reset_at": None, "week_reset_at": None}
    assert client.post("/v1/ingest/quota", json=payload).status_code == 401
    response = client.post("/v1/ingest/quota", json=payload, headers={"Authorization": "Bearer ingest-token"})
    assert response.status_code == 200


def test_landscape_frame_rotates_to_physical_size():
    device = Device("oasis1", "token", 1080, 1440, "landscape", 90)
    png = render_dashboard(device, {}, datetime(2026, 7, 16, tzinfo=ZoneInfo("Asia/Shanghai")))
    image = Image.open(io.BytesIO(png))
    assert image.size == (1080, 1440)


def test_frame_renders_multiple_quota_accounts():
    device = Device("kpw3", "token", 1072, 1448, "portrait", 0)
    state = {"quota": {"accounts": [
        {"name": "Codex 1", "summary": "5h 42% · 周 10%"},
        {"name": "Codex 2", "summary": "5h 18% · 周 32%"},
        {"name": "Codex 3", "summary": "5h 70% · 周 60%"},
        {"name": "Claude", "summary": "5h 24% · 周 15%"},
        {"name": "DeepSeek", "summary": "余额 ¥20"},
    ]}}
    png = render_dashboard(device, state, datetime(2026, 7, 16, tzinfo=ZoneInfo("Asia/Shanghai")))
    image = Image.open(io.BytesIO(png))
    assert image.size == (1072, 1448)


def test_collectors_keep_each_others_quota_accounts(tmp_path):
    client = create_app(config(tmp_path)).test_client()
    headers = {"Authorization": "Bearer ingest-token"}
    assert client.post("/v1/ingest/quota", json={
        "source": "codex",
        "accounts": [{"name": "Codex 1", "summary": "周 10%"}],
    }, headers=headers).status_code == 200
    assert client.post("/v1/ingest/quota", json={
        "source": "deepseek",
        "accounts": [{"name": "DeepSeek", "summary": "余额 ¥20"}],
    }, headers=headers).status_code == 200
    persisted = json.loads((tmp_path / "state.json").read_text(encoding="utf-8"))
    assert set(persisted["quota"]["sources"]) == {"codex", "deepseek"}
    response = client.get("/frame/kpw3/frame-token.png")
    assert response.status_code == 200
