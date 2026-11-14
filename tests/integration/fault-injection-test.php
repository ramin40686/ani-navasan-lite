<?php
/**
 * Task 10c — Fault-injection tests for the hardened backup/restore/verify.
 *
 * Run against the AUTHORIZED local site (anishop-package-test.local) ONLY:
 *
 *   cd ~/Local\ Sites/anishop-package-test/app/public
 *   wp eval-file /path/to/tests/integration/fault-injection-test.php
 *
 * These tests exercise the EXACT same backup/restore/verify code paths as the
 * main integration suite (tests/integration/backup-restore.php), but inject
 * controlled database faults via T10c_WpdbProxy to prove that:
 *
 *   FI-1  A failed backup SELECT aborts the backup (T10c_BackupException).
 *   FI-2  A failed START TRANSACTION aborts the restore BEFORE any write.
 *   FI-3  A failed verification SELECT is a failure, never a pass.
 *   FI-4  A postmeta meta_id difference is detected (identity comparison).
 *   FI-5  Duplicate postmeta rows are compared correctly.
 *
 * NO live database mutation ever occurs: a faulted query is never executed,
 * and the pure-function tests (FI-4/FI-5) use synthetic data only. A final
 * safety check re-verifies the baseline to prove the site is unchanged.
 *
 * PHP 7.4 compatible.
 */

