"""Create bounded, source-specific quote references without changing the retained catalog."""
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
BASE = ROOT / "docs/knowledge/consumables-price-list-20261001"
VERSION = "consumables-price-items-reference-20261001-v1"
SOURCE_SHA = "BF8AA1E0290BCF7F6464D6FFA63BDB653C1E11745A816EA53F517E2FC3EBA4F0"


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest().upper()


def write_once(path, value):
    text = json.dumps(value, ensure_ascii=False, indent=2, allow_nan=False) + "\n"
    if path.exists() and path.read_text(encoding="utf-8") != text:
        raise ValueError("Retained content conflict: " + path.name)
    if not path.exists():
        path.write_text(text, encoding="utf-8")


def build():
    manifest = json.loads((BASE / "source-manifest.json").read_text(encoding="utf-8"))
    if manifest["source_sha256"] != SOURCE_SHA:
        raise ValueError("Source identity conflict")
    for retained in manifest["retained_files"]:
        if sha(BASE / retained["file"]) != retained["sha256"]:
            raise ValueError("Source retained hash conflict")
    catalog = json.loads((BASE / "catalog.json").read_text(encoding="utf-8"))
    if [item["id"] for item in catalog["items"]] != list(range(1, 28)):
        raise ValueError("Catalog item identity conflict")
    tiers = {tier["id"]: tier for tier in catalog["tiers"]}
    entries = []
    quote_count = 0
    for item in catalog["items"]:
        quotation_lines = [
            f"Sheet1!{quote['source_cell']} {tiers[quote['tier_id']]['label']}：{quote['raw_text']}"
            for quote in item["quotes"]
        ]
        context = [row for row in catalog["recommendations"] if item["id"] in row["layout_context_item_ids"]]
        recommendation_lines = [
            f"布局上下文建议，非本SKU确认报价；Sheet1!{cell['source_cell']} {tiers[cell['tier_id']]['label']}：{cell['raw_text']}"
            for row in context for cell in row["cells"]
        ]
        limits = ["供应商、报价生效日、有效期、统一币种、税运费、计价包装及箱内数量未知；不是已成交价或本店实耗。"]
        if any(q["quote_kind"] == "configuration_optional_not_free" for q in item["quotes"]):
            limits.append("可不配置属于配置选择，不能填免费0；启用后需取得真实所选规格价格。")
        if item["id"] in [1, 2, 8, 9]:
            limits.append("A/B是来源款式区分，不自动同时投放，也不自行删并。")
        if item["id"] in [10, 11, 12, 13, 14]:
            limits.append("瓶型或净含量不同不可直接比裸价；20kg桶装建议不证明各液体SKU同价，未知密度不换mL。")
        if item["id"] in [20, 21, 24, 25]:
            limits.append("纸品或垃圾袋克重是规格，不能替代计价包装数量；C31的50G卷纸不是50元。")
        limits.append("区间不取默认中点，上下限和左右原样保留；不得自动用于成本、采购、库存或资金写入。")
        # Each scalar stays below the existing retrieval service's 600-character limit.
        if any(len(line) > 500 for line in quotation_lines + recommendation_lines + limits):
            raise ValueError("Reference segment exceeds bounded retrieval length")
        entries.append({
            "key": f"item_{item['id']:02d}", "item_id": item["id"],
            "title": item["name"] + "采购推荐价与规格参考",
            "summary": "用户提供价目表的原始五档内容；报价单位和日期待确认，reference_only。",
            "quotation_lines": quotation_lines, "recommendation_lines": recommendation_lines,
            "interpretation_limits": limits,
            "method": ["报价、规格和选配条件保留原文及单元格，不补未知单位、不自动采用。"],
            "source_section": f"Sheet1!B{item['source_row']}:G{item['source_row']}" +
                ("；布局上下文行" + ",".join(str(row["source_row"]) for row in context) if context else ""),
            "query": item["name"] + "采购推荐价",
            "maturity": "understood", "gates": {"reference_only": True, "automatic_application": False,
                "requires_hotel_period_and_units": True},
            "known_unknowns": ["供应商、报价日期和有效期未知。", "统一币种、税运费及计价包装待确认。",
                "本店实际耗用、损耗和服务事件量未知。"],
        })
        quote_count += len(quotation_lines)
    if len(entries) != 27 or quote_count != 135:
        raise ValueError("Item coverage conflict")
    pack = {"version": VERSION, "reviewed_at": "2026-10-01", "review_due_at": "2026-11-01",
        "source_sha256": SOURCE_SHA, "usage_policy": "reference_only", "entries": entries}
    write_once(BASE / "item-knowledge-pack.json", pack)
    write_once(BASE / "item-source-manifest.json", {
        "version_label": VERSION, "source_sha256": SOURCE_SHA,
        "source_manifest_sha256": sha(BASE / "source-manifest.json"),
        "catalog_sha256": sha(BASE / "catalog.json"),
        "pack_sha256": sha(BASE / "item-knowledge-pack.json"),
        "items": len(entries), "quoted_cells": quote_count,
        "purpose": "bounded_per_item_runtime_retrieval_of_all_five_original_tier_cells",
        "original_catalog_modified": False, "automatic_application_authorized": False,
    })
    print(json.dumps({"status": "built", "items": len(entries), "quoted_cells": quote_count,
        "source_sha256": SOURCE_SHA, "pack_sha256": sha(BASE / "item-knowledge-pack.json")}, ensure_ascii=False))


if __name__ == "__main__":
    build()
