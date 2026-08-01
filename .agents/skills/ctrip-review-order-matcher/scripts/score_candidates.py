#!/usr/bin/env python3
"""Offline Ctrip review -> authorized order candidate scorer.

PMS fields are optional. The script never attempts to resolve an anonymous
reviewer identity and deliberately keeps weak Ctrip order candidates visible.
"""

from __future__ import annotations

import argparse
import json
import re
from collections import Counter
from datetime import datetime
from pathlib import Path
from typing import Any


VALID_STATUS = ("已退房", "已离店", "已完成", "完成", "已入住", "checkedout", "completed")
WEAK_STATUS = ("进行中", "已预订", "待确认", "预订", "booked", "reserved", "pending")
INVALID_STATUS = ("已取消", "已关闭", "取消", "关闭", "noshow", "no show", "cancelled", "canceled", "closed")
ROOM_KEYWORDS = ("亲子", "家庭", "儿童", "深睡", "大床", "双床", "洞穴", "智能", "私汤", "浴缸", "泡池", "雅居", "漫波", "景观", "庭院")
CONTENT_HINTS = {
    "亲子": (("亲子", "家庭", "小孩", "孩子", "儿童", "宝宝"), ("亲子", "家庭", "儿童"), True),
    "大床": (("一个人", "散心", "出差", "大床"), ("大床", "深睡", "智能"), False),
    "双床": (("朋友", "同事", "双床", "两张床"), ("双床",), True),
    "私汤": (("私汤", "浴缸", "泡池"), ("私汤", "浴缸", "泡池"), True),
    "设施": (("智能马桶", "智能床垫", "负离子", "烘衣机", "投影", "隔音"), ("智能", "深睡", "洞穴", "大床"), False),
}


def text(value: Any) -> str:
    return str(value or "").strip()


def first(data: dict[str, Any], keys: tuple[str, ...]) -> Any:
    for key in keys:
        if key in data and data[key] not in (None, ""):
            return data[key]
    return None


def parse_dt(value: Any) -> tuple[datetime | None, bool]:
    raw = text(value)
    if not raw:
        return None, False
    has_time = bool(re.search(r"\d{1,2}[:：]\d{1,2}", raw))
    normalized = raw.replace("年", "-").replace("月", "-").replace("日", "").replace("/", "-").replace("：", ":")
    for fmt in ("%Y-%m-%d %H:%M:%S", "%Y-%m-%d %H:%M", "%Y-%m-%d"):
        try:
            return datetime.strptime(normalized, fmt), has_time
        except ValueError:
            pass
    match = re.search(r"(20\d{2})[-/.年](\d{1,2})[-/.月](\d{1,2})", raw)
    if match:
        return datetime(int(match[1]), int(match[2]), int(match[3])), has_time
    return None, False


def date_only(value: Any) -> str:
    parsed, _ = parse_dt(value)
    return parsed.strftime("%Y-%m-%d") if parsed else ""


def month_only(value: Any) -> str:
    match = re.search(r"(20\d{2})[-/.年](\d{1,2})", text(value))
    return f"{int(match[1]):04d}-{int(match[2]):02d}" if match else ""


def amount_value(value: Any) -> float | None:
    if value in (None, ""):
        return None
    match = re.search(r"-?\d+(?:\.\d+)?", text(value).replace(",", ""))
    return float(match[0]) if match else None


def truthy(value: Any) -> bool:
    return value is True or text(value).lower() in {"1", "true", "yes", "verified", "已复核", "已核对"}


def normalize_room(value: Any) -> str:
    value = re.sub(r"[（(][^）)]*[）)]", "", text(value).lower())
    return re.sub(r"[\s\-—_·,，.。/\\|]+", "", value)


def is_ctrip(order: dict[str, Any]) -> bool:
    channel = text(first(order, ("channel", "channel_name", "source_platform", "platform"))).lower()
    return bool(channel) and ("携程" in channel or "ctrip" in channel)


def order_id(order: dict[str, Any]) -> str:
    return text(first(order, ("pms_order_no", "pmsOrderNo", "order_no", "orderNo", "channel_order_no", "channelOrderNo", "order_id", "orderId")))


def channel_order_no(order: dict[str, Any]) -> str:
    return text(first(order, ("channel_order_no", "channelOrderNo", "ctrip_order_no", "ctripOrderNo", "order_id", "orderId", "order_no", "orderNo")))


