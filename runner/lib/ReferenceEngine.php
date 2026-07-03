<?php

declare(strict_types=1);

namespace Dejavu\Benchmark;

/**
 * A minimal, dependency-free reference implementation of the dejavu read path
 * (layers L0–L3) — enough to grade the benchmark out of the box and to serve as
 * an executable specification of the expected behaviour.
 *
 * It is deliberately simple: substring/word cue matching instead of embeddings,
 * one-hop spreading instead of a full graph walk. A real engine (the live
 * dejavu-push.php hook) is wired through {@see DejavuPushEngine}; it should meet
 * or beat these results, never fall below them.
 *
 * Layer map (see dejavu-spec/spec/03-activation-layers.md):
 *   L0  cue extraction    lowercase the prompt
 *   L1  activation        direct cue match (=1.0) + one-hop spreading over links
 *   L2  salience-gate     stale/domain/project filter · supersede · habituation · budget
 *   L3  delivery          return the surviving slugs, ranked
 */
final class ReferenceEngine implements EngineInterface
{
    /** Max facts delivered per turn (hard budget, spec: start 3–5). */
    public const BUDGET = 3;
    /** Activation decay across one fact_links edge. */
    public const GRAPH_DECAY = 0.5;
    /** Minimum spread activation for a neighbour to be considered. */
    public const GRAPH_THRESHOLD = 0.3;
    /** A habituated fact must re-fire at least this much stronger to surface again. */
    public const HABITUATION_FACTOR = 1.5;

    private const DEFAULT_SALIENCE = 0.5;
    private const DEFAULT_STRENGTH = 1.0;
    private const SPREADABLE = ['relates', 'derived_from'];
    private const HIDDEN_STATUS = ['stale', 'archived'];

    /** @var array<string,array> slug => fact */
    private array $facts = [];
    /** @var array<string,float> slug => strongest delivery score already surfaced this session */
    private array $surfaced = [];

    public function name(): string
    {
        return 'reference';
    }

    public function loadCase(array $case): void
    {
        $this->facts = [];
        $this->surfaced = [];
        foreach ($case['seed'] ?? [] as $fact) {
            $this->facts[$fact['slug']] = $fact;
        }
    }

    public function push(array $turn): array
    {
        $prompt = $this->normalize((string)($turn['prompt'] ?? ''));
        $context = $turn['context'] ?? [];

        // --- L1: direct activation ------------------------------------------
        // A direct cue hit fully activates the node (activation = strength).
        // Delivery score (used for ranking/budget/habituation) folds in salience.
        $candidates = [];
        foreach ($this->facts as $slug => $fact) {
            if ($this->isHidden($fact)) {
                continue;
            }
            if ($this->cueHit($prompt, $fact)) {
                $strength = (float)($fact['strength'] ?? self::DEFAULT_STRENGTH);
                $salience = (float)($fact['salience'] ?? self::DEFAULT_SALIENCE);
                $candidates[$slug] = [
                    'activation' => $strength,
                    'score' => $salience * $strength,
                    'direct' => true,
                ];
            }
        }

        // --- L1b: one-hop spreading activation ------------------------------
        foreach (array_keys($candidates) as $slug) {
            if (!$candidates[$slug]['direct']) {
                continue;
            }
            foreach ($this->facts[$slug]['links'] ?? [] as $link) {
                if (!in_array($link['relation'] ?? 'relates', self::SPREADABLE, true)) {
                    continue;
                }
                $to = $link['to'] ?? null;
                if ($to === null || !isset($this->facts[$to]) || isset($candidates[$to])) {
                    continue;
                }
                $neighbour = $this->facts[$to];
                if ($this->isHidden($neighbour)) {
                    continue;
                }
                $spread = $candidates[$slug]['activation'] * (float)($link['weight'] ?? 0.5) * self::GRAPH_DECAY;
                if ($spread < self::GRAPH_THRESHOLD) {
                    continue;
                }
                $salience = (float)($neighbour['salience'] ?? self::DEFAULT_SALIENCE);
                $candidates[$to] = [
                    'activation' => $spread,
                    'score' => $salience * $spread,
                    'direct' => false,
                ];
            }
        }

        // --- L2: salience-gate ----------------------------------------------
        $candidates = $this->filterByContext($candidates, $context);
        $candidates = $this->applySupersede($candidates);
        $candidates = $this->applyHabituation($candidates);

        // rank (score desc, salience desc, slug asc for determinism) and cap.
        $slugs = array_keys($candidates);
        usort($slugs, function (string $a, string $b) use ($candidates): int {
            $byScore = $candidates[$b]['score'] <=> $candidates[$a]['score'];
            if ($byScore !== 0) {
                return $byScore;
            }
            $sa = (float)($this->facts[$a]['salience'] ?? self::DEFAULT_SALIENCE);
            $sb = (float)($this->facts[$b]['salience'] ?? self::DEFAULT_SALIENCE);
            return ($sb <=> $sa) ?: strcmp($a, $b);
        });
        $delivered = array_slice($slugs, 0, self::BUDGET);

        // record what we surfaced, for next-turn habituation.
        foreach ($delivered as $slug) {
            $this->surfaced[$slug] = max($this->surfaced[$slug] ?? 0.0, $candidates[$slug]['score']);
        }

        return $delivered;
    }

