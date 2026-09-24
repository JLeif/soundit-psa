<?php

// Synthetic shape experiment, not a claim about production traffic or real secrets.
require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../tests/Fixtures/ScannerCoverage.php';

use App\Services\Wiki\Mining\WikiRedactor;
use Tests\Fixtures\ScannerCoverage;

$patterns = (new ReflectionClass(WikiRedactor::class))->getConstant('SECRET_PATTERNS');
$baseline = '/\b[A-Za-z0-9+\/_-]{24,}[+\/]+[A-Za-z0-9+\/_-]*={0,2}\b/';
$naive = '/\b[A-Za-z0-9+\/_-]{24,}[+]+[A-Za-z0-9+\/_-]*={0,2}\b/';
$mix = '~\b(?=[A-Za-z0-9+/_-]*[A-Z])(?=[A-Za-z0-9+/_-]*[a-z])(?=[A-Za-z0-9+/_-]*[0-9])[A-Za-z0-9+/_-]{24,}/[A-Za-z0-9+/_-]*\b|\b[A-Za-z0-9+/_-]{24,}\+[A-Za-z0-9+/_-]*={0,2}\b~';
// Replace only the distinctive rule in each comparison; other policy remains identical.
$sets = ['baseline' => $baseline, 'naive' => $naive, 'mix' => $mix, 'chosen' => $patterns[6]];
$detected = function (string $text, string $rule) use ($patterns): bool {
    $copy = $patterns;
    $copy[6] = $rule;
    foreach ($copy as $pattern) {
        $result = preg_match($pattern, $text);
        if ($result === false) {
            throw new RuntimeException(preg_last_error_msg());
        }
        if ($result === 1) {
            return true;
        }
    }

    return false;
};
$report = ['seed' => ScannerCoverage::SEED, 'generator' => 'base64(first 30 bytes SHA256(seed:decimal-index))', 'groups' => []];
foreach (['random' => ScannerCoverage::randomBase64(), 'signed_urls' => ScannerCoverage::signedUrls(), 'ordinary_urls' => ScannerCoverage::urls()] as $group => $rows) {
    $counts = array_fill_keys(array_keys($sets), 0);
    $new = array_fill_keys(array_keys($sets), []);
    $n = 0;
    $slashOnly = 0;
    foreach ($rows as $id => $text) {
        $n++;
        if (str_contains($text, '/') && ! str_contains($text, '+') && ! str_contains($text, '=')) {
            $slashOnly++;
        }
        $old = $detected($text, $baseline);
        foreach ($sets as $name => $rule) {
            $hit = $detected($text, $rule);
            $counts[$name] += (int) $hit;
            if ($old && ! $hit) {
                $new[$name][] = $id;
            }
        }
    }
    $report['groups'][$group] = ['denominator' => $n, 'slash_no_plus_no_padding' => $slashOnly, 'detected' => $counts, 'newly_admitted_count' => array_map('count', $new), 'newly_admitted_ids' => $new];
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
