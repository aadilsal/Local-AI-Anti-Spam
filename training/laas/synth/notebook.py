"""Build the Kaggle/Colab generation notebook: ``python -m laas.synth.notebook``.

The notebook embeds spec.py and gen.py verbatim, so it can never drift from the repo.
``--check`` exits non-zero if the committed notebook is out of date (run by the tests).
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Any

SYNTH_DIR = Path(__file__).resolve().parent
NOTEBOOK = SYNTH_DIR.parents[1] / "notebooks" / "generate_synthetic.ipynb"

INTRO = """# Synthetic contact-form messages for Local AI Anti-Spam

Generates realistic genuine messages **and** spam for training the spam filter (PRD D6, review
notes R13/R14). Built from `training/laas/synth/` by `python -m laas.synth.notebook`; don't edit
this notebook by hand.

**Run it on Kaggle (recommended)**
1. *Settings* (right panel): **Accelerator: GPU T4 x2**, **Internet: On** (needs a phone-verified
   account).
2. Leave `PILOT = True` for the first run (about 1,000 messages, roughly 20-30 minutes).
3. **Save Version, then Save & Run All (Commit)**. It runs in the background; you can close the tab.
4. When it finishes: open the version, tab **Output**, download `synthetic.zip`, and unzip it into
   `training/data/synthetic/` in the repo.

**On Colab:** Runtime, Change runtime type, **T4 GPU**, then Run all. The output is under
`/content/synthetic`; download `synthetic.zip` from the file browser.

Only invented messages are generated here. Never upload real beta-site messages to Kaggle or Colab.
"""

CONFIG = """import os

PILOT = True  # True: ~1,000 messages to check quality. False: the full run (~40,000 messages).
N_REQUESTS = 100 if PILOT else 4000  # per generator; each request asks for 5 messages

GENERATORS = [
    # Apache-2.0 (checked 18 Oct 2026). AWQ fits one T4; the 4-bit fallback is for transformers.
    dict(generator="qwen2.5-7b", model="Qwen/Qwen2.5-7B-Instruct-AWQ", quantization="awq",
         fallback_model="Qwen/Qwen2.5-7B-Instruct", fallback_4bit=True),
    # MIT. A different model family, so the data isn't one model's writing style.
    dict(generator="phi-4-mini", model="microsoft/Phi-4-mini-instruct", quantization="",
         fallback_model="microsoft/Phi-4-mini-instruct", fallback_4bit=False),
]

OUT_DIR = "/kaggle/working/synthetic" if os.path.isdir("/kaggle/working") else "/content/synthetic"
os.makedirs(OUT_DIR, exist_ok=True)
print("Output folder:", OUT_DIR)
"""

INSTALL = """!nvidia-smi --query-gpu=name,memory.total --format=csv
# vLLM is much faster; if its install or start-up fails, gen.py falls back to transformers.
%pip install -q vllm bitsandbytes
"""

RUN = """import subprocess, sys, time, torch

def command(g):
    cmd = [sys.executable, "gen.py", "--generator", g["generator"], "--model", g["model"],
           "--n-requests", str(N_REQUESTS), "--out", f"{OUT_DIR}/{g['generator']}.jsonl"]
    if g["quantization"]:
        cmd += ["--quantization", g["quantization"]]
    if g["fallback_model"]:
        cmd += ["--fallback-model", g["fallback_model"]]
    if g["fallback_4bit"]:
        cmd.append("--fallback-4bit")
    return cmd

gpus = torch.cuda.device_count()
print(f"{gpus} GPU(s): running {'in parallel' if gpus >= 2 else 'one after the other'}")
started = time.time()
jobs = []
for i, g in enumerate(GENERATORS):
    env = {**os.environ, "CUDA_VISIBLE_DEVICES": str(i % max(gpus, 1))}
    log = open(f"{OUT_DIR}/{g['generator']}.log", "a")
    proc = subprocess.Popen(command(g), env=env, stdout=log, stderr=subprocess.STDOUT)
    if gpus >= 2:
        jobs.append((g, proc))
    else:
        proc.wait()
        print(g["generator"], "exit code", proc.returncode)
