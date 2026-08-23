#!/usr/bin/env python3
"""Local-only ASR helper for SUXIOS. It never uploads or retains source media."""

from __future__ import annotations

import argparse
import json
import math
import os
import subprocess
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
MODEL_ROOT = ROOT / "storage" / "local-ai" / "models"
DEFAULT_MODEL = "small"
MAX_DURATION_SECONDS = 5 * 60
MAX_NO_SPEECH_PROBABILITY = 0.60


def emit(payload: dict, exit_code: int = 0) -> None:
    # Keep the subprocess wire format ASCII-only so redirected stdout remains
    # valid JSON on Windows regardless of the active console code page.
    print(json.dumps(payload, ensure_ascii=True, separators=(",", ":")))
    raise SystemExit(exit_code)


def model_marker(model_name: str) -> Path:
    return MODEL_ROOT / f"{model_name}.ready.json"


def load_runtime():
    try:
        import faster_whisper  # type: ignore
        from faster_whisper import WhisperModel  # type: ignore
    except Exception as exc:  # pragma: no cover - exercised by runtime health
        emit({
            "status": "blocked_not_configured",
            "error_code": "asr_runtime_missing",
            "detail": type(exc).__name__,
        }, 2)
    return faster_whisper, WhisperModel


def duration_seconds(source: Path) -> float | None:
    try:
        result = subprocess.run(
            [
                "ffprobe", "-v", "error", "-show_entries", "format=duration",
                "-of", "default=noprint_wrappers=1:nokey=1", str(source),
            ],
            check=False,
            capture_output=True,
            text=True,
            timeout=20,
        )
        if result.returncode != 0:
            return None
        value = float(result.stdout.strip())
        return value if math.isfinite(value) and value >= 0 else None
    except Exception:
        return None


def build_model(model_name: str, allow_download: bool):
    faster_whisper, whisper_model = load_runtime()
    MODEL_ROOT.mkdir(parents=True, exist_ok=True)
    model = whisper_model(
        model_name,
        device="cpu",
        compute_type="int8",
        download_root=str(MODEL_ROOT),
        local_files_only=not allow_download,
    )
    marker = model_marker(model_name)
    marker.write_text(json.dumps({
        "model": model_name,
        "runtime_version": getattr(faster_whisper, "__version__", "unknown"),
        "device": "cpu",
        "compute_type": "int8",
    }, ensure_ascii=False), encoding="utf-8")
    return faster_whisper, model


def health(model_name: str) -> None:
    faster_whisper, _ = load_runtime()
    marker = model_marker(model_name)
    emit({
        "status": "ready" if marker.is_file() else "blocked_not_configured",
        "error_code": None if marker.is_file() else "asr_model_missing",
        "runtime_version": getattr(faster_whisper, "__version__", "unknown"),
        "model": model_name,
        "model_ready": marker.is_file(),
        "device": "cpu",
        "compute_type": "int8",
        "local_only": True,
    }, 0 if marker.is_file() else 3)


def bootstrap(model_name: str) -> None:
    faster_whisper, _ = build_model(model_name, allow_download=True)
    emit({
        "status": "ready",
        "error_code": None,
        "runtime_version": getattr(faster_whisper, "__version__", "unknown"),
        "model": model_name,
        "model_ready": True,
        "device": "cpu",
        "compute_type": "int8",
        "local_only": True,
    })


def transcribe(source: Path, model_name: str) -> None:
    if not source.is_file():
        emit({"status": "failed", "error_code": "source_file_missing"}, 4)
    duration = duration_seconds(source)
    if duration is None:
        emit({"status": "failed", "error_code": "media_duration_unreadable"}, 5)
    if duration > MAX_DURATION_SECONDS:
        emit({
            "status": "failed",
            "error_code": "media_duration_exceeded",
            "duration_seconds": round(duration, 3),
        }, 6)

    faster_whisper, model = build_model(model_name, allow_download=False)
    try:
        segments_iter, info = model.transcribe(
            str(source),
            language=None,
            beam_size=5,
            vad_filter=True,
            condition_on_previous_text=False,
        )
        segments = []
        transcript_parts = []
        probabilities = []
        rejected_segment_count = 0
        for segment in segments_iter:
            text = str(getattr(segment, "text", "")).strip()
            if not text:
                continue
            no_speech_probability = max(
                0.0,
                min(1.0, float(getattr(segment, "no_speech_prob", 1.0))),
            )
            accepted = no_speech_probability < MAX_NO_SPEECH_PROBABILITY
            avg_logprob = float(getattr(segment, "avg_logprob", -20.0))
            if len(segments) < 120:
                segments.append({
                    "start": round(float(getattr(segment, "start", 0.0)), 3),
                    "end": round(float(getattr(segment, "end", 0.0)), 3),
                    "text": text[:500],
                    "no_speech_probability": round(no_speech_probability, 5),
                    "accepted": accepted,
                })
            if not accepted:
                rejected_segment_count += 1
                continue
            transcript_parts.append(text)
            probabilities.append(max(0.0, min(1.0, math.exp(avg_logprob))))
        transcript = " ".join(transcript_parts).strip()[:20000]
        confidence = round(sum(probabilities) / len(probabilities), 5) if probabilities else None
        emit({
            "status": "ready" if transcript else "partial",
            "error_code": None if transcript else "speech_not_detected",
            "method": "faster_whisper_local",
            "extractor_version": f"faster-whisper/{getattr(faster_whisper, '__version__', 'unknown')}:{model_name}:cpu-int8",
            "text": transcript or None,
            "confidence": confidence,
            "structured": {
                "language": str(getattr(info, "language", "")),
                "language_probability": round(float(getattr(info, "language_probability", 0.0)), 5),
                "duration_seconds": round(duration, 3),
                "segments": segments,
                "rejected_segment_count": rejected_segment_count,
                "no_speech_probability_threshold": MAX_NO_SPEECH_PROBABILITY,
                "source_retained": False,
            },
        })
    except Exception as exc:
        emit({
            "status": "failed",
            "error_code": "asr_transcription_failed",
            "detail": type(exc).__name__,
        }, 7)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--health", action="store_true")
    parser.add_argument("--bootstrap", action="store_true")
    parser.add_argument("--input")
    parser.add_argument("--model", default=os.environ.get("SUXIOS_WHISPER_MODEL", DEFAULT_MODEL))
    args = parser.parse_args()
    model_name = str(args.model).strip() or DEFAULT_MODEL
    if args.health:
        health(model_name)
    if args.bootstrap:
        bootstrap(model_name)
    if not args.input:
        emit({"status": "failed", "error_code": "input_required"}, 2)
    transcribe(Path(args.input).resolve(), model_name)


if __name__ == "__main__":
    main()