// ---------------------------------------------------------------------------
// Guards: must run in WP-CLI with the plugin active
// ---------------------------------------------------------------------------
if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "This test must be run via WP-CLI (wp eval-file).\n");
    exit(1);
}
if (!is_plugin_active('ani-navasan-lite/ani-navasan-lite.php')) {
    fwrite(STDERR, "Ani Navasan Lite plugin is not active.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Authorized scope (identical to the main integration suite)
// ---------------------------------------------------------------------------
$GLOBALS['t10b_post_ids']    = [17613, 17615, 17616, 17617, 17654, 17655];
$GLOBALS['t10b_product_ids'] = [17613, 17615, 17616, 17617];

$admin_id = 1;
if (!get_user_by('id', $admin_id)) {
    $admin_id = (int) get_option('administrator_id', 0);
}
wp_set_current_user($admin_id);

require_once __DIR__ . '/backup-restore.php';

// ---------------------------------------------------------------------------
// Test state + helpers
// ---------------------------------------------------------------------------
$GLOBALS['fi_passed'] = 0;
$GLOBALS['fi_failed'] = 0;

function fi_check($name, $actual, $expected) {
    $ok = ($actual === $expected);
    if ($ok) {
        $GLOBALS['fi_passed']++;
        echo "  PASS  {$name}\n";
    } else {
        $GLOBALS['fi_failed']++;
        echo "  FAIL  {$name}\n"
            . "        expected: " . var_export($expected, true) . "\n"
            . "        actual:   " . var_export($actual, true) . "\n";
    }
    return $ok;
}

/**
 * Swap $GLOBALS['wpdb'] with a fault-injection proxy, run $fn, then restore
 * the real $wpdb (even if $fn throws). Returns $fn's return value.
 */
function fi_with_proxy($real_wpdb, $setup_faults, $fn) {
    $proxy = new T10c_WpdbProxy($real_wpdb);
    $setup_faults($proxy);
    $GLOBALS['wpdb'] = $proxy;
    try {
        return $fn();
    } finally {
        $GLOBALS['wpdb'] = $real_wpdb;
    }
}

$real_wpdb = $GLOBALS['wpdb'];

// Baseline backup (real, no fault) — the reference for the final safety check.
$baseline = t10b_backup_state();
echo "[fi] baseline captured: " . count($baseline['posts']) . " posts, "
    . count($baseline['lookup']) . " lookup rows, "
    . count($baseline['options']) . " targeted options\n";

// ===========================================================================
// FI-1 — A failed backup SELECT aborts the backup
// ===========================================================================
echo "\n=== FI-1: backup SELECT failure ===\n";
$fi1_thrown  = false;
$fi1_message = '';
try {
    fi_with_proxy($real_wpdb, function ($proxy) {
        $proxy->add_fault('SELECT meta_id, post_id, meta_key, meta_value FROM', false);
    }, function () {
        return t10b_backup_state();
    });
} catch (T10c_BackupException $e) {
    $fi1_thrown  = true;
    $fi1_message = $e->getMessage();
}
fi_check('FI-1.1 backup threw T10c_BackupException', $fi1_thrown, true);
fi_check('FI-1.2 exception names the failed postmeta query', strpos($fi1_message, 'wp_postmeta') !== false, true);

// ===========================================================================
// FI-2 — A failed START TRANSACTION aborts the restore BEFORE any write
// ===========================================================================
echo "\n=== FI-2: transaction-start failure ===\n";
$fi2_result = fi_with_proxy($real_wpdb, function ($proxy) {
    $proxy->add_fault('START TRANSACTION', false);
}, function () use ($baseline) {
    return t10b_restore_state($baseline);
});
// Defensive: ensure no transaction is left open (a no-op if none is active).
$real_wpdb->query('ROLLBACK');
fi_check('FI-2.1 restore reported failure', $fi2_result['ok'], false);
fi_check('FI-2.2 failure names START TRANSACTION', strpos(implode(' | ', $fi2_result['errors']), 'START TRANSACTION') !== false, true);

// ===========================================================================
// FI-3 — A failed verification SELECT is a failure, never a pass
// ===========================================================================
echo "\n=== FI-3: verification-query failure ===\n";
// Sanity: without a fault, the baseline verifies cleanly (DB is unmodified).
$fi3_clean = t10b_verify_restore($baseline);
fi_check('FI-3.0 baseline verifies cleanly (no fault)', $fi3_clean['ok'], true);
$fi3_result = fi_with_proxy($real_wpdb, function ($proxy) {
    $proxy->add_fault('SELECT meta_id, post_id, meta_key, meta_value FROM', false);
}, function () use ($baseline) {
    return t10b_verify_restore($baseline);
});
fi_check('FI-3.1 verification reported failure', $fi3_result['ok'], false);
fi_check('FI-3.2 failure names the failed SELECT', strpos(implode(' | ', $fi3_result['mismatches']), 'verification SELECT failed') !== false, true);

// ===========================================================================
// FI-4 — postmeta meta_id difference detection (pure function, no DB)
// ===========================================================================
echo "\n=== FI-4: postmeta meta_id difference detection ===\n";
$brows = [
    ['meta_id' => 100, 'post_id' => 5, 'meta_key' => '_price', 'meta_value' => '1000'],
    ['meta_id' => 101, 'post_id' => 5, 'meta_key' => '_mnswmc_active', 'meta_value' => 'yes'],
];
// Same data, but meta_id 101 was reassigned to 999 (an identity change).
$arows_changed_id = [
    ['meta_id' => 100, 'post_id' => 5, 'meta_key' => '_price', 'meta_value' => '1000'],
    ['meta_id' => 999, 'post_id' => 5, 'meta_key' => '_mnswmc_active', 'meta_value' => 'yes'],
];
$fi4_mismatches = t10c_compare_postmeta($brows, $arows_changed_id, 5);
fi_check('FI-4.1 meta_id difference is detected', empty($fi4_mismatches), false);
fi_check('FI-4.2 missing meta_id 101 is named', strpos(implode(' | ', $fi4_mismatches), '101') !== false, true);
fi_check('FI-4.3 unexpected meta_id 999 is named', strpos(implode(' | ', $fi4_mismatches), '999') !== false, true);
$fi4_identical = t10c_compare_postmeta($brows, $brows, 5);
fi_check('FI-4.4 identical rows produce no mismatch', $fi4_identical, []);

// ===========================================================================
// FI-5 — duplicate postmeta row comparison (pure function, no DB)
// ===========================================================================
echo "\n=== FI-5: duplicate postmeta row comparison ===\n";
// Two rows with the SAME meta_key but different meta_id (a legitimate duplicate).
$dup_b = [
    ['meta_id' => 200, 'post_id' => 7, 'meta_key' => '_some_key', 'meta_value' => 'first'],
    ['meta_id' => 201, 'post_id' => 7, 'meta_key' => '_some_key', 'meta_value' => 'second'],
];
$fi5_identical = t10c_compare_postmeta($dup_b, $dup_b, 7);
fi_check('FI-5.1 identical duplicate rows produce no mismatch', $fi5_identical, []);
// One duplicate's value changed => mismatch scoped to that meta_id only.
$dup_a_changed = [
    ['meta_id' => 200, 'post_id' => 7, 'meta_key' => '_some_key', 'meta_value' => 'first'],
    ['meta_id' => 201, 'post_id' => 7, 'meta_key' => '_some_key', 'meta_value' => 'CHANGED'],
];
$fi5_mismatches = t10c_compare_postmeta($dup_b, $dup_a_changed, 7);
fi_check('FI-5.2 changed duplicate value is detected', empty($fi5_mismatches), false);
fi_check('FI-5.3 mismatch is scoped to meta_id 201', strpos(implode(' | ', $fi5_mismatches), 'meta_id=201') !== false, true);
fi_check('FI-5.4 unchanged meta_id 200 is not flagged', strpos(implode(' | ', $fi5_mismatches), 'meta_id=200') === false, true);

// ===========================================================================
// Final safety check — the site must be unchanged by the fault injection
// ===========================================================================
echo "\n=== Final safety check: site unchanged ===\n";
$safety = t10b_verify_restore($baseline);
fi_check('SAFETY.1 database matches the pre-test baseline', $safety['ok'], true);
if (!$safety['ok']) {
    foreach ($safety['mismatches'] as $m) {
        echo "        - {$m}\n";
    }
}

// ===========================================================================
// Summary
// ===========================================================================
echo "\n=== Fault-injection summary ===\n";
echo "Passed: {$GLOBALS['fi_passed']}  Failed: {$GLOBALS['fi_failed']}\n";
if ($GLOBALS['fi_failed'] > 0) {
    echo "RESULT: FAIL\n";
    exit(1);
}
echo "RESULT: PASS\n";