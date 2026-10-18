"""Run one generator model over the request grid (runs on a GPU: Kaggle or Colab).

    python gen.py --generator qwen2.5-7b --model Qwen/Qwen2.5-7B-Instruct-AWQ --quantization awq \
        --fallback-model Qwen/Qwen2.5-7B-Instruct --fallback-4bit --n-requests 200 --out out.jsonl

Rows are appended and flushed after every batch, and requests already in the output are skipped,
so a session that times out can simply be run again. vLLM is used when it works; otherwise plain
transformers (slower). Standard library only at import time, so the notebook can embed it.
"""

from __future__ import annotations

import argparse
import json
import sys
import time
from collections.abc import Iterator, Sequence
from pathlib import Path
from typing import Any, TextIO

try:
    from laas.synth.spec import (
        PROMPT_VERSION,
        Request,
        build_requests,
        clean_messages,
        parse_response,
    )
except ImportError:  # inside the notebook, spec.py sits next to this file
    from spec import (  # type: ignore[no-redef,import-not-found]
        PROMPT_VERSION,
        Request,
        build_requests,
        clean_messages,
        parse_response,
    )


def log(message: str) -> None:
    print(f"[{time.strftime('%H:%M:%S')}] {message}", flush=True)


def batches(items: Sequence[Request], size: int) -> Iterator[Sequence[Request]]:
    for start in range(0, len(items), size):
        yield items[start : start + size]


def done_ids(path: Path) -> set[str]:
    if not path.exists():
        return set()
    with path.open(encoding="utf-8") as fh:
        return {json.loads(line)["request_id"] for line in fh if line.strip()}


def write(fh: TextIO, req: Request, reply: str, args: argparse.Namespace, checkpoint: str) -> int:
    texts = clean_messages(parse_response(reply))
    for i, text in enumerate(texts):
        row = {
            **req.meta(),
            "generator": args.generator,
            "checkpoint": checkpoint,
            "prompt_version": PROMPT_VERSION,
            "index": i,
            "text": text,
        }
        fh.write(json.dumps(row, ensure_ascii=False) + "\n")
    if not texts:  # record the attempt so a resume doesn't retry a request that keeps failing
        fh.write(json.dumps({**req.meta(), "generator": args.generator, "failed": True}) + "\n")
    return len(texts)