    // ---------------------------------------------------------------------

    private function filterByContext(array $candidates, array $context): array
    {
        $domain = $context['domain'] ?? null;
        $project = $context['project'] ?? null;
        foreach ($candidates as $slug => $_) {
            $fact = $this->facts[$slug];
            if ($domain !== null && !empty($fact['domain']) && $fact['domain'] !== $domain) {
                unset($candidates[$slug]);
                continue;
            }
            if ($project !== null && !empty($fact['source_project']) && $fact['source_project'] !== $project) {
                unset($candidates[$slug]);
            }
        }
        return $candidates;
    }

    /** A surviving fact that supersedes another evicts it (STM correction / update). */
    private function applySupersede(array $candidates): array
    {
        $evicted = [];
        foreach (array_keys($candidates) as $slug) {
            foreach ($this->facts[$slug]['supersedes'] ?? [] as $old) {
                $evicted[$old] = true;
            }
        }
        foreach (array_keys($evicted) as $old) {
            unset($candidates[$old]);
        }
        return $candidates;
    }

    /** Suppress facts already surfaced this session unless materially stronger now. */
    private function applyHabituation(array $candidates): array
    {
        foreach ($candidates as $slug => $cand) {
            if (!isset($this->surfaced[$slug])) {
                continue;
            }
            if ($cand['score'] < self::HABITUATION_FACTOR * $this->surfaced[$slug]) {
                unset($candidates[$slug]);
            }
        }
        return $candidates;
    }

    private function isHidden(array $fact): bool
    {
        return in_array($fact['status'] ?? 'confirmed', self::HIDDEN_STATUS, true);
    }

    private function cueHit(string $prompt, array $fact): bool
    {
        foreach ($fact['cues'] ?? [] as $cue) {
            $value = $this->normalize((string)($cue['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $mode = $cue['match'] ?? 'substring';
            $hit = false;
            switch ($mode) {
                case 'exact':
                    $hit = (bool)preg_match('/\b' . preg_quote($value, '/') . '\b/u', $prompt);
                    break;
                case 'prefix':
                    $hit = (bool)preg_match('/\b' . preg_quote($value, '/') . '/u', $prompt);
                    break;
                case 'regex':
                    $hit = (bool)@preg_match('/' . $value . '/u', $prompt);
                    break;
                case 'substring':
                default:
                    $hit = mb_strpos($prompt, $value) !== false;
                    break;
            }
            if ($hit) {
                return true;
            }
        }
        return false;
    }

    private function normalize(string $s): string
    {
        return mb_strtolower(trim($s), 'UTF-8');
    }
}
