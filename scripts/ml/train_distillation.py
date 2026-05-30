from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

import torch
import torch.nn.functional as F

from scripts.ml.checkpoint import load_checkpoint, load_model_weights, save_checkpoint
from scripts.ml.config import load_training_config
from scripts.ml.data import build_dataloader
from scripts.ml.distillation import build_teacher_if_enabled, vanilla_kd_loss
from scripts.ml.models import build_model


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Train a student model with optional vanilla knowledge distillation.")
    parser.add_argument("--config", required=True, help="Path to JSON training config.")
    parser.add_argument("--max-batches", type=int, default=0, help="Stop after N batches for smoke tests.")
    parser.add_argument("--device", default="", help="Override config.device, for example cpu or cuda.")
    parser.add_argument("--teacher-checkpoint", default="", help="Override distill.teacher_checkpoint.")
    return parser.parse_args(argv)


def train(config: dict[str, Any], *, max_batches: int = 0) -> dict[str, Any]:
    torch.manual_seed(int(config.get("seed", 42)))
    device = torch.device(str(config.get("device", "cpu")))

    dataloader = build_dataloader(config["data"])
    student = build_model(config["model"]).to(device)

    resume_checkpoint = str(config["train"].get("resume_checkpoint", "")).strip()
    if resume_checkpoint:
        load_model_weights(student, resume_checkpoint, device)

    teacher = build_teacher_if_enabled(config["model"], config["distill"], device)
    optimizer = torch.optim.Adam(student.parameters(), lr=float(config["train"]["lr"]))
    if resume_checkpoint:
        checkpoint = load_checkpoint(resume_checkpoint, device)
        if isinstance(checkpoint, dict) and "optimizer_state_dict" in checkpoint:
            optimizer.load_state_dict(checkpoint["optimizer_state_dict"])

    epochs = int(config["train"]["epochs"])
    batch_count = 0
    last_parts: dict[str, float] = {}

    student.train()
    for epoch in range(epochs):
        for inputs, labels in dataloader:
            inputs = inputs.to(device)
            labels = labels.to(device)
            optimizer.zero_grad(set_to_none=True)

            student_logits = student(inputs)
            if teacher is None:
                loss = F.cross_entropy(student_logits, labels)
                last_parts = {
                    "ce_loss": float(loss.detach().cpu()),
                    "kd_loss": 0.0,
                    "total_loss": float(loss.detach().cpu()),
                }
            else:
                teacher.eval()
                with torch.no_grad():
                    teacher_logits = teacher(inputs)
                loss, last_parts = vanilla_kd_loss(
                    student_logits,
                    labels,
                    teacher_logits,
                    temperature=float(config["distill"]["temperature"]),
                    ce_weight=float(config["distill"]["ce_weight"]),
                    kd_weight=float(config["distill"]["kd_weight"]),
                )

            loss.backward()
            optimizer.step()
            batch_count += 1

            if max_batches > 0 and batch_count >= max_batches:
                break
        if max_batches > 0 and batch_count >= max_batches:
            break

    checkpoint_path = Path(str(config["train"]["checkpoint_dir"])) / str(config["train"]["checkpoint_name"])
    metrics = {
        "batches": batch_count,
        "epochs": epochs,
        "distill_enabled": teacher is not None,
        **last_parts,
    }
    save_checkpoint(checkpoint_path, model=student, optimizer=optimizer, config=config, epoch=epochs, metrics=metrics)
    metrics["checkpoint_path"] = str(checkpoint_path)
    return metrics


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    config = load_training_config(args.config)
    if args.device:
        config["device"] = args.device
    if args.teacher_checkpoint:
        config["distill"]["teacher_checkpoint"] = args.teacher_checkpoint

    metrics = train(config, max_batches=max(0, int(args.max_batches)))
    print(json.dumps(metrics, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
