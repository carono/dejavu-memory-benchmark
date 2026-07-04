# Results

A **result document** is what `runner/run.php --out=FILE` produces and what you submit to
the leaderboard. [`example-reference.json`](example-reference.json) is a full run of the
built-in reference engine (21/21 gradable, score 1.0, 9 skipped) — use it as the schema
reference.

## Schema

```jsonc
{
  "benchmark": "dejavu-memory-benchmark",
  "benchmark_version": "0.3.0",     // pins the case set the score is comparable within
  "engine": "reference",            // engine under test
  "engine_version": "0.3.0",         // your engine's version (--engine-version)
  "submitter": "reference",          // who ran it (--submitter)
  "generated_at": "2026-07-04T00:00:00+00:00",  // UTC ISO-8601
  "environment": { "php": "8.4.19", "os": "Linux" },
  "summary": {
    "total": 30,                     // all cases loaded
    "passed": 21,
    "failed": 0,
    "skipped": 9,                    // dialog (STM/LTM) cases with no consolidated snapshot
    "score": 1.0,                    // passed / (total − skipped), the headline number
    "by_situation": {                // per-situation breakdown (skipped tracked per situation)
      "habituation": { "total": 1, "passed": 1, "skipped": 0 },
      "ltm_sleep": { "total": 5, "passed": 0, "skipped": 5 }
      // ...
    }
  },
  "cases": [                         // full per-case / per-turn trace
    {
      "id": "habituation-no-repeat",
      "situation": "habituation",
      "passed": true,
      "turns": [
        { "index": 0, "prompt": "...", "pushed": ["docker-sock"], "passed": true, "failures": [] },
        { "index": 1, "prompt": "...", "pushed": [], "passed": true, "failures": [] }
      ]
    }
  ]
}
```

## Submitting to the leaderboard

The `summary.score` (cases passed ÷ gradable, i.e. total − skipped) is the ranked number;
`by_situation` powers the per-capability columns. A `skipped` case is one the engine cannot be
graded on (a dialog STM/LTM case with no consolidated snapshot); it is excluded from the score
denominator, never counted as a failure. The `cases[]` trace lets the leaderboard show *which*
situation an engine fails and reproduce it. Keep `benchmark_version` — scores are only
comparable within the same case set.

Do **not** commit personal runs here beyond the reference example; submit them to the
leaderboard instead.
