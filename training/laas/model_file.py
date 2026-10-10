"""The ``model.bin`` format (architecture.md §4.2) and reference scoring.

Layout, little-endian:
    b"LAAS" | uint16 format version | uint32 header length H | H bytes of JSON header
    | n_buckets x float32 hashed weights | len(dense_names) x float32 dense weights

PHP reads this with unpack() at offsets (plugin/src/Inference/ModelFile.php). Python scores with the
float32-rounded weights it wrote, so both sides compute the same number.
"""

from __future__ import annotations

import hashlib
import json
import math
import struct
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import numpy as np
import numpy.typing as npt

from laas.featurize import DENSE_NAMES, FEATURIZER_VERSION, Features, featurize

MAGIC = b"LAAS"
FORMAT_VERSION = 1
PREFIX = struct.Struct("<4sHI")


@dataclass
class LocalModel:
    header: dict[str, Any]
    weights: npt.NDArray[np.float32]
    dense_weights: npt.NDArray[np.float32]

    @property
    def n_buckets(self) -> int:
        return int(self.header["n_buckets"])

    def logit(self, features: Features) -> float:
        z = float(self.header["bias"])
        for bucket, value in features.hashed.items():
            z += float(self.weights[bucket]) * value
        means, stds = self.header["dense_means"], self.header["dense_stds"]
        for i, x in enumerate(features.dense):
            z += float(self.dense_weights[i]) * (x - means[i]) / stds[i]
        return z

    def score(self, text: str, email: str | None = None) -> float:
        z = self.logit(featurize(text, email, self.n_buckets))
        return 1.0 / (1.0 + math.exp(-(self.header["platt_a"] * z + self.header["platt_b"])))


def write_model(
    path: Path,
    *,
    weights: npt.ArrayLike,
    dense_weights: npt.ArrayLike,
    bias: float,
    dense_means: list[float],
    dense_stds: list[float],
    platt_a: float,
    platt_b: float,
    t_low: float,
    t_high: float,
    model_version: str,
    metrics: dict[str, Any] | None = None,
    trained_at: str = "",
) -> LocalModel:
    w = np.asarray(weights, dtype="<f4")
    dw = np.asarray(dense_weights, dtype="<f4")
    n_buckets = int(w.shape[0])
    if n_buckets & (n_buckets - 1) or dw.shape[0] != len(DENSE_NAMES):
        raise ValueError(
            "n_buckets must be a power of two and dense weights must match DENSE_NAMES"
        )

    body = w.tobytes() + dw.tobytes()
    header = {
        "model_version": model_version,
        "featurizer_version": FEATURIZER_VERSION,
        "n_buckets": n_buckets,
        "dense_names": list(DENSE_NAMES),
        "dense_means": [float(m) for m in dense_means],
        "dense_stds": [float(s) if s > 1e-12 else 1.0 for s in dense_stds],
        "bias": float(bias),
        "platt_a": float(platt_a),
        "platt_b": float(platt_b),
        "t_low": float(t_low),
        "t_high": float(t_high),
        "sha256_of_weights": hashlib.sha256(body).hexdigest(),
        "trained_at": trained_at,
        "metrics": metrics or {},
    }
    header_bytes = json.dumps(header, sort_keys=True, separators=(",", ":")).encode("utf-8")
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(PREFIX.pack(MAGIC, FORMAT_VERSION, len(header_bytes)) + header_bytes + body)
    return read_model(path)


def read_model(path: Path) -> LocalModel:
    blob = path.read_bytes()
    magic, version, header_len = PREFIX.unpack_from(blob, 0)
    if magic != MAGIC or version != FORMAT_VERSION:
        raise ValueError(f"{path} is not a LAAS model file (format {FORMAT_VERSION})")
    start = PREFIX.size
    header = json.loads(blob[start : start + header_len])
    body = blob[start + header_len :]
    if hashlib.sha256(body).hexdigest() != header["sha256_of_weights"]:
        raise ValueError(f"{path}: weights checksum mismatch")
    n = int(header["n_buckets"])
    weights = np.frombuffer(body, dtype="<f4", count=n)
    dense = np.frombuffer(body, dtype="<f4", count=len(header["dense_names"]), offset=4 * n)
    return LocalModel(header=header, weights=weights, dense_weights=dense)
