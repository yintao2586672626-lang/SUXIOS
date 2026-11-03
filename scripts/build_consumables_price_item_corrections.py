"""Retain five reviewed wording corrections separately from the immutable original pack."""
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
BASE = ROOT / "docs/knowledge/consumables-price-list-20261001"


def build():
    pack_bytes = (BASE / "item-knowledge-pack.json").read_bytes()
    pack_sha = hashlib.sha256(pack_bytes).hexdigest().upper()
    if pack_sha != "E24533560BC55C2CD532AF807CD80117AEC5F4ED46B998FD31825F09903C8347":
        raise ValueError("Original item pack conflict")
    pack = json.loads(pack_bytes)
    receipt = json.loads((BASE / "item-verification.json").read_text(encoding="utf-8"))
    rows = {row["key"]: row for row in receipt["results"]["persist"]["readback"]}
    entries = {entry["key"]: entry for entry in pack["entries"]}
    changes = {
        "item_01": ("A/B是来源款式区分，不自动同时投放，也不自行删并。",
                    "A/B是来源品项标记，具体款式、是否配套及投放量待确认；不自动同时投放，也不自行删并。"),
        "item_02": ("A/B是来源款式区分，不自动同时投放，也不自行删并。",
                    "A/B是来源品项标记，具体款式、是否配套及投放量待确认；不自动同时投放，也不自行删并。"),
        "item_20": ("纸品或垃圾袋克重是规格，不能替代计价包装数量；C31的50G卷纸不是50元。",
                    "C30:E30散抽7.5kg是来源规格或包装质量描述，不能据此推断抽数、张数或报价按箱；F30:G30独立包装的净含量及计价包装另须确认。"),
        "item_24": ("纸品或垃圾袋克重是规格，不能替代计价包装数量；C31的50G卷纸不是50元。",
                    "D37:G37的25g/28g至35g/40g是袋体规格描述，不能反推每包或每箱数量；D36:G36报价的采购计量单位仍未知。"),
        "item_25": ("纸品或垃圾袋克重是规格，不能替代计价包装数量；C31的50G卷纸不是50元。",
                    "D39:G39的2g/2.3g至2.5g/3g是袋体规格描述，不能反推每包或每箱数量；D38:G38报价的采购计量单位仍未知。"),
    }
    corrections = []
    for key, (before, after) in changes.items():
        limits = entries[key]["interpretation_limits"]
        if limits.count(before) != 1:
            raise ValueError("Correction target conflict")
        corrections.append({"key": key, "unit_id": rows[key]["unit_id"],
            "previous_chunk_id": rows[key]["chunk_id"],
            "expected_previous_content_digest": rows[key]["content_digest"],
            "replaced_line": before, "replacement_line": after,
            "interpretation_limits": [after if line == before else line for line in limits],
            "reason": "source_specific_interpretation_and_unknown_AB_meaning",
        })
    correction = {"version": "consumables-price-item-corrections-20261001-v2",
        "source_sha256": pack["source_sha256"], "original_pack_sha256": pack_sha,
        "reviewed_on": "2026-10-01", "changes": corrections,
        "quotation_lines_changed": False, "recommendation_lines_changed": False,
        "automatic_application_authorized": False}
    path = BASE / "item-corrections-v2.json"
    text = json.dumps(correction, ensure_ascii=False, indent=2, allow_nan=False) + "\n"
    if path.exists() and path.read_text(encoding="utf-8") != text:
        raise ValueError("Retained correction conflict")
    if not path.exists():
        path.write_text(text, encoding="utf-8")
    print(json.dumps({"status": "built", "corrected_items": len(corrections),
        "correction_sha256": hashlib.sha256(path.read_bytes()).hexdigest().upper()}))


if __name__ == "__main__":
    build()
