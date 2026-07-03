<?php

declare(strict_types=1);

namespace Dejavu\Benchmark;

/**
 * Grades the pushed set of one turn against its assertions, and rolls turn
 * verdicts up into a case verdict. Assertions are ANDed; see cases/README.md.
 */
final class Grader
{
    /**
     * @param string[] $pushed slugs the engine delivered this turn
     * @return array{passed:bool,failures:string[]}
     */
    public static function gradeTurn(array $turn, array $pushed): array
    {
        $failures = [];
        $pushedSet = array_fill_keys($pushed, true);

        if (!empty($turn['expect_empty'])) {
            if ($pushed !== []) {
                $failures[] = 'expected nothing, got [' . implode(', ', $pushed) . ']';
            }
        }

        foreach ($turn['expect_facts'] ?? [] as $slug) {
            if (!isset($pushedSet[$slug])) {
                $failures[] = "missing expected fact '{$slug}'";
            }
        }

        foreach ($turn['reject_facts'] ?? [] as $slug) {
            if (isset($pushedSet[$slug])) {
                $failures[] = "rejected fact '{$slug}' was pushed";
            }
        }

        foreach ($turn['expect_suppressed'] ?? [] as $slug) {
            if (isset($pushedSet[$slug])) {
                $failures[] = "fact '{$slug}' should be habituated but was pushed";
            }
        }

        return ['passed' => $failures === [], 'failures' => $failures];
    }

    /**
     * Run a whole case through an engine and grade every turn.
     *
     * @return array case result: {id, situation, passed, turns:[...]}
     */
    public static function gradeCase(EngineInterface $engine, array $case): array
    {
        $engine->loadCase($case);
        $turns = [];
        $allPassed = true;
        foreach ($case['turns'] ?? [] as $i => $turn) {
            $pushed = $engine->push($turn);
            $verdict = self::gradeTurn($turn, $pushed);
            $allPassed = $allPassed && $verdict['passed'];
            $turns[] = [
                'index' => $i,
                'prompt' => $turn['prompt'] ?? '',
                'pushed' => $pushed,
                'passed' => $verdict['passed'],
                'failures' => $verdict['failures'],
            ];
        }
        return [
            'id' => $case['id'],
            'situation' => $case['situation'] ?? 'unknown',
            'passed' => $allPassed,
            'turns' => $turns,
        ];
    }
}
