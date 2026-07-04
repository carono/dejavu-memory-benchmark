# Third-party memory libraries vs dejavu-benchmark

Task #480. Popular AI-agent memory libraries graded through the `--engine=push`
protocol and compared against the vanilla **baseline** (no memory, 1/11) and the
**reference** dejavu engine (ceiling, 11/11).

All runs are **fully local, no external API key**: embeddings `bge-m3` (1024-dim)
and LLM `qwen2.5:7b` via the local Ollama server. Adapters live in `adapters/`
(see `adapters/README.md`); result documents are the `*.json` next to this file.

## Scoreboard

| engine | score | pass | 01 kw | 02 sem | 03 spr | 04 stm | 05 dom | 06 stl | 07 hab | 08 prj | 09 neg | 10 per |
|--------|:-----:|:----:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| baseline (vanilla, no memory) | **0.091** | 1/11 | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ | ✗ |
| **Zep / Graphiti** 0.29.2 (Neo4j + Ollama) | **0.091** | 1/11 | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ | ✗ |
| rag-ollama (generic vector-RAG) | **0.455** | 5/11 | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ~ | ✗ | ✓ | ✗ |
| LangChain (FAISS + bge-m3) | **0.455** | 5/11 | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ~ | ✗ | ✓ | ✗ |
| **mem0** 2.0.11 (Chroma + Ollama) | **0.545** | 6/11 | ✓ | ✓ | ✗ | ✗ | ✓ | ✗ | ✗ | ✓ | ✓ | ✗ |
| reference (dejavu) | **1.000** | 11/11 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |

Situations: 01 keyword · 02 semantic recall · 03 graph spreading · 04 STM-vs-LTM ·
05 domain conflict · 06 staleness · 07 habituation · 08 multi-project · 09 negative ·
10 personal meta-cues. `~` = passed by threshold luck, not by a real mechanism (see 07).

Zep's mark is the **representative** run: across 5 back-to-back runs it scored
`[1, 1, 1, 1, 3]` (mean 1.4, median 1). Only 09 passes in every run; 01 and 07 each
passed exactly once — in the same lucky run — and are extraction noise, not a mechanism.

## What every RAG library gets right

- **01 keyword / 02 semantic recall.** This is the home turf of embedding memory.
  bge-m3 maps `"what is my name?"` onto `"The user's name is Ivan."` with no token
  overlap — all three libraries clear 01 and 02 cleanly. The dejavu reference does
  the same with cheap projected meta-cues instead of an embedding call.
- **09 negative / fail-open.** With a calibrated similarity threshold, the store
  stays silent when nothing is relevant (`"write a haiku…"` scores below τ). Note:
  this needs the *thresholded* config. LangChain's **default** `VectorStoreRetrieverMemory`
  has no threshold and always returns top-k — it would fail 09 (and even 01, by
  dragging in distractors). We graded LangChain at its precision-aware best.

## Where they fail — and why it is structural, not a tuning miss

| # | Situation | Why pull-RAG can't do it |
|---|-----------|--------------------------|
| 03 | **Graph spreading** | `"set up an nginx vhost"` must also surface the linked `php-fpm` fact. RAG has no fact graph; `php-fpm` (cosine 0.45) ranks *below* the unrelated `redis` distractor (0.51). Only symbolic one-hop spreading recovers it. |
| 04 | **STM vs LTM** | The session correction (`port 5533`) must *evict* the stale LTM value (`5432`). RAG returns both by similarity. Even mem0 in LLM `infer=True` mode tagged the correction `event=ADD` (not `UPDATE`) with the local LLM — **both ports survived**. No supersede semantics. |
| 06 | **Staleness** | A fact tagged `stale` must never surface even at high salience. RAG has no status gate; the store retrieves by similarity alone, so the deprecated `deploy.sh` fact comes back. |
| 07 | **Habituation** | A fact already delivered this session must go quiet next turn. No RAG library has session-level suppression. mem0 fails this honestly; rag-ollama/LangChain "pass" only because turn-2 similarity happened to dip under τ — a threshold artifact, not a mechanism (marked `~`). |
| 10 | **Personal meta-cues** | `"what is my profession?"` must hit *only* `user-job`, not the whole personal cluster. Pure similarity bleeds: mem0 returned `user-name`+`user-age` for a name query; the profession query fell under τ and returned nothing. Needs data-type meta-cues resolving to exactly one fact. |

## mem0's edge over vanilla RAG: metadata filtering

mem0 scores **6/11**, one above vanilla RAG, and the whole difference is **05 domain
conflict** and **08 multi-project**. mem0 supports `filters=` on search, so passing
the active `domain`/`project` from harness context isolates the right fact
(`yii-run` for web, `push-forbidden-a` for site-a). This is a genuine mem0 capability,
not a shim trick — and it is exactly the symbolic **gate** that naive vector memory
lacks. LangChain *can* do the same with a metadata-filtered retriever, but its default
memory does not, so out-of-box it fails 05/08 (it drops to RAG's profile).

mem0 trades that gain for **07 habituation**, which the thresholded RAG passed by luck —
so net +1. Its headline **LLM memory-management** (`infer=True`, ADD/UPDATE/DELETE
conflict resolution) is mem0's answer to case 04, but with a local `qwen2.5:7b` it did
**not** resolve the port correction (kept both values). It may fare better with a
frontier LLM, but that is a paid, non-deterministic path; the graded run uses the
deterministic `infer=False` store.

## Zep / Graphiti: the strongest design on paper, baseline in practice (task #481)

Zep discontinued its self-hosted Community Edition — the `zepai/zep` server image was
pulled from Docker Hub. What remains open-source is **Graphiti**, the temporal knowledge
graph that *is* Zep's memory: the automatic fact-invalidation (`valid_at`/`invalid_at` on
edges) that no other library here has. We stood it up honestly — dockerized **Neo4j 5.26**
+ **graphiti-core 0.29.2**, same local backend as every other adapter (`qwen2.5:7b` for
extraction/temporal reasoning, `bge-m3` embeddings). Shim: `adapters/zep_shim.py`. Seeds
are ingested as chronological episodes (old → new); each turn surfaces only currently-valid
edges. It reads **none** of the benchmark's `supersedes`/`status`/`tier` fields — the graph
must derive supersede/staleness from the statements and their chronology alone, exactly what
a real Zep deployment sees.

