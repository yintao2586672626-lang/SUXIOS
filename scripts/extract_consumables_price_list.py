"""Read an authorized quotation workbook as data; never execute formulas or links."""
import argparse
import hashlib
import json
import shutil
import zipfile
from datetime import date, datetime
from pathlib import Path

import openpyxl


def value_json(value):
    return value.isoformat() if isinstance(value, (date, datetime)) else value


def extract(source: Path, destination: Path):
    digest = hashlib.sha256(source.read_bytes()).hexdigest().upper()
    with zipfile.ZipFile(source) as archive:
        entries = archive.infolist()
        if len(entries) > 300 or sum(item.file_size for item in entries) > 16_000_000:
            raise ValueError("Workbook exceeds bounded extraction size")
        names = [item.filename for item in entries]
        structure = {
            "zip_entries": len(entries), "uncompressed_bytes": sum(item.file_size for item in entries),
            "macro_parts": [name for name in names if "vbaProject" in name],
            "external_link_parts": [name for name in names if name.startswith("xl/externalLinks/")],
            "embedded_parts": [name for name in names if name.startswith("xl/embeddings/")],
        }
    workbook = openpyxl.load_workbook(source, data_only=False, keep_links=False)
    cached = openpyxl.load_workbook(source, data_only=True, keep_links=False)
    result = {"schema_version": "procurement-workbook-extraction.v1", "source_filename": source.name,
              "source_sha256": digest, "source_bytes": source.stat().st_size, "extracted_on": "2026-10-01",
              "quote_effective_date": None, "source_kind": "user_supplied_procurement_quote_reference",
              "formulas_executed": False, "external_links_fetched": False, "structure": structure, "sheets": []}
    for sheet in workbook:
        if sheet.max_row * sheet.max_column > 50_000:
            raise ValueError("Worksheet exceeds bounded extraction cells")
        cells = []
        for row in sheet:
            for cell in row:
                if cell.value is None:
                    continue
                record = {"cell": cell.coordinate, "value": value_json(cell.value), "type": cell.data_type}
                if cell.data_type == "f":
                    record["cached_value"] = value_json(cached[sheet.title][cell.coordinate].value)
                cells.append(record)
        result["sheets"].append({"name": sheet.title, "state": sheet.sheet_state,
                                "dimension": sheet.calculate_dimension(), "max_row": sheet.max_row,
                                "max_column": sheet.max_column, "merged_ranges": [str(r) for r in sheet.merged_cells.ranges],
                                "hidden_rows": [i for i, d in sheet.row_dimensions.items() if d.hidden],
                                "hidden_columns": [i for i, d in sheet.column_dimensions.items() if d.hidden],
                                "cells": cells})
    destination.mkdir(parents=True, exist_ok=True)
    retained = destination / "source.xlsx"
    if retained.exists() and hashlib.sha256(retained.read_bytes()).hexdigest().upper() != digest:
        raise ValueError("Existing retained source conflicts; no overwrite")
    if not retained.exists():
        shutil.copyfile(source, retained)
    if hashlib.sha256(retained.read_bytes()).hexdigest().upper() != digest:
        raise ValueError("Retained source hash mismatch")
    output = destination / "extracted.json"
    serialized = json.dumps(result, ensure_ascii=False, indent=2, allow_nan=False) + "\n"
    if output.exists() and output.read_text(encoding="utf-8") != serialized:
        raise ValueError("Existing extraction conflicts; no overwrite")
    if not output.exists():
        output.write_text(serialized, encoding="utf-8")
    for sheet in result["sheets"]:
        rows = {}
        for cell in sheet["cells"]:
            row = int("".join(c for c in cell["cell"] if c.isdigit()))
            rows.setdefault(row, []).append(f"{cell['cell']}={cell['value']}")
        print(json.dumps({"sheet": sheet["name"], "state": sheet["state"], "dimension": sheet["dimension"],
                          "merges": sheet["merged_ranges"], "rows": rows}, ensure_ascii=False))


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("source", type=Path)
    parser.add_argument("destination", type=Path)
    args = parser.parse_args()
    extract(args.source, args.destination)
