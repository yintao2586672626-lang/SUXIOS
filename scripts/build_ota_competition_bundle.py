#!/usr/bin/env python3
"""Build one governed OTA competition-circle analysis bundle.

The builder accepts either:

- the Meituan package directory contract
  (project_config.json + market_summary.json + hotels.csv);
- a Ctrip CSV using the package's documented columns; or
- a canonical JSON object containing project_config, market_summary and hotels.

It deliberately keeps Ctrip and Meituan calculations separate. Missing source
totals, hotel binding, dates, or denominators stay missing and make the result
ineligible for decisions; they are never replaced with detail sums or zero.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import math
import re
import statistics
import sys
from datetime import date, datetime
from pathlib import Path
from typing import Any, Iterable


SCHEMA_VERSION = "1.0.0"
MISSING_MARKERS = {"", "-", "--", "—", "null", "none", "n/a", "na"}
HARD_SOURCE_STATUSES = {
    "binding_missing",
    "permission_denied",
    "collection_failed",
}
ALLOWED_SOURCE_STATUSES = {
    "available",
    "partial",
    "stale",
    "unverified",
    "binding_missing",
    "permission_denied",
    "collection_failed",
    "synthetic",
}

MEITUAN_FIELDS = (
    "stay_room_nights",
    "room_revenue",
    "stay_adr",
    "sold_room_nights",
    "sales_revenue",
    "sales_adr",
    "impressions",
    "views",
    "orders",
)
MEITUAN_RATE_FIELDS = (
    "platform_view_conversion",
    "platform_pay_conversion",
    "platform_absolute_conversion",
)
CTRIP_FIELDS = (
    "sales",
    "room_nights",
    "adr",
    "ari",
    "sci",
    "ctrip_orders",
    "ctrip_visitors",
    "ctrip_rating",
    "qunar_visitors",
    "qunar_rating",
)
CTRIP_RATE_FIELDS = (
    "ctrip_platform_conversion",
    "booking_conversion",
    "qunar_conversion",
)

MEITUAN_TOTALS = {
    "total_stay_room_nights": "stay_room_nights",
    "total_room_revenue": "room_revenue",
    "total_sold_room_nights": "sold_room_nights",
    "total_sales_revenue": "sales_revenue",
    "total_impressions": "impressions",
    "total_views": "views",
    "total_orders": "orders",
}
CTRIP_TOTALS = {
    "total_sales": "sales",
    "total_room_nights": "room_nights",
    "total_ctrip_visitors": "ctrip_visitors",
    "total_ctrip_orders": "ctrip_orders",
}


class BundleBuildError(ValueError):
    """Raised when a source cannot be parsed into a bundle at all."""


def _unique(items: Iterable[str]) -> list[str]:
    return list(dict.fromkeys(item for item in items if item))


def _text(value: Any) -> str:
    if value is None:
        return ""
    return str(value).strip()


def _is_missing(value: Any) -> bool:
    return value is None or _text(value).lower() in MISSING_MARKERS


def parse_number(value: Any, field: str, gaps: list[str]) -> float | None:
    """Parse a number without turning missing/invalid values into zero."""

    if _is_missing(value):
        return None
    if isinstance(value, bool):
        gaps.append(f"{field}:布尔值不能作为数值")
        return None
    text = _text(value).replace(",", "").replace("￥", "").replace("¥", "")
    if text.endswith("%"):
        gaps.append(f"{field}:百分比不能作为普通数值")
        return None
    try:
        number = float(text)
    except (TypeError, ValueError):
        gaps.append(f"{field}:无法解析数值")
        return None
    if not math.isfinite(number):
        gaps.append(f"{field}:非有限数值")
        return None
    if number < 0:
        gaps.append(f"{field}:不允许负数")
        return None
    return number


def parse_rate(value: Any, field: str, gaps: list[str]) -> float | None:
    """Parse a rate while rejecting the ambiguous bare value 1.

    Use 0.01 for 1%, "1%" for 1%, or "100%" for 100%.
    """

    if _is_missing(value):
        return None
    text = _text(value).replace(",", "")
    percent_marked = text.endswith("%")
    if percent_marked:
        text = text[:-1].strip()
    try:
        number = float(text)
    except (TypeError, ValueError):
        gaps.append(f"{field}:无法解析百分比")
        return None
    if not math.isfinite(number) or number < 0:
        gaps.append(f"{field}:百分比无效")
        return None
    if percent_marked:
        number /= 100
    elif number == 1:
        gaps.append(f"{field}:裸值1含义不明确，请写0.01、1%或100%")
        return None
    elif number > 1:
        number /= 100
    if number > 1:
        gaps.append(f"{field}:百分比超过100%")
        return None
    return number


def safe_ratio(numerator: float | None, denominator: float | None) -> float | None:
    if numerator is None or denominator is None or denominator <= 0:
        return None
    return numerator / denominator


def rounded(value: float | None, digits: int = 6) -> float | None:
    return None if value is None else round(value, digits)


def first_present(*values: float | None) -> float | None:
    for value in values:
        if value is not None:
            return value
    return None


def truthy(value: Any) -> bool:
    return _text(value).lower() in {"1", "true", "yes", "y", "是", "本店", "我的酒店"}


def normalize_platform(value: Any) -> str:
    text = _text(value).lower()
    if "美团" in text or text == "meituan":
        return "meituan"
    if "携程" in text or text == "ctrip":
        return "ctrip"
    raise BundleBuildError("platform 必须明确为 meituan/美团 或 ctrip/携程")


def read_json(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8-sig"))
    except FileNotFoundError as exc:
        raise BundleBuildError(f"文件不存在: {path}") from exc
    except json.JSONDecodeError as exc:
        raise BundleBuildError(f"JSON 无法解析: {path}: {exc}") from exc
    if not isinstance(value, dict):
        raise BundleBuildError(f"JSON 顶层必须是对象: {path}")
    return value


def read_csv(path: Path) -> list[dict[str, Any]]:
    try:
        with path.open("r", encoding="utf-8-sig", newline="") as handle:
            return [dict(row) for row in csv.DictReader(handle)]
    except FileNotFoundError as exc:
        raise BundleBuildError(f"文件不存在: {path}") from exc


def load_source(
    source: Path,
    context_path: Path | None,
    platform_override: str | None,
) -> dict[str, Any]:
    context = read_json(context_path) if context_path else {}
    config: dict[str, Any] = {}
    summary: dict[str, Any] = {}
    hotels: list[dict[str, Any]]
    source_contract: str

    if source.is_dir():
        config = read_json(source / "project_config.json")
        summary = read_json(source / "market_summary.json")
        hotels = read_csv(source / "hotels.csv")
        source_contract = "meituan_package_directory"
    elif source.suffix.lower() == ".csv":
        hotels = read_csv(source)
        summary = context.get("market_summary", {})
        if not isinstance(summary, dict):
            raise BundleBuildError("context.market_summary 必须是对象")
        source_contract = "ctrip_package_csv"
    elif source.suffix.lower() == ".json":
        payload = read_json(source)
        config = payload.get("project_config", {})
        summary = payload.get("market_summary", {})
        hotels = payload.get("hotels", [])
        embedded_context = payload.get("context", {})
        if not all(isinstance(value, dict) for value in (config, summary, embedded_context)):
            raise BundleBuildError("project_config、market_summary、context 必须是对象")
        if not isinstance(hotels, list) or not all(isinstance(row, dict) for row in hotels):
            raise BundleBuildError("hotels 必须是对象数组")
        context = {**embedded_context, **context}
        source_contract = "suxios_canonical_json"
    else:
        raise BundleBuildError("source 只支持目录、CSV 或 JSON")

    platform_value = (
        platform_override
        or context.get("platform")
        or config.get("platform")
    )
    platform = normalize_platform(platform_value)
    if source_contract == "meituan_package_directory" and platform != "meituan":
        raise BundleBuildError("美团目录契约不能声明为携程")

    fingerprint_payload = {
        "platform": platform,
        "config": config,
        "market_summary": summary,
        "hotels": hotels,
        "context": context,
    }
    fingerprint = hashlib.sha256(
        json.dumps(
            fingerprint_payload,
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        ).encode("utf-8")
    ).hexdigest()
    return {
        "platform": platform,
        "config": config,
        "market_summary": summary,
        "hotels": hotels,
        "context": context,
        "source_contract": source_contract,
        "input_fingerprint": fingerprint,
    }


def normalize_rows(
    platform: str,
    raw_rows: list[dict[str, Any]],
    gaps: list[str],
) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    numeric_fields = MEITUAN_FIELDS if platform == "meituan" else CTRIP_FIELDS
    rate_fields = MEITUAN_RATE_FIELDS if platform == "meituan" else CTRIP_RATE_FIELDS

    for index, raw in enumerate(raw_rows, start=1):
        prefix = f"hotels[{index}]"
        hotel_id = _text(raw.get("hotel_id") or raw.get("platform_hotel_id"))
        hotel_name = _text(raw.get("hotel_name"))
        rank = parse_number(raw.get("rank"), f"{prefix}.rank", gaps)
        row: dict[str, Any] = {
            "rank": int(rank) if rank is not None and rank.is_integer() else rank,
            "hotel_name": hotel_name or None,
            "platform_hotel_id": hotel_id or None,
            "is_target": truthy(raw.get("is_target")) or truthy(raw.get("is_subject")),
        }
        for field in numeric_fields:
            row[field] = parse_number(raw.get(field), f"{prefix}.{field}", gaps)
        for field in rate_fields:
            row[field] = parse_rate(raw.get(field), f"{prefix}.{field}", gaps)
        if platform == "meituan":
            row["official_position"] = _text(raw.get("official_position")) or None
            row["vip_label"] = _text(raw.get("vip_label")) or None
            row["source_material"] = _text(raw.get("source_material")) or None
        rows.append(row)
    return rows


def duplicate_issues(rows: list[dict[str, Any]]) -> list[str]:
    seen: dict[str, int] = {}
    issues: list[str] = []
    for index, row in enumerate(rows, start=1):
        hotel_id = row.get("platform_hotel_id")
        hotel_name = row.get("hotel_name")
        if hotel_id:
            key = f"id:{hotel_id}"
        elif hotel_name:
            key = f"name:{str(hotel_name).casefold()}"
        else:
            issues.append(f"hotels[{index}] 同时缺少 hotel_id 和 hotel_name")
            continue
        if key in seen:
            issues.append(f"酒店唯一键重复: {key}（第{seen[key]}行与第{index}行）")
        else:
            seen[key] = index
    return issues


def identify_target(
    rows: list[dict[str, Any]],
    context: dict[str, Any],
    config: dict[str, Any],
) -> tuple[dict[str, Any] | None, list[str]]:
    expected_ids = {
        value
        for value in (
            _text(context.get("platform_hotel_id")),
            _text(config.get("hotel_id")),
        )
        if value
    }
    expected_names = {
        value.casefold()
        for value in (
            _text(context.get("hotel_name")),
            _text(config.get("hotel_name")),
        )
        if value
    }
    candidate_indexes: set[int] = set()
    for index, row in enumerate(rows):
        if row.get("is_target"):
            candidate_indexes.add(index)
        if row.get("platform_hotel_id") in expected_ids:
            candidate_indexes.add(index)
        name = _text(row.get("hotel_name")).casefold()
        if name and name in expected_names:
            candidate_indexes.add(index)
    if len(candidate_indexes) == 1:
        return rows[next(iter(candidate_indexes))], []
    if not candidate_indexes:
        return None, ["无法唯一识别本店；需提供精确 hotel_id 或唯一 is_target/is_subject"]
    return None, [f"本店识别冲突：命中了 {len(candidate_indexes)} 行"]


def parse_summary(
    platform: str,
    raw: dict[str, Any],
    gaps: list[str],
) -> dict[str, Any]:
    totals = MEITUAN_TOTALS if platform == "meituan" else CTRIP_TOTALS
    summary: dict[str, Any] = {}
    for key in totals:
        summary[key] = parse_number(raw.get(key), f"market_summary.{key}", gaps)
    if platform == "meituan":
        summary["hotel_count"] = parse_number(
            raw.get("hotel_count"), "market_summary.hotel_count", gaps
        )
        for field in (
            "platform_avg_view_conversion",
            "platform_avg_pay_conversion",
            "platform_avg_absolute_conversion",
        ):
            summary[field] = parse_rate(raw.get(field), f"market_summary.{field}", gaps)
    else:
        if summary["total_sales"] is None:
            summary["total_sales"] = parse_number(
                raw.get("sales"), "market_summary.total_sales", gaps
            )
        if summary["total_room_nights"] is None:
            summary["total_room_nights"] = parse_number(
                raw.get("room_nights"), "market_summary.total_room_nights", gaps
            )
        if summary["total_ctrip_visitors"] is None:
            summary["total_ctrip_visitors"] = parse_number(
                raw.get("ctrip_visitors"),
                "market_summary.total_ctrip_visitors",
                gaps,
            )
        if summary["total_ctrip_orders"] is None:
            summary["total_ctrip_orders"] = parse_number(
                raw.get("ctrip_orders"), "market_summary.total_ctrip_orders", gaps
            )
    return summary


def build_closure(
    platform: str,
    summary: dict[str, Any],
    rows: list[dict[str, Any]],
    gaps: list[str],
) -> tuple[dict[str, Any], dict[str, float | None]]:
    total_map = MEITUAN_TOTALS if platform == "meituan" else CTRIP_TOTALS
    closure: dict[str, Any] = {}
    detail_totals: dict[str, float | None] = {}
    for total_key, row_key in total_map.items():
        values = [row.get(row_key) for row in rows]
        missing_rows = sum(value is None for value in values)
        present_values = [value for value in values if value is not None]
        detail_total = sum(present_values) if present_values else None
        detail_totals[total_key] = rounded(detail_total)
        source_total = summary.get(total_key)
        coverage = safe_ratio(detail_total, source_total)
        passed = (
            source_total is not None
            and source_total > 0
            and missing_rows == 0
            and coverage is not None
            and 0.90 <= coverage <= 1.10
        )
        if source_total is None:
            gaps.append(f"缺少来源汇总 {total_key}；禁止用逐店求和回填")
        elif source_total <= 0:
            gaps.append(f"来源汇总 {total_key} 必须大于0")
        if missing_rows:
            gaps.append(f"{row_key} 有 {missing_rows} 行缺失")
        if coverage is not None and not 0.90 <= coverage <= 1.10:
            gaps.append(f"{total_key} 明细/汇总闭合率超出90%-110%")
        closure[total_key] = {
            "source_total": rounded(source_total),
            "detail_total": rounded(detail_total),
            "missing_detail_rows": missing_rows,
            "detail_to_source_ratio": rounded(coverage),
            "passed": passed,
        }
    return closure, detail_totals


def validate_identity(context: dict[str, Any], gaps: list[str]) -> list[str]:
    missing: list[str] = []
    for field in (
        "system_hotel_id",
        "platform_hotel_id",
        "binding_status",
        "data_date",
        "collected_at",
        "source_method",
        "source_trace_id",
        "verification_status",
    ):
        if not _text(context.get(field)):
            missing.append(field)
    if missing:
        gaps.append("缺少宿析来源上下文: " + ", ".join(missing))

    data_date = _text(context.get("data_date"))
    if data_date:
        try:
            date.fromisoformat(data_date)
        except ValueError:
            gaps.append("data_date 必须是 YYYY-MM-DD")
            missing.append("data_date")
    collected_at = _text(context.get("collected_at"))
    if collected_at:
        try:
            datetime.fromisoformat(collected_at.replace("Z", "+00:00"))
        except ValueError:
            gaps.append("collected_at 必须是 ISO-8601 时间")
            missing.append("collected_at")
    status = _text(context.get("verification_status")).lower()
    if status and status not in ALLOWED_SOURCE_STATUSES:
        gaps.append(f"verification_status 不受支持: {status}")
        missing.append("verification_status")
    return _unique(missing)


def rank_of(rows: list[dict[str, Any]], target: dict[str, Any], field: str) -> int | None:
    target_value = target.get(field)
    if target_value is None:
        return None
    values = sorted(
        (row.get(field) for row in rows if row.get(field) is not None),
        reverse=True,
    )
    return values.index(target_value) + 1


def percentile(values: list[float], ratio: float) -> float | None:
    if not values:
        return None
    ordered = sorted(values)
    if len(ordered) == 1:
        return ordered[0]
    position = (len(ordered) - 1) * ratio
    lower = math.floor(position)
    upper = math.ceil(position)
    if lower == upper:
        return ordered[lower]
    weight = position - lower
    return ordered[lower] * (1 - weight) + ordered[upper] * weight


def compact_hotel(platform: str, row: dict[str, Any]) -> dict[str, Any]:
    common = {
        "platform_hotel_id": row.get("platform_hotel_id"),
        "hotel_name": row.get("hotel_name"),
    }
    if platform == "meituan":
        common.update(
            {
                "sales_revenue": rounded(row.get("sales_revenue")),
                "sold_room_nights": rounded(row.get("sold_room_nights")),
                "sales_adr": rounded(
                    first_present(
                        row.get("sales_adr"),
                        safe_ratio(
                            row.get("sales_revenue"), row.get("sold_room_nights")
                        ),
                    )
                ),
                "impressions": rounded(row.get("impressions")),
                "views": rounded(row.get("views")),
                "orders": rounded(row.get("orders")),
                "platform_absolute_conversion": rounded(
                    row.get("platform_absolute_conversion")
                ),
            }
        )
    else:
        common.update(
            {
                "sales": rounded(row.get("sales")),
                "room_nights": rounded(row.get("room_nights")),
                "adr": rounded(
                    first_present(
                        row.get("adr"),
                        safe_ratio(row.get("sales"), row.get("room_nights")),
                    )
                ),
                "ari": rounded(row.get("ari")),
                "sci": rounded(row.get("sci")),
                "ctrip_visitors": rounded(row.get("ctrip_visitors")),
                "ctrip_orders": rounded(row.get("ctrip_orders")),
                "ctrip_platform_conversion": rounded(
                    row.get("ctrip_platform_conversion")
                ),
            }
        )
    return common


def select_competitors(
    platform: str,
    rows: list[dict[str, Any]],
    target: dict[str, Any],
) -> dict[str, list[dict[str, Any]]]:
    candidates = [row for row in rows if row is not target]
    if platform == "meituan":
        target_adr = first_present(
            target.get("sales_adr"),
            safe_ratio(target.get("sales_revenue"), target.get("sold_room_nights")),
        )

        def adr(row: dict[str, Any]) -> float | None:
            return first_present(
                row.get("sales_adr"),
                safe_ratio(row.get("sales_revenue"), row.get("sold_room_nights")),
            )

        direct = [
            row
            for row in candidates
            if target_adr is not None
            and adr(row) is not None
            and (
                (target_adr == 0 and adr(row) == 0)
                or (target_adr != 0 and abs(adr(row) / target_adr - 1) <= 0.15)
            )
        ]
        attack = sorted(
            (row for row in candidates if row.get("sales_revenue") is not None),
            key=lambda row: row["sales_revenue"],
            reverse=True,
        )
        traffic = sorted(
            (row for row in candidates if row.get("impressions") is not None),
            key=lambda row: row["impressions"],
            reverse=True,
        )
        conversion = sorted(
            (
                row
                for row in candidates
                if row.get("platform_absolute_conversion") is not None
            ),
            key=lambda row: row["platform_absolute_conversion"],
            reverse=True,
        )
    else:
        target_adr = first_present(
            target.get("adr"),
            safe_ratio(target.get("sales"), target.get("room_nights")),
        )
        target_sci = target.get("sci")

        def adr(row: dict[str, Any]) -> float | None:
            return first_present(
                row.get("adr"),
                safe_ratio(row.get("sales"), row.get("room_nights")),
            )

        direct = [
            row
            for row in candidates
            if (
                target_adr is not None
                and adr(row) is not None
                and abs(adr(row) / target_adr - 1) <= 0.15
            )
            or (
                target_sci is not None
                and target_sci > 0
                and row.get("sci") is not None
                and abs(row["sci"] / target_sci - 1) <= 0.20
            )
        ]
        attack = sorted(
            (row for row in candidates if row.get("sales") is not None),
            key=lambda row: row["sales"],
            reverse=True,
        )
        traffic = sorted(
            (row for row in candidates if row.get("ctrip_visitors") is not None),
            key=lambda row: row["ctrip_visitors"],
            reverse=True,
        )
        conversion = sorted(
            (
                row
                for row in candidates
                if row.get("ctrip_platform_conversion") is not None
            ),
            key=lambda row: row["ctrip_platform_conversion"],
            reverse=True,
        )

    def top(items: list[dict[str, Any]]) -> list[dict[str, Any]]:
        return [compact_hotel(platform, row) for row in items[:5]]

    direct_sorted = sorted(
        direct,
        key=lambda row: abs((adr(row) or target_adr or 0) - (target_adr or 0)),
    )
    return {
        "direct": top(direct_sorted),
        "attack_benchmark": top(attack),
        "traffic_benchmark": top(traffic),
        "conversion_benchmark": top(conversion),
    }


def derive_meituan(
    summary: dict[str, Any],
    rows: list[dict[str, Any]],
    target: dict[str, Any],
) -> tuple[dict[str, Any], dict[str, Any]]:
    total_sales = summary.get("total_sales_revenue")
    total_nights = summary.get("total_sold_room_nights")
    total_impressions = summary.get("total_impressions")
    total_views = summary.get("total_views")
    total_orders = summary.get("total_orders")
    shares = [
        safe_ratio(row.get("sales_revenue"), total_sales)
        for row in rows
        if row.get("sales_revenue") is not None
    ]
    shares = [share for share in shares if share is not None]
    market = {
        "hotel_count": len(rows),
        "sales_weighted_adr": rounded(safe_ratio(total_sales, total_nights)),
        "stay_weighted_adr": rounded(
            safe_ratio(
                summary.get("total_room_revenue"),
                summary.get("total_stay_room_nights"),
            )
        ),
        "sales_hhi": rounded(sum(share * share for share in shares) if shares else None),
        "view_conversion_derived": rounded(
            safe_ratio(total_views, total_impressions)
        ),
        "pay_conversion_derived": rounded(safe_ratio(total_orders, total_views)),
        "absolute_conversion_derived": rounded(
            safe_ratio(total_orders, total_impressions)
        ),
        "platform_avg_view_conversion": rounded(
            summary.get("platform_avg_view_conversion")
        ),
        "platform_avg_pay_conversion": rounded(
            summary.get("platform_avg_pay_conversion")
        ),
        "platform_avg_absolute_conversion": rounded(
            summary.get("platform_avg_absolute_conversion")
        ),
    }
    target_metrics = {
        **compact_hotel("meituan", target),
        "stay_room_nights": rounded(target.get("stay_room_nights")),
        "room_revenue": rounded(target.get("room_revenue")),
        "stay_adr": rounded(
            first_present(
                target.get("stay_adr"),
                safe_ratio(target.get("room_revenue"), target.get("stay_room_nights")),
            )
        ),
        "sales_share": rounded(safe_ratio(target.get("sales_revenue"), total_sales)),
        "room_night_share": rounded(
            safe_ratio(target.get("sold_room_nights"), total_nights)
        ),
        "impression_share": rounded(
            safe_ratio(target.get("impressions"), total_impressions)
        ),
        "view_share": rounded(safe_ratio(target.get("views"), total_views)),
        "order_share": rounded(safe_ratio(target.get("orders"), total_orders)),
        "view_conversion_derived": rounded(
            safe_ratio(target.get("views"), target.get("impressions"))
        ),
        "pay_conversion_derived": rounded(
            safe_ratio(target.get("orders"), target.get("views"))
        ),
        "absolute_conversion_derived": rounded(
            safe_ratio(target.get("orders"), target.get("impressions"))
        ),
        "sales_rank": rank_of(rows, target, "sales_revenue"),
        "room_night_rank": rank_of(rows, target, "sold_room_nights"),
        "impression_rank": rank_of(rows, target, "impressions"),
        "order_rank": rank_of(rows, target, "orders"),
    }
    return market, target_metrics


def derive_ctrip(
    summary: dict[str, Any],
    rows: list[dict[str, Any]],
    target: dict[str, Any],
) -> tuple[dict[str, Any], dict[str, Any]]:
    total_sales = summary.get("total_sales")
    total_nights = summary.get("total_room_nights")
    total_visitors = summary.get("total_ctrip_visitors")
    total_orders = summary.get("total_ctrip_orders")
    shares = [
        safe_ratio(row.get("sales"), total_sales)
        for row in rows
        if row.get("sales") is not None
    ]
    shares = [share for share in shares if share is not None]
    platform_conversion_pairs = [
        (row.get("ctrip_platform_conversion"), row.get("ctrip_visitors"))
        for row in rows
        if row.get("ctrip_platform_conversion") is not None
        and row.get("ctrip_visitors") is not None
    ]
    platform_weight = sum(weight for _, weight in platform_conversion_pairs)
    platform_weighted_conversion = (
        sum(rate * weight for rate, weight in platform_conversion_pairs) / platform_weight
        if platform_weight > 0
        else None
    )
    visitor_average = safe_ratio(total_visitors, float(len(rows)) if rows else None)
    market = {
        "hotel_count": len(rows),
        "weighted_adr": rounded(safe_ratio(total_sales, total_nights)),
        "sales_hhi": rounded(sum(share * share for share in shares) if shares else None),
        "booking_conversion_derived": rounded(
            safe_ratio(total_orders, total_visitors)
        ),
        "platform_weighted_conversion": rounded(platform_weighted_conversion),
        "average_ctrip_visitors": rounded(visitor_average),
        "ari_mean": rounded(
            statistics.fmean(
                row["ari"] for row in rows if row.get("ari") is not None
            )
            if any(row.get("ari") is not None for row in rows)
            else None
        ),
        "sci_mean": rounded(
            statistics.fmean(
                row["sci"] for row in rows if row.get("sci") is not None
            )
            if any(row.get("sci") is not None for row in rows)
            else None
        ),
    }
    target_metrics = {
        **compact_hotel("ctrip", target),
        "sales_share": rounded(safe_ratio(target.get("sales"), total_sales)),
        "room_night_share": rounded(
            safe_ratio(target.get("room_nights"), total_nights)
        ),
        "visitor_share": rounded(
            safe_ratio(target.get("ctrip_visitors"), total_visitors)
        ),
        "order_share": rounded(safe_ratio(target.get("ctrip_orders"), total_orders)),
        "booking_conversion_derived": rounded(
            safe_ratio(target.get("ctrip_orders"), target.get("ctrip_visitors"))
        ),
        "single_visitor_value": rounded(
            safe_ratio(target.get("sales"), target.get("ctrip_visitors"))
        ),
        "traffic_scale_index": rounded(
            (
                safe_ratio(target.get("ctrip_visitors"), visitor_average) * 100
                if safe_ratio(target.get("ctrip_visitors"), visitor_average) is not None
                else None
            )
        ),
        "platform_conversion_index": rounded(
            (
                safe_ratio(
                    target.get("ctrip_platform_conversion"),
                    platform_weighted_conversion,
                )
                * 100
                if safe_ratio(
                    target.get("ctrip_platform_conversion"),
                    platform_weighted_conversion,
                )
                is not None
                else None
            )
        ),
        "sales_rank": rank_of(rows, target, "sales"),
        "room_night_rank": rank_of(rows, target, "room_nights"),
        "adr_rank": rank_of(rows, target, "adr"),
        "ari_rank": rank_of(rows, target, "ari"),
        "sci_rank": rank_of(rows, target, "sci"),
        "visitor_rank": rank_of(rows, target, "ctrip_visitors"),
        "order_rank": rank_of(rows, target, "ctrip_orders"),
    }
    return market, target_metrics


def decision_diagnosis(
    platform: str,
    market: dict[str, Any],
    target: dict[str, Any],
    eligible: bool,
) -> dict[str, Any]:
    if not eligible:
        return {
            "status": "withheld",
            "channel_role": None,
            "first_conflict": None,
            "reason": "数据质量或来源链未达到决策门槛",
        }

    if platform == "meituan":
        average_share = 1 / market["hotel_count"] if market["hotel_count"] else None
        scale_index = safe_ratio(target.get("sales_share"), average_share)
        conversion_index = safe_ratio(
            target.get("platform_absolute_conversion"),
            market.get("platform_avg_absolute_conversion"),
        )
        if scale_index is None or conversion_index is None:
            return {
                "status": "withheld",
                "channel_role": None,
                "first_conflict": None,
                "reason": "角色判断缺少规模或平台转化口径",
            }
        if scale_index >= 1.1 and conversion_index >= 1.1:
            role = "美团渠道规模效率领跑型"
        elif scale_index >= 1.1 and conversion_index < 0.9:
            role = "美团渠道规模高但转化承压型"
        elif scale_index < 0.9 and conversion_index >= 1.1:
            role = "美团渠道效率型增长候选"
        else:
            role = "美团渠道中腰部防守型"
        if scale_index < 0.9:
            conflict = "渠道规模不足"
        elif conversion_index < 0.9:
            conflict = "平台转化效率不足"
        elif target.get("room_night_share") is not None and (
            target["room_night_share"] + 0.02 < target.get("sales_share", 0)
        ):
            conflict = "价格贡献高于间夜规模，需验证量价可持续性"
        else:
            conflict = "无单一强冲突，进入小步实验"
    else:
        traffic_index = target.get("traffic_scale_index")
        conversion_index = target.get("platform_conversion_index")
        if traffic_index is None or conversion_index is None:
            return {
                "status": "withheld",
                "channel_role": None,
                "first_conflict": None,
                "reason": "角色判断缺少携程访客或平台转化口径",
            }
        if traffic_index >= 110 and conversion_index >= 110:
            role = "携程渠道规模效率领跑型"
        elif traffic_index >= 110 and conversion_index < 90:
            role = "携程渠道流量高但转化承压型"
        elif traffic_index < 90 and conversion_index >= 110:
            role = "携程渠道效率型增长候选"
        else:
            role = "携程渠道中腰部防守型"
        if traffic_index < 90:
            conflict = "携程渠道流量规模不足"
        elif conversion_index < 90:
            conflict = "携程平台转化效率不足"
        elif target.get("ari") is not None and target.get("sci") is not None:
            if target["ari"] > 110 and target["sci"] < 100:
                conflict = "相对价格偏高且渠道竞争力未同步"
            elif target["ari"] < 90 and target["sci"] >= 100:
                conflict = "低价竞争力较强，需验证提价空间"
            else:
                conflict = "无单一强冲突，进入小步实验"
        else:
            conflict = "无单一强冲突，进入小步实验"
    return {
        "status": "ready",
        "channel_role": role,
        "first_conflict": conflict,
    }


def route_editions(
    configured: Any,
    override: str | None,
    request_text: str,
) -> list[str]:
    configured_value = _text(override or configured or "auto").lower()
    if configured_value not in {"auto", "lite", "flagship", "both"}:
        configured_value = "auto"
    request = request_text.lower()
    wants_both = any(token in request for token in ("双版", "两版", "both"))
    wants_lite = any(token in request for token in ("简版", "lite"))
    wants_flagship = any(
        token in request for token in ("旗舰", "完整版", "深度版", "html", "flagship")
    )
    if configured_value == "both" or wants_both or (wants_lite and wants_flagship):
        return ["lite", "flagship"]
    if configured_value == "flagship" or wants_flagship:
        return ["flagship"]
    if configured_value == "lite" or wants_lite:
        return ["lite"]
    return ["lite"]


def price_test(
    config: dict[str, Any],
    context: dict[str, Any],
    eligible: bool,
) -> dict[str, Any]:
    raw = {}
    if isinstance(config.get("price_test"), dict):
        raw.update(config["price_test"])
    if isinstance(context.get("price_test"), dict):
        raw.update(context["price_test"])
    gaps: list[str] = []
    current = parse_number(raw.get("current_price"), "price_test.current_price", gaps)
    variable_cost = parse_number(
        raw.get("variable_cost"), "price_test.variable_cost", gaps
    )
    step = parse_rate(raw.get("step_pct", "3%"), "price_test.step_pct", gaps)
    if not eligible:
        return {
            "status": "withheld",
            "reason": "数据未达到行动门槛",
            "room_type": _text(raw.get("room_type")) or None,
        }
    if current is None or variable_cost is None or step is None or current <= variable_cost:
        return {
            "status": "inputs_required",
            "reason": "需要有效的当前价、单间夜变动成本和测试步长",
            "gaps": _unique(gaps),
            "room_type": _text(raw.get("room_type")) or None,
        }
    scenarios = []
    current_margin = current - variable_cost
    for direction, candidate in (
        ("down", current * (1 - step)),
        ("up", current * (1 + step)),
    ):
        candidate_margin = candidate - variable_cost
        break_even = (
            current_margin / candidate_margin if candidate_margin > 0 else None
        )
        scenarios.append(
            {
                "direction": direction,
                "candidate_price": rounded(candidate, 2),
                "break_even_room_night_multiplier": rounded(break_even),
            }
        )
    return {
        "status": "scenario_only",
        "advisory_only": True,
        "room_type": _text(raw.get("room_type")) or None,
        "current_price": rounded(current, 2),
        "variable_cost": rounded(variable_cost, 2),
        "step_pct": rounded(step),
        "scenarios": scenarios,
        "guard": "单房型、单变量、3-7天测试；未达到保本间夜倍率即回滚",
    }


def build_actions(
    platform: str,
    diagnosis: dict[str, Any],
    eligible: bool,
    gaps: list[str],
) -> dict[str, Any]:
    if not eligible or diagnosis.get("status") != "ready":
        return {
            "status": "withheld",
            "advisory_only": True,
            "reason": diagnosis.get("reason") or "数据未达到行动门槛",
            "required_inputs": gaps[:12],
        }
    conflict = diagnosis["first_conflict"]
    if "流量" in conflict or "规模" in conflict:
        immediate = "核对可售、活动入口、主图标题和核心搜索词覆盖，先补足渠道曝光/访客"
    elif "转化" in conflict:
        immediate = "核对到手价、取消规则、房型权益、库存连续性和详情页承接，定位转化漏点"
    elif "价格" in conflict or "提价" in conflict:
        immediate = "选择一个主销房型做小步价格实验，并设置保本间夜与回滚阈值"
    else:
        immediate = "保持核心盘面，只选择一个可归因变量做3-7天实验"
    return {
        "status": "ready",
        "advisory_only": True,
        "scope": f"{platform}_ota_channel",
        "0_7_days": [immediate, "每日记录同口径曝光/访客、订单、间夜、收入与价格"],
        "8_30_days": [
            "按直接竞品、流量标杆、转化标杆分别复盘，不混成一个竞品名单",
            "只保留通过保本与回滚门槛的实验动作",
        ],
        "31_90_days": [
            "滚动更新竞争商圈和本店渠道角色",
            "将验证后的渠道动作交给运营管理，不外推为全酒店经营结论",
        ],
    }


def build_bundle(
    loaded: dict[str, Any],
    edition_override: str | None = None,
    request_text: str = "",
) -> dict[str, Any]:
    platform = loaded["platform"]
    config = loaded["config"]
    context = loaded["context"]
    gaps: list[str] = []
    structural_issues: list[str] = []
    rows = normalize_rows(platform, loaded["hotels"], gaps)
    if not rows:
        structural_issues.append("hotels 没有逐店数据")
    structural_issues.extend(duplicate_issues(rows))
    target, target_issues = identify_target(rows, context, config)
    structural_issues.extend(target_issues)
    identity_missing = validate_identity(context, gaps)
    summary = parse_summary(platform, loaded["market_summary"], gaps)
    closure, detail_totals = build_closure(platform, summary, rows, gaps)
    all_closure_passed = bool(closure) and all(
        item["passed"] for item in closure.values()
    )
    critical_missing: list[str] = []
    if target is not None and platform == "meituan":
        for field in (
            "platform_avg_view_conversion",
            "platform_avg_pay_conversion",
            "platform_avg_absolute_conversion",
        ):
            if summary.get(field) is None:
                critical_missing.append(f"market_summary.{field}")
        for field in MEITUAN_RATE_FIELDS:
            if target.get(field) is None:
                critical_missing.append(f"target.{field}")
    elif target is not None:
        for field in ("adr", "ari", "sci", "ctrip_platform_conversion"):
            if target.get(field) is None:
                critical_missing.append(f"target.{field}")
    if critical_missing:
        gaps.append("缺少平台决策关键字段: " + ", ".join(critical_missing))

    verification_status = _text(context.get("verification_status")).lower()
    binding_status = _text(context.get("binding_status")).lower()
    dataset_kind = _text(context.get("dataset_kind")).lower()
    if structural_issues:
        quality_status = "blocked"
    elif verification_status in HARD_SOURCE_STATUSES:
        quality_status = verification_status
    elif binding_status and binding_status != "verified" and dataset_kind != "synthetic":
        quality_status = "binding_missing"
    elif dataset_kind == "synthetic" or verification_status == "synthetic":
        quality_status = "synthetic"
    elif verification_status == "stale":
        quality_status = "stale"
    elif identity_missing or verification_status in {"", "unverified"}:
        quality_status = "unverified"
    elif (
        verification_status == "partial"
        or not all_closure_passed
        or bool(critical_missing)
    ):
        quality_status = "partial"
    else:
        quality_status = "available"

    eligible = (
        quality_status == "available"
        and binding_status == "verified"
        and not identity_missing
        and not structural_issues
        and all_closure_passed
        and not critical_missing
        and target is not None
    )
    grade = "A" if eligible else ("D" if structural_issues else "C")
    if target is not None:
        if platform == "meituan":
            market, target_metrics = derive_meituan(summary, rows, target)
        else:
            market, target_metrics = derive_ctrip(summary, rows, target)
        competitors = select_competitors(platform, rows, target)
    else:
        market, target_metrics, competitors = {}, {}, {
            "direct": [],
            "attack_benchmark": [],
            "traffic_benchmark": [],
            "conversion_benchmark": [],
        }
    diagnosis = decision_diagnosis(platform, market, target_metrics, eligible)
    requested_editions = route_editions(
        config.get("edition"),
        edition_override,
        request_text,
    )
    input_hash = loaded["input_fingerprint"]
    bundle_id = (
        f"{platform}-{_text(context.get('data_date')) or 'undated'}-{input_hash[:12]}"
    )
    normalized_gaps = _unique(gaps)
    blocking_issues = list(structural_issues)
    if quality_status in HARD_SOURCE_STATUSES:
        blocking_issues.append(f"来源状态阻断: {quality_status}")
    if quality_status == "binding_missing":
        blocking_issues.append("酒店平台绑定未验证")
    bundle = {
        "schema_version": SCHEMA_VERSION,
        "bundle_id": bundle_id,
        "input_fingerprint_sha256": input_hash,
        "source": {
            "platform": platform,
            "metric_scope": "ota_channel",
            "whole_hotel_conclusion_allowed": False,
            "source_contract": loaded["source_contract"],
            "system_hotel_id": _text(context.get("system_hotel_id")) or None,
            "platform_hotel_id": _text(context.get("platform_hotel_id")) or None,
            "binding_status": binding_status or None,
            "data_date": _text(context.get("data_date")) or None,
            "collected_at": _text(context.get("collected_at")) or None,
            "source_method": _text(context.get("source_method")) or None,
            "source_trace_id": _text(context.get("source_trace_id")) or None,
            "verification_status": verification_status or "unverified",
            "dataset_kind": dataset_kind or None,
            "period": _text(config.get("period") or context.get("period")) or None,
        },
        "quality": {
            "status": quality_status,
            "grade": grade,
            "decision_eligible": eligible,
            "blocking_issues": _unique(blocking_issues),
            "data_gaps": normalized_gaps,
            "closure": closure,
            "rule": "只有来源可用、酒店绑定已验证、日期/来源链完整且明细汇总闭合时才进入角色、价格和行动决策",
        },
        "facts": {
            "source_market_totals": {
                key: rounded(value) if isinstance(value, float) else value
                for key, value in summary.items()
            },
            "detail_totals_for_closure_only": detail_totals,
            "hotel_count": len(rows),
            "target": compact_hotel(platform, target) if target else None,
        },
        "derived_metrics": {
            "market": market,
            "target": target_metrics,
            "formula_boundary": {
                "missing_denominator_result": None,
                "platform_conversion_and_derived_conversion_are_separate": True,
                "ctrip_ari_sci_are_platform_fields_not_reverse_engineered": True,
                "meituan_sales_and_stay_windows_are_separate": True,
            },
        },
        "analysis": {
            "status": "ready" if eligible else "withheld",
            "channel_role_and_conflict": diagnosis,
            "competitor_sets": competitors if eligible else {
                key: [] for key in competitors
            },
            "competitor_candidates_for_review": competitors,
        },
        "recommendations": build_actions(
            platform, diagnosis, eligible, normalized_gaps
        ),
        "price_experiment": price_test(config, context, eligible),
        "render_contract": {
            "canonical_file": "analysis_bundle.json",
            "single_calculation": True,
            "requested_editions": requested_editions,
            "lite_is_default": True,
            "ctrip_compatibility_name": "analysis_context.json",
            "rule": "简版与旗舰版必须读取同一 bundle，不得分别重算",
        },
        "operating_loop": {
            "sequence": [
                "昨日OTA数据",
                "异常判断",
                "竞品对比",
                "AI建议",
                "今日运营动作",
            ],
            "current_gate": (
                "actions_ready" if eligible else f"data_{quality_status}"
            ),
        },
    }
    return bundle


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Build one governed Ctrip/Meituan competition analysis bundle."
    )
    parser.add_argument(
        "--source",
        required=True,
        help="Meituan package directory, Ctrip CSV, or canonical JSON.",
    )
    parser.add_argument(
        "--context",
        help="SUXIOS source/binding/date context JSON. Required for decision eligibility.",
    )
    parser.add_argument("--platform", choices=("meituan", "ctrip"))
    parser.add_argument("--edition", choices=("auto", "lite", "flagship", "both"))
    parser.add_argument("--request", default="", help="Natural-language edition hint.")
    parser.add_argument("--output", default="analysis_bundle.json")
    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    output = Path(args.output)
    try:
        loaded = load_source(
            Path(args.source),
            Path(args.context) if args.context else None,
            args.platform,
        )
        bundle = build_bundle(loaded, args.edition, args.request)
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(
            json.dumps(bundle, ensure_ascii=False, indent=2) + "\n",
            encoding="utf-8",
        )
    except BundleBuildError as exc:
        print(f"BUILD_FAILED: {exc}", file=sys.stderr)
        return 2
    status = bundle["quality"]["status"]
    print(
        json.dumps(
            {
                "output": str(output),
                "bundle_id": bundle["bundle_id"],
                "platform": bundle["source"]["platform"],
                "quality_status": status,
                "decision_eligible": bundle["quality"]["decision_eligible"],
                "requested_editions": bundle["render_contract"]["requested_editions"],
            },
            ensure_ascii=False,
        )
    )
    return 2 if status in {"blocked", *HARD_SOURCE_STATUSES} else 0


if __name__ == "__main__":
    raise SystemExit(main())
