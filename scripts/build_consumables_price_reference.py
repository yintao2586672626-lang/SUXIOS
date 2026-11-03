"""Normalize this retained recommendation sheet without inventing purchase or consumption facts."""
import hashlib
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
BASE = ROOT / "docs/knowledge/consumables-price-list-20261001"
VERSION = "consumables-price-list-reference-20261001-v1"
SOURCE_SHA = "BF8AA1E0290BCF7F6464D6FFA63BDB653C1E11745A816EA53F517E2FC3EBA4F0"
NUM = r"\d+(?:\.\d+)?"


def write_once(path, obj):
    text = json.dumps(obj, ensure_ascii=False, indent=2, allow_nan=False) + "\n"
    if path.exists() and path.read_text(encoding="utf-8") != text:
        raise ValueError(f"Retained content conflict: {path.name}")
    if not path.exists():
        path.write_text(text, encoding="utf-8")


def classify(raw):
    text = str(raw)
    result = {"quote_kind": "unparsed_reference", "amount": None, "lower": None, "upper": None,
              "annotation": None, "currency": None, "price_unit": None,
              "package_quantity": None, "consumption_quantity": None, "quote_effective_date": None,
              "optional_in_source": "选配" in text, "auto_adopt": False}
    if text == "可不配置":
        result["quote_kind"] = "configuration_optional_not_free"
        return result
    if text == "根据酒店配置":
        result["quote_kind"] = "configuration_dependent"
        return result
    if text == "50G卷纸":
        result["quote_kind"] = "specification_only"
        return result
    match = re.fullmatch(fr"({NUM})-({NUM})(.*)", text)
    if match:
        lower, upper = map(float, match.group(1, 2))
        if lower > upper:
            raise ValueError("Reversed source price range")
        result.update(quote_kind="range", lower=lower, upper=upper, annotation=match.group(3) or None)
    else:
        match = re.fullmatch(fr"({NUM})(元?)(以上|以下|左右)(.*)", text)
        if match:
            number, word = float(match.group(1)), match.group(3)
            kind = {"以上": "lower_bound", "以下": "upper_bound", "左右": "approximate"}[word]
            result.update(quote_kind=kind, annotation=word + match.group(4), currency_label="元" if match.group(2) else None,
                          amount=number if kind == "approximate" else None,
                          lower=number if kind == "lower_bound" else None,
                          upper=number if kind == "upper_bound" else None, boundary_inclusion=None)
        elif isinstance(raw, (int, float)) and not isinstance(raw, bool):
            result.update(quote_kind="point", amount=float(raw))
        elif re.fullmatch(NUM, text):
            result.update(quote_kind="point", amount=float(text))
    return result


def entry(key, title, summary, method, section, query, unknowns):
    return {"key": key, "title": title, "summary": summary, "method": method, "source_section": section,
            "query": query, "maturity": "understood", "gates": {"reference_only": True, "automatic_application": False,
            "requires_hotel_period_and_units": True}, "known_unknowns": unknowns}