def review_strong_id(review: dict[str, Any]) -> str:
    return text(first(review, ("ctrip_order_no", "ctripOrderNo", "channel_order_no", "channelOrderNo", "order_id", "orderId", "order_no", "orderNo")))


def has_strong_id(review: dict[str, Any], order: dict[str, Any]) -> bool:
    strong = review_strong_id(review)
    identifiers = {
        text(order.get(key))
        for key in ("channel_order_no", "channelOrderNo", "ctrip_order_no", "ctripOrderNo", "order_id", "orderId", "order_no", "orderNo", "pms_order_no", "pmsOrderNo")
        if text(order.get(key))
    }
    return bool(strong and strong in identifiers)


def review_room(review: dict[str, Any]) -> str:
    return text(first(review, ("room_name", "roomName", "hotelRoomInfo", "room_type", "roomType", "product_name")))


def order_room(order: dict[str, Any]) -> str:
    return text(first(order, ("room_type", "roomType", "room_name", "roomName", "product_name")))


def publish_value(review: dict[str, Any]) -> Any:
    return first(review, ("publish_time", "publishTime", "published_at", "addtime", "comment_time", "review_date", "date"))


def checkout_value(order: dict[str, Any]) -> Any:
    return first(order, ("departure_date_time", "departureDateTime", "checkout", "departure_date", "departureDate", "check_out", "checkOut"))


def arrival_value(order: dict[str, Any]) -> Any:
    return first(order, ("arrival_date_time", "arrivalDateTime", "checkin", "arrival_date", "arrivalDate", "check_in", "checkIn"))


def room_score(review_value: str, order_value: str, mapping: dict[str, Any]) -> tuple[int, list[str], list[str]]:
    review_norm, order_norm = normalize_room(review_value), normalize_room(order_value)
    for source, raw_targets in mapping.items():
        source_norm = normalize_room(source)
        if not source_norm or (review_norm != source_norm and source_norm not in review_norm):
            continue
        targets = raw_targets if isinstance(raw_targets, list) else [raw_targets]
        for target in targets:
            target_norm = normalize_room(target)
            if target_norm and (order_norm == target_norm or target_norm in order_norm or order_norm in target_norm):
                return 30, [f"房型明确映射：{source} -> {order_value}"], []
    if review_norm and review_norm == order_norm:
        return 30, ["点评房型与订单房型标准化后完全一致"], []
    if review_norm and order_norm and min(len(review_norm), len(order_norm)) >= 3 and (review_norm in order_norm or order_norm in review_norm):
        return 25, ["房型主体名称一致"], []
    shared = [word for word in ROOM_KEYWORDS if word in review_value and word in order_value]
    if shared:
        return min(25, 20 + 2 * len(shared)), ["房型关键词重合：" + "、".join(shared)], []
    if review_value and order_value:
        return 0, ["房型无明确映射"], [f"房型未明确映射：点评={review_value}，订单={order_value}"]
    return 0, ["房型字段缺失"], ["缺少点评房型或订单房型"]


def date_score(publish: Any, checkout: Any) -> tuple[int, bool, list[str], list[str]]:
    publish_dt, publish_has_time = parse_dt(publish)
    checkout_dt, checkout_has_time = parse_dt(checkout)
    if not publish_dt or not checkout_dt:
        return 0, False, ["缺少点评发表时间或离店时间"], ["无法核对离店后点评窗口"]
    if not checkout_has_time:
        checkout_dt = checkout_dt.replace(hour=14, minute=0, second=0)
    if not publish_has_time and publish_dt.date() == checkout_dt.date():
        return 0, False, ["点评与离店为同一天，但点评时间精度不足"], ["点评发表时间只有日期，无法确认是否晚于离店日14:00"]
    if publish_dt < checkout_dt:
        return -30, True, ["点评早于离店日14:00，时间逻辑硬冲突"], ["点评时间早于最早可点评时间，候选不能高置信"]
    delta = (publish_dt.date() - checkout_dt.date()).days
    if delta <= 3:
        return 30, False, [f"离店后{delta}天发表点评"], []
    if delta <= 7:
        return 22, False, [f"离店后{delta}天发表点评"], []
    if delta <= 14:
        return 12, False, [f"离店后{delta}天发表点评"], []
    return 4, False, [f"离店后{delta}天发表点评，时间较远"], ["时间窗口偏远，需要复核"]


