# Results

A **result document** is what `runner/run.php --out=FILE` produces and what you submit to
the leaderboard. [`example-reference.json`](example-reference.json) is a full run of the
built-in reference engine (11/11, score 1.0) — use it as the schema reference.

## Schema

```jsonc
{
  "benchmark": "dejavu-memory-benchmark",
  "benchmark_version": "0.1.0",     // pins the case set the score is comparable within
  "engine": "reference",            // engine under test
  "engine_version": "0.1.0",         // your engine's version (--engine-version)
  "submitter": "reference",          // who ran it (--submitter)
  "generated_at": "2026-07-03T18:34:59+00:00",  // UTC ISO-8601
  "environment": { "php": "8.4.19", "os": "Linux" },
  "summary": {
    "total": 11,
    "passed": 11,
    "failed": 0,
    "score": 1.0,                    // passed / total, the headline number
    "by_situation": {                // per-situation breakdown
      "habituation": { "total": 1, "passed": 1 }
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

The `summary.score` (cases passed ÷ total) is the ranked number; `by_situation` powers the
per-capability columns. The `cases[]` trace lets the leaderboard show *which* situation an
engine fails and reproduce it. Keep `benchmark_version` — scores are only comparable within
the same case set.

Do **not** commit personal runs here beyond the reference example; submit them to the
leaderboard instead.
