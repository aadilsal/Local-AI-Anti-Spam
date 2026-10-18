"""Build the Kaggle/Colab generation notebook: ``python -m laas.synth.notebook``.

The notebook embeds spec.py and gen.py verbatim, so it can never drift from the repo.
``--check`` exits non-zero if the committed notebook is out of date (run by the tests).
Every cell prints a start line and a done/failed line, and the generation cell prints a status
line every 30 seconds, so a run that is working never looks stuck.
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
2. Leave `PILOT = True` for the first run (about 1,000 messages, roughly 20-40 minutes).
   Leave `USE_VLLM = False` unless you want to try the faster engine (it runs isolated, so it
   can't break anything; if it fails the run continues with transformers).
3. **Save Version, then Save & Run All (Commit)**, or run the cells one by one.
4. Every cell prints a ▶ line when it starts and a ✅ (or ❌) line when it ends. Step 4 prints a
   status line every 30 seconds while it generates.
5. When it finishes: tab **Output**, download `synthetic.zip`, and unzip it into
   `training/data/synthetic/` in the repo.

**On Colab:** Runtime, Change runtime type, **T4 GPU**, then Run all. The output is under
`/content/synthetic`; download `synthetic.zip` from the file browser.

Only invented messages are generated here. Never upload real beta-site messages to Kaggle or Colab.
"""

CONFIG = """print("▶ Step 1/5: settings")
import os

PILOT = True  # True: ~1,000 messages to check quality. False: the full run (~40,000 messages).
N_REQUESTS = 100 if PILOT else 4000  # per generator; each request asks for 5 messages

# False (default): use Kaggle's own PyTorch + transformers. Nothing big is installed, so nothing breaks.
# True: also try vLLM (much faster) in a separate virtual environment, falling back to transformers.
# Never pip-install vLLM into the main environment: it replaces Kaggle's PyTorch and breaks transformers.
USE_VLLM = False

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
print(f"   mode: {'PILOT' if PILOT else 'FULL RUN'} · {N_REQUESTS} requests x 5 messages per model")
print(f"   models: {', '.join(g['generator'] for g in GENERATORS)}")
print(f"   engine: {'vLLM (isolated) with transformers fallback' if USE_VLLM else 'transformers'}")
print(f"   output folder: {OUT_DIR}")
print("✅ Step 1 done")
"""

INSTALL = """print("▶ Step 2/5: checking the GPU environment")
import subprocess, sys

def run(cmd):
    return subprocess.run(cmd, capture_output=True, text=True)

def last_line(result):
    return (result.stderr.strip().splitlines() or result.stdout.strip().splitlines() or ["?"])[-1]

gpu = run(["nvidia-smi", "--query-gpu=name,memory.total", "--format=csv,noheader"])
print("   GPUs:", gpu.stdout.strip().replace("\\n", " | ") or "NONE FOUND: turn on a GPU accelerator")

# bitsandbytes (4-bit loading) is the only addition, and --no-deps stops it touching Kaggle's PyTorch.
if run([sys.executable, "-c", "import bitsandbytes"]).returncode != 0:
    print("   installing bitsandbytes (without touching PyTorch)...")
    run([sys.executable, "-m", "pip", "install", "-q", "--no-deps", "bitsandbytes"])

# Load the actual model classes now, so a broken environment fails here in seconds, not in step 4.
probe = run([sys.executable, "-c",
             "import torch, transformers, bitsandbytes, accelerate;"
             "from transformers import Qwen2ForCausalLM, Phi3ForCausalLM;"
             "print(f'PyTorch {torch.__version__} (CUDA {torch.version.cuda}), "
             "transformers {transformers.__version__}, bitsandbytes {bitsandbytes.__version__}, "
             "GPU available: {torch.cuda.is_available()}')"])
if probe.returncode != 0:
    print("❌ Step 2 failed: PyTorch/transformers can't load the models in this session.")
    print("   Error:", last_line(probe))
    print("   Fix: restart the session so Kaggle restores its packages (power icon at the top, or")
    print("   Run > Restart & clear cell outputs; on Colab, Runtime > Disconnect and delete runtime),")
    print("   then Run All again. If it still fails, send this error over.")
    raise SystemExit("environment check failed")
print("  ", probe.stdout.strip())

VLLM_PYTHON = None
if USE_VLLM:
    print("   USE_VLLM: installing vLLM into its own virtual environment (5-10 minutes)...")
    venv = "/tmp/vllm-env"
    run([sys.executable, "-m", "venv", venv])
    pip = run([f"{venv}/bin/python", "-m", "pip", "install", "-q", "vllm"])
    check = run([f"{venv}/bin/python", "-c", "import vllm; print(vllm.__version__)"])
    if check.returncode == 0:
        VLLM_PYTHON = f"{venv}/bin/python"
        print(f"   vLLM {check.stdout.strip()} ready (isolated; the main environment is untouched)")
    else:
        print("   ⚠ vLLM didn't install or import; using transformers. Reason:", last_line(check))
print("✅ Step 2 done")
"""

