# Runner

A dependency-free PHP runner (PHP 7.4+). No Composer, no extensions beyond `mbstring`.

```bash
php runner/run.php                 # run every case on the reference engine
php runner/run.php -v              # also print each turn's pushed set
php runner/run.php --situation=habituation
php runner/run.php --out=results/my-run.json --submitter=alice --engine-version=1.2.0
```

Exit code: `0` all cases passed · `1` some failed · `2` runner error.

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

It is intentionally simple — it is the floor a real engine must clear, and a spec you can read.

### Wiring the real hook (`--engine=push`)

The benchmark stays store-agnostic. Provide a shim executable and point `DEJAVU_PUSH_CMD` at it:

```bash
DEJAVU_PUSH_CMD=/path/to/dejavu-shim.php php runner/run.php --engine=push --out=results/live.json
```

The shim speaks one JSON object in / one JSON object out per turn:

```jsonc
// stdin
{ "prompt": "...", "context": { "project": "site-a" }, "seed": [ /* facts */ ], "reset": true }
// stdout
{ "pushed": ["slug-a", "slug-b"] }
```

`reset` is `true` on a case's first turn (load the seed into a scratch store, clear session
state) and `false` afterwards. The shim owns all store-specific wiring — seeding the factStore,
invoking `dejavu-push.php`, and mapping its emitted `additionalContext` back to slugs. See the
protocol contract at the top of `runner/lib/DejavuPushEngine.php`.

## Files

```
runner/
  run.php                 CLI entry: load → grade → summarise → (optional) write result
  lib/
    EngineInterface.php   the engine contract (loadCase + push)
    ReferenceEngine.php   built-in L0–L3 reference implementation
    DejavuPushEngine.php  adapter to the live hook via DEJAVU_PUSH_CMD
    CaseLoader.php        loads + validates cases/*.json
    Grader.php            turn/case assertion checking
```
