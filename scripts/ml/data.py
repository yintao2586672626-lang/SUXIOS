from __future__ import annotations

import csv
from collections.abc import Mapping, Sequence
from pathlib import Path
from typing import Any

import torch
from torch.utils.data import DataLoader, Dataset, TensorDataset


class CsvClassificationDataset(Dataset[tuple[torch.Tensor, torch.Tensor]]):
    def __init__(self, path: str | Path, *, label_column: str, feature_columns: Sequence[str] | None = None) -> None:
        data_path = Path(path)
        if not data_path.is_file():
            raise FileNotFoundError(f"CSV dataset not found: {data_path}")

        with data_path.open("r", encoding="utf-8-sig", newline="") as handle:
            reader = csv.DictReader(handle)
            if reader.fieldnames is None:
                raise ValueError(f"CSV dataset has no header: {data_path}")
            columns = list(feature_columns or [name for name in reader.fieldnames if name != label_column])
            if label_column not in reader.fieldnames:
                raise ValueError(f"CSV label_column not found: {label_column}")
            if not columns:
                raise ValueError("CSV feature_columns must not be empty")

            features: list[list[float]] = []
            labels: list[int] = []
            for row_number, row in enumerate(reader, start=2):
                try:
                    features.append([float(row[column]) for column in columns])
                    labels.append(int(row[label_column]))
                except KeyError as exc:
                    raise ValueError(f"CSV feature column not found: {exc.args[0]}") from exc
                except ValueError as exc:
                    raise ValueError(f"Invalid numeric value in CSV row {row_number}") from exc

        if not features:
            raise ValueError(f"CSV dataset is empty: {data_path}")

        self.features = torch.tensor(features, dtype=torch.float32)
        self.labels = torch.tensor(labels, dtype=torch.long)

    def __len__(self) -> int:
        return int(self.labels.shape[0])

    def __getitem__(self, index: int) -> tuple[torch.Tensor, torch.Tensor]:
        return self.features[index], self.labels[index]


def synthetic_classification_dataset(config: Mapping[str, Any]) -> TensorDataset:
    input_dim = int(config["input_dim"])
    num_classes = int(config["num_classes"])
    num_samples = int(config.get("num_samples", 128))
    if input_dim <= 0:
        raise ValueError("data.input_dim must be greater than 0")
    if num_classes <= 1:
        raise ValueError("data.num_classes must be greater than 1")
    if num_samples <= 0:
        raise ValueError("data.num_samples must be greater than 0")

    generator = torch.Generator().manual_seed(int(config.get("seed", 0)))
    features = torch.randn(num_samples, input_dim, generator=generator)
    weights = torch.randn(input_dim, num_classes, generator=generator)
    labels = torch.argmax(features @ weights, dim=1).long()
    return TensorDataset(features, labels)


def build_dataloader(config: Mapping[str, Any]) -> DataLoader[tuple[torch.Tensor, torch.Tensor]]:
    dataset_type = str(config.get("type", "synthetic"))
    batch_size = int(config.get("batch_size", 32))
    if batch_size <= 0:
        raise ValueError("data.batch_size must be greater than 0")

    if dataset_type == "synthetic":
        dataset = synthetic_classification_dataset(config)
    elif dataset_type == "csv":
        dataset = CsvClassificationDataset(
            config["path"],
            label_column=str(config.get("label_column", "label")),
            feature_columns=config.get("feature_columns"),
        )
    else:
        raise ValueError(f"Unsupported data.type: {dataset_type}")

    return DataLoader(dataset, batch_size=batch_size, shuffle=bool(config.get("shuffle", True)))
