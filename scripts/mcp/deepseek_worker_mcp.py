#!/usr/bin/env python3
"""Minimal DeepSeek MCP worker for Claude Code.

Required environment:
  DEEPSEEK_API_KEY=sk-...

Optional environment:
  DEEPSEEK_MODEL=deepseek-chat
  DEEPSEEK_BASE_URL=https://api.deepseek.com
"""

from __future__ import annotations

import json
import os
import sys
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[2]
DEFAULT_MODEL = "deepseek-chat"
DEFAULT_BASE_URL = "https://api.deepseek.com"


TOOLS = [
    {
        "name": "ask",
        "description": "Ask a DeepSeek worker to analyze code, logs, plans, or implementation questions.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "prompt": {"type": "string", "description": "The task or question for DeepSeek."},
                "system_prompt": {
                    "type": "string",
                    "description": "Optional system instruction.",
                },
                "model": {"type": "string", "description": "Optional DeepSeek model override."},
                "temperature": {"type": "number", "default": 0.2},
                "max_tokens": {"type": "integer", "default": 4096},
            },
            "required": ["prompt"],
        },
    },
    {
        "name": "bulk_read",
        "description": "Read selected repository files and ask DeepSeek to analyze them.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "paths": {
                    "type": "array",
                    "items": {"type": "string"},
                    "description": "Repository-relative file paths to include.",
                },
                "question": {
                    "type": "string",
                    "description": "What DeepSeek should look for in the files.",
                },
                "max_chars_per_file": {"type": "integer", "default": 20000},
                "model": {"type": "string", "description": "Optional DeepSeek model override."},
            },
            "required": ["paths", "question"],
        },
    },
]


def write_response(payload: dict[str, Any]) -> None:
    sys.stdout.write(json.dumps(payload, ensure_ascii=False) + "\n")
    sys.stdout.flush()


def ok(request_id: Any, result: dict[str, Any]) -> None:
    write_response({"jsonrpc": "2.0", "id": request_id, "result": result})


def err(request_id: Any, code: int, message: str) -> None:
    write_response({"jsonrpc": "2.0", "id": request_id, "error": {"code": code, "message": message}})


def text_result(text: str) -> dict[str, Any]:
    return {"content": [{"type": "text", "text": text}]}


def call_deepseek(
    prompt: str,
    *,
    system_prompt: str | None = None,
    model: str | None = None,
    temperature: float = 0.2,
    max_tokens: int = 4096,
) -> str:
    api_key = os.environ.get("DEEPSEEK_API_KEY")
    if not api_key:
        raise RuntimeError("Missing DEEPSEEK_API_KEY in the environment that starts Claude Code.")

    base_url = os.environ.get("DEEPSEEK_BASE_URL", DEFAULT_BASE_URL).rstrip("/")
    selected_model = model or os.environ.get("DEEPSEEK_MODEL", DEFAULT_MODEL)
    messages: list[dict[str, str]] = []
    if system_prompt:
        messages.append({"role": "system", "content": system_prompt})
    messages.append({"role": "user", "content": prompt})

    body = json.dumps(
        {
            "model": selected_model,
            "messages": messages,
            "temperature": temperature,
            "max_tokens": max_tokens,
        }
    ).encode("utf-8")

    request = urllib.request.Request(
        f"{base_url}/chat/completions",
        data=body,
        headers={
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
        },
        method="POST",
    )

    try:
        with urllib.request.urlopen(request, timeout=120) as response:
            data = json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        detail = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"DeepSeek HTTP {exc.code}: {detail}") from exc

    try:
        return data["choices"][0]["message"]["content"]
    except (KeyError, IndexError, TypeError) as exc:
        raise RuntimeError(f"Unexpected DeepSeek response: {json.dumps(data, ensure_ascii=False)[:1000]}") from exc


def safe_read(path_text: str, max_chars: int) -> str:
    target = (ROOT / path_text).resolve()
    try:
        target.relative_to(ROOT)
    except ValueError as exc:
        raise RuntimeError(f"Path escapes repository root: {path_text}") from exc
    if not target.is_file():
        raise RuntimeError(f"File not found: {path_text}")
    return target.read_text(encoding="utf-8", errors="replace")[:max_chars]


def handle_tool_call(request_id: Any, params: dict[str, Any]) -> None:
    name = params.get("name")
    arguments = params.get("arguments") or {}

    try:
        if name == "ask":
            answer = call_deepseek(
                str(arguments["prompt"]),
                system_prompt=arguments.get("system_prompt"),
                model=arguments.get("model"),
                temperature=float(arguments.get("temperature", 0.2)),
                max_tokens=int(arguments.get("max_tokens", 4096)),
            )
            ok(request_id, text_result(answer))
            return

        if name == "bulk_read":
            paths = arguments.get("paths") or []
            question = str(arguments["question"])
            max_chars = int(arguments.get("max_chars_per_file", 20000))
            chunks = []
            for path in paths:
                content = safe_read(str(path), max_chars)
                chunks.append(f"--- FILE: {path} ---\n{content}")
            prompt = question + "\n\n" + "\n\n".join(chunks)
            answer = call_deepseek(prompt, model=arguments.get("model"))
            ok(request_id, text_result(answer))
            return

        err(request_id, -32601, f"Unknown tool: {name}")
    except Exception as exc:
        ok(request_id, {"content": [{"type": "text", "text": f"ERROR: {exc}"}], "isError": True})


def handle(message: dict[str, Any]) -> None:
    method = message.get("method")
    request_id = message.get("id")

    if method == "initialize":
        ok(
            request_id,
            {
                "protocolVersion": "2024-11-05",
                "capabilities": {"tools": {}},
                "serverInfo": {"name": "deepseek-worker", "version": "1.0.0"},
            },
        )
        return

    if method == "tools/list":
        ok(request_id, {"tools": TOOLS})
        return

    if method == "tools/call":
        handle_tool_call(request_id, message.get("params") or {})
        return

    if method and method.startswith("notifications/"):
        return

    if request_id is not None:
        err(request_id, -32601, f"Unsupported method: {method}")


def main() -> None:
    for line in sys.stdin:
        line = line.strip().lstrip("\ufeff")
        if not line:
            continue
        try:
            handle(json.loads(line))
        except Exception as exc:
            err(None, -32700, f"Invalid request: {exc}")


if __name__ == "__main__":
    main()
