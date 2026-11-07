"""Put a trained local model into the plugin: ``python -m laas.export --run runs/<id>``.

Writes, together (architecture.md §13, agents.md "Ship a new local model"):
- ``plugin/model/model.bin``: the run's model file, after checks;
- ``parity/release-fixtures.json``: Python's score on the shipped model for every parity input, so
  PHPUnit proves the plugin scores that exact file the same way (``ReleaseModelTest``);
- ``training/eval/report.md``: the run's report, committed with the model.

Exporting is not releasing. The PRD gates need the real hand-labelled test set; a model exported
without it is for development only, and the report says so.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import shutil
from pathlib import Path
from typing import Any

from laas.featurize import FEATURIZER_VERSION, featurize
from laas.model_file import read_model
from laas.parity import HANDWRITTEN, generated_cases

REPO_ROOT = Path(__file__).resolve().parents[2]
MAX_MODEL_BYTES = 3 * 1024 * 1024  # PRD M3
RELEASE_FIXTURES = "release-fixtures.json"


class ExportError(ValueError):
    """The run's model must not go into the plugin."""


def check_model(path: Path, allow_experiment: bool) -> dict[str, Any]:
    if not path.is_file():
        raise ExportError(f"{path} not found; is this a local model run folder?")
    if path.stat().st_size > MAX_MODEL_BYTES:
        raise ExportError(f"{path} is {path.stat().st_size:,} bytes; the limit is 3 MB (M3)")
    model = read_model(path)  # checks magic, format version and weights checksum
    header = model.header
    if header["featurizer_version"] != FEATURIZER_VERSION:
        raise ExportError(
            f"model uses featurizer {header['featurizer_version']}, code is {FEATURIZER_VERSION}"
        )
    if not 0.0 < header["t_low"] < header["t_high"] < 1.0:
        raise ExportError(f"thresholds out of order: {header['t_low']}, {header['t_high']}")
    if not header.get("metrics", {}).get("production") and not allow_experiment:
        raise ExportError("not a production-mode run (pass --allow-experiment to export anyway)")
    return header


def release_fixtures(path: Path) -> dict[str, Any]:
    model = read_model(path)
    cases = []
    for text, email in HANDWRITTEN + generated_cases(20261107, 60):
        f = featurize(text, email, model.n_buckets)
        cases.append(
            {
                "text": text,
                "email": email,
                "logit": model.logit(f),
                "score": model.score(text, email),
            }
        )
    return {
        "featurizer_version": FEATURIZER_VERSION,
        "model_version": model.header["model_version"],
        "model_sha256": hashlib.sha256(path.read_bytes()).hexdigest(),
        "t_low": model.header["t_low"],
        "t_high": model.header["t_high"],
        "cases": cases,
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--run", type=Path, required=True, help="a runs/<id>-production folder")
    parser.add_argument("--plugin", type=Path, default=REPO_ROOT / "plugin")
    parser.add_argument("--parity", type=Path, default=REPO_ROOT / "parity")
    parser.add_argument("--eval", type=Path, default=REPO_ROOT / "training" / "eval")
    parser.add_argument("--allow-experiment", action="store_true")
    args = parser.parse_args(argv)

    source = args.run / "model.bin"
    try:
        header = check_model(source, args.allow_experiment)
    except (ExportError, ValueError) as error:
        parser.error(str(error))

    target = args.plugin / "model" / "model.bin"
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(source, target)
    fixtures = release_fixtures(target)
    args.parity.mkdir(parents=True, exist_ok=True)
    (args.parity / RELEASE_FIXTURES).write_text(
        json.dumps(fixtures, ensure_ascii=False, separators=(",", ":")) + "\n", encoding="utf-8"
    )
    args.eval.mkdir(parents=True, exist_ok=True)
    report = (args.run / "report.md").read_text(encoding="utf-8")
    note = (
        f"> Exported from `{args.run.name}` by `laas.export`. **Development build:** the PRD gates"
        " need the real hand-labelled test set, which no metric below uses.\n\n"
    )
    (args.eval / "report.md").write_text(note + report, encoding="utf-8")

    print(f"Model {header['model_version']} -> {target} ({target.stat().st_size:,} bytes)")
    print(f"t_low {header['t_low']:.4f}, t_high {header['t_high']:.4f}")
    print(f"{len(fixtures['cases'])} release fixtures -> {args.parity / RELEASE_FIXTURES}")
    print(f"Report -> {args.eval / 'report.md'}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
