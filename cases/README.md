# Case format

Each file in `cases/` is a JSON document describing **one situation** from the
dejavu push-memory model (see [`dejavu-spec`](https://github.com/carono/dejavu-spec)).
A file holds an array of **cases**; each case is *self-contained* — it ships its own
seed facts, so a case can be graded without a live `factStore`.

```jsonc
{
  "situation": "L1_keyword_recall",     // one of the situations (see the table below)
  "cases": [
    {
      "id": "l1-nginx-reload",          // globally unique, kebab-case
      "title": "Exact keyword hits the fact",
      "seed": [ /* Fact[] */ ],          // facts loaded into the store before the run
      "turns": [ /* Turn[] */ ],         // ordered prompts + per-turn assertions
      "note": "why this case exists / what it proves"
    }
  ]
}
```

## Fact

A seed fact mirrors the `factStore` row plus its projected `cueMemory` and `fact_links`.

```jsonc
{
  "slug": "nginx",                       // stable id, referenced by expectations
  "statement": "nginx reload: docker exec nginx nginx -s reload",
  "kind": "env",                         // env | user | project | rule | note | ...
  "status": "confirmed",                 // confirmed | pending | stale | archived
  "salience": 0.7,                        // 0..1, delivery priority (default 0.5)
  "strength": 1.0,                        // recency×frequency trace (default 1.0)
  "tier": "ltm",                          // ltm | stm  (stm = current-session fact)
  "domain": "web",                        // optional; used by domain-conflict cases
  "source_project": null,                 // optional; used by multi-project cases
  "cues": [                               // projected cueMemory (flow A)
    { "type": "keyword", "value": "nginx", "match": "substring" },
    { "type": "command", "value": "nginx -s reload", "match": "substring" }
  ],
  "supersedes": ["nginx-old"],            // optional; this fact wins over listed slugs (STM/update)
  "links": [                              // fact_links graph (spreading activation, L1b)
    { "to": "php-fpm", "relation": "relates", "weight": 0.6 }
  ]
}
```

`match` modes: `substring` (default), `exact`, `prefix`, `regex`.
`cue.type` follows the spec: `keyword | path | path_prefix | container | command | error | project | domain`.

## Turn

A turn is one harness event (a prompt) plus what the memory channel is expected to do.

```jsonc
{
  "prompt": "reload nginx after editing the config",
  "session": "s1",                        // optional session id (multi-session cases)
  "context": {                            // optional harness context for L2 gating
    "project": "site-a",                  // active project → project isolation
    "domain": "web"                       // active domain → domain disambiguation
  },
  "expect_facts": ["nginx"],              // slugs that MUST be pushed
  "reject_facts": ["redis", "yii2-ar"],   // slugs that must NOT be pushed
  "expect_empty": false,                  // true → nothing may be pushed (negative / fail-open)
  "expect_suppressed": []                 // slugs that fired earlier and must now be habituated away
}
```

Assertions are ANDed. A turn passes only if every present assertion holds. A case
passes only if all its turns pass. `expect_empty: true` is mutually exclusive with
`expect_facts`.

### Sessions (multi-turn & multi-session)

The `turns` array is already **multi-turn**: turns run in order and the engine keeps
per-session state across them (habituation, STM eviction), so a fact seeded early can be
queried many turns later (see `long_session_recall`).

`session` (alias `session_id`) marks a turn's **session**. Turns sharing an id are one
session; when the id changes between turns, the engine crosses a **session boundary** —
it consolidates STM→LTM and resets *session-scoped* state (habituation), while long-term
facts persist. Turns without a `session` all share one implicit session, so existing
single-session cases are unaffected. Use it for cross-session recall, per-session
habituation reset, and cross-session updates (`cross_session_recall`, `temporal_relevance`).

## The situations

### Push-paradigm core (situations 01–10)

These are the ten canonical situations from the dejavu design task; they isolate the
mechanics unique to **push** memory (symbolic cue-index, STM/LTM interplay, gates,
habituation, staying silent). The third-party scoreboard in
[`../results/THIRD-PARTY.md`](../results/THIRD-PARTY.md) is measured on this set.

| # | `situation` | Proves |
|---|-------------|--------|
| 01 | `L1_keyword_recall`   | Exact/substring cue hits the right fact. |
| 02 | `L1c_semantic_recall` | No literal overlap; recall via meta/semantic cue. |
| 03 | `L1b_spreading`       | Prompt hits fact A, the answer is linked fact B. |
| 04 | `STM_vs_LTM`          | A fresh session fact overrides a stale LTM one. |
| 05 | `domain_conflict`     | One signal, two domains — the active domain wins. |
| 06 | `staleness`           | A `stale`/`archived` fact never surfaces. |
| 07 | `habituation`         | A fact already surfaced this session is suppressed. |
| 08 | `multi_project`       | Project A rules do not leak into project B. |
| 09 | `negative`            | No matching fact ⇒ nothing injected (fail-open). |
| 10 | `personal_meta`       | Personal facts recalled by data-type meta-cues. |

### General-memory cases (situations 11–14)

> **Scope note.** Situations 01–10 measure the **push paradigm** specifically. The cases
> below test **general memory capability** — the things *any* good memory system (push or
> pull, RAG or graph) should get right regardless of mechanism. They are mechanism-neutral:
> phrased as recall/precision goals, not as push internals. A strong memory library should
> pass them; they are here so the benchmark reflects general memory, not only push.

| # | `situation` | Proves |
|---|-------------|--------|
| 11 | `long_session_recall`   | A fact stated early is recalled 30+ turns later; look-alike distractors stay silent. |
| 12 | `cross_session_recall`  | A fact from an earlier session is recalled later; habituation resets per session; updates and history aggregate across sessions. |
| 13 | `scattered_facts`       | Facts spread across separate replies are connected by one aggregate query; a later correction overrides an earlier scattered fact. |
| 14 | `temporal_relevance`    | A fact changed over time returns its current value; the stale one is retired by the temporal relation, not by a `stale` flag. |

## Grading

`runner/run.php` loads every case, feeds each turn through an **engine**, and grades the
pushed set against the assertions. See [`../runner/README.md`](../runner/README.md).
