from __future__ import annotations

from collections.abc import Mapping
from pathlib import Path
from typing import Any

import torch
from torch import nn


STATE_DICT_KEYS = ("model_state_dict", "student_state_dict", "state_dict", "model")


def _torch_load(path: Path, device: torch.device) -> Any:
    try:
        return torch.load(path, map_location=device, weights_only=False)
    except TypeError:
        return torch.load(path, map_location=device)


def load_checkpoint(path: str | Path, device: torch.device) -> Any:
    checkpoint_path = Path(path)
    if not checkpoint_path.is_file():
        raise FileNotFoundError(f"Checkpoint not found: {checkpoint_path}")
    return _torch_load(checkpoint_path, device)


def extract_model_state_dict(checkpoint: Any) -> Mapping[str, torch.Tensor]:
    if isinstance(checkpoint, nn.Module):
        return checkpoint.state_dict()

    if isinstance(checkpoint, Mapping):
        if checkpoint and all(torch.is_tensor(value) for value in checkpoint.values()):
            return checkpoint

        for key in STATE_DICT_KEYS:
            value = checkpoint.get(key)
            if isinstance(value, nn.Module):
                return value.state_dict()
            if isinstance(value, Mapping):
                return value

    raise ValueError(
        "Checkpoint must be a model state_dict, nn.Module, or dict containing one of: "
        + ", ".join(STATE_DICT_KEYS)
    )


def load_model_weights(
    model: nn.Module,
    checkpoint_path: str | Path,
    device: torch.device,
    *,
    strict: bool = True,
) -> Any:
    checkpoint = load_checkpoint(checkpoint_path, device)
    state_dict = extract_model_state_dict(checkpoint)
    return model.load_state_dict(state_dict, strict=strict)


def save_checkpoint(
    path: str | Path,
    *,
    model: nn.Module,
    config: Mapping[str, Any],
    epoch: int,
    optimizer: torch.optim.Optimizer | None = None,
    metrics: Mapping[str, Any] | None = None,
) -> Path:
    checkpoint_path = Path(path)
    checkpoint_path.parent.mkdir(parents=True, exist_ok=True)
    payload: dict[str, Any] = {
        "epoch": int(epoch),
        "config": dict(config),
        "model_state_dict": model.state_dict(),
        "metrics": dict(metrics or {}),
    }
    if optimizer is not None:
        payload["optimizer_state_dict"] = optimizer.state_dict()

    torch.save(payload, checkpoint_path)
    return checkpoint_path
