# Runner

A dependency-free PHP runner (PHP 7.4+). No Composer, no extensions beyond `mbstring`.

```bash
php runner/run.php                 # run every case on the reference engine
php runner/run.php -v              # also print each turn's pushed set
php runner/run.php --situation=habituation
php runner/run.php --out=results/my-run.json --submitter=alice --engine-version=1.2.0
php runner/run.php --snapshots=my-memory.json   # grade the STM/LTM dialog cases too
```

Exit code: `0` all gradable cases passed · `1` some failed · `2` runner error.

## Two grading axes

The runner grades cases on **two** axes, routed automatically by case shape:

- **Push path** (situations 01–14) — a case has `turns`; the runner feeds each turn to the
  engine and grades the delivered ("pushed") slugs. See `Grader`.
- **Memory state** (situations 15–16) — a case has a `dialog` + `criteria`; the runner asks
  the engine (or a `--snapshots` file) for the memory *after* STM extraction / LTM
  consolidation, and grades that snapshot against the criteria. See `MemoryGrader`.

A dialog case is graded only when a consolidated snapshot is available (see below); otherwise
it is reported **skipped** — counted separately, never as a failure, and excluded from the
score denominator.

## Engines

| `--engine` | Class | What it grades |
|------------|-------|----------------|
| `reference` (default) | `ReferenceEngine` | Built-in executable spec of layers L0–L3. Always available, deterministic. |
| `push` | `DejavuPushEngine` | The real `dejavu-push.php` hook, via a shim (`DEJAVU_PUSH_CMD`). |

### Reference engine

`runner/lib/ReferenceEngine.php` is a minimal, readable model of the dejavu read path:

- **L0** — lowercase the prompt.
- **L1** — direct cue match (substring / exact / prefix / regex) fully activates a fact; one-hop **spreading** over `relates` / `derived_from` links (`activation × weight × 0.5`, threshold `0.3`).
- **L2** — gate: drop `stale`/`archived`, filter by active `domain` and `project`, apply `supersedes` (STM eviction), **habituation** (a fact surfaced this session is suppressed unless it re-fires ≥1.5× stronger), then rank by `salience × activation` and cap at a **budget of 3**.
- **L3** — return the surviving slugs.

**Sessions.** A turn may carry a `session` id. When it changes between turns the engine crosses
a **session boundary**: it resets *session-scoped* state (habituation) while keeping long-term
facts, so a fact suppressed in one session may surface again in the next. Turns without a
`session` share one implicit session (single-session cases behave exactly as before). This is
what the `cross_session_recall` / `temporal_relevance` cases exercise.

It is intentionally simple — it is the floor a real engine must clear, and a spec you can read.

> **The reference engine does not run the dialog (STM/LTM) cases.** It is a symbolic
> cue-index push model, not a text extractor, so it cannot consolidate a free-text
> conversation into facts. Those cases are reported *skipped* for it rather than tuned to
> pass — the benchmark's honesty rule (§ cases/README.md) forbids shaping the algorithm to
> the tests. Supply a consolidated snapshot to grade them (next section).

## Grading STM/LTM (dialog) cases

Dialog cases (`situation: stm_extraction | ltm_sleep`) measure what a memory holds *after*
extraction and consolidation. Provide the consolidated state one of two ways:

**1. Out of process — `--snapshots=FILE` (language-agnostic).** Run your own memory over each
case's `dialog` (+ `seed`), then dump a JSON map `caseId → memoryItem[]`:

```jsonc
{
  "ltm-sleep-update-supersede": [
    { "text": "Portal runs on PHP 8.4.", "kind": "project", "active": true,
      "supersedes": ["Portal runs on PHP 8.1."] },
    { "text": "Portal runs on PHP 8.1.", "kind": "project", "active": false }
  ]
}
```

A memory item: `text` (graded by substring), optional `kind`, `active` (default `true`;
`false` = retired history, ignored by `must_not_remember`), and the optional edge lists
`supersedes` / `derived_from` / `links` (texts of related facts). A wrapper
`{ "snapshots": { ... } }` is also accepted.

**2. In process — implement `ConsolidatingEngine`.** An engine that can extract/consolidate
implements `consolidate(array $case): array` returning the same memory-item list; the runner
calls it automatically for dialog cases. See `runner/lib/ConsolidatingEngine.php`.

Only the criteria are graded (mechanism-neutral substring/edge checks); the assistant's
replies in the dialog are never inspected.

### Wiring the real hook (`--engine=push`)

The benchmark stays store-agnostic. Provide a shim executable and point `DEJAVU_PUSH_CMD` at it:

```bash
DEJAVU_PUSH_CMD=/path/to/dejavu-shim.php php runner/run.php --engine=push --out=results/live.json
```

The shim speaks one JSON object in / one JSON object out per turn:

```jsonc
// stdin
{ "prompt": "...", "context": { "project": "site-a" }, "seed": [ /* facts */ ], "reset": true, "session": "s1" }
// stdout
{ "pushed": ["slug-a", "slug-b"] }
```

`reset` is `true` on a case's first turn (load the seed into a scratch store, clear session
state) and `false` afterwards. `session` is the turn's session id (`null` when absent); reset
your engine's per-session state when it changes between turns while keeping long-term facts. The shim owns all store-specific wiring — seeding the factStore,
invoking `dejavu-push.php`, and mapping its emitted `additionalContext` back to slugs. See the
protocol contract at the top of `runner/lib/DejavuPushEngine.php`.

## Files

```
runner/
  run.php                 CLI entry: load → grade → summarise → (optional) write result
  lib/
    EngineInterface.php     the push-path contract (loadCase + push)
    ConsolidatingEngine.php optional STM-extraction / LTM-consolidation capability
    ReferenceEngine.php     built-in L0–L3 reference implementation
    DejavuPushEngine.php    adapter to the live hook via DEJAVU_PUSH_CMD
    CaseLoader.php          loads + validates cases/*.json
    Grader.php              push-path turn/case assertion checking
    MemoryGrader.php        memory-state (dialog criteria) checking
```
