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

The ten canonical push situations (from the design task #437):

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

### General-memory cases (situations 11–14)

> **Scope disclaimer.** Situations 01–10 above measure the **push paradigm** specifically —
> they were designed around dejavu's push read-path and are what the
> [third-party scoreboard](results/THIRD-PARTY.md) compares. Situations 11–14 are a broader,
> **mechanism-neutral** layer: they test **general memory capability** that *any* good memory
> system — push or pull, RAG, vector, or temporal graph — should get right. They are phrased
> as recall/precision goals, not as push internals, so they are fair to grade a non-push
> engine on. Added so the benchmark reflects memory in general, not only the push mechanism.

| # | Situation | What it proves |
|---|-----------|----------------|
| 11 | **Long-session recall** | A fact stated early is still recalled 30+ turns later; look-alike distractors (a teammate's city, a legacy language) stay silent. |
| 12 | **Cross-session recall** | A fact from session 1 answers in session 2; habituation resets per session; a later-session update wins; multi-session history aggregates on one query. |
| 13 | **Scattered facts** | Facts dropped across separate replies are connected by one “what do you know about me?” query; a later correction overrides an earlier scattered value. |
| 14 | **Temporal relevance** | A value that changed over time returns its current reading; the outdated one is retired by the temporal relation, with no `stale` flag. |

These use two extra protocol features (both backward compatible): **multi-turn** — many turns
in one case, so an early fact is queried far downstream — and **multi-session** — a `session`
id on turns that, when it changes, crosses a session boundary (resets per-session habituation,
keeps long-term facts). See [`cases/README.md`](cases/README.md#sessions-multi-turn--multi-session).

### STM/LTM cases (situations 15–16) — a second axis

> **Different axis.** Situations 01–14 grade the **push path** (what the channel delivers on a
> turn). Situations 15–16 grade the **memory state** instead: after reading a whole
> conversation and consolidating it (an LLM "sleep"), what is actually stored? Each case ships
> a raw `dialog` (role/content) and `criteria` on the memory **after extraction/consolidation**
> — never on the model's reply. Mechanism-neutral and, by the honesty rule, **not tuned to any
> engine**: a case a simple engine can't satisfy stays as written.

| # | Situation | What it proves |
|---|-----------|----------------|
| 15 | **STM extraction** | Over a 12–23-turn session: facts scattered far apart, an in-session correction, an explicit "remember this rule" directive, and brainstorm distractors all resolve to the right short-term memory by session end. |
| 16 | **LTM sleep** | Across sessions with a consolidation between them: a fact survives the sleep and recalls later; an update supersedes the old value; a cross-session contradiction lets the newer win; a dependency chain and a derived fact are stored as *structure* (links / derived_from). |

Dialog cases run only against an engine that implements `ConsolidatingEngine`, or with a
`--snapshots=FILE` map of `caseId → memoryItem[]` that any external memory can produce. Without
either, they are **skipped** — reported N/A, never a failure, and excluded from the score. The
built-in reference engine is a symbolic push model, not a text extractor, so it skips them.
See [`runner/README.md`](runner/README.md#grading-stmltm-dialog-cases).

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
21/21 gradable cases passed · score 1.0000 · 9 skipped
```

The 9 skipped cases are the STM/LTM dialog cases (situations 15–16): the reference engine does
not consolidate free text, so they need a `ConsolidatingEngine` or a `--snapshots` file (see
[grading STM/LTM](runner/README.md#grading-stmltm-dialog-cases)). Grade only one axis or one
situation:

```bash
php runner/run.php --situation=long_session_recall     # one general-memory situation
php runner/run.php --situation=stm_extraction,ltm_sleep --snapshots=my-memory.json
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

**Десять push-ситуаций** (из задачи #437): 01 keyword recall (L1) · 02 семантический recall
(L1c, «как меня зовут?») · 03 spreading activation (L1b, факт через граф) · 04 STM vs LTM
(свежий факт вытесняет устаревший) · 05 конфликт доменов (побеждает активный) · 06 устаревание
(stale не всплывает) · 07 habituation (факт не повторяется каждый ход) · 08 мульти-проектный
контекст (правила проекта A не текут в B) · 09 негативный тест (нет факта — ничего не
инжектируется, fail-open) · 10 личные факты + мета-зацепки (имя/возраст/профессия без значения
в промпте). Здесь **precision важнее recall**: ложное срабатывание бьёт по каждому ходу.

**Дисклеймер про охват.** Ситуации 01–10 меряют именно **push-парадигму** — по ним же считается
[сравнение сторонних библиотек](results/THIRD-PARTY.md). Ситуации **11–14** — более широкий,
**механизмо-нейтральный** слой: они проверяют **общие возможности памяти**, которые обязана
брать *любая* хорошая система (push или pull, RAG, вектор, темпоральный граф), и сформулированы
как цели recall/precision, а не как внутренности push. Добавлены задачей #482, чтобы бенчмарк
отражал память в целом, а не только push-механику: 11 длинные сессии (факт вспоминается через
30+ ходов, дистракторы молчат) · 12 кросс-сессионный recall (факт из сессии 1 отвечает в сессии
2; habituation сбрасывается по сессиям; обновление в поздней сессии побеждает; история
агрегируется) · 13 разрозненные факты (данные из разных реплик связываются одним запросом «что
ты обо мне знаешь?»; поздняя правка перекрывает старое) · 14 временна́я актуальность
(изменившийся во времени факт отдаёт текущее значение; устаревшее снимается темпоральной
связью, без флага `stale`). Эти кейсы используют два обратносовместимых расширения протокола:
**multi-turn** (много ходов в кейсе) и **multi-session** (поле `session` на ходах — смена id
пересекает границу сессии и сбрасывает per-session habituation, сохраняя долговременные факты).

**Вторая ось — состояние памяти (ситуации 15–16, задача #487).** 01–14 меряют **push-путь** (что
канал выдал за ход). 15–16 меряют **состояние памяти**: после прочтения всего диалога и
консолидации («сон» на LLM) — что реально сохранилось? Каждый кейс несёт сырой `dialog`
(role/content) и `criteria` на память **после извлечения/консолидации**, а не на ответ модели.
15 **STM extraction** (рассеянные по 12–23 ходам факты, коррекция внутри сессии, директива
«запомни правило», дистракторы-брейншторм) · 16 **LTM sleep** (факт переживает сон и вспоминается
позже; обновление вытесняет старое через supersede; кросс-сессионное противоречие — побеждает
новое; цепочка зависимостей и выведенный факт сохраняются как *структура* — links / derived_from).
Механизмо-нейтральны и по правилу честности **не подгоняются под движок**: кейс, который простой
движок не берёт, остаётся как есть. Диалоговые кейсы грейдятся только при движке с
`ConsolidatingEngine` или при файле `--snapshots` (`caseId → memoryItem[]`), иначе — **skipped**
(N/A, не провал, вне знаменателя). Референс-движок — символьная push-модель, не экстрактор
текста, поэтому их пропускает.

**Запуск** (нужен PHP 7.4+ с `mbstring`, без Composer и БД):

```bash
php runner/run.php          # все кейсы на встроенном референс-движке
php runner/run.php -v       # с выводом каждого хода
php runner/run.php --situation=cross_session_recall   # только одну ситуацию
php runner/run.php --snapshots=my-memory.json         # + грейд диалоговых STM/LTM кейсов
```

На чистом чекауте — `21/21 gradable cases passed · score 1.0000 · 9 skipped` (9 диалоговых
кейсов пропущены — референс-движок их не консолидирует). Референс-движок
(`runner/lib/ReferenceEngine.php`) — читаемая модель слоёв L0–L3 и одновременно нижняя планка,
которую обязана взять любая реальная реализация.

**Реальный хук.** Прогнать живой `dejavu-push.php` — через тонкий shim и `--engine=push`
(`DEJAVU_PUSH_CMD`, JSON-протокол по ходу). Контракт — в `runner/lib/DejavuPushEngine.php`.

**Формат результата** (`--out=FILE`) — документ с метаданными, `summary` (`score = passed/total`,
`by_situation`) и полным трейсом `cases[]`; его и отправляют на leaderboard
(`dejavu-memory-leaderboard`). Подробности — в [`results/`](results/README.md) и
[`cases/`](cases/README.md).
