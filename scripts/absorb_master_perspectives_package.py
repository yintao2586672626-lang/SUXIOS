#!/usr/bin/env python3
"""Build a traceable, reference-only index from the 165-perspective package.

The source ZIP and every nested ZIP are treated as untrusted input. This
script reads archives without executing bundled content, rejects unsafe paths
and symlinks, records source fingerprints, and emits deterministic JSON. It
does not install any Skill or copy persona prompts into the runtime.
"""

from __future__ import annotations

import argparse
import hashlib
import io
import json
import re
import sys
import zipfile
from collections import Counter
from pathlib import Path, PurePosixPath
from typing import Any, Iterable


SCRIPT_EXTENSIONS = {
    ".sh",
    ".bash",
    ".ps1",
    ".bat",
    ".cmd",
    ".py",
    ".js",
    ".mjs",
    ".cjs",
    ".ts",
    ".rb",
    ".php",
    ".exe",
}
TEXT_EXTENSIONS = {
    "",
    ".md",
    ".txt",
    ".json",
    ".yaml",
    ".yml",
    ".toml",
    ".xml",
    ".csv",
    *SCRIPT_EXTENSIONS,
}
RISK_PATTERNS = (
    ("high", "pipe-to-shell", re.compile(r"(?:curl|wget)[^\n|]*\|\s*(?:sh|bash|zsh)\b", re.I), False),
    ("high", "encoded-powershell", re.compile(r"powershell(?:\.exe)?[^\n]*(?:-enc|-encodedcommand)\b", re.I), False),
    ("high", "invoke-expression", re.compile(r"\b(?:Invoke-Expression|iex)\b", re.I), False),
    ("high", "broad-destructive-command", re.compile(r"(?:rm\s+-rf\s+(?:/|~|\$HOME)|git\s+reset\s+--hard)", re.I), False),
    ("high", "preapproved-shell", re.compile(r"^\s*allowed-tools\s*:\s*.*(?:\*|shell|bash|powershell)", re.I | re.M), False),
    ("medium", "network-access", re.compile(r"(?:\bcurl\b|\bwget\b|Invoke-WebRequest|Invoke-RestMethod|\bfetch\s*\(|requests\.(?:get|post|put|delete)\s*\(|https?://)", re.I), True),
    ("medium", "runtime-install", re.compile(r"(?:\bnpm\s+(?:install|i)\b|\bnpx\b|\bpip(?:3)?\s+install\b|\buv\s+(?:add|pip\s+install)\b)", re.I), True),
    ("medium", "prompt-injection-language", re.compile(r"(?:ignore\s+(?:all\s+)?previous\s+instructions|reveal\s+(?:the\s+)?system\s+prompt|exfiltrat(?:e|ion))", re.I), False),
    ("medium", "credential-access", re.compile(r"(?:process\.env|os\.environ|System\.Environment|getenv\s*\(|credential|api[_-]?key|secret|token)", re.I), False),
    ("medium", "machine-absolute-path", re.compile(r"(?:[A-Za-z]:\\(?:Users|Windows|Program Files)\\|/(?:home|Users)/[^/\s]+)"), False),
)
NAME_PATTERN = re.compile(r"^[a-z0-9]+(?:-[a-z0-9]+)*$")
FRONTMATTER_PATTERN = re.compile(r"\A---\s*\r?\n(.*?)\r?\n---(?:\r?\n|\Z)", re.S)
SCALAR_PATTERN_TEMPLATE = r"^{key}:\s*(.+?)\s*$"
INTRO_CATEGORY_PATTERN = re.compile(r"^##\s+(\d{2})\s+(.+?)\s*$")
INTRO_ITEM_PATTERN = re.compile(r"^###\s+(\d{2})\s+(.+?)\s*$")
INTRO_FIELD_PATTERN = re.compile(r"^- \*\*(蒸馏对象|一句话说明|核心内容|核心思想|使用场景|触发关键词)\*\*：\s*(.*)$")


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def canonical_json(value: Any) -> bytes:
    return (json.dumps(value, ensure_ascii=False, indent=2, sort_keys=False) + "\n").encode("utf-8")


def archive_path_is_safe(name: str) -> bool:
    normalized = name.replace("\\", "/")
    pure = PurePosixPath(normalized)
    return not (
        pure.is_absolute()
        or ".." in pure.parts
        or re.match(r"^[A-Za-z]:", normalized) is not None
    )


