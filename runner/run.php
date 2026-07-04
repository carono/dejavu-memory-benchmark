<?php

declare(strict_types=1);

/**
 * dejavu-memory-benchmark runner.
 *
 * Loads every case under cases/, runs it through the selected engine, grades the
 * pushed facts against the assertions, and prints a summary. With --out it also
 * writes a leaderboard-submittable JSON result document.
 *
 * Usage:
 *   php runner/run.php [options]
 *
 * Options:
 *   --engine=reference|push   engine under test (default: reference)
 *   --cases=DIR               cases directory (default: <repo>/cases)
 *   --situation=NAME[,NAME]   run only cases of these situation(s) (comma-separated)
 *   --out=FILE                write the leaderboard JSON result here
 *   --submitter=NAME          name recorded in the result (default: env USER)
 *   --engine-version=VER      engine version string recorded in the result
 *   -v, --verbose             print every turn's pushed set
 *   -h, --help                show this help
 */

namespace Dejavu\Benchmark;

const BENCHMARK_NAME = 'dejavu-memory-benchmark';
const BENCHMARK_VERSION = '0.3.0';

require __DIR__ . '/lib/EngineInterface.php';
require __DIR__ . '/lib/ConsolidatingEngine.php';
require __DIR__ . '/lib/ReferenceEngine.php';
require __DIR__ . '/lib/DejavuPushEngine.php';
require __DIR__ . '/lib/CaseLoader.php';
require __DIR__ . '/lib/Grader.php';
require __DIR__ . '/lib/MemoryGrader.php';

$opts = parse_args($argv);
if (isset($opts['help']) || isset($opts['h'])) {
    fwrite(STDOUT, help_text());
    exit(0);
}

$repo = dirname(__DIR__);
$casesDir = $opts['cases'] ?? $repo . '/cases';
$engineName = $opts['engine'] ?? 'reference';
$verbose = isset($opts['verbose']) || isset($opts['v']);

try {
    $engine = make_engine($engineName);
    $cases = CaseLoader::load($casesDir);
} catch (\Throwable $e) {
    fwrite(STDERR, "error: " . $e->getMessage() . "\n");
    exit(2);
}

if (isset($opts['situation'])) {
    $wanted = array_values(array_filter(array_map('trim', explode(',', (string)$opts['situation'])), 'strlen'));
    $cases = array_values(array_filter($cases, fn($c) => in_array($c['situation'] ?? '', $wanted, true)));
}
if ($cases === []) {
    fwrite(STDERR, "error: no cases found in {$casesDir}\n");
    exit(2);
}

try {
    $snapshots = load_snapshots($opts['snapshots'] ?? null);
} catch (\Throwable $e) {
    fwrite(STDERR, "error: " . $e->getMessage() . "\n");
    exit(2);
}

$results = [];
foreach ($cases as $case) {
    // Two grading axes. A "dialog" case measures memory *state* after STM
    // extraction / LTM consolidation (situations 15+); every other case measures
    // the *push* path per turn (situations 01–14).
    if (isset($case['dialog'])) {
        $results[] = grade_dialog_case($engine, $case, $snapshots);
    } else {
        $results[] = Grader::gradeCase($engine, $case);
    }
}

$doc = build_result_doc($engine, $results, $opts);
print_summary($doc, $results, $verbose);