WRITTEN = """print("▶ Step 3/5: checking the generation code")
import importlib, spec, gen
importlib.reload(spec); importlib.reload(gen)
print(f"   {len(spec.INTENTS)} intents · {len(spec.STYLES)} writing styles · "
      f"{len(spec.BUSINESSES)} business types · {len(spec.LOCALES)} locales")
print("✅ Step 3 done")
"""

RUN = """print("▶ Step 4/5: generating messages (pilot: about 20-40 minutes with transformers).")
print("   A status line prints every 30 seconds. Re-run this cell if a session times out: it resumes.")
import subprocess, sys, time, torch

def command(g, python, backend):
    cmd = [python, "-u", "gen.py", "--generator", g["generator"], "--model", g["model"],
           "--backend", backend,
           "--n-requests", str(N_REQUESTS), "--out", f"{OUT_DIR}/{g['generator']}.jsonl"]
    if g["quantization"]:
        cmd += ["--quantization", g["quantization"]]
    if g["fallback_model"]:
        cmd += ["--fallback-model", g["fallback_model"]]
    if g["fallback_4bit"]:
        cmd.append("--fallback-4bit")
    return cmd

def messages_written(g):
    path = f"{OUT_DIR}/{g['generator']}.jsonl"
    if not os.path.exists(path):
        return 0
    with open(path, encoding="utf-8") as fh:
        return sum(1 for line in fh if line.strip() and '"failed": true' not in line)

def latest_status(g):
    try:
        with open(f"{OUT_DIR}/{g['generator']}.log", encoding="utf-8", errors="replace") as fh:
            lines = [l.strip() for l in fh if l.strip()]
    except FileNotFoundError:
        return "starting..."
    ours = [l for l in lines if any(k in l for k in ("STAGE", "PROGRESS", "Done", "failed", "Error"))]
    return (ours or lines or ["starting..."])[-1][:150]

def watch(running, started):
    while any(proc.poll() is None for _, proc in running):
        time.sleep(30)
        minutes, seconds = divmod(int(time.time() - started), 60)
        for g, proc in running:
            state = "running" if proc.poll() is None else f"finished (exit {proc.returncode})"
            print(f"   [{minutes:02d}:{seconds:02d}] {g['generator']:<11} {messages_written(g):>6,} msgs · "
                  f"{state} · {latest_status(g)}", flush=True)

def launch(g, gpu_index, python, backend):
    env = {**os.environ, "CUDA_VISIBLE_DEVICES": str(gpu_index), "PYTHONUNBUFFERED": "1"}
    log = open(f"{OUT_DIR}/{g['generator']}.log", "a")
    print(f"   started {g['generator']} on GPU {gpu_index} with {backend}", flush=True)
    proc = subprocess.Popen(command(g, python, backend), env=env, stdout=log, stderr=subprocess.STDOUT)
    return g, proc

def run_phase(models, python, backend):
    # Run models (in parallel when there are 2+ GPUs); return the ones that failed.
    failed = []
    if gpus >= 2:
        running = [launch(g, i, python, backend) for i, g in enumerate(models)]
        watch(running, started)
    else:
        running = []
        for g in models:
            one = [launch(g, 0, python, backend)]
            watch(one, started)
            running += one
    for g, proc in running:
        if proc.returncode != 0:
            print(f"   {g['generator']} stopped with exit code {proc.returncode} ({backend})")
            failed.append(g)
    return failed

gpus = torch.cuda.device_count()
print(f"   {gpus} GPU(s): running {'both models in parallel' if gpus >= 2 else 'the models one after the other'}")
started = time.time()
todo = list(GENERATORS)
if VLLM_PYTHON:
    todo = run_phase(todo, VLLM_PYTHON, "vllm")
    if todo:
        print("   switching those to transformers; finished requests are kept and skipped")
if todo:
    todo = run_phase(todo, sys.executable, "transformers")

print(f"   all models finished in {(time.time() - started) / 60:.0f} min")
ok = True
for g in GENERATORS:
    count = messages_written(g)
    if g not in todo and count > 0:
        print(f"   ✓ {g['generator']}: {count:,} messages · {latest_status(g)}")
    else:
        ok = False
        print(f"   ✗ {g['generator']}: {count:,} messages. Last log lines:")
        with open(f"{OUT_DIR}/{g['generator']}.log", encoding="utf-8", errors="replace") as fh:
            print("".join(fh.readlines()[-20:]))
print("✅ Step 4 done" if ok else "❌ Step 4 had a problem: copy the log lines above and send them over")
"""