def status_score(status: Any) -> tuple[int, list[str], list[str]]:
    value = text(status).lower()
    if any(item.lower() in value for item in INVALID_STATUS):
        return 0, [f"订单状态为低可信状态：{text(status)}"], ["订单已取消/关闭/NoShow，不能高置信"]
    if any(item.lower() in value for item in VALID_STATUS):
        return 15, [f"有效入住状态：{text(status)}"], []
    if any(item.lower() in value for item in WEAK_STATUS):
        return 6, [f"弱状态：{text(status)}"], ["订单状态未达到已退房/已完成"]
    return 4, [f"状态未明确：{text(status)}"], ["订单状态需要复核"]


def amount_score(amount: Any) -> tuple[int, list[str], list[str]]:
    value = amount_value(amount)
    if value is None:
        return 4, ["金额缺失"], ["金额缺失，不能高置信"]
    if value > 0:
        return 10, [f"金额有效：{value:g}"], []
    return 0, ["金额为0"], ["0元订单保留为低分候选，不能高置信"]


def content_score(content: Any, room: str) -> tuple[int, list[str], list[str]]:
    value = text(content)
    hits, conflicts = [], []
    for name, (words, room_words, hard_conflict) in CONTENT_HINTS.items():
        has_content = any(word in value for word in words)
        has_room = any(word in room for word in room_words)
        if has_content and has_room:
            hits.append(name)
        elif has_content and not has_room and hard_conflict:
            conflicts.append(name)
    if conflicts:
        return -10, ["内容线索与房型冲突：" + "、".join(conflicts)], ["点评内容与候选房型存在冲突"]
    if hits:
        return (10 if len(hits) >= 2 else 4), ["内容线索吻合：" + "、".join(hits)], []
    return 0, ["无明显内容线索"], []


def normalize_order(order: dict[str, Any]) -> dict[str, Any]:
    amount = first(order, ("amount", "total_amount", "totalAmount", "paid_amount", "paidAmount", "order_amount"))
    return {
        "order_id": order_id(order),
        "channel_order_no": channel_order_no(order),
        "arrival_date": date_only(arrival_value(order)),
        "departure_date": date_only(checkout_value(order)),
        "room_name": order_room(order),
        "order_status": text(first(order, ("status", "order_status", "orderStatus", "order_state"))),
        "amount": amount_value(amount),
        "detail_verified": truthy(first(order, ("detail_verified", "detailVerified", "order_detail_verified"))),
        "channel": "ctrip",
    }


def final_status(score: int, unique: bool, candidate: dict[str, Any], strong: bool, ambiguous: bool, duplicate: bool, store_verified: bool) -> str:
    if strong:
        return "confirmed"
    breakdown = candidate["score_breakdown"]
    amount = candidate["amount"]
    high = (
        score >= 75 and unique and not ambiguous and not duplicate and store_verified
        and breakdown["room_score"] >= 25 and breakdown["date_score"] > 0
        and not breakdown["time_logic_conflict"] and breakdown["status_score"] == 15
        and amount is not None and amount > 0 and candidate["detail_verified"]
    )
    if ambiguous or duplicate:
        return "ambiguous"
    return "high_confidence" if high else "candidate"


