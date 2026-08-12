<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CI gate: enforce the chain-E merge-blocking coverage thresholds against the
 * clover report produced by `composer test:coverage`.
 *
 * Thresholds (ADR-008 / openconnector-comprehensive-tests spec, Decision 4):
 *   - line coverage   >= 80%  (merge-blocking)
 *   - branch coverage >= 70%  (merge-blocking)
 *
 * 100% is the aspirational quarterly tech-debt target, documented in the
 * README — it is NOT enforced here.
 *
 * The clover file path defaults to coverage/clover.xml and may be overridden as
 * the first CLI argument. The thresholds may be overridden via the env vars
 * COVERAGE_MIN_LINE / COVERAGE_MIN_BRANCH (used by the gate's own unit checks).
 *
 * Exits 0 (PASS) when both thresholds are met.
 * Exits 1 (FAIL) and prints which threshold(s) were missed otherwise.
 * Exits 2 when the clover file is missing or unparseable.
 */

$cloverPath = $argv[1] ?? (__DIR__ . '/../../coverage/clover.xml');

$minLine = (float)(getenv('COVERAGE_MIN_LINE') !== false ? getenv('COVERAGE_MIN_LINE') : 80);
$minBranch = (float)(getenv('COVERAGE_MIN_BRANCH') !== false ? getenv('COVERAGE_MIN_BRANCH') : 70);

if (is_file($cloverPath) === false) {
	fwrite(STDERR, "check:coverage: clover report not found at {$cloverPath}\n");
	fwrite(STDERR, "check:coverage: run `composer test:coverage` first.\n");
	exit(2);
}

$xml = @simplexml_load_file($cloverPath);
if ($xml === false || isset($xml->project->metrics) === false) {
	fwrite(STDERR, "check:coverage: could not parse clover metrics from {$cloverPath}\n");
	exit(2);
}

$metrics = $xml->project->metrics;

$statements = (int)$metrics['statements'];
$coveredStatements = (int)$metrics['coveredstatements'];
$conditionals = (int)$metrics['conditionals'];
$coveredConditionals = (int)$metrics['coveredconditionals'];

$linePct = $statements > 0
	? round(($coveredStatements / $statements) * 100, 2)
	: 100.0;

// Branch coverage maps to clover's conditionals. When a codebase has no
// conditionals at all, branch coverage is vacuously 100%.
$branchPct = $conditionals > 0
	? round(($coveredConditionals / $conditionals) * 100, 2)
	: 100.0;

printf("Line coverage:   %5.2f%% (min %.0f%%)\n", $linePct, $minLine);
printf("Branch coverage: %5.2f%% (min %.0f%%)\n", $branchPct, $minBranch);

$failures = [];
if ($linePct < $minLine) {
	$failures[] = sprintf('line coverage %.2f%% is below the %.0f%% threshold', $linePct, $minLine);
}

if ($branchPct < $minBranch) {
	$failures[] = sprintf('branch coverage %.2f%% is below the %.0f%% threshold', $branchPct, $minBranch);
}

if ($failures !== []) {
	foreach ($failures as $failure) {
		fwrite(STDERR, "check:coverage: FAIL — {$failure}\n");
	}

	exit(1);
}

echo "check:coverage: PASS — both thresholds met\n";
exit(0);
