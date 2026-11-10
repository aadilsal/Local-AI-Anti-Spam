import json
from pathlib import Path

import numpy as np
import pytest

from laas.export import main
from laas.model_file import read_model, write_model


def _run(tmp_path: Path, production: bool = True, t_low: float = 0.4) -> Path:
    run = tmp_path / "runs" / "20261107-000000-production"
    rng = np.random.default_rng(0)
    write_model(
        run / "model.bin",
        weights=rng.normal(0, 0.5, 1 << 12),
        dense_weights=rng.normal(0, 0.5, 7),
        bias=-1.0,
        dense_means=[0.0] * 7,
        dense_stds=[1.0] * 7,
        platt_a=1.0,
        platt_b=0.0,
        t_low=t_low,
        t_high=0.9,
        model_version="local-test",
        metrics={"production": production},
    )
    (run / "report.md").write_text("# Local model run\n", encoding="utf-8")
    return run


def _args(tmp_path: Path, run: Path) -> list[str]:
    return [
        "--run",
        str(run),
        "--plugin",
        str(tmp_path / "plugin"),
        "--parity",
        str(tmp_path / "parity"),
        "--eval",
        str(tmp_path / "eval"),
    ]


def test_export_writes_model_fixtures_and_report(tmp_path: Path) -> None:
    run = _run(tmp_path)
    assert main(_args(tmp_path, run)) == 0
    shipped = tmp_path / "plugin" / "model" / "model.dat"
    assert shipped.read_bytes() == (run / "model.bin").read_bytes()

    fixtures = json.loads((tmp_path / "parity" / "release-fixtures.json").read_text("utf-8"))
    model = read_model(shipped)
    assert fixtures["model_version"] == "local-test"
    assert len(fixtures["cases"]) > 60
    case = fixtures["cases"][3]
    assert case["score"] == pytest.approx(model.score(case["text"], case["email"]))
    assert "Development build" in (tmp_path / "eval" / "report.md").read_text("utf-8")


def test_export_refuses_experiment_runs_unless_asked(tmp_path: Path) -> None:
    run = _run(tmp_path, production=False)
    with pytest.raises(SystemExit):
        main(_args(tmp_path, run))
    assert not (tmp_path / "plugin" / "model" / "model.dat").exists()
    assert main([*_args(tmp_path, run), "--allow-experiment"]) == 0


def test_export_refuses_thresholds_out_of_order(tmp_path: Path) -> None:
    with pytest.raises(SystemExit):
        main(_args(tmp_path, _run(tmp_path, t_low=0.95)))
