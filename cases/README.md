# Case format

Each file in `cases/` is a JSON document describing **one situation** from the
dejavu push-memory model (see [`dejavu-spec`](https://github.com/carono/dejavu-spec)).
A file holds an array of **cases**; each case is *self-contained* — it ships its own
seed facts, so a case can be graded without a live `factStore`.

```jsonc
{
  "situation": "L1_keyword_recall",     // one of the 10 canonical situations
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

## The 10 situations

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

## Grading

`runner/run.php` loads every case, feeds each turn through an **engine**, and grades the
pushed set against the assertions. See [`../runner/README.md`](../runner/README.md).
