<?php

// Separate process: namespace shims never affect another test or production.

namespace App\Services\Wiki\Mining;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function preg_match($pattern, $subject, ...$args)
{
    if ($pattern === $GLOBALS['faultPattern']) {
        $GLOBALS['faultCalls']++;

        return false;
    }

    return \preg_match($pattern, $subject, ...$args);
}

function preg_replace($pattern, $replacement, $subject, ...$args)
{
    if ($pattern === $GLOBALS['faultPattern']) {
        $GLOBALS['faultCalls']++;

        return null;
    }

    return \preg_replace($pattern, $replacement, $subject, ...$args);
}

function preg_replace_callback($pattern, $callback, $subject, ...$args)
{
    if ($pattern === $GLOBALS['faultPattern']) {
        $GLOBALS['faultCalls']++;

        return null;
    }

    return \preg_replace_callback($pattern, $callback, $subject, ...$args);
}

$r = new WikiRedactor;
$reflection = new \ReflectionClass($r);
$method = $argv[1];
$patterns = $reflection->getConstant('SECRET_PATTERNS');
if ($method === 'scan') {
    $patterns = array_merge($patterns, $reflection->getConstant('INJECTION_PATTERNS'), [$reflection->getConstant('MARKER_PATTERN')]);
}
$patterns[] = $reflection->getConstant('CANDIDATE_PATTERN');
$rows = [];
foreach ($patterns as $pattern) {
    $GLOBALS['faultPattern'] = $pattern;
    $GLOBALS['faultCalls'] = 0;
    $result = $r->$method($method === 'scan' ? 'ordinary prose' : 'ordinary prose password: synthetic-only');
    $rows[] = ['result' => $result, 'calls' => $GLOBALS['faultCalls']];
}
$GLOBALS['faultPattern'] = null;
echo json_encode(['rows' => $rows, 'normal' => $r->$method('ordinary prose'), 'source' => $reflection->getFileName()], JSON_THROW_ON_ERROR);
