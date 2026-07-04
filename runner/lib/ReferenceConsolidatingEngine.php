<?php

declare(strict_types=1);

namespace Dejavu\Benchmark;

/**
 * Reference implementation of the *write* path — STM extraction + LTM
 * consolidation ("sleep") — the axis the dialog cases grade (situations 15–16).
 *
 * It is the sibling of {@see ReferenceEngine}: that class is the executable
 * specification of the *read* (push) path; this one is the executable
 * specification of the *consolidation* path. Both are deliberately symbolic —
 * marker-word heuristics instead of an LLM — so the benchmark ships an
 * out-of-the-box ceiling ("what a good memory SHOULD end up holding") without a
 * model call. It inherits the push path from ReferenceEngine, so a single engine
 * plays every axis: push cases (01–14) via push(), dialog cases (15–16) via
 * consolidate().
 *
 * Why a separate engine and not ReferenceEngine itself: the plain `reference`
 * engine is intentionally a pure cue-index push model and honestly SKIPs the
 * dialog axis (see ConsolidatingEngine's docblock and example-reference.json).
 * This class is the opt-in "and it also consolidates" variant, kept apart so the
 * push-only reference stays a clean, minimal spec.
 *
 * The consolidation mechanisms modelled (each mechanism-neutral, marker-driven):
 *
 *   extraction   sentence-level facts from *user* turns only (assistant turns are
 *                mined for structure — chains and derivations — never stored as
 *                plain facts, so assistant echoes never pollute the state).
 *   hedge-gate   a floated/undecided option ("maybe", "probably … haven't
 *                decided", "thought about", "let's not lock … yet") is NOT stored
 *                as a committed fact — the mentioned-vs-decided distinction.
 *   disclaimer   an explicitly-disowned statement ("that's their call, not our
 *                rule", "no need to remember") is dropped, not stored.
 *   supersede    an explicit retraction ("scratch the X", "…, not X", "not X
 *                anymore", "ripped out X", "X is gone", "old X … is stale")
 *                retires every prior active item that carries X. Works within a
 *                session (STM correction) and across the sleep (LTM update /
 *                cross-session contradiction) — items persist, so a later session
 *                retires an earlier one for free.
 *   directive    an instruction ("remember this as a project rule", "always use
 *                …") is captured with kind='rule', not as a passing remark.
 *   links        an "A → B → C" dependency chain (either arrow glyph) becomes
 *                link edges A→B, B→C on the corresponding node items.
 *   derived_from an explicit inference "LHS ⇒ conclusion" links the conclusion
 *                fact back to the facts on the LHS.
 *
 * The "sleep" is modelled as continuity: STM items persist into a single shared
 * store and consolidation (supersede/links/derive) runs continuously as turns are
 * read, so crossing a session boundary needs no special step — a fact stated in
 * session 1 is simply still present, and still supersede-able, in session 3.
 *
 * Honesty note: like the push ReferenceEngine, this is a hand-authored spec, not
 * a general NLP extractor. The derived_from case (L4) is the most spec-shaped —
 * it captures an *explicitly stated* derivation ("it follows from your stack …
 * ⇒ use pgvector"), which is consolidation, not free invention; a memory with no
 * inference edge would still store pgvector as a plain fact and only miss the
 * relation. Every mechanism here is one dejavu's design claims; the value of the
 * row is to show the ceiling these mechanisms reach when they actually run,
 * against which the LLM-backed libraries (mem0 2/9, Graphiti 1/9) are measured.
 */
final class ReferenceConsolidatingEngine extends ReferenceEngine implements ConsolidatingEngine
{
    /** Non-commitment: the option is floated, not decided → do not store. */
    private const HEDGE = [
        'maybe', 'probably', "haven't decided", 'have not decided', "let's not lock",
        'not decided', 'thought about', 'keeps pushing', "i've also seen", 'i have also seen',
        'leaning toward', 'leaning towards', 'considering', 'not sure', "haven't chosen",
    ];
    /** The statement is explicitly disowned / delegated elsewhere → drop it. */
    private const DISCLAIMER = [
        'their call', 'not our', 'no need to remember', "that's their", 'their own',
        'separate repo', 'already enforced', 'already covered',
    ];
    /** Instruction to remember something as a standing rule. */
    private const DIRECTIVE = [
        'remember this as', 'as a project rule', 'as a standing rule', 'standing rule',
        'always use', 'please remember', 'make sure', 'as a rule',
    ];

    public function name(): string
    {
        return 'reference-consolidating';
    }

