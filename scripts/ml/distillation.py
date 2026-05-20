from __future__ import annotations

from collections.abc import Mapping
from pathlib import Path
from typing import Any

import torch
import torch.nn.functional as F
from torch import nn

from scripts.ml.checkpoint import load_model_weights
from scripts.ml.models import build_model


SUPPORTED_METHOD = "vanilla_kd"


def vanilla_kd_loss(
    student_logits: torch.Tensor,
    labels: torch.Tensor,
    teacher_logits: torch.Tensor,
    *,
    temperature: float,
    ce_weight: float,
    kd_weight: float,
) -> tuple[torch.Tensor, dict[str, float]]:
    if temperature <= 0:
        raise ValueError("distill.temperature must be greater than 0")
    if ce_weight < 0 or kd_weight < 0:
        raise ValueError("distill.ce_weight and distill.kd_weight must be non-negative")

    ce_loss = F.cross_entropy(student_logits, labels)
    student_log_probs = F.log_softmax(student_logits / temperature, dim=1)
    teacher_probs = F.softmax(teacher_logits / temperature, dim=1)
    kd_loss = F.kl_div(student_log_probs, teacher_probs, reduction="batchmean") * (temperature**2)
    total_loss = ce_weight * ce_loss + kd_weight * kd_loss

    return total_loss, {
        "ce_loss": float(ce_loss.detach().cpu()),
        "kd_loss": float(kd_loss.detach().cpu()),
        "total_loss": float(total_loss.detach().cpu()),
    }


def load_teacher_model(
    model_config: Mapping[str, Any],
    checkpoint_path: str | Path,
    device: torch.device,
) -> nn.Module:
    teacher = build_model(model_config).to(device)
    load_model_weights(teacher, checkpoint_path, device)
    teacher.eval()
    for param in teacher.parameters():
        param.requires_grad_(False)
    return teacher


def build_teacher_if_enabled(
    model_config: Mapping[str, Any],
    distill_config: Mapping[str, Any],
    device: torch.device,
) -> nn.Module | None:
    if not bool(distill_config.get("enabled", False)):
        return None

    method = str(distill_config.get("method", SUPPORTED_METHOD))
    if method != SUPPORTED_METHOD:
        raise ValueError(f"Unsupported distill.method: {method}")

    checkpoint_path = str(distill_config.get("teacher_checkpoint", "")).strip()
    if checkpoint_path == "":
        raise ValueError("distill.teacher_checkpoint is required when distill.enabled is true")

    return load_teacher_model(model_config, checkpoint_path, device)
