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
 *   --situation=NAME          run only cases of this situation
 *   --out=FILE                write the leaderboard JSON result here
 *   --submitter=NAME          name recorded in the result (default: env USER)
 *   --engine-version=VER      engine version string recorded in the result
 *   -v, --verbose             print every turn's pushed set
 *   -h, --help                show this help
 */

namespace Dejavu\Benchmark;

const BENCHMARK_NAME = 'dejavu-memory-benchmark';
const BENCHMARK_VERSION = '0.2.0';

require __DIR__ . '/lib/EngineInterface.php';
require __DIR__ . '/lib/ReferenceEngine.php';
require __DIR__ . '/lib/DejavuPushEngine.php';
require __DIR__ . '/lib/CaseLoader.php';
require __DIR__ . '/lib/Grader.php';

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
    $cases = array_values(array_filter($cases, fn($c) => ($c['situation'] ?? '') === $opts['situation']));
}
if ($cases === []) {
    fwrite(STDERR, "error: no cases found in {$casesDir}\n");
    exit(2);
}

$results = [];
foreach ($cases as $case) {
    $results[] = Grader::gradeCase($engine, $case);
}

$doc = build_result_doc($engine, $results, $opts);
print_summary($doc, $results, $verbose);

if (isset($opts['out'])) {
    file_put_contents($opts['out'], json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    fwrite(STDOUT, "\nresult written to {$opts['out']}\n");
}

exit($doc['summary']['failed'] === 0 ? 0 : 1);

// ---------------------------------------------------------------------------

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
    $passed = count(array_filter($results, fn($r) => $r['passed']));
    $bySituation = [];
    foreach ($results as $r) {
        $s = $r['situation'];
        $bySituation[$s] ??= ['total' => 0, 'passed' => 0];
        $bySituation[$s]['total']++;
        $bySituation[$s]['passed'] += $r['passed'] ? 1 : 0;
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
            'failed' => $total - $passed,
            'score' => $total > 0 ? round($passed / $total, 4) : 0.0,
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
        $mark = $r['passed'] ? 'PASS' : 'FAIL';
        fwrite(STDOUT, sprintf("[%s] %-22s %s\n", $mark, $r['situation'], $r['id']));
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
    fwrite(STDOUT, sprintf(
        "%d/%d cases passed · score %.4f\n",
        $s['passed'], $s['total'], $s['score']
    ));
    foreach ($s['by_situation'] as $name => $b) {
        fwrite(STDOUT, sprintf("  %-22s %d/%d\n", $name, $b['passed'], $b['total']));
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
  --situation=NAME          run only cases of this situation
  --out=FILE                write the leaderboard JSON result here
  --submitter=NAME          name recorded in the result
  --engine-version=VER      engine version recorded in the result
  -v, --verbose             print every turn's pushed set
  -h, --help                show this help

  exit code 0 = all cases passed, 1 = failures, 2 = runner error

TXT;
}