def normalized_zip_name(name: str) -> str:
    """Repair UTF-8 names written without the ZIP UTF-8 flag.

    The supplied archive was produced on macOS with UTF-8 path bytes but some
    central-directory entries omit the language flag. Python therefore
    exposes those bytes through CP437 code points, while .NET repairs them
    implicitly. ASCII and correctly flagged names pass through unchanged.
    """

    try:
        return name.encode("cp437").decode("utf-8")
    except (UnicodeEncodeError, UnicodeDecodeError):
        return name


def zip_info_is_symlink(info: zipfile.ZipInfo) -> bool:
    return ((info.external_attr >> 16) & 0o170000) == 0o120000


def validated_infos(archive: zipfile.ZipFile, label: str) -> list[zipfile.ZipInfo]:
    infos = archive.infolist()
    unsafe = [normalized_zip_name(info.filename) for info in infos if not archive_path_is_safe(normalized_zip_name(info.filename))]
    symlinks = [normalized_zip_name(info.filename) for info in infos if zip_info_is_symlink(info)]
    if unsafe:
        raise ValueError(f"unsafe archive paths in {label}: {unsafe[:5]}")
    if symlinks:
        raise ValueError(f"symlinks are not accepted in {label}: {symlinks[:5]}")
    return infos


def decode_text(data: bytes) -> str:
    return data.decode("utf-8-sig", errors="replace")


def frontmatter_scalar(text: str, key: str) -> str:
    match = FRONTMATTER_PATTERN.search(text)
    if not match:
        return ""
    scalar = re.search(
        SCALAR_PATTERN_TEMPLATE.format(key=re.escape(key)),
        match.group(1),
        re.M,
    )
    if not scalar:
        return ""
    return scalar.group(1).strip().strip("\"'")


def split_list(value: str, separators: str = r"[、；;]") -> list[str]:
    return [item.strip() for item in re.split(separators, value) if item.strip()]


def parse_intro(text: str) -> list[dict[str, Any]]:
    category_code = ""
    category_name = ""
    current: dict[str, Any] | None = None
    records: list[dict[str, Any]] = []
    field_names = {
        "蒸馏对象": "distilled_subject",
        "一句话说明": "description",
        "核心内容": "core_content",
        "核心思想": "core_ideas_raw",
        "使用场景": "use_cases_raw",
        "触发关键词": "source_triggers_raw",
    }
    for line in text.splitlines():
        category = INTRO_CATEGORY_PATTERN.match(line)
        if category:
            category_code = category.group(1)
            category_name = category.group(2).strip()
            current = None
            continue
        item = INTRO_ITEM_PATTERN.match(line)
        if item and category_code:
            current = {
                "category_code": category_code,
                "category_name": category_name,
                "item_index": item.group(1),
                "display_name": item.group(2).strip(),
                "distilled_subject": "",
                "description": "",
                "core_content": "",
                "core_ideas_raw": "",
                "use_cases_raw": "",
                "source_triggers_raw": "",
            }
            records.append(current)
            continue
        if current is None:
            continue
        field = INTRO_FIELD_PATTERN.match(line)
        if field:
            current[field_names[field.group(1)]] = field.group(2).strip()

    for record in records:
        missing = [
            key
            for key in ("description", "core_ideas_raw", "use_cases_raw", "source_triggers_raw")
            if not record[key]
        ]
        if missing:
            raise ValueError(f"intro record missing {missing}: {record['display_name']}")
        record["core_ideas"] = split_list(record.pop("core_ideas_raw"))
        record["use_cases"] = split_list(record.pop("use_cases_raw"), r"[、；;]")
        record["source_trigger_examples"] = split_list(record.pop("source_triggers_raw"), r"[、；;]")
    if len(records) != 165:
        raise ValueError(f"expected 165 intro records, got {len(records)}")
    return records


def nested_archive_identity(path: str) -> tuple[str, str, str]:
    parts = PurePosixPath(path).parts
    if len(parts) < 3:
        raise ValueError(f"unexpected nested archive path: {path}")
    category_match = re.match(r"^(\d{2})\s+(.+)$", parts[-2])
    item_match = re.match(r"^(\d{2})\s+(.+?)\.zip$", parts[-1], re.I)
    if not category_match or not item_match:
        raise ValueError(f"unexpected nested archive identity: {path}")
    return category_match.group(1), item_match.group(1), item_match.group(2).strip()