def build():
    extraction = json.loads((BASE / "extracted.json").read_text(encoding="utf-8"))
    if extraction["source_sha256"] != SOURCE_SHA or len(extraction["sheets"]) != 1:
        raise ValueError("Unexpected source identity")
    sheet = extraction["sheets"][0]
    if sheet["name"] != "Sheet1" or sheet["dimension"] != "A1:G41":
        raise ValueError("Unexpected source sheet")
    cells = {cell["cell"]: cell["value"] for cell in sheet["cells"]}
    tiers = [{"id": column.lower(), "column": column, "label": cells[column + "2"], "source_cell": column + "2"}
             for column in "CDEFG"]
    contexts = {5: [1, 2], 8: [4], 10: [5], 12: [6], 14: [7], 17: [8, 9], 23: [10, 11, 12, 13, 14],
                30: [20], 32: [21], 35: [23], 37: [24], 39: [25]}
    items, recommendations = [], []
    for row in range(3, 42):
        identity = cells.get("A" + str(row))
        if isinstance(identity, int):
            quotes = [{"tier_id": tier["id"], "source_cell": tier["column"] + str(row),
                       "raw_text": str(cells[tier["column"] + str(row)]),
                       **classify(cells[tier["column"] + str(row)])} for tier in tiers]
            items.append({"id": identity, "name": cells["B" + str(row)], "name_cell": "B" + str(row), "source_row": row,
                          "quotes": quotes, "recommendation_rows": [r for r, ids in contexts.items() if identity in ids]})
        elif cells.get("B" + str(row)) == "产品建议":
            recommendations.append({"source_row": row, "label_cell": "B" + str(row), "layout_context_item_ids": contexts[row],
                "mapping_quality": "layout_context_only_not_same_sku_quote",
                "cells": [{"tier_id": tier["id"], "source_cell": tier["column"] + str(row),
                           "raw_text": str(cells[tier["column"] + str(row)])} for tier in tiers if tier["column"] + str(row) in cells]})
    assert len(items) == 27 and [item["id"] for item in items] == list(range(1, 28))
    assert sum(len(item["quotes"]) for item in items) == 135 and len(recommendations) == 12
    kinds = {}
    for item in items:
        for quote in item["quotes"]:
            kinds[quote["quote_kind"]] = kinds.get(quote["quote_kind"], 0) + 1
    assert kinds["configuration_optional_not_free"] == 19 and kinds["configuration_dependent"] == 2
    assert kinds["specification_only"] == 1 and "unparsed_reference" not in kinds
    catalog = {"schema_version": "consumables-price-catalog.v1", "source_sha256": SOURCE_SHA,
               "source_sheet": "Sheet1", "source_range": "A1:G41", "title": cells["A1"],
               "extracted_on": "2026-10-01", "quote_effective_date": None, "supplier": None, "currency": None,
               "source_quality": "procurement_price_recommendation_reference", "usage_policy": "reference_only",
               "contains_current_hotel_fact": False, "auto_adopt": False, "tiers": tiers, "items": items,
               "recommendations": recommendations, "source_price_cells": 135, "recommendation_rows_count": 12,
               "recommendation_cells_count": sum(len(row["cells"]) for row in recommendations), "quote_kind_counts": kinds}
    write_once(BASE / "catalog.json", catalog)
    prices = [f"Sheet1!B{item['source_row']}: {item['name']}；" + "；".join(
        f"{tier['label']}({quote['source_cell']})={quote['raw_text']}" for tier, quote in zip(tiers, item["quotes"])) for item in items]
    configs = [f"Sheet1!B{row['source_row']} 产品建议，布局上下文品项{row['layout_context_item_ids']}：" + "；".join(
        f"{next(t['label'] for t in tiers if t['id'] == cell['tier_id'])}({cell['source_cell']})={cell['raw_text']}" for cell in row["cells"]) for row in recommendations]
    unknowns = ["供应商、报价生效日期、有效期、统一币种、税费、运费与MOQ未给出。", "多项计价包装、净含量或每包装件数未知。", "本酒店的领用、实际耗用、服务事件量、库存和损耗未提供。"]
    entries = [
        entry("quote_catalog", "1001易耗品采购推荐价参考：27品项五档原始价目", "保存27品项、5档位共135个原始价格/配置单元和单元格来源；19个可不配置、2个按酒店配置、1个规格项不属于数值价格。",
              prices + ["数值区间、上/下限和左右近似值保留原意；不选中点、不填开口区间未知端点。"], "Sheet1!A2:G41 主品项行", "1001易耗品采购推荐价五档牙具拖鞋洗发水价目", unknowns),
        entry("tier_configuration", "1001易耗品推荐配置参考：包装、克重与选配", "保存12行46个建议单元，保留牙膏、香皂、纸品克重、包装材质、洗沐瓶型与选配；推荐不是酒店标准或采购指令。",
              configs + ["AB款是来源中的款型标识；不得默认每间房同时选A和B或双计。​", "推荐行与主品项的关联只是表格布局上下文，不能推为相同SKU或每种补液同价。"], "Sheet1!B5:G39 产品建议行", "1001易耗品牙膏克重浴帽护理包装拖鞋推荐配置", unknowns),
        entry("comparison_scope", "1001易耗品价格比较：大瓶小瓶与整包装口径", "源表存在瓶型变化、包装量缺失和规格非价格，不能据表面金额排序得出高档更便宜或每间夜成本。", [
            "Sheet1!E19:F21 是10-15/15-20的锁盖大瓶，G19:G21 是1.5-2.5的小瓶装；净含量未知，不直接比较每mL或每间夜成本。",
            "Sheet1!D23 为通用产品建议‘补充液20kg桶装150-220’，不是每个液体SKU的确切采购价。若另行确认币种人民币、150-220为每20kg净料桶价，则条件算术为7.5-11元/kg；不能用未知密度换成mL价格。",
            "Sheet1!D29:E29 抽纸90-100与D30:E30散抽7.5kg只是关联描述；税运、净量与计价整包仍应确认。F29:G30又涉及散抽或独立包装，不能强行沿用7.5kg规格。",
            "Sheet1!C31 写‘50G卷纸’是规格而非50元；D31:G31价目配D32:G32纸卷克重仍缺每件/每箱卷数。",
            "Sheet1!D36:G39 垃圾袋价目330-450、320-440及单袋克重不证明每袋或每箱价格；包装数量未明确前不计算单位袋价。",
            "五档‘房价’未定义为ADR、挂牌价或订单价，100-200/200-400等边界未约定，不据此自动给酒店分档。"], "Sheet1!C19:G39 规格与口径", "1001易耗品大瓶小瓶补充液20kg抽纸卷纸垃圾袋计价单位", unknowns),
        entry("adoption_gaps", "1001易耗品报价接入成本测算：选择价格与待补字段", "价格目录已作知识参考；用于现有易耗品明细必须选择具体价、明确包装单位和用量来源，不能自动覆盖酒店成本。", [
            "来源名称应写采购推荐价参考；文件名1001或文件接收时间不能充当报价生效日，来源中没有供应商信息。",
            "可不配置属于建议，选配属于条件配置；只有明确未采用的情景才排除该行，均不能转成已知免费价格0。",
            "具体品项/档位/SKU确定后，再确认币种、含税及运费、采购包装净量或件数；区间不得默取中点。",
            "计算器package_price采用所选择包装的具体金额，package_quantity为同unit件/mL/g的净量；源表未确认的字段留待补，不默认一包一件。",
            "每客夜、每次清洁与每已售间夜的用量、事件换算应有同酒店同期间依据；AB、儿童品、茶咖和可选用品的投放条件须单独确认。",
            "现有经营情景测算默认使用手填成本，选择完整明细后才替换；保持假设状态并核对保存回读、利润/保本/敏感性及租金等去重。",
            "本目录不建立供应商账号、采购订单、库存调整、付款或实际成本凭证；价格参考可检索不代表可直接作为经营事实。"], "Sheet1!A1:G41 与现有易耗品明细字段合同", "1001易耗品推荐报价导入实际耗用成本缺报价单位日期", unknowns)
    ]
    pack = {"version": VERSION, "reviewed_at": "2026-10-01", "review_due_at": "2026-11-01",
            "entries": entries, "usage_policy": "reference_only", "automatic_application": False}
    write_once(BASE / "knowledge-pack.json", pack)
    retained = ["source.xlsx", "extracted.json", "catalog.json", "README.md", "knowledge-pack.json"]
    manifest = {"source_kind": "user_supplied_procurement_price_recommendation", "source_filename": extraction["source_filename"],
                "source_sha256": SOURCE_SHA, "source_sheet": "Sheet1", "source_range": "A1:G41", "extracted_on": "2026-10-01",
                "version_label": VERSION, "quote_effective_date": None, "supplier": None, "contains_current_hotel_fact": False,
                "original_workbook_modified": False, "formulas_executed": False, "external_links_fetched": False,
                "retained_files": [{"file": name, "sha256": hashlib.sha256((BASE / name).read_bytes()).hexdigest().upper()} for name in retained]}
    write_once(BASE / "source-manifest.json", manifest)
    print(json.dumps({"items": len(items), "price_cells": 135, "recommendation_rows": 12,
                      "recommendation_cells": catalog["recommendation_cells_count"], "quote_kinds": kinds}, ensure_ascii=True))


if __name__ == "__main__":
    build()
