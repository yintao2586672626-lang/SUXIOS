#!/usr/bin/env python3
"""Extract the largest embedded raster from each scanned PDF page for review."""

from __future__ import annotations

import argparse
import json
from pathlib import Path

from PIL import Image
from pypdf import PdfReader


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", required=True)
    parser.add_argument("pdfs", nargs="+")
    args = parser.parse_args()
    output = Path(args.output)
    output.mkdir(parents=True, exist_ok=True)
    records = []
    for pdf_index, pdf_value in enumerate(args.pdfs, 1):
        path = Path(pdf_value)
        reader = PdfReader(path, strict=False)
        for page_index, page in enumerate(reader.pages, 1):
            candidates = []
            for image_file in page.images:
                try:
                    image = image_file.image.convert("RGB")
                    candidates.append((image.width * image.height, image))
                except Exception:  # noqa: BLE001
                    continue
            if not candidates:
                records.append({"pdf": str(path), "page": page_index, "status": "no_embedded_image"})
                continue
            _, image = max(candidates, key=lambda item: item[0])
            target = output / f"pdf-{pdf_index:02d}-page-{page_index:02d}.png"
            image.save(target, format="PNG", optimize=True)
            records.append({
                "pdf": str(path), "page": page_index, "status": "extracted",
                "path": str(target.resolve()), "width": image.width, "height": image.height,
            })
    (output / "manifest.json").write_text(json.dumps(records, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps(records, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
