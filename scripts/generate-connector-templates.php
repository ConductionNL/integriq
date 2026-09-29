<?php

/**
 * Generate the SaaS connector templates from the pinned directory snapshot.
 *
 *   php scripts/generate-connector-templates.php refresh   # network: re-pin the snapshot for the allow-list
 *   php scripts/generate-connector-templates.php generate  # offline: write saas/<slug>.json from the snapshot
 *
 * Nothing reaches the Store that is not on saas/allow-list.json (design D3).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/connector-catalog/spec.md#requirement-generated-saas-templates-come-from-a-pinned-directory-and-a-reviewed-allow-list-req-ccx-003
 */

declare(strict_types=1);

require_once __DIR__ . '/ConnectorTemplateGenerator.php';

use OCA\Integriq\Scripts\ConnectorTemplateGenerator;

$saas = __DIR__ . '/../lib/Settings/connector-templates/saas';
$snapshot = $saas . '/snapshot';
$command = ($argv[1] ?? 'generate');
$generator = new ConnectorTemplateGenerator();

$readJson = static function (string $path): array {
	$data = json_decode((string)file_get_contents($path), true);
	if (is_array($data) === false) {
		fwrite(STDERR, "Cannot read $path\n");
		exit(1);
	}

	return $data;
};
$writeJson = static function (string $path, array $data): void {
	file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
};

$allowList = $readJson($saas . '/allow-list.json')['entries'];

if ($command === 'refresh') {
	$list = json_decode((string)file_get_contents('https://api.apis.guru/v2/list.json'), true);
	if (is_array($list) === false) {
		fwrite(STDERR, "Could not fetch the directory\n");
		exit(1);
	}

	$index = [];
	foreach ($list as $key => $entry) {
		$version = $entry['versions'][$entry['preferred']];
		$index[$key] = [
			'preferred' => $entry['preferred'],
			'title' => (string)($version['info']['title'] ?? ''),
			'categories' => ($version['info']['x-apisguru-categories'] ?? []),
			'swaggerUrl' => $version['swaggerUrl'],
			'updated' => substr((string)$version['updated'], 0, 10),
		];
	}

	ksort($index);
	$writeJson($snapshot . '/index.json', $index);

	@mkdir($snapshot . '/specs', 0755, true);
	foreach ($allowList as $allowed) {
		$key = $allowed['key'];
		if (isset($index[$key]) === false) {
			fwrite(STDERR, "Not in the directory: $key\n");
			continue;
		}

		$spec = json_decode((string)file_get_contents($index[$key]['swaggerUrl']), true);
		$writeJson($snapshot . '/specs/' . str_replace(':', '__', $key) . '.json', $generator->trimSpec($spec));
	}

	$writeJson($snapshot . '/snapshot.json', ['source' => 'https://api.apis.guru/v2/list.json', 'date' => date('Y-m-d')]);
	echo "Snapshot re-pinned. Run generate next.\n";
	exit(0);
}//end if

$index = $readJson($snapshot . '/index.json');
$specs = [];
foreach (glob($snapshot . '/specs/*.json') ?: [] as $file) {
	$specs[str_replace('__', ':', basename($file, '.json'))] = $readJson($file);
}

$date = (string)$readJson($snapshot . '/snapshot.json')['date'];
$templates = $generator->generate(index: $index, specs: $specs, allowList: $allowList, snapshotDate: $date);
foreach ($templates as $slug => $template) {
	$writeJson($saas . '/' . $slug . '.json', $template);
}

echo 'Wrote ' . count($templates) . ' generated templates from the snapshot of ' . $date . ".\n";