SUMMARY = """print("▶ Step 5/5: checking the output and packaging it")
import collections, glob, json, random, shutil

rows = []
for path in glob.glob(f"{OUT_DIR}/*.jsonl"):
    rows += [json.loads(line) for line in open(path, encoding="utf-8") if line.strip()]
ok = [r for r in rows if not r.get("failed")]
failed = len(rows) - len(ok)
print(f"   {len(ok):,} messages, {failed:,} failed requests")
print("   by generator:", dict(collections.Counter(r["generator"] for r in ok)))
print("   by label:", dict(collections.Counter(r["label"] for r in ok)))
print("   by style:", dict(collections.Counter(r["style"] for r in ok)))
print("   by intent:", collections.Counter(r["intent"] for r in ok).most_common())

random.seed(0)
for label in ("ham", "spam"):
    pool = [r for r in ok if r["label"] == label]
    print(f"\\n===== random {label} samples (read these: do they look real?) =====")
    for r in random.sample(pool, k=min(6, len(pool))):
        tag = f"{r['generator']} | {r['intent']} | {r['style']} | {r['business']}"
        print(f"\\n[{tag}]\\n{r['text'][:600]}")

archive = shutil.make_archive(OUT_DIR, "zip", OUT_DIR)
size_mb = os.path.getsize(archive) / 1e6
print(f"\\n   packaged: {archive} ({size_mb:.1f} MB)")

# Download: automatic on Colab; a clickable link on Kaggle (Kaggle doesn't allow notebooks to start
# downloads, and a background "Save & Run All" run has no browser, so use the Output tab there).
try:
    from google.colab import files
    print("   starting the download in your browser (Colab)...")
    files.download(archive)
except ImportError:
    from IPython.display import FileLink, display
    print("   click to download (interactive Kaggle session), or use the Output tab:")
    display(FileLink(os.path.relpath(archive), result_html_prefix="   ⬇ "))
print("✅ Step 5 done: unzip synthetic.zip into training/data/synthetic/ in the repo")
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
            _code(WRITTEN),
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