for g, proc in jobs:
    proc.wait()
    print(g["generator"], "exit code", proc.returncode)
print(f"Finished in {(time.time() - started) / 60:.0f} min")
for g in GENERATORS:
    print(f"--- last lines of {g['generator']}.log ---")
    print("".join(open(f"{OUT_DIR}/{g['generator']}.log").readlines()[-8:]))
"""

SUMMARY = """import collections, glob, json, random, shutil

rows = []
for path in glob.glob(f"{OUT_DIR}/*.jsonl"):
    rows += [json.loads(line) for line in open(path, encoding="utf-8") if line.strip()]
ok = [r for r in rows if not r.get("failed")]
failed = len(rows) - len(ok)
print(f"{len(ok):,} messages, {failed:,} failed requests")
print("By generator:", collections.Counter(r["generator"] for r in ok))
print("By label:", collections.Counter(r["label"] for r in ok))
print("By style:", collections.Counter(r["style"] for r in ok))
print("By intent:", collections.Counter(r["intent"] for r in ok).most_common())

random.seed(0)
for label in ("ham", "spam"):
    pool = [r for r in ok if r["label"] == label]
    print(f"\\n===== random {label} samples =====")
    for r in random.sample(pool, k=min(6, len(pool))):
        tag = f"{r['generator']} | {r['intent']} | {r['style']} | {r['business']}"
        print(f"\\n[{tag}]\\n{r['text'][:600]}")

shutil.make_archive(OUT_DIR, "zip", OUT_DIR)
print(f"\\nDownload {OUT_DIR}.zip and unzip it into training/data/synthetic/")
"""


def _code(text: str) -> dict[str, Any]:
    return {
        "cell_type": "code",
        "execution_count": None,
        "metadata": {},
        "outputs": [],
        "source": text.splitlines(keepends=True),
    }


def _markdown(text: str) -> dict[str, Any]:
    return {"cell_type": "markdown", "metadata": {}, "source": text.splitlines(keepends=True)}


def build_notebook() -> dict[str, Any]:
    spec = (SYNTH_DIR / "spec.py").read_text(encoding="utf-8")
    gen = (SYNTH_DIR / "gen.py").read_text(encoding="utf-8")
    return {
        "cells": [
            _markdown(INTRO),
            _markdown("## 1. Settings"),
            _code(CONFIG),
            _markdown("## 2. GPU check and install"),
            _code(INSTALL),
            _markdown("## 3. Generation code (copied from `training/laas/synth/`)"),
            _code("%%writefile spec.py\n" + spec),
            _code("%%writefile gen.py\n" + gen),
            _markdown("## 4. Generate (resumable: re-run this cell if a session times out)"),
            _code(RUN),
            _markdown("## 5. Check the output and package it"),
            _code(SUMMARY),
        ],
        "metadata": {
            "kernelspec": {"display_name": "Python 3", "language": "python", "name": "python3"},
            "language_info": {"name": "python"},
            "accelerator": "GPU",
        },
        "nbformat": 4,
        "nbformat_minor": 5,
    }


def render() -> str:
    return json.dumps(build_notebook(), indent=1, ensure_ascii=False) + "\n"


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true", help="Fail if the notebook is stale")
    args = parser.parse_args(argv)
    text = render()
    if args.check:
        current = NOTEBOOK.read_text(encoding="utf-8") if NOTEBOOK.exists() else ""
        if current != text:
            print(f"{NOTEBOOK} is out of date: run python -m laas.synth.notebook", file=sys.stderr)
            return 1
        return 0
    NOTEBOOK.parent.mkdir(parents=True, exist_ok=True)
    NOTEBOOK.write_text(text, encoding="utf-8", newline="\n")
    print(f"Wrote {NOTEBOOK}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