**Result: 1/11 — tied with vanilla baseline, below every RAG library.** This is the most
counter-intuitive finding in the comparison, and the reason is *not* the graph:

- **The bottleneck is extraction, not the mechanism.** Graphiti turns each fact into
  entities + edges via the LLM. On a local 7B this is badly unreliable: short statements
  like *"The user's name is Ivan."* or *"the database listens on port 5432"* repeatedly
  yield **0 edges even after 3 retries** — the fact never enters the graph, so it can't be
  retrieved. Recall collapses *upstream* of any temporal logic. Graphiti fails even 01/02
  keyword/semantic recall that vanilla RAG clears trivially, because RAG embeds the raw
  statement (no extraction step to fail).
- **High variance confirms it.** Five back-to-back runs scored `[1, 1, 1, 1, 3]`. The lone
  3/11 came from a run where the 7B happened to extract a couple more edges; 01 and 07
  "passed" only in that run. Only **09 negative** passes every time — because passing 09
  means the graph returns *nothing*, which an empty graph does for free.
- **04 STM/supersede — the mechanism works, when extraction does.** In isolated manual
  probing the temporal graph *did* invalidate the stale `port 5432` edge once the
  `port 5533` correction was ingested — the one design here that can. But in the graded
  runs the `db-port-*` facts usually produced no edges at all, so the case fails on recall
  before supersede can fire. The capability is real; the local-7B extraction can't feed it.
- **06 staleness — honest structural miss.** Graphiti invalidates edges only when a later
  episode's *text contradicts* an earlier one. The benchmark's two deploy facts don't
  textually contradict (legacy `deploy.sh` vs CI pipeline are just two methods); staleness
  is carried by the `status: stale` flag, which — per the fair-adapter rule — the shim does
  not read. So `deploy-old` surfaces. Same failure as every RAG library, for the same
  reason: no status gate.
- **07 habituation — no session suppression.** Zep/Graphiti has no "already-surfaced this
  session, stay quiet" mechanism. The `docker` cue fires on both turns and re-pushes the
  fact on turn 2. It failed 07 in 4 of 5 runs; the single pass was a turn-2 retrieval miss,
  not suppression.

**Takeaway.** Graphiti is architecturally the closest library to dejavu's push model — it
owns the one hard mechanism (temporal fact invalidation) that mem0 and RAG lack. But that
mechanism is gated behind an LLM extraction step, and on a commodity local 7B that step is
the weakest link, dragging the whole engine to baseline. A frontier extraction model would
very likely lift it toward — and past — mem0 on 04, but that is a paid, non-deterministic
path; on the *same fair local footing* as the other adapters, the temporal graph never gets
the clean fact set it needs. Push semantics implemented as "extract-then-graph" inherit the
extractor's reliability; dejavu's symbolic cue/gate path does not.

## Libraries evaluated but not run, with reasoning

| library | status | expected profile |
|---------|--------|------------------|
| **Memoripy** | represented by `rag-ollama` | Its core is embedding store + cosine retrieval with a decay/reinforcement score. It has a recency/decay knob but no domain gate, no graph, no session habituation → the vanilla-RAG profile (~5/11). Adapting it needs OpenAI-shaped clients; the generic RAG shim is a faithful stand-in. |
| **Letta / MemGPT** | not run — needs a server | Requires a running Letta server + Postgres and models its memory as an *agent loop* (the LLM decides when to page core/archival memory in). It does not expose a per-turn push read-path; grading it means driving a full agent, out of scope for the store-agnostic protocol. Its archival memory is vector-RAG → same 03/06/07 failures; its editable *core memory* could help 04 but only via LLM tool-calls. |
| **Zep** | **run** (task #481) — see below | Stood up as Graphiti (Zep's own server image was pulled from Docker Hub). Scores **1/11**, at baseline. Its temporal graph is the right *mechanism* for 04/06 but on a local 7B the entity/edge extraction is too flaky to realize it — recall collapses before the graph logic ever matters. |

## Takeaway

Off-the-shelf memory libraries are **pull-RAG**: they excel at recall (01/02) and,
with a threshold, at staying silent (09), landing at **0.45–0.55**. They plateau there
because the remaining six situations need mechanisms RAG does not have — a **symbolic
gate**: graph spreading (03), STM eviction/supersede (04), a staleness status gate (06),
session habituation (07), and precise meta-cue resolution (10). mem0's metadata filter
buys back domain/project isolation (05/08) and is the only library here that clears
**half** — but the ceiling for the pull paradigm on this push benchmark sits well under
the dejavu reference's **1.0**. The benchmark measures exactly the axis these libraries
were never built for, which is the point: push memory is a different mechanism, not a
better retriever.

**Zep / Graphiti is the instructive exception:** it *does* own the push-side mechanism
(temporal fact invalidation) the others lack — but it delivers it through an LLM extraction
step, and on the same fair local 7B that step is unreliable enough to sink recall to
baseline (1/11). The lesson cuts both ways: the right mechanism behind a fragile extractor
loses to a plain retriever, and a symbolic cue/gate path (dejavu) buys reliability an
extract-then-graph pipeline can't guarantee on commodity hardware.
