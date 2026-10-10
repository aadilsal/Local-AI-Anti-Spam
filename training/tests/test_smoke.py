import tomllib
from pathlib import Path

import laas


def test_package_version_matches_pyproject() -> None:
    pyproject = tomllib.loads((Path(__file__).parents[1] / "pyproject.toml").read_text("utf-8"))
    assert laas.__version__ == pyproject["project"]["version"]