def score_candidate(
    review: dict[str, Any],
    order: dict[str, Any],
    mapping: dict[str, Any],
    *,
    unique: bool = False,
    ambiguous: bool = False,
    duplicate_conflict: bool = False,
    store_mapping_verified: bool = False,
) -> dict[str, Any]:
    strong = has_strong_id(review, order)
    normalized = normalize_order(order)
    breakdown: dict[str, Any] = {
        "strong_id_score": 100 if strong else 0,
        "room_score": 0,
        "date_score": 0,
        "time_logic_conflict": False,
        "status_score": 0,
        "amount_score": 0,
        "content_score": 0,
        "uniqueness_score": 0,
        "duplicate_penalty": 0,
        "detail_review_score": 0,
    }
    evidence, missing = [], []
    if strong:
        evidence.append("点评可见订单标识与携程渠道订单标识完全一致")
    else:
        room_points, why, miss = room_score(review_room(review), normalized["room_name"], mapping)
        breakdown["room_score"] = room_points; evidence += why; missing += miss
        date_points, hard_conflict, why, miss = date_score(publish_value(review), checkout_value(order))
        breakdown["date_score"] = date_points; breakdown["time_logic_conflict"] = hard_conflict; evidence += why; missing += miss
        status_points, why, miss = status_score(normalized["order_status"])
        breakdown["status_score"] = status_points; evidence += why; missing += miss
        amount_points, why, miss = amount_score(normalized["amount"])
        breakdown["amount_score"] = amount_points; evidence += why; missing += miss
        content_points, why, miss = content_score(first(review, ("content", "review_text", "reviewContent")), normalized["room_name"])
        breakdown["content_score"] = content_points; evidence += why; missing += miss
        breakdown["detail_review_score"] = 0 if normalized["detail_verified"] else -5
        if normalized["detail_verified"]:
            evidence.append("订单详情已复核")
        else:
            evidence.append("订单详情未复核"); missing.append("未完成订单详情复核，不能高置信")
        breakdown["uniqueness_score"] = 5 if unique else 0
        evidence.append("同窗口同映射房型候选唯一" if unique else "候选不唯一或唯一性证据不足")
        missing.append("点评订单号或渠道订单号未命中")
        if not store_mapping_verified:
            missing.append("携程点评门店与订单来源门店映射未显式复核")
        if duplicate_conflict:
            breakdown["duplicate_penalty"] = -15
            evidence.append("同一订单命中多条点评，已扣15分并降级")
            missing.append("同一订单对应多条点评，缺少强订单号证据")
    score = max(0, min(100, sum(value for value in breakdown.values() if isinstance(value, int) and not isinstance(value, bool))))
    candidate = {**normalized, "score_breakdown": breakdown}
    status = final_status(score, unique, candidate, strong, ambiguous, duplicate_conflict, store_mapping_verified)
    missing = list(dict.fromkeys(item for item in missing if item))
    breakdown["evidence"] = evidence
    breakdown["missing_evidence"] = missing
    return {**normalized, "score": score, "status": status, "score_breakdown": breakdown, "evidence": evidence, "missing_evidence": missing}


def select_window(review: dict[str, Any], orders: list[dict[str, Any]]) -> tuple[list[dict[str, Any]], str, list[dict[str, Any]]]:
    searches: list[dict[str, Any]] = []
    review_arrival = date_only(first(review, ("checkin_date", "checkInDate", "checkinTimeStr", "arrival_date", "stay_date")))
    review_departure = date_only(first(review, ("checkout_date", "checkOutDate", "checkoutTimeStr", "departure_date")))
    if review_arrival or review_departure:
        explicit = [order for order in orders if (not review_arrival or date_only(arrival_value(order)) == review_arrival) and (not review_departure or date_only(checkout_value(order)) == review_departure)]
        searches.append({"window": "explicit_stay_dates", "candidate_count": len(explicit)})
        if explicit:
            return explicit, "explicit_stay_dates", searches
    publish, _ = parse_dt(publish_value(review))
    if publish:
        for low, high, name in ((0, 14, "checkout_0_14_days_before_review"), (15, 30, "checkout_15_30_days_before_review")):
            candidates = []
            for order in orders:
                checkout, _ = parse_dt(checkout_value(order))
                if checkout and low <= (publish.date() - checkout.date()).days <= high:
                    candidates.append(order)
            searches.append({"window": name, "candidate_count": len(candidates)})
            if candidates:
                return candidates, name, searches
    else:
        searches += [
            {"window": "checkout_0_14_days_before_review", "candidate_count": 0, "skipped_reason": "review_publish_time_missing"},
            {"window": "checkout_15_30_days_before_review", "candidate_count": 0, "skipped_reason": "review_publish_time_missing"},
        ]
    month = month_only(first(review, ("checkin_date", "checkInDate", "checkinTimeStr", "arrival_date", "stay_month")))
    if month:
        candidates = [order for order in orders if date_only(arrival_value(order)).startswith(month)]
        searches.append({"window": "stay_month", "month": month, "candidate_count": len(candidates)})
        if candidates:
            return candidates, "stay_month", searches
    else:
        searches.append({"window": "stay_month", "candidate_count": 0, "skipped_reason": "review_stay_month_missing"})
    return [], "none", searches