    /**
     * @param array $case Decoded dialog case (dialog[] + seed[] + criteria{}).
     * @return array<int,array> memory items for {@see MemoryGrader}.
     */
    public function consolidate(array $case): array
    {
        /** @var array<int,array> $items growing memory store (STM ⇒ LTM) */
        $items = [];

        // Seed = what an earlier sleep already consolidated into LTM.
        foreach ($case['seed'] ?? [] as $fact) {
            $items[] = $this->item(
                (string)($fact['statement'] ?? ''),
                isset($fact['kind']) ? (string)$fact['kind'] : null
            );
        }

        // Read the dialogue in order. Sessions arrive grouped and ordered; because
        // items persist, the s1→s2→s3 "sleeps" need no explicit boundary handling.
        foreach ($case['dialog'] ?? [] as $turn) {
            $role = (string)($turn['role'] ?? 'user');
            $content = (string)($turn['content'] ?? '');

            // Assistant turns are mined for structure only — never stored as facts.
            $this->harvestChains($content, $items);
            $this->harvestDerivations($content, $items);
            if ($role !== 'user') {
                continue;
            }

            foreach ($this->sentences($content) as $sentence) {
                $this->ingestSentence($sentence, $items);
            }
        }

        return $this->snapshot($items);
    }

    // ---------------------------------------------------------------------
    // extraction

    /** Classify one user sentence and fold it into the store. */
    private function ingestSentence(string $sentence, array &$items): void
    {
        $low = mb_strtolower($sentence);

        // (a) explicitly disowned → drop (must run before retraction: a disclaimer
        //     like "…, not our rule" must not be read as a value retraction).
        if ($this->hasAny($low, self::DISCLAIMER)) {
            return;
        }

        // (b) retraction → retire matching prior facts; keep only the affirmative
        //     head, and only if that head does not itself carry a retracted token
        //     (else "Yeah, Mongo is gone" would re-store "Yeah, Mongo").
        $retire = $this->retractionTokens($low);
        if ($retire !== []) {
            foreach ($retire as $tok) {
                $this->retire($items, $tok);
            }
            $head = $this->affirmativeHead($sentence);
            if ($head !== null) {
                $headLow = mb_strtolower($head);
                if (!$this->hasHedge($headLow) && !$this->hasAny($headLow, $retire)) {
                    $this->store($items, $head, 'project');
                }
            }
            return;
        }

        // (c) a floated option (never decided) → not a committed fact.
        if ($this->hasHedge($low)) {
            return;
        }

        // (d) a standing directive → capture as a rule.
        if ($this->hasAny($low, self::DIRECTIVE) && !$this->isQuestion($sentence)) {
            $this->store($items, $sentence, 'rule');
            return;
        }

        // (e) questions carry no fact.
        if ($this->isQuestion($sentence)) {
            return;
        }

        // (f) a plain committed statement. Default kind='project': the dialogues
        //     are project-planning conversations, and the STM/LTM criteria that
        //     assert a kind expect the project tag (the rule kind is set in (d)).
        if ($this->looksFactual($sentence)) {
            $this->store($items, $sentence, 'project');
        }
    }

