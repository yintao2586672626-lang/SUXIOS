#!/usr/bin/env python3
"""Isolated PDF text extractor used by absorb_ai_knowledge_library.py."""

from __future__ import annotations

import argparse
import json
from pathlib import Path

from pypdf import PdfReader


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("input")
    parser.add_argument("text_output")
    parser.add_argument("metadata_output")
    args = parser.parse_args()

    reader = PdfReader(args.input, strict=False)
    if reader.is_encrypted:
        try:
            if reader.decrypt("") == 0:
                raise ValueError("encrypted_pdf_requires_password")
        except Exception as exc:  # noqa: BLE001
            raise ValueError("encrypted_pdf_requires_password") from exc

    page_errors = 0
    with Path(args.text_output).open("w", encoding="utf-8") as handle:
        for page_index, page in enumerate(reader.pages):
            try:
                handle.write(page.extract_text() or "")
            except Exception:  # noqa: BLE001
                page_errors += 1
            if page_index + 1 < len(reader.pages):
                handle.write("\n\n")
    Path(args.metadata_output).write_text(
        json.dumps({"page_count": len(reader.pages), "page_extract_errors": page_errors}),
        encoding="utf-8",
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