def _score_review(review: dict[str, Any], orders: list[dict[str, Any]], mapping: dict[str, Any], store_verified: bool, duplicate_ids: set[str]) -> dict[str, Any]:
    strong = review_strong_id(review)
    if strong:
        matches = [order for order in orders if has_strong_id(review, order)]
        if len(matches) == 1:
            candidate = score_candidate(review, matches[0], mapping, unique=True, store_mapping_verified=store_verified)
            return {"review": review, "review_status": "confirmed", "review_flags": [], "window_used": "strong_identifier", "search_windows": [], "candidates": [candidate]}
        if matches:
            candidates = [score_candidate(review, order, mapping, ambiguous=True, store_mapping_verified=store_verified) for order in matches[:5]]
            return {"review": review, "review_status": "ambiguous", "review_flags": ["强订单标识命中多条记录"], "window_used": "strong_identifier", "search_windows": [], "candidates": candidates}
        return {"review": review, "review_status": "not_found", "review_flags": ["强订单标识不在订单池"], "window_used": "strong_identifier", "search_windows": [], "candidates": []}
    window_orders, window_used, searches = select_window(review, orders)
    if not window_orders:
        return {"review": review, "review_status": "not_found", "review_flags": ["全部日期窗口无候选"], "window_used": window_used, "search_windows": searches, "candidates": []}
    preliminary = [score_candidate(review, order, mapping, store_mapping_verified=store_verified) for order in window_orders]
    preliminary.sort(key=lambda item: (-item["score"], item["order_id"]))
    close = len(preliminary) > 1 and preliminary[0]["score"] - preliminary[1]["score"] < 10
    clear_rooms = sum(item["score_breakdown"]["room_score"] >= 25 for item in preliminary)
    unique_top = not close and clear_rooms == 1
    top_id = preliminary[0]["order_id"]
    rescored = [
        score_candidate(
            review,
            order,
            mapping,
            unique=order_id(order) == top_id and unique_top and order_id(order) not in duplicate_ids,
            ambiguous=close,
            duplicate_conflict=order_id(order) in duplicate_ids,
            store_mapping_verified=store_verified,
        )
        for order in window_orders
    ]
    rescored.sort(key=lambda item: (-item["score"], item["order_id"]))
    rescored = rescored[:5]
    top = rescored[0]
    final_close = len(rescored) > 1 and top["score"] - rescored[1]["score"] < 10
    flags = []
    if final_close:
        flags.append("前两名候选分差小于10")
    if top["order_id"] in duplicate_ids:
        flags.append("同一订单命中多条点评")
    if top["score_breakdown"]["time_logic_conflict"]:
        flags.append("点评时间与离店时间硬冲突")
    if top["score_breakdown"]["room_score"] == 0:
        flags.append("房型未映射或存在冲突")
    status = top["status"]
    if flags and status != "confirmed":
        status = "ambiguous"
        top["status"] = status
    return {"review": review, "review_status": status, "review_flags": flags, "window_used": window_used, "search_windows": searches, "candidates": rescored}


def build_matches(reviews: list[dict[str, Any]], orders: list[dict[str, Any]], mapping: dict[str, Any], store_mapping_verified: bool = False) -> list[dict[str, Any]]:
    ctrip_orders = [order for order in orders if is_ctrip(order)]
    if not ctrip_orders:
        return [{"review": review, "review_status": "not_found", "review_flags": ["授权携程订单池为空"], "window_used": "none", "search_windows": [], "candidates": []} for review in reviews]
    initial = [_score_review(review, ctrip_orders, mapping, store_mapping_verified, set()) for review in reviews]
    hits = Counter(
        match["candidates"][0]["order_id"]
        for match in initial
        if match["review_status"] != "confirmed" and match["candidates"] and match["candidates"][0]["score"] >= 45
    )
    duplicates = {identifier for identifier, count in hits.items() if identifier and count > 1}
    if not duplicates:
        return initial
    return [_score_review(review, ctrip_orders, mapping, store_mapping_verified, duplicates) for review in reviews]


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    parser.add_argument("--output")
    args = parser.parse_args()
    data = json.loads(Path(args.input).read_text(encoding="utf-8-sig"))
    matches = build_matches(
        data.get("reviews", []),
        data.get("orders", []),
        data.get("room_mapping", {}),
        truthy(data.get("store_mapping_verified", False)),
    )
    output = json.dumps({"matches": matches}, ensure_ascii=False, indent=2)
    if args.output:
        Path(args.output).write_text(output, encoding="utf-8")
    else:
        print(output)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
