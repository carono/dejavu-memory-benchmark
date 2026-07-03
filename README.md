# dejavu-memory-benchmark

> A behavioural benchmark for **push-memory** agents — the [dejavu](https://github.com/carono/dejavu-spec) pattern.

Existing memory benchmarks (LongMemEval, LoCoMo) grade **pull-RAG** over long chat
transcripts: does the model retrieve the right passage when it decides to search? dejavu is a
different mechanism — the *environment* injects a fact when a cue fires, without the agent
asking. This benchmark grades **that**: the push read-path (cue → activation → gate →
delivery), across the ten situations where push either shines or quietly fails.

*English is the canonical language. A Russian summary follows — see [Русская версия](#русская-версия).*

---

## What it measures

Each case is a tiny, self-contained session: it ships its own **seed facts** (statement +
projected cues + graph links) and a list of **turns** (a prompt + what the memory channel is
expected to do). The runner feeds every turn to an engine and checks the delivered set against
the assertions. No live store required — a case carries everything it needs.

The ten canonical situations (from the design task #437):

| # | Situation | What it proves |
|---|-----------|----------------|
| 01 | **L1 keyword recall** | An exact/substring cue hits the right fact; unrelated facts stay silent. |
| 02 | **L1c semantic recall** | No literal overlap (“what is my name?” → “name is Ivan”); recovered via a meta/synonym cue. |
| 03 | **L1b spreading activation** | The prompt hits fact A; the needed fact B is one graph hop away. |
| 04 | **STM vs LTM** | A fresh session correction overrides — and evicts — the stale long-term value. |
| 05 | **Domain conflict** | One ambiguous signal, two domains; the active domain wins, the other stays out. |
| 06 | **Staleness** | A fact marked `stale`/`archived` never surfaces, even at high salience. |
| 07 | **Habituation** | A fact already surfaced this session is suppressed next turn. |
| 08 | **Multi-project context** | Project A’s rule does not leak into project B. |
| 09 | **Negative / fail-open** | Nothing matches ⇒ nothing is injected (precision over recall). |
| 10 | **Personal facts + meta-cues** | Name / age / profession recalled by data-type cues, value absent from the prompt. |

Why these and not a chat benchmark: they isolate the mechanics unique to push — the symbolic
cue-index, STM/LTM interplay, cue disambiguation, domain and project isolation, habituation,
and the discipline of staying silent. A false positive here costs every turn, so the bar is
**precision over recall**.

## Layout

```
cases/     one JSON file per situation — the test set (see cases/README.md for the schema)
runner/    dependency-free PHP runner + a built-in reference engine (see runner/README.md)
results/   result-document schema + an example reference run (see results/README.md)
```

## Quick start

Requires PHP 7.4+ with `mbstring`. No Composer, no database.

```bash
php runner/run.php                 # run all cases on the built-in reference engine
php runner/run.php -v              # verbose: show every turn's pushed set
php runner/run.php --situation=habituation
```

Expected on a clean checkout:

```
11/11 cases passed · score 1.0000
```

The **reference engine** (`runner/lib/ReferenceEngine.php`) is a readable model of layers
L0–L3 (cue match + one-hop spreading + salience-gate with budget, habituation, staleness,
domain/project isolation, STM eviction). It doubles as an executable spec of the expected
behaviour and as the floor any real engine must clear.

## Grading your own engine

Grade the live `dejavu-push.php` hook (or any push engine) by wiring a thin shim and running
with `--engine=push`:

```bash
DEJAVU_PUSH_CMD=/path/to/dejavu-shim.php \
  php runner/run.php --engine=push --submitter=me --engine-version=1.0.0 --out=results/my-run.json
```

The shim speaks a one-object-in / one-object-out JSON protocol per turn (`{prompt, context,
seed, reset}` → `{pushed:[slugs]}`); it owns all store-specific wiring. Full contract in
`runner/lib/DejavuPushEngine.php` and `runner/README.md`.

## Result format & leaderboard

`--out=FILE` writes a result document: `benchmark`/`engine`/`submitter` metadata, a `summary`
(`total`, `passed`, `failed`, `score = passed/total`, `by_situation`) and a full per-turn
`cases[]` trace. `summary.score` is the ranked number; `by_situation` powers the per-capability
columns; the trace lets the leaderboard reproduce a failure. Schema and an example live in
[`results/`](results/README.md). Scores are comparable only within the same `benchmark_version`.

## Related

- [`dejavu-spec`](https://github.com/carono/dejavu-spec) — the implementation-independent spec (L0–L5, `cueMemory`/`factStore`, the three flows).
- `dejavu-memory-leaderboard` — the public results table that ingests these result documents.

## License

MIT.

---

<a id="русская-версия"></a>
## Русская версия

**dejavu-memory-benchmark** — поведенческий бенчмарк для агентов с **push-памятью** (паттерн
[dejavu](https://github.com/carono/dejavu-spec)). LongMemEval и LoCoMo проверяют **pull-RAG**
по длинным диалогам — догадается ли модель сама поискать. dejavu работает иначе: **среда сама
инжектирует** факт, когда сработала зацепка. Этот бенчмарк проверяет именно read-путь push:
зацепка → активация → salience-gate → доставка.

**Что внутри.** Каждый кейс — крошечная самодостаточная сессия: несёт свои **seed-факты**
(утверждение + спроецированные зацепки + связи графа) и список **ходов** (промпт + что канал
памяти обязан сделать). Раннер прогоняет ходы через движок и сверяет выданный набор с
критериями. Живое хранилище не нужно.

**Десять ситуаций** (из задачи #437): 01 keyword recall (L1) · 02 семантический recall (L1c,
«как меня зовут?») · 03 spreading activation (L1b, факт через граф) · 04 STM vs LTM (свежий
факт вытесняет устаревший) · 05 конфликт доменов (побеждает активный) · 06 устаревание (stale
не всплывает) · 07 habituation (факт не повторяется каждый ход) · 08 мульти-проектный контекст
(правила проекта A не текут в B) · 09 негативный тест (нет факта — ничего не инжектируется,
fail-open) · 10 личные факты + мета-зацепки (имя/возраст/профессия без значения в промпте).
Здесь **precision важнее recall**: ложное срабатывание бьёт по каждому ходу.

**Запуск** (нужен PHP 7.4+ с `mbstring`, без Composer и БД):

```bash
php runner/run.php          # все кейсы на встроенном референс-движке
php runner/run.php -v       # с выводом каждого хода
```

На чистом чекауте — `11/11 cases passed · score 1.0000`. Референс-движок
(`runner/lib/ReferenceEngine.php`) — читаемая модель слоёв L0–L3 и одновременно нижняя планка,
которую обязана взять любая реальная реализация.

**Реальный хук.** Прогнать живой `dejavu-push.php` — через тонкий shim и `--engine=push`
(`DEJAVU_PUSH_CMD`, JSON-протокол по ходу). Контракт — в `runner/lib/DejavuPushEngine.php`.

**Формат результата** (`--out=FILE`) — документ с метаданными, `summary` (`score = passed/total`,
`by_situation`) и полным трейсом `cases[]`; его и отправляют на leaderboard
(`dejavu-memory-leaderboard`). Подробности — в [`results/`](results/README.md) и
[`cases/`](cases/README.md).
