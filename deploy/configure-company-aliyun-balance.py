#!/usr/bin/env python3
"""Securely configure the RAM read-only credential for account balance reads."""
import getpass
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
from decimal import Decimal

ENV_FILE = Path("/etc/remote-eink-dashboard/company-aliyun.env")


def main() -> None:
    if os.geteuid() != 0:
        raise SystemExit("请以 root 运行")
    if ENV_FILE.exists():
        raise SystemExit("余额查询凭据文件已存在；为避免覆盖，请先通过服务器安全维护流程检查")
    aliyun = shutil.which("aliyun")
    if not aliyun:
        raise SystemExit("未找到阿里云 CLI；未保存任何凭据")
    access_key_id = input("输入公司 RAM 用户 AccessKey ID：").strip()
    access_key_secret = getpass.getpass("输入公司 RAM 用户 AccessKey Secret（输入不显示）：").strip()
    if not access_key_id or not access_key_secret:
        raise SystemExit("凭据为空；未保存")
    environment = os.environ.copy()
    environment.update({
        "ALIBABA_CLOUD_ACCESS_KEY_ID": access_key_id,
        "ALIBABA_CLOUD_ACCESS_KEY_SECRET": access_key_secret,
        "ALIBABA_CLOUD_IGNORE_PROFILE": "TRUE",
    })
    result = subprocess.run(
        [aliyun, "bssopenapi", "QueryAccountBalance", "--region", "cn-hangzhou"],
        capture_output=True,
        text=True,
        timeout=30,
        env=environment,
    )
    if result.returncode != 0:
        raise SystemExit("余额查询失败；未保存凭据。请核对 RAM 只读权限和 AccessKey")
    try:
        payload = json.loads(result.stdout)
        data = payload.get("Data")
        amount = Decimal(str(data["AvailableAmount"]))
        currency = str(data["Currency"])
        if payload.get("Success") is not True or currency not in {"CNY", "USD", "JPY"}:
            raise ValueError
    except (ValueError, KeyError, TypeError, json.JSONDecodeError):
        raise SystemExit("阿里云余额响应格式异常；未保存凭据")
    ENV_FILE.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    contents = (
        "ALIBABA_CLOUD_ACCESS_KEY_ID=" + access_key_id + "\n"
        + "ALIBABA_CLOUD_ACCESS_KEY_SECRET=" + access_key_secret + "\n"
        + "ALIBABA_CLOUD_IGNORE_PROFILE=TRUE\n"
    )
    with tempfile.NamedTemporaryFile(mode="w", encoding="utf-8", dir=ENV_FILE.parent, prefix=".company-aliyun-", delete=False) as handle:
        handle.write(contents)
        handle.flush()
        os.fsync(handle.fileno())
        temporary = handle.name
    os.chmod(temporary, 0o600)
    os.replace(temporary, ENV_FILE)
    access_key_secret = ""
    symbol = {"CNY": "¥", "USD": "$", "JPY": "¥"}[currency]
    print("公司阿里云账户可用余额：" + symbol + f"{amount:,.2f}")
    print("只读凭据已保存到 /etc/remote-eink-dashboard/company-aliyun.env（0600）")
    try:
        subprocess.run(["systemctl", "start", "remote-eink-dashboard-company-aliyun.service"], check=True)
    except (OSError, subprocess.CalledProcessError):
        print("余额已核验；可手动启动 remote-eink-dashboard-company-aliyun.service 更新看板")
    else:
        print("看板余额采集已启动")


if __name__ == "__main__":
    try:
        main()
    except (KeyboardInterrupt, EOFError, subprocess.TimeoutExpired):
        raise SystemExit("已取消或超时；未确认保存结果")
