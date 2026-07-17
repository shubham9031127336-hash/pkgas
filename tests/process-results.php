<?php
/**
 * E2E Test Results Processor
 * Reads docs/testing/playwright-results.json, writes structured failure reports
 * to docs/testing/failures/<test-name>.json, and generates consolidated summary.
 * Run: php tests/process-results.php
 */
$results_file = __DIR__ . '/../docs/testing/playwright-results.json';
$failures_dir  = __DIR__ . '/../docs/testing/failures/';
$summary_file  = __DIR__ . '/../docs/testing/results-summary.json';
$results_dir   = __DIR__ . '/../test-results/';

function stripBom($text) {
    if (substr($text, 0, 3) === "\xEF\xBB\xBF") return substr($text, 3);
    if (substr($text, 0, 2) === "\xFF\xFE") return substr($text, 2);
    if (substr($text, 0, 2) === "\xFE\xFF") return substr($text, 2);
    return $text;
}

if (isset($argv[1]) && file_exists($argv[1])) {
    $input = stripBom(file_get_contents($argv[1]));
} elseif (file_exists($results_file)) {
    $input = stripBom(file_get_contents($results_file));
} else {
    die("Usage: php process-results.php [path-to-playwright-json]\n       Default path: $results_file\n       Run playwright with --reporter=json and pipe output to a file first.\n");
}

$suites = json_decode($input, true);
if (!$suites || !isset($suites['suites'])) {
    $err = json_last_error_msg();
    die("ERROR: Invalid Playwright results JSON ($err).\n");
}

$all_results = [];
$by_priority = [];
$failures    = [];

function extract_specs($suite, &$out, $parent_title = '') {
    $prefix = $parent_title ? $parent_title . ' › ' : '';
    if (isset($suite['title'])) {
        $prefix .= $suite['title'] . ' › ';
    }
    if (isset($suite['specs'])) {
        foreach ($suite['specs'] as $spec) {
            $title = $prefix . $spec['title'];
            $ok = $spec['ok'] ?? false;
            $tests = $spec['tests'] ?? [];
            $duration = 0;
            $errors = [];
            foreach ($tests as $t) {
                $duration += $t['duration'] ?? 0;
                if (isset($t['errors'])) {
                    foreach ($t['errors'] as $e) {
                        $errors[] = [
                            'message' => $e['message'] ?? '',
                            'location' => $e['location'] ?? null,
                        ];
                    }
                }
                if (isset($t['annotations'])) {
                    foreach ($t['annotations'] as $a) {
                        if (($a['type'] ?? '') === 'skip') {
                            $ok = 'skipped';
                        }
                    }
                }
            }
            $out[] = [
                'title'    => $title,
                'ok'       => $ok,
                'duration' => $duration,
                'errors'   => $errors,
            ];
        }
    }
    if (isset($suite['suites'])) {
        foreach ($suite['suites'] as $child) {
            extract_specs($child, $out, rtrim($prefix, ' › '));
        }
    }
}

extract_specs($suites, $all_results);

$total   = count($all_results);
$passed  = 0;
$failed  = 0;
$skipped = 0;

$now = date('c');

foreach ($all_results as $r) {
    if ($r['ok'] === true) {
        $passed++;
    } elseif ($r['ok'] === 'skipped') {
        $skipped++;
    } else {
        $failed++;
        $test_id = preg_replace('/[^A-Za-z0-9_-]/', '_', $r['title']);
        $test_id = preg_replace('/_+/', '_', $test_id);
        $test_id = trim($test_id, '_');

        $error_msg = '';
        $error_loc = null;
        if (!empty($r['errors'])) {
            $error_msg = $r['errors'][0]['message'] ?? '';
            $error_loc = $r['errors'][0]['location'] ?? null;
        }

        $failure = [
            'test_id'    => $test_id,
            'title'      => $r['title'],
            'status'     => 'failed',
            'duration_ms' => $r['duration'],
            'error'      => [
                'message'  => $error_msg,
                'location' => $error_loc,
            ],
            'timestamp' => $now,
            'screenshots' => [],
        ];

        $spec_dir = $results_dir . $test_id . '/';
        if (is_dir($spec_dir)) {
            $files = scandir($spec_dir);
            foreach ($files as $f) {
                if (preg_match('/\.png$/i', $f)) {
                    $src = $spec_dir . $f;
                    $dst = $failures_dir . $test_id . '-' . $f;
                    copy($src, $dst);
                    $failure['screenshots'][] = 'failures/' . $test_id . '-' . $f;
                }
            }
        }

        $failure_file = $failures_dir . $test_id . '.json';
        file_put_contents($failure_file, json_encode($failure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo "  [FAIL] {$r['title']}\n";
        if ($error_msg) {
            echo "         {$error_msg}\n";
        }
        echo "         → {$failure_file}\n";

        $failures[] = [
            'test_id' => $test_id,
            'title'   => $r['title'],
            'error'   => mb_substr($error_msg, 0, 200),
            'file'    => 'failures/' . $test_id . '.json',
        ];
    }
}

$summary = [
    'generated_at' => $now,
    'config' => [
        'suites_total' => count($all_results),
        'workers' => 4,
        'fully_parallel' => true,
    ],
    'summary' => [
        'total'         => $total,
        'passed'        => $passed,
        'failed'        => $failed,
        'skipped'       => $skipped,
        'pass_rate_pct' => $total > 0 ? round($passed / $total * 100, 1) : 0,
    ],
    'results' => array_map(function ($r) {
        return [
            'title'    => $r['title'],
            'status'   => $r['ok'] === true ? 'passed' : ($r['ok'] === 'skipped' ? 'skipped' : 'failed'),
            'duration_ms' => $r['duration'],
        ];
    }, $all_results),
    'failures' => $failures,
];

file_put_contents($summary_file, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "\n=== Summary ===\n";
echo "Total:  $total\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
echo "Skipped: $skipped\n";
echo "Rate:   {$summary['summary']['pass_rate_pct']}%\n";
echo "Summary: {$summary_file}\n";

if ($failed > 0) {
    echo "\n=== Failure Files ===\n";
    foreach ($failures as $f) {
        echo "  {$f['file']}\n";
    }
}