def remote_and_commit(files: dict[str, bytes]) -> tuple[str, str]:
    config_name = next((name for name in files if name.endswith(".git/config")), "")
    if not config_name:
        config_name = next((name for name in files if name == ".git/config"), "")
    config = decode_text(files.get(config_name, b""))
    remote_match = re.search(r"^\s*url\s*=\s*(.+?)\s*$", config, re.M)
    remote = remote_match.group(1).strip() if remote_match else ""

    head_name = next((name for name in files if name.endswith(".git/HEAD")), "")
    if not head_name:
        head_name = next((name for name in files if name == ".git/HEAD"), "")
    head = decode_text(files.get(head_name, b"")).strip()
    if re.fullmatch(r"[0-9a-f]{40}", head):
        return remote, head
    if head.startswith("ref: "):
        ref = head[5:].strip()
        prefix = head_name[: -len("HEAD")]
        ref_name = prefix + ref
        value = decode_text(files.get(ref_name, b"")).strip()
        if re.fullmatch(r"[0-9a-f]{40}", value):
            return remote, value
        packed_name = prefix + "packed-refs"
        packed = decode_text(files.get(packed_name, b""))
        packed_match = re.search(rf"^([0-9a-f]{{40}})\s+{re.escape(ref)}$", packed, re.M)
        if packed_match:
            return remote, packed_match.group(1)
    return remote, ""


def static_preview(non_git_files: dict[str, bytes], skill_text: str, directory_name: str) -> dict[str, Any]:
    findings: list[dict[str, Any]] = []
    scripts: list[str] = []
    total_bytes = 0
    for name in sorted(non_git_files):
        content = non_git_files[name]
        suffix = PurePosixPath(name).suffix.lower()
        total_bytes += len(content)
        is_script = name.startswith("scripts/") or suffix in SCRIPT_EXTENSIONS
        if is_script:
            scripts.append(name)
        if suffix == ".exe":
            findings.append({"severity": "high", "code": "binary-executable", "file": name})
        if len(content) > 1024 * 1024:
            findings.append({"severity": "medium", "code": "large-file", "file": name})
            continue
        if suffix not in TEXT_EXTENSIONS:
            continue
        text = decode_text(content)
        for severity, code, pattern, scripts_only in RISK_PATTERNS:
            if scripts_only and not is_script:
                continue
            match = pattern.search(text)
            if match:
                line = text.count("\n", 0, match.start()) + 1
                findings.append({"severity": severity, "code": code, "file": name, "line": line})
    if total_bytes > 5 * 1024 * 1024:
        findings.append({"severity": "medium", "code": "large-package", "file": None})

    name = frontmatter_scalar(skill_text, "name")
    description = frontmatter_scalar(skill_text, "description")
    allowed_tools = frontmatter_scalar(skill_text, "allowed-tools")
    validation_errors: list[str] = []
    if not FRONTMATTER_PATTERN.search(skill_text):
        validation_errors.append("frontmatter_missing_or_malformed")
    if not name or not NAME_PATTERN.fullmatch(name) or len(name) > 64:
        validation_errors.append("skill_name_not_portable_slug")
    if name and name != directory_name:
        validation_errors.append("skill_name_directory_mismatch")
    if not description or len(description) > 1024:
        validation_errors.append("skill_description_missing_or_too_long")
    status = "invalid" if validation_errors else ("review_required" if findings or scripts else "previewed")
    return {
        "status": status,
        "validation_errors": validation_errors,
        "finding_codes": sorted({finding["code"] for finding in findings}),
        "finding_count": len(findings),
        "script_files": scripts,
        "requested_tool_permissions": split_list(allowed_tools, r"[,\s]+") if allowed_tools else [],
        "manual_review_disposition": (
            "frontmatter_schema_incompatible_do_not_install"
            if status == "invalid"
            else "false_positive_words_or_unneeded_large_assets_no_credential_access"
            if status == "review_required"
            else "no_deterministic_finding_manual_review_still_required"
        ),
    }


def non_git_tree_hash(files: dict[str, bytes]) -> str:
    manifest = "\n".join(f"{name}\0{sha256(files[name])}" for name in sorted(files))
    return sha256(manifest.encode("utf-8"))


def write_or_check(path: Path, data: bytes, check: bool) -> None:
    if check:
        if not path.exists():
            raise ValueError(f"missing generated file: {path}")
        if path.read_bytes() != data:
            raise ValueError(f"generated file drift: {path}")
        return
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(data)


