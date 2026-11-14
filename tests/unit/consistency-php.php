<?php
/**
 * Task 10 — PHP side of the PHP<->JS rounding consistency check.
 *
 * Reads the shared input vectors and prints "input=result" lines using the
 * ACTUAL ani_navasan_round_to_thousand() from includes/functions.php.
 * The runner diffs this output against the JS output.
 *
 * Run: php tests/unit/consistency-php.php
 */
require_once __DIR__ . '/bootstrap.php';

$vectors = json_decode(file_get_contents(__DIR__ . '/consistency-vectors.json'), true);
foreach ($vectors as $v) {
    echo $v . '=' . ani_navasan_round_to_thousand($v) . "\n";
}
