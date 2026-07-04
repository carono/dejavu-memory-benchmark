# Writing a push-engine adapter (shim)

This is the contract for grading **any** memory implementation through the
benchmark's `--engine=push` mode. Write a small executable ("shim") that speaks a
one-object-in / one-object-out JSON protocol, point `DEJAVU_PUSH_CMD` at it, and the
runner grades your engine against all cases. The shim is the only thing you write —
it owns all store-specific wiring (seed the DB, invoke the hook, map results to slugs).

The reference implementation of the runner side lives in
`runner/lib/DejavuPushEngine.php`; this document is the shim author's view of it.

---

## 1. Transport

- The runner starts your command **once per turn** via `proc_open` (a fresh process
  each time — do not rely on in-process state surviving between turns; see §4).
- It writes **one JSON object** to your **stdin**, then closes stdin.
- Your shim writes **one JSON object** to **stdout** and exits **0**.
- Exit non-zero, or emit malformed JSON, and the run aborts with your `stderr`
  attached to the error. `stderr` is otherwise ignored — use it freely for tracing.

Because the working directory / argv is a shell string, `DEJAVU_PUSH_CMD` is run
through `sh -c`. If your path contains spaces, quote it:

```bash
DEJAVU_PUSH_CMD="/path/to/venv/bin/python '/abs/path with spaces/my_shim.py'" \
  php runner/run.php --engine=push --out=results/mine.json
```

---

## 2. Input object (stdin)

```jsonc
{
  "prompt":  "reload nginx after editing the site config",  // string: the user turn
  "context": { "domain": "web", "project": "site-a" },       // object: harness context (may be {})
  "seed":    [ { /* fact */ }, ... ],                        // array: the case's seed facts
  "reset":   true                                            // bool: true on a case's FIRST turn
}
```

- **`prompt`** — the text of the current turn. Match cues / embed / retrieve against this.
- **`context`** — optional harness signals. Two keys are used by the cases:
  - `domain` — the active domain (e.g. `"web"`, `"python"`). Used by case 05.
  - `project` — the active project (e.g. `"site-a"`). Used by case 08.
  - Absent keys mean "no constraint". Treat unknown keys as forward-compat extras.
- **`seed`** — the full fact set for this case (see §3). **Re-sent on every turn** of
  the case, identical each time, so a stateless shim can rebuild its store from it
  each turn.
- **`reset`** — `true` on the first turn of each case, `false` afterwards. Use it to
  clear any per-case session state (habituation memory, an on-disk store) and load the
  seed. If your shim is stateless (rebuilds from `seed` every turn), you can ignore it.

### Seed fact schema

A fact is a JSON object. All fields except `slug` are optional; honor the ones your
engine models, ignore the rest.

```jsonc
{
  "slug":       "nginx",                       // string, REQUIRED — the id you return
  "statement":  "Reload nginx after ...",       // string — the human-readable fact text
  "kind":       "env",                          // env|rule|user|project|note|... (free tag)
  "salience":   0.7,                            // 0..1 — base importance (default 0.5)
  "strength":   1.0,                            // 0..1 — memory-trace strength (default 1.0)
  "status":     "confirmed",                    // confirmed|stale|archived — hidden ones must NOT surface (case 06)
  "tier":       "ltm",                          // ltm|stm — short/long-term (case 04)
  "domain":     "web",                          // isolates by context.domain (case 05)
  "source_project": "site-a",                   // isolates by context.project (case 08)
  "supersedes": ["db-port-ltm"],                // slugs this fact evicts when it fires (case 04)
  "cues": [                                      // symbolic triggers (see match modes below)
    { "type": "keyword", "value": "nginx", "match": "substring" },
    { "type": "command", "value": "nginx -s reload", "match": "substring" }
  ],
  "links": [                                     // fact graph for spreading activation (case 03)
    { "to": "php-fpm", "relation": "relates", "weight": 0.7 }
  ]
}
```

