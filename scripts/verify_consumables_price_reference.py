"""Independently reconcile retained normalized prices to XLSX XML source cells."""
import hashlib
import json
import zipfile
from pathlib import Path
from xml.etree import ElementTree as ET

ROOT = Path(__file__).resolve().parent.parent
BASE = ROOT / "docs/knowledge/consumables-price-list-20261001"
NS = {"s": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}
SHA = "BF8AA1E0290BCF7F6464D6FFA63BDB653C1E11745A816EA53F517E2FC3EBA4F0"


def verify():
    checks = []
    def check(value, name):
        if not value:
            raise ValueError(name)
        checks.append(name)
    check(hashlib.sha256((BASE / "source.xlsx").read_bytes()).hexdigest().upper() == SHA, "retained_original_hash")
    with zipfile.ZipFile(BASE / "source.xlsx") as archive:
        names = archive.namelist()
        strings = []
        if "xl/sharedStrings.xml" in names:
            for node in ET.fromstring(archive.read("xl/sharedStrings.xml")).findall("s:si", NS):
                strings.append("".join(text.text or "" for text in node.findall(".//s:t", NS)))
        cells = {}
        sheet = ET.fromstring(archive.read("xl/worksheets/sheet1.xml"))
        for node in sheet.findall(".//s:c", NS):
            value = node.find("s:v", NS)
            if node.attrib.get("t") == "s":
                cells[node.attrib["r"]] = strings[int(value.text)]
            elif node.attrib.get("t") == "inlineStr":
                cells[node.attrib["r"]] = "".join(text.text or "" for text in node.findall(".//s:t", NS))
            elif value is not None:
                number = float(value.text)
                cells[node.attrib["r"]] = int(number) if number.is_integer() else number
        check(len(sheet.findall(".//s:f", NS)) == 0, "no_source_formulas_executed_or_needed")
        check(not any("vbaProject" in name or name.startswith(("xl/embeddings/", "xl/externalLinks/")) for name in names), "no_macro_embedded_or_external_link_parts")
    extracted = json.loads((BASE / "extracted.json").read_text(encoding="utf-8"))
    extracted_cells = {cell["cell"]: cell["value"] for cell in extracted["sheets"][0]["cells"]}
    check(cells == extracted_cells, "all_nonempty_cells_match_independent_xml")
    catalog = json.loads((BASE / "catalog.json").read_text(encoding="utf-8"))
    check(catalog["source_sha256"] == SHA and catalog["source_sheet"] == "Sheet1", "catalog_source_identity")
    check(len(catalog["items"]) == 27 and len(catalog["tiers"]) == 5, "all_items_and_tiers_retained")
    price_cells = []
    for item in catalog["items"]:
        check(cells[item["name_cell"]] == item["name"] and cells["A" + str(item["source_row"])] == item["id"], "item_identity_" + str(item["id"]))
        for quote in item["quotes"]:
            check(str(cells[quote["source_cell"]]) == quote["raw_text"], "quote_source_" + quote["source_cell"])
            price_cells.append(quote["source_cell"])
            check(all(quote[key] is None for key in ["currency", "price_unit", "package_quantity", "consumption_quantity", "quote_effective_date"])
                  and quote["auto_adopt"] is False, "unknowns_not_filled_" + quote["source_cell"])
    check(len(price_cells) == 135 and len(set(price_cells)) == 135, "135_unique_source_quote_or_configuration_cells")
    check(len(catalog["recommendations"]) == 12, "all_12_product_suggestion_rows")
    rec_cells = []
    for row in catalog["recommendations"]:
        check(cells[row["label_cell"]] == "产品建议" and row["mapping_quality"] == "layout_context_only_not_same_sku_quote", "recommendation_context_" + str(row["source_row"]))
        for cell in row["cells"]:
            check(str(cells[cell["source_cell"]]) == cell["raw_text"], "recommendation_source_" + cell["source_cell"])
            rec_cells.append(cell["source_cell"])
    check(len(rec_cells) == 46 and len(set(rec_cells)) == 46, "46_unique_recommendation_cells")
    check(catalog["quote_kind_counts"]["configuration_optional_not_free"] == 19, "optional_configuration_not_zero_price")
    by_cell = {quote["source_cell"]: quote for item in catalog["items"] for quote in item["quotes"]}
    check(by_cell["C31"]["quote_kind"] == "specification_only" and by_cell["C31"]["amount"] is None, "50G_roll_paper_not_50_currency")
    check(by_cell["G19"]["lower"] == 1.5 and by_cell["G19"]["upper"] == 2.5 and "小瓶" in by_cell["G19"]["annotation"], "small_bottle_range_preserves_specification")
    check(cells["D23"] == "补充液20kg桶装150-220" and all(item["source_row"] != 23 for item in catalog["items"]), "refill_suggestion_not_extra_sku")
    check(catalog["quote_effective_date"] is None and catalog["supplier"] is None and catalog["currency"] is None, "quote_identity_missing_preserved")
    manifest = json.loads((BASE / "source-manifest.json").read_text(encoding="utf-8"))
    check(all(hashlib.sha256((BASE / record["file"]).read_bytes()).hexdigest().upper() == record["sha256"] for record in manifest["retained_files"]), "all_manifest_fingerprints")
    result = {"status": "passed", "checks_passed": len(checks), "verification_method": "independent_zip_xml_vs_extraction_and_catalog",
              "source_sha256": SHA, "source_cells_matched": len(cells), "price_or_configuration_cells": 135,
              "recommendation_cells": 46, "checks": checks, "original_workbook_modified": False,
              "business_cost_applied": False, "quote_effective_date": None, "supplier": None}
    (BASE / "extraction-verification.json").write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({key: value for key, value in result.items() if key != "checks"}, ensure_ascii=True))


if __name__ == "__main__":
    verify()
