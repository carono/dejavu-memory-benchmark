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
| rag-ollama (generic vector-RAG) | **0.455** | 5/11 | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ~ | ✗ | ✓ | ✗ |
| LangChain (FAISS + bge-m3) | **0.455** | 5/11 | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ~ | ✗ | ✓ | ✗ |
| **mem0** 2.0.11 (Chroma + Ollama) | **0.545** | 6/11 | ✓ | ✓ | ✗ | ✗ | ✓ | ✗ | ✗ | ✓ | ✓ | ✗ |
| reference (dejavu) | **1.000** | 11/11 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |

Situations: 01 keyword · 02 semantic recall · 03 graph spreading · 04 STM-vs-LTM ·
05 domain conflict · 06 staleness · 07 habituation · 08 multi-project · 09 negative ·
10 personal meta-cues. `~` = passed by threshold luck, not by a real mechanism (see 07).

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

## Libraries evaluated but not run, with reasoning

| library | status | expected profile |
|---------|--------|------------------|
| **Memoripy** | represented by `rag-ollama` | Its core is embedding store + cosine retrieval with a decay/reinforcement score. It has a recency/decay knob but no domain gate, no graph, no session habituation → the vanilla-RAG profile (~5/11). Adapting it needs OpenAI-shaped clients; the generic RAG shim is a faithful stand-in. |
| **Letta / MemGPT** | not run — needs a server | Requires a running Letta server + Postgres and models its memory as an *agent loop* (the LLM decides when to page core/archival memory in). It does not expose a per-turn push read-path; grading it means driving a full agent, out of scope for the store-agnostic protocol. Its archival memory is vector-RAG → same 03/06/07 failures; its editable *core memory* could help 04 but only via LLM tool-calls. |
| **Zep** | not run — needs a server / cloud key | Zep CE needs its own Docker service (Postgres + NLP worker) or a Zep Cloud API key. Its temporal knowledge graph (Graphiti) is the one design here that *could* touch 03/04/06 (edges, fact invalidation with `valid_at`/`invalid_at`). Worth a follow-up if the server is stood up; would likely beat mem0 on 04/06 but still lacks session habituation (07). |

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
