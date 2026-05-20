from __future__ import annotations

from collections.abc import Mapping
from typing import Any

import torch
from torch import nn


class SimpleClassifier(nn.Module):
    def __init__(self, input_dim: int, hidden_dim: int, num_classes: int) -> None:
        super().__init__()
        if input_dim <= 0:
            raise ValueError("model.input_dim must be greater than 0")
        if hidden_dim <= 0:
            raise ValueError("model.hidden_dim must be greater than 0")
        if num_classes <= 1:
            raise ValueError("model.num_classes must be greater than 1")

        self.net = nn.Sequential(
            nn.Linear(input_dim, hidden_dim),
            nn.ReLU(),
            nn.Linear(hidden_dim, num_classes),
        )

    def forward(self, inputs: torch.Tensor) -> torch.Tensor:
        return self.net(inputs.float())


def build_model(config: Mapping[str, Any]) -> nn.Module:
    model_type = str(config.get("type", "mlp"))
    if model_type != "mlp":
        raise ValueError(f"Unsupported model.type: {model_type}")

    return SimpleClassifier(
        input_dim=int(config["input_dim"]),
        hidden_dim=int(config.get("hidden_dim", 64)),
        num_classes=int(config["num_classes"]),
    )