if (isset($opts['out'])) {
    file_put_contents($opts['out'], json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    fwrite(STDOUT, "\nresult written to {$opts['out']}\n");
}

exit($doc['summary']['failed'] === 0 ? 0 : 1);

// ---------------------------------------------------------------------------

/**
 * Grade a dialog-format case on the memory-state axis. The snapshot comes from
 * a ConsolidatingEngine or a --snapshots file; without either, the case is
 * skipped (reported N/A, never counted as a failure) — the benchmark stays
 * honest instead of tuning a non-extractive engine to pass.
 */
function grade_dialog_case(EngineInterface $engine, array $case, array $snapshots): array
{
    $snapshot = null;
    if ($engine instanceof ConsolidatingEngine) {
        $snapshot = $engine->consolidate($case);
    } elseif (array_key_exists($case['id'], $snapshots)) {
        $snapshot = $snapshots[$case['id']];
    }

    if ($snapshot === null) {
        return [
            'id' => $case['id'],
            'situation' => $case['situation'] ?? 'unknown',
            'passed' => false,
            'skipped' => true,
            'skip_reason' => 'needs a consolidating engine or a --snapshots entry',
            'turns' => [],
        ];
    }

    return MemoryGrader::gradeCase($case, $snapshot);
}

/**
 * Load an optional snapshots file: a JSON map of caseId => (memory item[]),
 * letting any external memory system (any language) supply its consolidated
 * state out of process. Returns [] when no file is given.
 */
function load_snapshots(?string $path): array
{
    if ($path === null || $path === '') {
        return [];
    }
    if (!is_file($path)) {
        throw new \RuntimeException("snapshots file not found: {$path}");
    }
    $doc = json_decode((string)file_get_contents($path), true);
    if (!is_array($doc)) {
        throw new \RuntimeException("invalid JSON in snapshots file {$path}: " . json_last_error_msg());
    }
    // Accept either a flat map or a wrapper { "snapshots": { ... } }.
    return $doc['snapshots'] ?? $doc;
}

function make_engine(string $name): EngineInterface
{
    switch ($name) {
        case 'reference':
            return new ReferenceEngine();
        case 'push':
            return new DejavuPushEngine();
        default:
            throw new \RuntimeException("unknown engine '{$name}' (use: reference | push)");
    }
}

function build_result_doc(EngineInterface $engine, array $results, array $opts): array
{
    $total = count($results);
    $skipped = count(array_filter($results, fn($r) => !empty($r['skipped'])));
    $gradable = $total - $skipped;
    $passed = count(array_filter($results, fn($r) => empty($r['skipped']) && $r['passed']));
    $bySituation = [];
    foreach ($results as $r) {
        $s = $r['situation'];
        $bySituation[$s] ??= ['total' => 0, 'passed' => 0, 'skipped' => 0];
        $bySituation[$s]['total']++;
        if (!empty($r['skipped'])) {
            $bySituation[$s]['skipped']++;
        } elseif ($r['passed']) {
            $bySituation[$s]['passed']++;
        }
    }

    return [
        'benchmark' => BENCHMARK_NAME,
        'benchmark_version' => BENCHMARK_VERSION,
        'engine' => $engine->name(),
        'engine_version' => $opts['engine-version'] ?? 'unknown',
        'submitter' => $opts['submitter'] ?? (getenv('USER') ?: 'anonymous'),
        'generated_at' => gmdate('c'),
        'environment' => [
            'php' => PHP_VERSION,
            'os' => PHP_OS,
        ],
        'summary' => [
            'total' => $total,
            'passed' => $passed,
            'failed' => $gradable - $passed,
            'skipped' => $skipped,
            'score' => $gradable > 0 ? round($passed / $gradable, 4) : 0.0,
            'by_situation' => $bySituation,
        ],
        'cases' => $results,
    ];
}

function print_summary(array $doc, array $results, bool $verbose): void
{
    $s = $doc['summary'];
    fwrite(STDOUT, "dejavu-memory-benchmark · engine={$doc['engine']} · php={$doc['environment']['php']}\n");
    fwrite(STDOUT, str_repeat('-', 60) . "\n");
    foreach ($results as $r) {
        $mark = !empty($r['skipped']) ? 'SKIP' : ($r['passed'] ? 'PASS' : 'FAIL');
        fwrite(STDOUT, sprintf("[%s] %-22s %s\n", $mark, $r['situation'], $r['id']));
        if (!empty($r['skipped'])) {
            if ($verbose && isset($r['skip_reason'])) {
                fwrite(STDOUT, "       — {$r['skip_reason']}\n");
            }
            continue;
        }
        foreach ($r['turns'] as $t) {
            if ($verbose || !$t['passed']) {
                $pushed = $t['pushed'] === [] ? '(silent)' : implode(', ', $t['pushed']);
                fwrite(STDOUT, sprintf("       turn %d → [%s]\n", $t['index'], $pushed));
                foreach ($t['failures'] as $f) {
                    fwrite(STDOUT, "         ✗ {$f}\n");
                }
            }
        }
    }
    fwrite(STDOUT, str_repeat('-', 60) . "\n");
    $gradable = $s['total'] - ($s['skipped'] ?? 0);
    fwrite(STDOUT, sprintf(
        "%d/%d gradable cases passed · score %.4f%s\n",
        $s['passed'], $gradable, $s['score'],
        !empty($s['skipped']) ? sprintf(" · %d skipped", $s['skipped']) : ''
    ));
    foreach ($s['by_situation'] as $name => $b) {
        $skip = !empty($b['skipped']) ? sprintf(" (%d skipped)", $b['skipped']) : '';
        $den = $b['total'] - ($b['skipped'] ?? 0);
        fwrite(STDOUT, sprintf("  %-22s %d/%d%s\n", $name, $b['passed'], $den, $skip));
    }
}

function parse_args(array $argv): array
{
    $opts = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([^=]+)=(.*)$/', $arg, $m)) {
            $opts[$m[1]] = $m[2];
        } elseif (preg_match('/^--(.+)$/', $arg, $m)) {
            $opts[$m[1]] = true;
        } elseif (preg_match('/^-(\w+)$/', $arg, $m)) {
            foreach (str_split($m[1]) as $c) {
                $opts[$c] = true;
            }
        }
    }
    return $opts;
}

function help_text(): string
{
    return <<<TXT
dejavu-memory-benchmark runner

  php runner/run.php [options]

  --engine=reference|push   engine under test (default: reference)
  --cases=DIR               cases directory (default: <repo>/cases)
  --situation=NAME[,NAME]   run only cases of these situation(s), comma-separated
  --snapshots=FILE          JSON {caseId: memoryItem[]} for dialog (STM/LTM) cases;
                            supplies consolidated memory state out of process
  --out=FILE                write the leaderboard JSON result here
  --submitter=NAME          name recorded in the result
  --engine-version=VER      engine version recorded in the result
  -v, --verbose             print every turn's pushed set
  -h, --help                show this help

  Dialog cases (situations 15+) grade memory state after STM extraction / LTM
  consolidation. They run only against a ConsolidatingEngine or a --snapshots
  file; otherwise they are skipped (never counted as failures).

  exit code 0 = all gradable cases passed, 1 = failures, 2 = runner error

TXT;
}
