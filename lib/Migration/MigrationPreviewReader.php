<?php

/**
 * The read-only pass that reports what a migration would bring.
 *
 * @category Service
 * @package  OCA\Integriq\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Migration;

use Throwable;

/**
 * A count per record kind and a bounded sample of each, plus whether the
 * source was read completely. An incomplete read says incomplete: it is never
 * reported as a smaller count, which is the difference between "there are
 * 400 cases" and "we got as far as 400".
 *
 * Integriq draws no conclusion from these numbers. OpenRegister's import
 * engine does.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-read-only-pass-reports-what-a-migration-would-bring-req-msa-004
 */
class MigrationPreviewReader {
	/**
	 * How many records a sample holds unless a caller says otherwise.
	 */
	public const DEFAULT_SAMPLE_SIZE = 5;

	/**
	 * Constructor.
	 *
	 * @param MigrationSourceRegistry $registry The adapters.
	 */
	public function __construct(private readonly MigrationSourceRegistry $registry) {
	}//end __construct()

	/**
	 * Read a source without writing anything anywhere.
	 *
	 * @param string $sourceId The migration source id.
	 * @param array<string,mixed> $config The migration's configuration.
	 * @param int $sampleSize How many records to sample per kind.
	 *
	 * @return array<string,mixed> The preview.
	 *
	 * @throws UnknownMigrationSourceException When nothing answers to the source id.
	 */
	public function preview(string $sourceId, array $config = [], int $sampleSize = self::DEFAULT_SAMPLE_SIZE): array {
		$adapter = $this->registry->get($sourceId);
		$described = $adapter->describe();

		$kinds = [];
		$complete = true;
		foreach ($described['kinds'] as $kindDefinition) {
			$kind = $kindDefinition['kind'];
			$counted = ['count' => 0, 'complete' => false];
			$sample = [];

			try {
				$counted = $adapter->count($kind, $config);
				$sample = $adapter->sample($kind, $config, $sampleSize);
			} catch (Throwable $e) {
				$counted = ['count' => 0, 'complete' => false];
			}

			$kindComplete = ((bool)($counted['complete'] ?? false));
			$complete = ($complete && $kindComplete);

			$kinds[] = [
				'kind' => $kind,
				'label' => $kindDefinition['label'],
				'stableIdentifier' => $kindDefinition['stableIdentifier'],
				'count' => (int)($counted['count'] ?? 0),
				// The two are reported side by side on purpose. A count is
				// only a size when the read that produced it was complete.
				'countIs' => ($kindComplete === true ? 'complete' : 'partial'),
				'complete' => $kindComplete,
				'sample' => array_map(
					static fn (MigrationRecord $record): array => $record->toArray(),
					$sample
				),
			];
		}//end foreach

		return [
			'source' => $described['id'],
			'label' => $described['label'],
			'complete' => $complete,
			'wrote' => false,
			'kinds' => $kinds,
		];
	}//end preview()
}//end class
