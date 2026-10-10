# Documentation

Project documents for **Local AI Anti-Spam** (working name). This is a WordPress plugin that classifies form submissions and comments as spam with a machine-learning model running on the site's own server.

## Start here

| If you want to… | Read |
|---|---|
| Understand what we are building and why | [PRD.md](PRD.md) |
| Get the system in five minutes | [architecture-essentials.md](architecture-essentials.md) |
| Build or review a component | [architecture.md](architecture.md) |
| Design or review a screen, email or piece of copy | [design.md](design.md) |
| Ship anything that touches user data, wp-admin or the cloud | [security-analysis.md](security-analysis.md) |
| Write code in this repo (humans and AI agents) | [agents.md](agents.md) |

## The documents

| Document | Purpose | Owner | Update when |
|---|---|---|---|
| [PRD.md](PRD.md) | Requirements (F, PRO, M, D, T, C, L IDs), metrics, pricing, milestones, risks. Appendix A lists review notes R1–R10. | Product owner | Scope, pricing or milestones change |
| [architecture.md](architecture.md) | Components, request path, `model.bin` format, PHP inference, integrations, data model, cloud API, training pipeline, decisions log (A1–A8) | Tech lead | Any structural or interface change |
| [architecture-essentials.md](architecture-essentials.md) | One-page summary: the shape, seven invariants, five key decisions | Tech lead | architecture.md changes something on this page |
| [design.md](design.md) | UX principles, information architecture, every screen with wireframes, email, copy and voice, accessibility, listing assets | Product owner | A screen or flow changes |
| [security-analysis.md](security-analysis.md) | Assets, adversaries, trust boundaries, threats S1–S29 with controls, privacy traceability, release checklist, incident response | Tech lead | A new data flow, endpoint or render path |
| [agents.md](agents.md) | Repo map, commands, 15 hard rules, conventions, test expectations, playbooks | Tech lead | Commands or conventions change |

## How the IDs connect

- **PRD** defines requirements: `F1–F20` free, `PRO-1–PRO-10` Pro, `D1–D7` data, `M1–M9` models, `T1–T8` technical, `C1–C7` privacy, `L1–L6` listing. Review notes are `R1–R10`.
- **architecture.md** cites those IDs and records decisions `A1–A8`.
- **security-analysis.md** lists threats `S1–S29` and traces `C1–C7` to controls.
- **agents.md** turns the most important of these into hard rules for day-to-day coding.

When you change a requirement, search the other documents for its ID.

## Status

| Milestone (PRD §14) | State |
|---|---|
| Week 0: setup (repo, CI, tooling) | **In progress** |
| Weeks 1–2: data foundation | Not started |
| Weeks 3–4: models v1 | Not started |
| Weeks 3–6: free plugin built and submitted | Not started |
| ~Week 9: launch + Pro | Not started |

Decisions still open are listed at the end of [PRD.md](PRD.md#15-risks-and-open-questions) and [design.md](design.md#7-open-design-questions).
