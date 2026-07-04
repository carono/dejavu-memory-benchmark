<?php

declare(strict_types=1);

namespace Dejavu\Benchmark;

/**
 * Grades a *memory snapshot* (post extraction/consolidation) against a
 * dialog case's `criteria`. This is the STM/LTM axis — it asks what ended up in
 * memory, not what was pushed on any single turn.
 *
 * Criteria (all mechanism-neutral, matched by lowercase substring):
 *
 *   must_remember[]      an active item must match  ({all_of?, any_of?, kind?})
 *   must_not_remember[]  no active item may match   ({all_of?, any_of?})
 *   relations[]          structural edges in the consolidated memory:
 *     { kind: "links",        from_(all|any)_of, to_(all|any)_of }
 *     { kind: "derived_from", conclusion_(all|any)_of, from_(all|any)_of }
 *
 * `must_not_remember` inspects only the *active* view: a retired/superseded item
 * kept as inactive history does not count as "remembered". The result mirrors the
 * push-path result shape (id/situation/passed/turns) so run.php prints both the
 * same way; each criterion becomes one pseudo-turn.
 */
final class MemoryGrader
{
    /**
     * @param array $case     Decoded dialog case.
     * @param array $snapshot Memory items produced by consolidation.
     * @return array case result: {id, situation, passed, skipped:false, turns:[...]}
     */
    public static function gradeCase(array $case, array $snapshot): array
    {
        $items = self::normalizeItems($snapshot);
        $criteria = $case['criteria'] ?? [];
        $checks = [];

        foreach ($criteria['must_remember'] ?? [] as $spec) {
            [$hit] = self::findMatch($items, $spec, true);
            $checks[] = self::check(
                'remember: ' . self::label($spec),
                $hit !== null,
                $hit !== null ? [] : ['no active memory item matches ' . self::specText($spec)]
            );
        }

        foreach ($criteria['must_not_remember'] ?? [] as $spec) {
            [$hit] = self::findMatch($items, $spec, true);
            $checks[] = self::check(
                'forget: ' . self::label($spec),
                $hit === null,
                $hit === null ? [] : ["active item still holds it: \"{$hit['text']}\""]
            );
        }

        foreach ($criteria['relations'] ?? [] as $rel) {
            [$ok, $detail] = self::checkRelation($items, $rel);
            $checks[] = self::check(
                ($rel['kind'] ?? 'relation') . ': ' . self::label($rel),
                $ok,
                $ok ? [] : [$detail]
            );
        }

        $turns = [];
        $allPassed = true;
        foreach ($checks as $i => $c) {
            $allPassed = $allPassed && $c['passed'];
            $turns[] = [
                'index' => $i,
                'prompt' => $c['prompt'],
                'pushed' => $c['pushed'],
                'passed' => $c['passed'],
                'failures' => $c['failures'],
            ];
        }

        return [
            'id' => $case['id'],
            'situation' => $case['situation'] ?? 'unknown',
            'passed' => $allPassed,
            'skipped' => false,
            'turns' => $turns,
        ];
    }

    // -----------------------------------------------------------------------

    /**
     * Find the first item satisfying a match spec (all_of/any_of/kind).
     *
     * @return array{0:?array} matched item or null (wrapped for list() ergonomics)
     */
    private static function findMatch(array $items, array $spec, bool $activeOnly): array
    {
        $allOf = self::tokens($spec['all_of'] ?? []);
        $anyOf = self::tokens($spec['any_of'] ?? []);
        $kind = isset($spec['kind']) ? mb_strtolower((string)$spec['kind']) : null;

        foreach ($items as $item) {
            if ($activeOnly && !$item['active']) {
                continue;
            }
            if ($kind !== null && mb_strtolower((string)($item['kind'] ?? '')) !== $kind) {
                continue;
            }
            if (self::textMatches($item['text'], $allOf, $anyOf)) {
                return [$item];
            }
        }
        return [null];
    }

