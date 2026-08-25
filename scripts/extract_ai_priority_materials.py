#!/usr/bin/env python3
"""Extract a bounded set of user-prioritized knowledge files for review.

This helper only reads the supplied documents. It never executes embedded
scripts, follows links, installs dependencies, or performs external writes.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path

from absorb_ai_knowledge_library import (
    extract_document,
    normalize_text,
    redact_sensitive,
    sha256_bytes,
)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", action="append", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()

    records: list[dict[str, object]] = []
    for raw_path in args.input:
        path = Path(raw_path).resolve()
        data = path.read_bytes()
        text, status, metadata = extract_document(data, path.suffix.lower())
        text, redaction_count = redact_sensitive(normalize_text(text))
        records.append({
            "title": path.name,
            "source_path": str(path),
            "source_sha256": sha256_bytes(data),
            "bytes": len(data),
            "extraction_status": status,
            "extraction_metadata": metadata,
            "redaction_count": redaction_count,
            "char_count": len(text),
            "body": text,
            "decision_safe": False,
            "external_write_authorized": False,
        })

    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(
        json.dumps({"schema_version": 1, "records": records}, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    print(json.dumps({
        "output": str(output),
        "record_count": len(records),
        "records": [
            {
                "title": item["title"],
                "extraction_status": item["extraction_status"],
                "char_count": item["char_count"],
                "source_sha256": item["source_sha256"],
            }
            for item in records
        ],
    }, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