def run_vllm(reqs: Sequence[Request], args: argparse.Namespace, fh: TextIO) -> int:
    log("STAGE importing vLLM")
    from vllm import LLM, SamplingParams

    log(f"STAGE loading {args.model} with vLLM (the first run downloads it: a few minutes)")
    started = time.time()
    llm = LLM(
        model=args.model,
        dtype="half",  # T4 GPUs have no bfloat16
        quantization=args.quantization or None,
        max_model_len=args.max_model_len,
        gpu_memory_utilization=0.90,
        seed=args.seed,
    )
    log(f"STAGE model loaded in {time.time() - started:.0f}s; generating")
    tokenizer = llm.get_tokenizer()
    params = SamplingParams(temperature=args.temperature, top_p=0.95, max_tokens=args.max_tokens)
    total = 0
    n_batches = -(-len(reqs) // args.batch)
    for i, chunk in enumerate(batches(reqs, args.batch)):
        batch_started = time.time()
        prompts = [
            tokenizer.apply_chat_template(r.chat(), tokenize=False, add_generation_prompt=True)
            for r in chunk
        ]
        outputs = llm.generate(prompts, params, use_tqdm=False)
        for req, out in zip(chunk, outputs, strict=True):
            total += write(fh, req, out.outputs[0].text, args, args.model)
        fh.flush()
        log(
            f"PROGRESS batch {i + 1}/{n_batches} ({len(chunk)} requests in "
            f"{time.time() - batch_started:.0f}s): {total:,} messages so far"
        )
    return total


def run_transformers(reqs: Sequence[Request], args: argparse.Namespace, fh: TextIO) -> int:
    import torch
    from transformers import AutoModelForCausalLM, AutoTokenizer

    checkpoint = args.fallback_model or args.model
    tokenizer = AutoTokenizer.from_pretrained(checkpoint)
    tokenizer.padding_side = "left"
    if tokenizer.pad_token is None:
        tokenizer.pad_token = tokenizer.eos_token
    kwargs: dict[str, Any] = {"torch_dtype": torch.float16, "device_map": "cuda"}
    if args.fallback_4bit:
        from transformers import BitsAndBytesConfig

        kwargs["quantization_config"] = BitsAndBytesConfig(
            load_in_4bit=True, bnb_4bit_compute_dtype=torch.float16
        )
    log(f"STAGE loading {checkpoint} with transformers (4-bit: {args.fallback_4bit})")
    started = time.time()
    model = AutoModelForCausalLM.from_pretrained(checkpoint, **kwargs)
    log(f"STAGE model loaded in {time.time() - started:.0f}s; generating")
    total = 0
    n_batches = -(-len(reqs) // args.hf_batch)
    for i, chunk in enumerate(batches(reqs, args.hf_batch)):
        batch_started = time.time()
        prompts = [
            tokenizer.apply_chat_template(r.chat(), tokenize=False, add_generation_prompt=True)
            for r in chunk
        ]
        enc = tokenizer(prompts, return_tensors="pt", padding=True).to("cuda")
        with torch.no_grad():
            generated = model.generate(
                **enc,
                do_sample=True,
                temperature=args.temperature,
                top_p=0.95,
                max_new_tokens=args.max_tokens,
                pad_token_id=tokenizer.pad_token_id,
            )
        replies = tokenizer.batch_decode(
            generated[:, enc["input_ids"].shape[1] :], skip_special_tokens=True
        )
        for req, reply in zip(chunk, replies, strict=True):
            total += write(fh, req, reply, args, checkpoint)
        fh.flush()
        log(
            f"PROGRESS batch {i + 1}/{n_batches} ({len(chunk)} requests in "
            f"{time.time() - batch_started:.0f}s): {total:,} messages so far"
        )
    return total


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--generator", required=True, help="Short name, e.g. qwen2.5-7b")
    parser.add_argument("--model", required=True, help="Checkpoint for vLLM")
    parser.add_argument("--quantization", default="", help="e.g. awq (vLLM only)")
    parser.add_argument("--fallback-model", default="", help="Checkpoint for transformers")
    parser.add_argument("--fallback-4bit", action="store_true", help="Load fallback in 4-bit")
    parser.add_argument("--backend", choices=["auto", "vllm", "transformers"], default="auto")
    parser.add_argument("--n-requests", type=int, default=200)
    parser.add_argument("--seed", type=int, default=20261018)
    parser.add_argument("--out", type=Path, required=True)
    # Small enough that progress shows every minute or two; vLLM still batches efficiently.
    parser.add_argument("--batch", type=int, default=32)
    parser.add_argument("--hf-batch", type=int, default=16)
    parser.add_argument("--max-tokens", type=int, default=1600)
    parser.add_argument("--max-model-len", type=int, default=4096)
    parser.add_argument("--temperature", type=float, default=0.95)
    args = parser.parse_args(argv)

    reqs = build_requests(args.n_requests, args.seed, args.generator)
    done = done_ids(args.out)
    todo = [r for r in reqs if r.request_id not in done]
    log(
        f"{args.generator}: {len(reqs):,} requests, {len(done):,} already done, {len(todo):,} to go"
    )
    if not todo:
        return 0

    args.out.parent.mkdir(parents=True, exist_ok=True)
    with args.out.open("a", encoding="utf-8") as fh:
        if args.backend in ("auto", "vllm"):
            try:
                total = run_vllm(todo, args, fh)
                log(f"Done with vLLM: {total:,} messages")
                return 0
            except Exception as exc:  # any vLLM failure (install, CUDA, OOM) falls back
                if args.backend == "vllm":
                    raise
                log(f"vLLM failed ({type(exc).__name__}: {exc}); falling back to transformers")
                fh.flush()
                todo = [r for r in todo if r.request_id not in done_ids(args.out)]
        total = run_transformers(todo, args, fh)
        log(f"Done with transformers: {total:,} messages")
    return 0


if __name__ == "__main__":
    sys.exit(main())