    /** @return array{0:bool,1:string} verdict + failure detail */
    private static function checkRelation(array $items, array $rel): array
    {
        $kind = $rel['kind'] ?? '';

        if ($kind === 'links') {
            $from = self::findMatch($items, [
                'all_of' => $rel['from_all_of'] ?? [],
                'any_of' => $rel['from_any_of'] ?? [],
            ], false);
            $src = $from[0];
            if ($src === null) {
                return [false, 'no source item matches ' . self::joinTokens($rel, 'from')];
            }
            $edgeText = self::joinList($src['links']);
            if (self::textMatches($edgeText, self::tokens($rel['to_all_of'] ?? []), self::tokens($rel['to_any_of'] ?? []))) {
                return [true, ''];
            }
            return [false, "\"{$src['text']}\" has no link to " . self::joinTokens($rel, 'to')
                . ($edgeText === '' ? ' (item exposes no links)' : " (links: {$edgeText})")];
        }

        if ($kind === 'derived_from') {
            $c = self::findMatch($items, [
                'all_of' => $rel['conclusion_all_of'] ?? [],
                'any_of' => $rel['conclusion_any_of'] ?? [],
            ], true);
            $concl = $c[0];
            if ($concl === null) {
                return [false, 'no active item matches conclusion ' . self::joinTokens($rel, 'conclusion')];
            }
            $srcText = self::joinList($concl['derived_from']);
            if (self::textMatches($srcText, self::tokens($rel['from_all_of'] ?? []), self::tokens($rel['from_any_of'] ?? []))) {
                return [true, ''];
            }
            return [false, "\"{$concl['text']}\" is not derived from " . self::joinTokens($rel, 'from')
                . ($srcText === '' ? ' (item exposes no derived_from)' : " (derived_from: {$srcText})")];
        }

        return [false, "unknown relation kind '{$kind}'"];
    }

    private static function textMatches(string $text, array $allOf, array $anyOf): bool
    {
        $hay = mb_strtolower($text);
        foreach ($allOf as $t) {
            if ($t !== '' && mb_strpos($hay, $t) === false) {
                return false;
            }
        }
        if ($anyOf !== []) {
            foreach ($anyOf as $t) {
                if ($t !== '' && mb_strpos($hay, $t) !== false) {
                    return true;
                }
            }
            return false;
        }
        // No any_of constraint: pass iff all_of held (and there was at least one constraint).
        return $allOf !== [];
    }

    /** @return array<int,array{text:string,kind:?string,active:bool,supersedes:string[],derived_from:string[],links:string[]}> */
    private static function normalizeItems(array $snapshot): array
    {
        $out = [];
        foreach ($snapshot as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $out[] = [
                'text' => (string)($raw['text'] ?? $raw['statement'] ?? ''),
                'kind' => isset($raw['kind']) ? (string)$raw['kind'] : null,
                'active' => array_key_exists('active', $raw) ? (bool)$raw['active'] : true,
                'supersedes' => self::asStrings($raw['supersedes'] ?? []),
                'derived_from' => self::asStrings($raw['derived_from'] ?? []),
                'links' => self::asStrings($raw['links'] ?? []),
            ];
        }
        return $out;
    }

    /** @return string[] */
    private static function asStrings($v): array
    {
        if (!is_array($v)) {
            return [];
        }
        return array_values(array_map(fn($x) => (string)$x, $v));
    }

    /** @return string[] lowercased, trimmed, non-empty */
    private static function tokens($v): array
    {
        $v = is_array($v) ? $v : [$v];
        $out = [];
        foreach ($v as $t) {
            $t = mb_strtolower(trim((string)$t));
            if ($t !== '') {
                $out[] = $t;
            }
        }
        return $out;
    }

    private static function joinList(array $texts): string
    {
        return implode(' | ', $texts);
    }

    private static function joinTokens(array $rel, string $prefix): string
    {
        $all = $rel[$prefix . '_all_of'] ?? [];
        $any = $rel[$prefix . '_any_of'] ?? [];
        $all = is_array($all) ? $all : [$all];
        $any = is_array($any) ? $any : [$any];
        return '[' . implode(', ', array_merge($all, $any)) . ']';
    }

    private static function specText(array $spec): string
    {
        $parts = [];
        if (!empty($spec['all_of'])) {
            $parts[] = 'all[' . implode(', ', (array)$spec['all_of']) . ']';
        }
        if (!empty($spec['any_of'])) {
            $parts[] = 'any[' . implode(', ', (array)$spec['any_of']) . ']';
        }
        if (!empty($spec['kind'])) {
            $parts[] = "kind={$spec['kind']}";
        }
        return implode(' ', $parts) ?: '(no constraints)';
    }

    private static function label(array $spec): string
    {
        return (string)($spec['desc'] ?? $spec['id'] ?? self::specText($spec));
    }

    /** @return array{prompt:string,pushed:string[],passed:bool,failures:string[]} */
    private static function check(string $prompt, bool $passed, array $failures): array
    {
        return ['prompt' => $prompt, 'pushed' => [], 'passed' => $passed, 'failures' => $failures];
    }
}
