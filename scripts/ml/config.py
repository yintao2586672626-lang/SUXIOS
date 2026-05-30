from __future__ import annotations

import copy
import json
from collections.abc import Mapping
from pathlib import Path
from typing import Any


DEFAULT_CONFIG: dict[str, Any] = {
    "seed": 42,
    "device": "cpu",
    "model": {
        "type": "mlp",
        "input_dim": 8,
        "hidden_dim": 32,
        "num_classes": 3,
    },
    "data": {
        "type": "synthetic",
        "num_samples": 64,
        "input_dim": 8,
        "num_classes": 3,
        "batch_size": 8,
        "shuffle": True,
    },
    "train": {
        "epochs": 1,
        "lr": 0.001,
        "checkpoint_dir": "runtime/ml_checkpoints",
        "checkpoint_name": "student.pt",
        "resume_checkpoint": "",
    },
    "distill": {
        "enabled": False,
        "teacher_checkpoint": "",
        "temperature": 4.0,
        "ce_weight": 1.0,
        "kd_weight": 0.0,
        "method": "vanilla_kd",
    },
}


def _deep_merge(base: dict[str, Any], override: Mapping[str, Any]) -> dict[str, Any]:
    result = copy.deepcopy(base)
    for key, value in override.items():
        if isinstance(value, Mapping) and isinstance(result.get(key), dict):
            result[key] = _deep_merge(result[key], value)
        else:
            result[key] = value
    return result


def load_training_config(path: str | Path) -> dict[str, Any]:
    config_path = Path(path)
    if not config_path.is_file():
        raise FileNotFoundError(f"Training config not found: {config_path}")

    raw = json.loads(config_path.read_text(encoding="utf-8"))
    if not isinstance(raw, Mapping):
        raise ValueError("Training config root must be a JSON object")
    config = _deep_merge(DEFAULT_CONFIG, raw)
    _validate_config(config)
    return config


def _validate_config(config: Mapping[str, Any]) -> None:
    for section in ("model", "data", "train", "distill"):
        if not isinstance(config.get(section), Mapping):
            raise ValueError(f"{section} must be a JSON object")

    model = config["model"]
    data = config["data"]
    if int(model["input_dim"]) != int(data.get("input_dim", model["input_dim"])):
        raise ValueError("model.input_dim must match data.input_dim")
    if int(model["num_classes"]) != int(data.get("num_classes", model["num_classes"])):
        raise ValueError("model.num_classes must match data.num_classes")

    train = config["train"]
    if int(train.get("epochs", 0)) <= 0:
        raise ValueError("train.epochs must be greater than 0")
    if float(train.get("lr", 0.0)) <= 0:
        raise ValueError("train.lr must be greater than 0")

    distill = config["distill"]
    if str(distill.get("method", "")) != "vanilla_kd":
        raise ValueError("Only distill.method='vanilla_kd' is supported")
    if float(distill.get("temperature", 0.0)) <= 0:
        raise ValueError("distill.temperature must be greater than 0")
    if float(distill.get("ce_weight", -1.0)) < 0 or float(distill.get("kd_weight", -1.0)) < 0:
        raise ValueError("distill.ce_weight and distill.kd_weight must be non-negative")