**Cue `match` modes** (how `value` is tested against the lowercased prompt):
`substring` (default), `exact` (word-boundary), `prefix` (word-start), `regex`.
A purely semantic engine may ignore `cues` and embed `statement` instead — that is a
valid strategy; the benchmark does not prescribe *how* you activate a fact, only
*which* slugs you deliver.

**Link `relation`** values that spread activation in the reference engine:
`relates`, `derived_from` (weight × decay across one hop). Others (`supersedes`,
`contradicts`) are handled via their own fields, not by spreading.

---

## 3. Output object (stdout)

```jsonc
{ "pushed": ["nginx", "php-fpm"] }   // slugs delivered this turn, most-relevant first
```

- **`pushed`** — an array of fact **slugs** your engine chose to inject for this turn.
  Order is preserved (used by ranked situations); an empty array `[]` means "stay
  silent". Only slugs present in this case's `seed` are meaningful.
- Return nothing but `{"pushed": []}` when no fact is relevant — the negative case (09)
  requires silence, and false positives cost you on the reject/precision cases.

That is the entire output contract. No scores, no text — just the slugs.

---

## 4. State across turns

The runner invokes your shim as a **new process per turn**, but grades a case as a
sequence of turns. Two ways to handle multi-turn cases (habituation 07, domain 05,
project 08, personal-meta 10 all have >1 turn):

1. **Stateless (simplest).** Rebuild your index from `seed` every turn and answer from
   `prompt` + `context` alone. You will pass everything that needs no cross-turn memory,
   and *structurally* fail habituation (07) — which is the honest result if your library
   has no session suppression.
2. **Stateful.** Persist session state (e.g. what you surfaced already, for habituation)
   to a temp location keyed per case. Clear it when `reset` is `true`, load-or-init
   otherwise. This is how you can implement habituation, STM eviction, etc.

Whichever you pick, **only implement mechanisms your library actually has.** The point
of the benchmark is to reveal which situations a design covers — hand-rolling the push
gate on top of a library that lacks it measures your shim, not the library.

---

## 5. Minimal example (Python, stateless, semantic)

```python
#!/usr/bin/env python3
import json, sys
payload = json.load(sys.stdin)
prompt  = payload["prompt"]
seed    = payload["seed"]

# your library: index seed statements, retrieve for `prompt`, map hits -> slugs.
pushed = []
for fact in seed:
    if fact.get("statement", "").lower() and prompt.lower() in fact["statement"].lower():
        pushed.append(fact["slug"])          # toy rule; replace with real retrieval

print(json.dumps({"pushed": pushed[:3]}))     # budget: 3 per turn is the reference cap
```

Run it:

```bash
DEJAVU_PUSH_CMD="python3 '/abs/path/my_shim.py'" \
  php runner/run.php --engine=push \
    --submitter=you --engine-version=my-lib-1.0 \
    --out=results/my-lib.json
```

Filter to one situation while developing:

```bash
php runner/run.php --engine=push --situation=habituation -v
```

---

## 6. Checklist

- [ ] Reads exactly one JSON object from stdin, writes exactly one to stdout, exits 0.
- [ ] Output is `{"pushed": [<slugs>]}` — slugs from this case's `seed`, ranked, ≤ budget.
- [ ] Returns `{"pushed": []}` when nothing is relevant (don't force a weak match).
- [ ] Honors `reset` if you keep per-case state; otherwise rebuild from `seed`.
- [ ] Uses only your library's real capabilities — no hand-rolled push gate.
- [ ] Emits nothing extra on stdout (logs go to stderr).

Reference engine (`runner/lib/ReferenceEngine.php`) is a readable, dependency-free
model of the expected behaviour and the floor a real engine should clear. Worked
adapters for mem0 / LangChain / generic vector-RAG live beside this file (untracked;
see `adapters/README.md`) and the comparison they produced is in
`results/THIRD-PARTY.md`.
