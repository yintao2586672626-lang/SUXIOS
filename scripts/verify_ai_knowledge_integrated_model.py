#!/usr/bin/env python3
"""Verify the integrated hotel-operating knowledge model and golden cases."""

from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
from typing import Any


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def evaluate_case(model: dict[str, Any], case: dict[str, Any]) -> dict[str, Any]:
    payload = case["input"]
    if payload.get("hotel_identity") == "missing" or payload.get("business_date") == "missing":
        return {
            "readiness": "not_ready",
            "decision_safe": False,
            "allowed_output": "reference_and_missing_fields_only",
            "external_write_authorized": False,
        }
    if payload.get("event") in model["decision_rules"]["material_event_invalidation"]:
        return {
            "decision_state": "rebaseline_required",
            "old_recommendation_valid": False,
            "external_write_authorized": False,
        }
    if (payload.get("room_type_revpar_change") == "positive"
            and payload.get("hotel_revpar_change") == "negative"):
        return {"result_state": "mixed_result", "causality_claimed": False}
    return {"decision_state": "indeterminate", "external_write_authorized": False}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--directory",
        default=str(Path(__file__).resolve().parents[1] / "docs" / "knowledge" / "ai-library"),
    )
    args = parser.parse_args()
    root = Path(args.directory).resolve()
    model = json.loads((root / "integrated-model.json").read_text(encoding="utf-8"))
    contract = model["source_contract"]

    source_checks = {
        "source_manifest_sha256": sha256(root / "source-manifest.json"),
        "method_pack_sha256": sha256(root / "method-pack.json"),
        "priority_pack_sha256": sha256(root / "priority-pack.json"),
    }
    for field, actual in source_checks.items():
        if contract.get(field) != actual:
            raise SystemExit(f"source_contract_mismatch:{field}")

    method_pack = json.loads((root / "method-pack.json").read_text(encoding="utf-8"))
    priority_pack = json.loads((root / "priority-pack.json").read_text(encoding="utf-8"))
    if contract.get("method_entry_count") != len(method_pack.get("entries", [])):
        raise SystemExit("method_entry_count_mismatch")
    if contract.get("priority_entry_count") != len(priority_pack.get("entries", [])):
        raise SystemExit("priority_entry_count_mismatch")

    for field in ("decision_safe", "task_draft_safe", "external_write_authorized"):
        if model["boundary"].get(field) is not False:
            raise SystemExit(f"unsafe_integrated_model_boundary:{field}")

    required_metrics = {
        "available_room_nights",
        "sold_room_nights",
        "occupancy_rate",
        "adr",
        "revpar",
        "gross_pickup",
        "net_pickup",
        "room_type_revpar",
        "channel_share",
    }
    if not required_metrics.issubset(model.get("metric_dictionary", {})):
        raise SystemExit("required_metric_contract_missing")

    required_action_fields = {
        "problem",
        "evidence_refs",
        "hotel_and_date_scope",
        "action_type",
        "approver",
        "success_standard",
        "stop_condition",
        "rollback",
        "readback_at",
    }
    if not required_action_fields.issubset(model["action_contract"]["required_fields"]):
        raise SystemExit("action_contract_incomplete")

    case_results = []
    for case in model.get("golden_cases", []):
        actual = evaluate_case(model, case)
        if actual != case.get("expected"):
            raise SystemExit(f"golden_case_failed:{case.get('case_key')}")
        case_results.append({"case_key": case["case_key"], "status": "passed", "actual": actual})

    print(json.dumps({
        "status": "passed",
        "model_key": model["model_key"],
        "model_version": model["model_version"],
        "source_checks": source_checks,
        "method_entry_count": contract["method_entry_count"],
        "priority_entry_count": contract["priority_entry_count"],
        "golden_cases": case_results,
        "decision_safe": model["boundary"]["decision_safe"],
        "external_write_authorized": model["boundary"]["external_write_authorized"],
    }, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
