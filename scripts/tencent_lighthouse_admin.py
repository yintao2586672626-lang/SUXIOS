#!/usr/bin/env python3
"""腾讯云轻量应用服务器只读管理入口。

密钥只能通过本机环境变量注入，禁止写入源码、命令参数或项目配置：

    $env:TENCENTCLOUD_SECRET_ID = Read-Host "SecretId"
    $secureKey = Read-Host "SecretKey" -AsSecureString
    $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureKey)
    try {
        $env:TENCENTCLOUD_SECRET_KEY = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
    }

只读查询示例：

    python scripts/tencent_lighthouse_admin.py describe --instance-id <实例ID>
    python scripts/tencent_lighthouse_admin.py firewall-list --instance-id <实例ID>
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from typing import Any


DEFAULT_REGION = "ap-shanghai"
SECRET_ID_ENV = "TENCENTCLOUD_SECRET_ID"
SECRET_KEY_ENV = "TENCENTCLOUD_SECRET_KEY"


class ConfigurationError(RuntimeError):
    """本机配置不足，尚未发起腾讯云 API 请求。"""


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="安全查询腾讯云轻量应用服务器状态（只读）",
    )
    parser.add_argument(
        "--region",
        default=DEFAULT_REGION,
        help=f"腾讯云地域，默认 {DEFAULT_REGION}",
    )

    subparsers = parser.add_subparsers(dest="command", required=True)

    describe_parser = subparsers.add_parser("describe", help="查询指定实例详情")
    describe_parser.add_argument("--instance-id", required=True, help="轻量服务器实例 ID")
    describe_parser.add_argument(
        "--show-addresses",
        action="store_true",
        help="显示完整公网/内网地址；默认脱敏",
    )

    firewall_parser = subparsers.add_parser(
        "firewall-list",
        help="查询指定实例的防火墙规则",
    )
    firewall_parser.add_argument("--instance-id", required=True, help="轻量服务器实例 ID")

    return parser


def require_environment_credentials() -> None:
    missing = [
        name
        for name in (SECRET_ID_ENV, SECRET_KEY_ENV)
        if not os.environ.get(name, "").strip()
    ]
    if missing:
        raise ConfigurationError(
            "未发现本机腾讯云凭证环境变量："
            + ", ".join(missing)
            + "。请在当前 PowerShell 会话中安全输入新密钥，不要粘贴到聊天或源码。"
        )


def create_client(region: str) -> Any:
    require_environment_credentials()

    try:
        from tencentcloud.common import credential
        from tencentcloud.lighthouse.v20200324 import lighthouse_client
    except ImportError as exc:
        raise ConfigurationError(
            "缺少腾讯云 SDK。请先执行："
            "python -m pip install -r requirements-cloud.txt"
        ) from exc

    cloud_credential = credential.EnvironmentVariableCredential().get_credential()
    return lighthouse_client.LighthouseClient(cloud_credential, region)


def mask_address(address: str) -> str:
    if not address:
        return ""

    ipv4_parts = address.split(".")
    if len(ipv4_parts) == 4 and all(part.isdigit() for part in ipv4_parts):
        return ".".join([ipv4_parts[0], ipv4_parts[1], "x", "x"])

    if ":" in address:
        ipv6_parts = [part for part in address.split(":") if part]
        prefix = ":".join(ipv6_parts[:2])
        return f"{prefix}:…" if prefix else "…"

    return "***"


def format_addresses(addresses: list[str] | None, show_addresses: bool) -> list[str]:
    values = addresses or []
    if show_addresses:
        return values
    return [mask_address(value) for value in values]


def query_instance(client: Any, instance_id: str, show_addresses: bool) -> dict[str, Any]:
    from tencentcloud.lighthouse.v20200324 import models

    request = models.DescribeInstancesRequest()
    request.InstanceIds = [instance_id]
    response = client.DescribeInstances(request)

    if not response.InstanceSet:
        raise RuntimeError("未查询到目标实例；请检查地域、实例 ID 和 CAM 权限。")

    instance = response.InstanceSet[0]
    system_disk = instance.SystemDisk
    internet = instance.InternetAccessible

    return {
        "instance_id": instance.InstanceId,
        "instance_name": instance.InstanceName,
        "state": instance.InstanceState,
        "region_zone": instance.Zone,
        "platform": instance.Platform,
        "os_name": instance.OsName,
        "cpu_cores": instance.CPU,
        "memory_gb": instance.Memory,
        "system_disk_gb": getattr(system_disk, "DiskSize", None),
        "internet_max_bandwidth_mbps": getattr(
            internet,
            "InternetMaxBandwidthOut",
            None,
        ),
        "public_addresses": format_addresses(
            instance.PublicAddresses,
            show_addresses,
        ),
        "private_addresses": format_addresses(
            instance.PrivateAddresses,
            show_addresses,
        ),
        "expired_time": instance.ExpiredTime,
        "latest_operation": instance.LatestOperation,
        "latest_operation_state": instance.LatestOperationState,
        "request_id": response.RequestId,
    }


def query_firewall_rules(client: Any, instance_id: str) -> dict[str, Any]:
    from tencentcloud.lighthouse.v20200324 import models

    request = models.DescribeFirewallRulesRequest()
    request.InstanceId = instance_id
    request.Offset = 0
    request.Limit = 100
    response = client.DescribeFirewallRules(request)

    rules = []
    for rule in response.FirewallRuleSet or []:
        rules.append(
            {
                "app_type": rule.AppType,
                "protocol": rule.Protocol,
                "port": rule.Port,
                "ipv4_cidr": rule.CidrBlock,
                "ipv6_cidr": rule.Ipv6CidrBlock,
                "action": rule.Action,
                "description": rule.FirewallRuleDescription,
            }
        )

    return {
        "instance_id": instance_id,
        "firewall_version": response.FirewallVersion,
        "rule_count": response.TotalCount,
        "rules": rules,
        "request_id": response.RequestId,
    }


def execute(args: argparse.Namespace) -> dict[str, Any]:
    client = create_client(args.region)

    if args.command == "describe":
        return query_instance(client, args.instance_id, args.show_addresses)
    if args.command == "firewall-list":
        return query_firewall_rules(client, args.instance_id)

    raise ConfigurationError(f"不支持的命令：{args.command}")


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()

    try:
        result = execute(args)
    except ConfigurationError as exc:
        print(f"配置错误：{exc}", file=sys.stderr)
        return 2
    except Exception as exc:  # SDK 异常需要保留真实类型，便于定位权限或网络问题。
        print(f"腾讯云 API 调用失败：{type(exc).__name__}: {exc}", file=sys.stderr)
        return 1

    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