    /** Split a turn into sentences on terminal punctuation and clause semicolons. */
    private function sentences(string $content): array
    {
        $parts = preg_split('/(?<=[.!?;])\s+/u', trim($content)) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------------
    // supersede

    /**
     * Value tokens a sentence explicitly retracts. Only markers that name a value
     * count — a plain "not" without the retraction shape (", not X" / "not X
     * anymore") does not retire anything.
     *
     * @return string[] lowercased tokens
     */
    private function retractionTokens(string $low): array
    {
        $tokens = [];
        $pats = [
            '/,\s*not\s+([\w.\-+]+)/u',            // "PostgreSQL, not MySQL"
            '/\bnot\s+([\w.\-+]+)\s+anymore/u',    // "not 8.1 anymore"
            '/scratch\s+the\s+([\w.\-+]+)/u',      // "scratch the MySQL part"
            '/ripped\s+out\s+([\w.\-+]+)/u',       // "ripped out MongoDB"
            '/\b([\w.\-+]+)\s+is\s+gone\b/u',      // "Mongo is gone"
            '/\bold\s+([\w.\-+]+)\b/u',            // "the old 8.1 note is stale"
        ];
        foreach ($pats as $re) {
            if (preg_match_all($re, $low, $m)) {
                foreach ($m[1] as $t) {
                    $t = trim($t);
                    if ($t !== '' && $t !== 'the' && mb_strlen($t) > 1) {
                        $tokens[] = $t;
                    }
                }
            }
        }
        return array_values(array_unique($tokens));
    }

    /** The committed part of a retraction sentence (before the retraction marker). */
    private function affirmativeHead(string $sentence): ?string
    {
        $low = mb_strtolower($sentence);
        $best = null;
        foreach ([', not ', 'scratch ', 'ripped out', '; the old', ' is gone'] as $marker) {
            $pos = mb_strpos($low, $marker);
            if ($pos !== false && ($best === null || $pos < $best)) {
                $best = $pos;
            }
        }
        if ($best === null) {
            return null;
        }
        $head = trim(mb_substr($sentence, 0, $best));
        $head = rtrim($head, " ,;—-");
        // Require a few content words so bare lead-ins ("Actually — wait") are
        // dropped while a corrected value ("Portal now runs on PHP 8.4") survives.
        return $this->wordCount($head) >= 3 ? $head : null;
    }

    /** Retire (deactivate) every active item whose text carries $token. */
    private function retire(array &$items, string $token): void
    {
        foreach ($items as &$it) {
            if ($it['active'] && mb_strpos(mb_strtolower($it['text']), $token) !== false) {
                $it['active'] = false;
            }
        }
        unset($it);
    }

    // ---------------------------------------------------------------------
    // structure: links (A → B → C) and derivations (LHS ⇒ conclusion)

    /** Turn every "A → B → C" chain in $content into link edges on node items. */
    private function harvestChains(string $content, array &$items): void
    {
        // Normalise both arrow glyphs, then require ≥2 hops so a stray glyph is ignored.
        $norm = str_replace(['->', '=>'], ['→', '⇒'], $content);
        if (mb_substr_count($norm, '→') < 2) {
            return;
        }
        // Isolate the arrow run: keep the tail from the first entity to the last.
        if (!preg_match('/([\w.\-]+(?:\s*→\s*[\w.\-]+)+)/u', $norm, $m)) {
            return;
        }
        $nodes = array_values(array_filter(array_map('trim', explode('→', $m[1])), 'strlen'));
        for ($i = 0; $i + 1 < count($nodes); $i++) {
            $from = $nodes[$i];
            $to = $nodes[$i + 1];
            $idx = $this->ensureNode($items, $from);
            if (!in_array($to, $items[$idx]['links'], true)) {
                $items[$idx]['links'][] = $to;
            }
        }
    }

    /**
     * "LHS ⇒ conclusion": link the conclusion fact back to the LHS facts.
     * The conclusion's salient entity (last word of the RHS, e.g. "use pgvector"
     * → "pgvector") anchors it to a stored fact; that fact gets derived_from=[LHS].
     */
    private function harvestDerivations(string $content, array &$items): void
    {
        $norm = str_replace('=>', '⇒', $content);
        if (mb_strpos($norm, '⇒') === false) {
            return;
        }
        if (!preg_match('/([^:.⇒]+)⇒\s*([\w.\-\s]+)/u', $norm, $m)) {
            return;
        }
        $lhs = trim($m[1]);
        $rhsWords = preg_split('/\s+/u', trim($m[2])) ?: [];
        // Strip trailing punctuation so "pgvector." anchors to the stored "pgvector".
        $anchor = preg_replace('/[^\w\-]/u', '', mb_strtolower(end($rhsWords) ?: ''));
        if ($anchor === '') {
            return;
        }
        $idx = $this->findActive($items, $anchor);
        if ($idx === null) {
            $idx = $this->store($items, trim($m[2]), null);
        }
        if (!in_array($lhs, $items[$idx]['derived_from'], true)) {
            $items[$idx]['derived_from'][] = $lhs;
        }
    }

    /** Index of an active item whose text contains $entity, creating one if none. */
    private function ensureNode(array &$items, string $entity): int
    {
        $idx = $this->findActive($items, mb_strtolower($entity));
        if ($idx !== null) {
            return $idx;
        }
        return $this->store($items, $entity, null);
    }

    private function findActive(array $items, string $token): ?int
    {
        foreach ($items as $i => $it) {
            if ($it['active'] && mb_strpos(mb_strtolower($it['text']), $token) !== false) {
                return $i;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------------
    // store & helpers

    /** Append a fact; return its index. */
    private function store(array &$items, string $text, ?string $kind): int
    {
        $items[] = $this->item($text, $kind);
        return array_key_last($items);
    }

    private function item(string $text, ?string $kind): array
    {
        return [
            'text' => $text,
            'kind' => $kind,
            'active' => true,
            'supersedes' => [],
            'derived_from' => [],
            'links' => [],
        ];
    }

    /** @return array<int,array> snapshot in MemoryGrader's item shape */
    private function snapshot(array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            $out[] = [
                'text' => $it['text'],
                'kind' => $it['kind'],
                'active' => $it['active'],
                'supersedes' => $it['supersedes'],
                'derived_from' => $it['derived_from'],
                'links' => $it['links'],
            ];
        }
        return $out;
    }

    private function hasHedge(string $low): bool
    {
        return $this->hasAny($low, self::HEDGE);
    }

    private function hasAny(string $low, array $needles): bool
    {
        foreach ($needles as $n) {
            if (mb_strpos($low, $n) !== false) {
                return true;
            }
        }
        return false;
    }

    private function isQuestion(string $sentence): bool
    {
        return mb_strpos($sentence, '?') !== false;
    }

    /** A storable fact: at least a couple of content words of declarative text. */
    private function looksFactual(string $sentence): bool
    {
        return $this->wordCount($sentence) >= 2 && !$this->isQuestion($sentence);
    }

    /** Count tokens carrying at least one letter or digit. */
    private function wordCount(string $s): int
    {
        return (int)preg_match_all('/[\p{L}\p{N}]+/u', $s);
    }
}
