#!/usr/bin/env python3
"""Collect a minimal server status summary and push it to the dashboard.

Standard-library port of php/collect_server_status.php for hosts without PHP.
It reads /proc, the root filesystem capacity and an optional local Docker
socket only, and submits the same payload to the authenticated remote-status
endpoint. Configure it through the same environment variables:

    DASHBOARD_DATA_DIR              local state directory
    DASHBOARD_STATUS_URL            dashboard base URL
    DASHBOARD_SERVER_ID             registered server id
    DASHBOARD_SERVER_STATUS_TOKEN   remote-status ingest token
"""
from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone

USER_AGENT = "remote-eink-dashboard-status/1.0"
DATA_DIR = os.environ.get("DASHBOARD_DATA_DIR") or os.path.join(os.path.dirname(os.path.abspath(__file__)), ".dashboard-data")
STATUS_FILE = os.path.join(DATA_DIR, "server-status.json")
CPU_FILE = os.path.join(DATA_DIR, "server-status-cpu.json")


def cpu_totals() -> dict | None:
    try:
        with open("/proc/stat", encoding="ascii") as handle:
            first = handle.readline()
    except OSError:
        return None
    parts = first.split()
    if len(parts) < 5 or parts[0] != "cpu":
        return None
    values = [float(part) for part in parts[1:] if re.fullmatch(r"\d+", part)]
    if len(values) < 4:
        return None
    return {"total": sum(values), "idle": values[3] + (values[4] if len(values) > 4 else 0.0)}


def memory_status() -> dict:
    values = {}
    try:
        with open("/proc/meminfo", encoding="ascii") as handle:
            for line in handle:
                matched = re.fullmatch(r"(MemTotal|MemAvailable):\s+(\d+)\s+kB", line.strip())
                if matched:
                    values[matched.group(1)] = int(matched.group(2)) * 1024
    except OSError:
        pass
    total = values.get("MemTotal", 0)
    available = values.get("MemAvailable", 0)
    return {"total_bytes": total, "used_bytes": max(0, total - available)}


def docker_status() -> dict:
    path = shutil.which("docker")
    if not path:
        return {"available": False, "containers": []}
    try:
        result = subprocess.run(
            [path, "ps", "-a", "--format", "{{.Names}}\t{{.Status}}"],
            capture_output=True,
            text=True,
            timeout=15,
        )
    except (OSError, subprocess.SubprocessError):
        return {"available": False, "containers": []}
    if result.returncode != 0:
        return {"available": False, "containers": []}
    containers = []
    for line in result.stdout.splitlines():
        name, _, status = line.partition("\t")
        if not name:
            continue
        containers.append({"name": name, "status": status, "running": status.startswith("Up ")})
    return {"available": True, "containers": containers}


def read_previous_cpu() -> dict | None:
    try:
        with open(CPU_FILE, encoding="ascii") as handle:
            previous = json.load(handle)
    except (OSError, ValueError):
        return None
    if not isinstance(previous, dict):
        return None
    if not isinstance(previous.get("total"), (int, float)) or not isinstance(previous.get("idle"), (int, float)):
        return None
    return previous


def write_json(path: str, payload: dict, mode: int) -> None:
    temporary = path + ".tmp"
    with open(temporary, "w", encoding="utf-8") as handle:
        json.dump(payload, handle, ensure_ascii=False)
    os.replace(temporary, path)
    os.chmod(path, mode)


def push_status(payload: dict) -> None:
    base_url = (os.environ.get("DASHBOARD_STATUS_URL") or "").rstrip("/")
    server_id = (os.environ.get("DASHBOARD_SERVER_ID") or "").strip()
    token = (os.environ.get("DASHBOARD_SERVER_STATUS_TOKEN") or "").strip()
    if not base_url and not server_id and not token:
        return
    if not base_url or not re.fullmatch(r"[a-z0-9_-]{1,32}", server_id) or not token:
        sys.exit(1)
    request = urllib.request.Request(
        base_url + "/v1/ingest/server-status/" + urllib.parse.quote(server_id, safe=""),
        data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        headers={
            "Authorization": "Bearer " + token,
            "Content-Type": "application/json",
            # A default urllib agent is rejected by some CDNs in front of the dashboard.
            "User-Agent": USER_AGENT,
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            if not 200 <= response.status < 300:
                print("push rejected: HTTP " + str(response.status), file=sys.stderr)
                sys.exit(1)
    except urllib.error.HTTPError as error:
        print("push rejected: HTTP " + str(error.code), file=sys.stderr)
        sys.exit(1)
    except (urllib.error.URLError, OSError) as error:
        print("push failed: " + str(error), file=sys.stderr)
        sys.exit(1)


def main() -> None:
    os.makedirs(DATA_DIR, mode=0o755, exist_ok=True)
    cpu = cpu_totals()
    previous = read_previous_cpu()
    cpu_percent = None
    if cpu and previous:
        delta_total = cpu["total"] - float(previous["total"])
        delta_idle = cpu["idle"] - float(previous["idle"])
        if delta_total > 0:
            cpu_percent = round(max(0.0, min(100.0, 100 * (delta_total - delta_idle) / delta_total)), 1)
    if cpu:
        write_json(CPU_FILE, cpu, 0o644)

    stat = os.statvfs("/")
    total_disk = stat.f_blocks * stat.f_frsize
    free_disk = stat.f_bavail * stat.f_frsize
    load = os.getloadavg()
    payload = {
        "updated_at": datetime.now(timezone.utc).astimezone().isoformat(timespec="seconds"),
        "cpu_percent": cpu_percent,
        "load": {"one": load[0], "five": load[1], "fifteen": load[2]},
        "memory": memory_status(),
        "disk": {"total_bytes": total_disk, "used_bytes": max(0, total_disk - free_disk)},
        "docker": docker_status(),
    }
    write_json(STATUS_FILE, payload, 0o644)
    push_status(payload)


if __name__ == "__main__":
    main()
