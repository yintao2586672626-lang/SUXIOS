from __future__ import annotations

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))


try:
    import torch
except ModuleNotFoundError:  # pragma: no cover - documents missing optional ML dependency.
    torch = None


@unittest.skipIf(torch is None, "torch is required for ML distillation tests")
class DistillationTest(unittest.TestCase):
    def test_teacher_checkpoint_loads_in_eval_mode(self) -> None:
        from scripts.ml.checkpoint import save_checkpoint
        from scripts.ml.distillation import load_teacher_model
        from scripts.ml.models import build_model

        with tempfile.TemporaryDirectory() as tmp:
            checkpoint_path = Path(tmp) / "teacher.pt"
            model_config = {"type": "mlp", "input_dim": 4, "hidden_dim": 6, "num_classes": 3}
            teacher = build_model(model_config)
            save_checkpoint(checkpoint_path, model=teacher, config={"model": model_config}, epoch=0)

            loaded = load_teacher_model(model_config, checkpoint_path, torch.device("cpu"))

        self.assertFalse(loaded.training)
        self.assertTrue(all(not param.requires_grad for param in loaded.parameters()))

    def test_teacher_parameters_do_not_update_during_student_step(self) -> None:
        from scripts.ml.distillation import vanilla_kd_loss
        from scripts.ml.models import build_model

        model_config = {"type": "mlp", "input_dim": 4, "hidden_dim": 6, "num_classes": 3}
        student = build_model(model_config)
        teacher = build_model(model_config)
        teacher.eval()
        for param in teacher.parameters():
            param.requires_grad_(False)

        before = [param.detach().clone() for param in teacher.parameters()]
        optimizer = torch.optim.SGD(student.parameters(), lr=0.05)
        inputs = torch.randn(5, 4)
        labels = torch.tensor([0, 1, 2, 1, 0])

        student_logits = student(inputs)
        with torch.no_grad():
            teacher_logits = teacher(inputs)
        loss, _ = vanilla_kd_loss(
            student_logits,
            labels,
            teacher_logits,
            temperature=3.0,
            ce_weight=0.7,
            kd_weight=0.3,
        )
        loss.backward()
        optimizer.step()

        after = list(teacher.parameters())
        self.assertTrue(all(torch.equal(left, right) for left, right in zip(before, after)))
        self.assertTrue(all(param.grad is None for param in teacher.parameters()))

    def test_kd_loss_forward_backward_updates_student_gradients(self) -> None:
        from scripts.ml.distillation import vanilla_kd_loss
        from scripts.ml.models import build_model

        model_config = {"type": "mlp", "input_dim": 4, "hidden_dim": 6, "num_classes": 3}
        student = build_model(model_config)
        teacher = build_model(model_config)
        inputs = torch.randn(4, 4)
        labels = torch.tensor([0, 1, 2, 1])

        student_logits = student(inputs)
        with torch.no_grad():
            teacher_logits = teacher(inputs)
        loss, parts = vanilla_kd_loss(
            student_logits,
            labels,
            teacher_logits,
            temperature=4.0,
            ce_weight=0.5,
            kd_weight=0.5,
        )
        loss.backward()

        self.assertGreater(float(loss.detach()), 0.0)
        self.assertIn("ce_loss", parts)
        self.assertIn("kd_loss", parts)
        self.assertTrue(any(param.grad is not None for param in student.parameters()))

    def test_training_script_runs_one_kd_batch(self) -> None:
        from scripts.ml.checkpoint import save_checkpoint
        from scripts.ml.models import build_model

        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            teacher_checkpoint = tmp_path / "teacher.pt"
            output_dir = tmp_path / "checkpoints"
            model_config = {"type": "mlp", "input_dim": 4, "hidden_dim": 6, "num_classes": 3}
            save_checkpoint(
                teacher_checkpoint,
                model=build_model(model_config),
                config={"model": model_config},
                epoch=0,
            )

            config_path = tmp_path / "config.json"
            config_path.write_text(
                json.dumps(
                    {
                        "seed": 7,
                        "device": "cpu",
                        "model": model_config,
                        "data": {
                            "type": "synthetic",
                            "num_samples": 8,
                            "input_dim": 4,
                            "num_classes": 3,
                            "batch_size": 4,
                        },
                        "train": {
                            "epochs": 1,
                            "lr": 0.01,
                            "checkpoint_dir": str(output_dir),
                            "checkpoint_name": "student.pt",
                        },
                        "distill": {
                            "enabled": True,
                            "teacher_checkpoint": str(teacher_checkpoint),
                            "temperature": 2.0,
                            "ce_weight": 0.6,
                            "kd_weight": 0.4,
                            "method": "vanilla_kd",
                        },
                    }
                ),
                encoding="utf-8",
            )

            result = subprocess.run(
                [
                    sys.executable,
                    str(ROOT / "scripts" / "ml" / "train_distillation.py"),
                    "--config",
                    str(config_path),
                    "--max-batches",
                    "1",
                ],
                cwd=ROOT,
                text=True,
                capture_output=True,
                check=False,
            )

            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn('"distill_enabled": true', result.stdout)
            self.assertTrue((output_dir / "student.pt").is_file())


if __name__ == "__main__":
    unittest.main()
