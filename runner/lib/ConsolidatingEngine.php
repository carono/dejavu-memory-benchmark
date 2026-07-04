<?php

declare(strict_types=1);

namespace Dejavu\Benchmark;

/**
 * Optional capability for engines that model STM extraction and LTM
 * consolidation ("sleep") over a raw dialogue — the axis exercised by the
 * dialog-format cases (situations 15+, see cases/README.md).
 *
 * This is a *different axis* from {@see EngineInterface}. EngineInterface grades
 * the push path ("what did the channel deliver this turn?"). A ConsolidatingEngine
 * grades the resulting memory *state* ("what is in memory after extracting facts
 * from the conversation and consolidating across session boundaries?").
 *
 * An engine implements this only if it can turn a free-text conversation into a
 * set of stored facts. The built-in symbolic ReferenceEngine deliberately does
 * NOT — it is a cue-index push model, not an extractor — so dialog cases are
 * reported as skipped for it rather than tuned to pass. A real memory library
 * (LLM-backed extraction/consolidation) plugs in here, or supplies a snapshot
 * file out of process (see run.php --snapshots).
 */
interface ConsolidatingEngine
{
    /**
     * Extract STM facts from the case's dialog, consolidate them into LTM across
     * every session boundary ("sleep"), starting from the case's seed facts, and
     * return the resulting memory snapshot.
     *
     * The snapshot is a mechanism-neutral list of memory items; each item:
     *   [
     *     'text'         => string,   // the stored statement (graded by substring)
     *     'kind'         => ?string,  // user|project|rule|env|note|... (optional)
     *     'active'       => bool,     // true = current view; false = retired/superseded history
     *     'supersedes'   => string[], // texts of facts this item retires (optional)
     *     'derived_from' => string[], // texts of the facts this item was inferred from (optional)
     *     'links'        => string[], // texts of facts this item is linked to (optional)
     *   ]
     * Missing keys default to: kind=null, active=true, and empty arrays.
     *
     * @param array $case Decoded dialog case (dialog[] + seed[] + criteria{}).
     * @return array<int,array> memory items (see above)
     */
    public function consolidate(array $case): array;
}
