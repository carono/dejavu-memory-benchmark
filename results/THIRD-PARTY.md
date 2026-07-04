# Third-party memory libraries vs dejavu-benchmark

Task #480. Popular AI-agent memory libraries graded through the `--engine=push`
protocol and compared against the vanilla **baseline** (no memory, 1/11) and the
**reference** dejavu engine (ceiling, 11/11).

> **Scope of this scoreboard.** These runs measure the **push paradigm** — the ten
> canonical situations (11 cases, benchmark_version `0.1.0`) built around dejavu's push
> read-path. That is deliberately the axis where pull-RAG libraries are weakest, and the
> numbers below reflect exactly that. Benchmark `0.2.0` adds four **general-memory**
> situations (11–14: long-session, cross-session, scattered-facts, temporal) that are
> mechanism-neutral and *do* play to a good pull/RAG/graph system's strengths — see the
> [scope disclaimer in the README](../README.md#general-memory-cases-situations-1114). The
> `x/11` scores in *this* scoreboard are push-paradigm only — read `6/11` as "6 of the 10
> push situations", not "6 of everything memory". The same engines have now **also** been
> graded on the four general-memory situations (10 cases); those results are in
> [§ v0.2.0 general-memory layer](#v020-general-memory-layer-task-483) below.

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

---

## v0.2.0 general-memory layer (task #483)

Benchmark `0.2.0` adds four **general-memory** situations (11–14) — a broader,
mechanism-neutral layer that grades *any* memory system on recall/precision goals
rather than on push internals (see the
[README scope note](../README.md#general-memory-cases-situations-1114)). All five
engines above were re-run on these **10 new cases** on the same fully-local footing
(`bge-m3` embeddings, `qwen2.5:7b` LLM, Ollama). Runner:

```bash
php runner/run.php --engine=push \
  --situation=long_session_recall,cross_session_recall,scattered_facts,temporal_relevance \
  --out=results/<engine>-v0.2.0.json
```

### Push core vs general-memory, side by side

| engine | v0.1.0 (push, 11 cases) | v0.2.0 general (10 cases) |
|--------|:-----------------------:|:-------------------------:|
| baseline (vanilla, no memory) | 1/11 | **0/10** |
| rag-ollama (generic vector-RAG) | 5/11 | **2/10** |
| LangChain (FAISS + bge-m3) | 5/11 | **2/10** |
| mem0 2.0.11 (Chroma + Ollama) | 6/11 | **1/10** |
| Zep / Graphiti 0.29.2 (Neo4j + Ollama) | 1/11 | **0/10** |
| reference (dejavu) | 11/11 | **10/10** |

### Per-case scoreboard (10 general-memory cases)

The 10 cases: **11** long-session recall · **12a** cross-session basic recall ·
**12b** habituation resets per session · **12c** role updated in a later session ·
**12d** multi-session history aggregate · **13a** scattered progressive profile ·
**13b** scattered contradiction · **14a** temporal job-change · **14b** schedule
supersede · **14c** latest-value-wins.

| engine | score | pass | 11 | 12a | 12b | 12c | 12d | 13a | 13b | 14a | 14b | 14c |
|--------|:-----:|:----:|:--:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| baseline (no memory)        | **0.000** | 0/10 | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ |
| **Zep / Graphiti** 0.29.2   | **0.000** | 0/10 | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ |
| mem0 2.0.11                 | **0.100** | 1/10 | ✗ | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ |
| rag-ollama                  | **0.200** | 2/10 | ✗ | ✓ | ~ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ |
| LangChain (FAISS)           | **0.200** | 2/10 | ✗ | ✓ | ~ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ |
| reference (dejavu)          | **1.000** | 10/10 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |

`~` = the case passes but by **threshold luck**, not by a mechanism: turn 2 of the
habituation-reset case ("*actually, another coffee suggestion?*") happened to score
below τ and stay silent — the same artifact as case 07 in the push core, not real
per-session suppression (mem0, with a slightly higher normalized score, re-pushed and
failed it honestly).

### Why the RAG libraries score *lower* here than on the push core

The general-memory layer is billed as mechanism-neutral and RAG-friendly — yet
rag/LangChain drop from 5/11 to 2/10 and mem0 from 6/11 to 1/10. That is not a
contradiction; it is the **case mix**. Of the 10 general-memory cases, **6 require
supersede** (12c role-update, 13b contradiction, 14a/b/c temporal — an older value
must be silently retired when a newer one lands) and **2 require aggregating on a
contentless meta-cue** ("*what do you know about me?*" / "*summarize everything about
me*"). Only **12a basic recall** is pure RAG home turf, and **12b** is the lucky one.

- **Supersede (6 cases) — structural RAG miss.** A pull store returns *both* the old
  and new value ranked by similarity: on "*where do I work now?*" every RAG engine
  pushed `[tp-job-new, tp-job-old]` and failed the `reject_facts` on the stale one.
  Same for the June/August release, the 80/76 kg weight, the QA→backend role, the
  Moscow→Piter correction. RAG has no "the newer observation retires the older" — it
  ranks by distance, and both readings are equally close to the query. This is the
  exact push-core case 04 (STM/supersede) failure, now spread across six cases.
- **Meta-cue aggregation (13a, 12d) — no anchor to match.** "*what do you know about
  me?*" carries no keyword and no strong embedding signal, so the thresholded retriever
  returns **nothing** — it cannot connect three scattered facts under one contentless
  question. (Note: on the *stating* turns RAG over-fires on the user's own statements —
  e.g. mem0 pushed all three facts while the user was still introducing them — a false
  positive a push engine avoids; those turns are ungraded, so it costs nothing here, but
  it is the mirror image of the aggregation miss.)
- **Long-session (11) — recall *and* precision both bite.** The name query fell under τ
  (recall miss at distance); the city query bled the look-alike distractor
  `ls-colleague-city` (teammate in Moscow) next to the user's own Piter. A short-window
  or naive-similarity store fails one or the other; here it failed both.

**mem0 loses its one edge.** In the push core mem0's +1 over vanilla RAG was entirely
metadata filtering on `domain`/`project` (cases 05/08). The general-memory cases carry
**no** domain/project context, so that filter is inert — mem0 collapses to plain
recall (1/10) and actually lands *below* rag/LangChain because it did not catch the
lucky threshold pass on 12b.

### Zep / Graphiti on temporal: 0/3 on its home turf

The most anticipated result — Graphiti's temporal knowledge graph *is* built for
supersede-by-time (14a/b/c) — and it scored **0/10 overall, 0/3 on temporal**. The
mechanism never fired, for the reason already diagnosed in the push core: **extraction
collapses upstream of the graph.** On the local 7B, short factual statements yield
**zero edges** — "*The user works at Yandex.*", "*The user drinks an oat-milk latte.*",
and the supersede-pair updates ("*Later update: now works at Google.*", "*release moved
to August.*", "*weighs 76 kg.*") all produced no retrievable edge, so the query returned
silence and missed the expected new value. The temporal-invalidation logic — the one
mechanism here no other library has — cannot invalidate an edge that was never created.

- **11 long-session — extraction bias, no gate, no habituation.** Only the three
  *richest* statements extracted edges (`ls-stack` "PHP/Yii2", `ls-colleague-city`
  "teammate Dmitry in Moscow", `ls-legacy-stack` "legacy Python service"); the short
  ones (name, city, hobby, coffee) never entered the graph. Worse, hybrid search then
  returned that **same trio on every single turn** — including the 30 neutral
  "ok"/"thanks" turns — showing no relevance discrimination and no session habituation.
  On the graded queries it missed name/city/hobby/coffee (not extracted) and on the
  stack query it bled the Python distractor (`reject_facts` fail).
- **12a basic recall — the floor, failed on extraction.** "*The user works at Yandex.*"
  → 0 edges → silent. Graphiti fails the single most basic recall that every RAG engine
  clears trivially, because RAG embeds the raw statement with no extraction step to fail.

This is the v0.1.0 finding, sharpened: the temporal graph is architecturally the closest
design to dejavu's push model and owns the exact mechanism the general-memory temporal
cases probe — but behind a commodity-7B extractor it never gets a clean fact set, so it
sits at **baseline (0/10)**, *below* a plain vector store (2/10). A frontier extraction
model would very likely surface the edges and let the temporal logic win 14a/b/c; on the
same fair local footing as every other adapter, it does not.

### Takeaway

The general-memory layer does **not** rescue pull-RAG — it exposes the same wall from a
different angle. Where the push core needed a symbolic gate for graph/STM/staleness/
habituation, the general-memory cases need it for **supersede-by-time** (6 of 10) and
**precise meta-cue aggregation** (2 of 10) — mechanisms a similarity retriever still
lacks. RAG clears exactly the recall case it was built for (12a) and nothing that needs
retiring a stale value or connecting a contentless question. The dejavu reference clears
all 10 with the same cue/gate/supersede machinery it uses on the push core — the two
layers are the same mechanism gap measured on two different case families.