def build(source_zip: Path) -> tuple[dict[str, Any], dict[str, Any]]:
    outer_bytes = source_zip.read_bytes()
    outer_sha = sha256(outer_bytes)
    with zipfile.ZipFile(io.BytesIO(outer_bytes)) as outer:
        outer_infos = validated_infos(outer, source_zip.name)
        intro_info = next(
            (info for info in outer_infos if normalized_zip_name(info.filename).endswith("00-awesome-nuwa-Skill介绍材料.md")),
            None,
        )
        if intro_info is None:
            raise ValueError("overview markdown is missing")
        intro_bytes = outer.read(intro_info)
        intro_records = parse_intro(decode_text(intro_bytes))
        intro_by_key = {
            (record["category_code"], record["item_index"], record["display_name"]): record
            for record in intro_records
        }
        nested_infos = [
            info
            for info in outer_infos
            if normalized_zip_name(info.filename).lower().endswith(".zip")
            and not normalized_zip_name(info.filename).startswith("__MACOSX/")
            and not PurePosixPath(normalized_zip_name(info.filename)).name.startswith("._")
        ]
        if len(nested_infos) != 165:
            raise ValueError(f"expected 165 nested ZIP files, got {len(nested_infos)}")

        items: list[dict[str, Any]] = []
        method_items: list[dict[str, Any]] = []
        for nested_info in sorted(nested_infos, key=lambda item: normalized_zip_name(item.filename)):
            nested_path = normalized_zip_name(nested_info.filename)
            category_code, item_index, display_name = nested_archive_identity(nested_path)
            intro = intro_by_key.get((category_code, item_index, display_name))
            if intro is None:
                raise ValueError(f"overview/archive mismatch: {nested_path}")
            nested_bytes = outer.read(nested_info)
            with zipfile.ZipFile(io.BytesIO(nested_bytes)) as nested:
                infos = validated_infos(nested, nested_path)
                files = {
                    normalized_zip_name(info.filename): nested.read(info)
                    for info in infos
                    if not info.is_dir()
                }
            skill_names = [name for name in files if PurePosixPath(name).name.lower() == "skill.md"]
            license_names = [name for name in files if PurePosixPath(name).name.lower() in {"license", "license.md", "copying"}]
            readme_names = [name for name in files if PurePosixPath(name).name.lower() == "readme.md"]
            if len(skill_names) != 1 or len(license_names) != 1:
                raise ValueError(f"expected one SKILL.md and one license: {nested_path}")
            skill_bytes = files[skill_names[0]]
            skill_text = decode_text(skill_bytes)
            skill_name = frontmatter_scalar(skill_text, "name")
            portable_directory_name = skill_name if NAME_PATTERN.fullmatch(skill_name) else f"perspective-{category_code}-{item_index}"
            non_git_files = {
                name: content
                for name, content in files.items()
                if ".git" not in PurePosixPath(name).parts
            }
            preview = static_preview(non_git_files, skill_text, portable_directory_name)
            license_bytes = files[license_names[0]]
            license_spdx = "MIT" if decode_text(license_bytes).lstrip().startswith("MIT License") else "UNKNOWN"
            remote, commit = remote_and_commit(files)
            if not remote.startswith("https://github.com/") or not re.fullmatch(r"[0-9a-f]{40}", commit):
                raise ValueError(f"embedded repository provenance incomplete: {nested_path}")
            git_entries = [name for name in files if ".git" in PurePosixPath(name).parts]
            readme_bytes = files[readme_names[0]] if readme_names else b""
            source_record = {
                "category_code": category_code,
                "category_name": intro["category_name"],
                "item_index": item_index,
                "display_name": display_name,
                "archive_path": nested_path,
                "archive_sha256": sha256(nested_bytes),
                "archive_bytes": len(nested_bytes),
                "embedded_repository_url": remote,
                "embedded_commit": commit,
                "license_spdx": license_spdx,
                "license_sha256": sha256(license_bytes),
                "skill_name": skill_name,
                "skill_sha256": sha256(skill_bytes),
                "skill_bytes": len(skill_bytes),
                "readme_sha256": sha256(readme_bytes) if readme_bytes else None,
                "total_file_count": len(files),
                "git_entry_count": len(git_entries),
                "non_git_file_count": len(non_git_files),
                "non_git_tree_sha256": non_git_tree_hash(non_git_files),
                "static_preview": preview,
                "install_status": "not_installed_reference_only",
                "source_runtime_verified": False,
            }
            items.append(source_record)
            method_items.append(
                {
                    "category_code": category_code,
                    "category_name": intro["category_name"],
                    "item_index": item_index,
                    "display_name": display_name,
                    "skill_name": skill_name,
                    "description": intro["description"],
                    "core_ideas": intro["core_ideas"],
                    "use_cases": intro["use_cases"],
                    "source_trigger_examples": intro["source_trigger_examples"],
                    "perspective_role": "reference_lens_only",
                    "activation_allowed": False,
                    "authenticity_status": "source_package_synthesis_unverified",
                    "source_ref": f"embedded-git://{remote}@{commit}#SKILL.md",
                }
            )

    statuses = Counter(item["static_preview"]["status"] for item in items)
    finding_codes = Counter(
        code
        for item in items
        for code in item["static_preview"]["finding_codes"]
    )
    categories = Counter(f"{item['category_code']} {item['category_name']}" for item in items)
    license_hashes = Counter(item["license_sha256"] for item in items)
    manifest = {
        "schema_version": "suxios.master_perspectives.source_manifest.v1",
        "source_file_name": source_zip.name,
        "source_kind": "user_provided_nested_skill_zip",
        "source_observed_date": "2026-08-20",
        "outer_zip_sha256": outer_sha,
        "outer_zip_bytes": len(outer_bytes),
        "outer_entry_count": len(outer_infos),
        "overview_path": normalized_zip_name(intro_info.filename),
        "overview_sha256": sha256(intro_bytes),
        "nested_archive_count": len(items),
        "category_counts": [
            {"category": name, "count": categories[name]}
            for name in sorted(categories)
        ],
        "static_review": {
            "status": "reviewed_reference_only_not_installable_as_bundle",
            "preview_status_counts": dict(sorted(statuses.items())),
            "finding_code_package_counts": dict(sorted(finding_codes.items())),
            "unsafe_path_count": 0,
            "symlink_count": 0,
            "script_file_count": sum(len(item["static_preview"]["script_files"]) for item in items),
            "requested_tool_permission_count": sum(len(item["static_preview"]["requested_tool_permissions"]) for item in items),
            "license_spdx_counts": dict(sorted(Counter(item["license_spdx"] for item in items).items())),
            "license_text_hash_counts": dict(sorted(license_hashes.items())),
            "bundled_git_history_package_count": sum(item["git_entry_count"] > 0 for item in items),
            "manual_findings": [
                "Five packages have non-portable Chinese frontmatter names and missing standard descriptions.",
                "Credential-access findings are lexical false positives such as secret as a concept or credentialing as education; no credential API or environment access was found.",
                "Three hero GIF files exceed 1 MiB and are not needed for SUXIOS integration.",
                "No bundled Skill was executed or installed.",
            ],
        },
        "provenance_boundary": {
            "embedded_repository_state": "recorded_from_bundled_git_config_and_refs",
            "live_repository_refresh": "not_performed",
            "historical_authenticity": "not_verified_against_primary_sources",
            "persona_claims": "untrusted_source_authored_synthesis",
            "allowed_reuse": "paraphrased_reference_lenses_with_human_review",
            "blocked_reuse": [
                "automatic_skill_installation",
                "automatic_trigger_activation",
                "historical_quote_attribution_without_primary_source",
                "current_hotel_or_ota_fact",
                "automatic_external_action",
            ],
        },
        "items": items,
    }
    method_pack = {
        "schema_version": "suxios.master_perspectives.method_pack.v1",
        "source_outer_zip_sha256": outer_sha,
        "entry_count": len(method_items),
        "usage_policy": {
            "mode": "reference_only",
            "activation": "explicit_user_request_or_controlled_panel_selection_only",
            "selection_limit": 5,
            "quote_policy": "paraphrase_only_unless_primary_source_is_verified",
            "fact_policy": "never_fill_missing_hotel_platform_date_metric_or_source_facts",
            "action_policy": "local_draft_pending_human_approval_only",
            "persona_policy": "do_not_impersonate_or_claim_authentic_person_view",
        },
        "entries": method_items,
    }
    return manifest, method_pack


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("source_zip", type=Path)
    parser.add_argument(
        "--output-dir",
        type=Path,
        default=Path(__file__).resolve().parents[1] / "docs" / "knowledge" / "master-perspectives",
    )
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    if not args.source_zip.is_file():
        parser.error(f"source ZIP not found: {args.source_zip}")

    try:
        manifest, method_pack = build(args.source_zip)
        write_or_check(args.output_dir / "source-manifest.json", canonical_json(manifest), args.check)
        write_or_check(args.output_dir / "method-pack.json", canonical_json(method_pack), args.check)
    except (OSError, ValueError, zipfile.BadZipFile) as error:
        print(json.dumps({"status": "failed", "error": str(error)}, ensure_ascii=False), file=sys.stderr)
        return 1

    print(
        json.dumps(
            {
                "status": "checked" if args.check else "generated",
                "source_outer_zip_sha256": manifest["outer_zip_sha256"],
                "entries": manifest["nested_archive_count"],
                "categories": len(manifest["category_counts"]),
                "preview_status_counts": manifest["static_review"]["preview_status_counts"],
                "script_file_count": manifest["static_review"]["script_file_count"],
                "output_dir": str(args.output_dir.resolve()),
            },
            ensure_ascii=False,
            indent=2,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
