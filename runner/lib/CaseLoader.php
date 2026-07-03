<?php

declare(strict_types=1);

namespace Dejavu\Benchmark;

/**
 * Loads and lightly validates the JSON case files under cases/.
 */
final class CaseLoader
{
    /**
     * @return array<int,array> flat list of cases, each annotated with its situation & source file
     */
    public static function load(string $dir): array
    {
        $files = glob(rtrim($dir, '/') . '/*.json') ?: [];
        sort($files);
        $cases = [];
        foreach ($files as $file) {
            $raw = file_get_contents($file);
            $doc = json_decode($raw, true);
            if (!is_array($doc)) {
                throw new \RuntimeException("Invalid JSON in {$file}: " . json_last_error_msg());
            }
            $situation = $doc['situation'] ?? basename($file, '.json');
            foreach ($doc['cases'] ?? [] as $case) {
                if (empty($case['id'])) {
                    throw new \RuntimeException("Case without id in {$file}");
                }
                $case['situation'] = $case['situation'] ?? $situation;
                $case['_file'] = basename($file);
                $cases[] = $case;
            }
        }
        return $cases;
    }
}
