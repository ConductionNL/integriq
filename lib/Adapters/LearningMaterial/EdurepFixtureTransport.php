<?php

/**
 * Integriq Edurep fixture transport.
 *
 * Serves the SRU responses recorded from Edurep under tests/fixtures/edurep,
 * keyed by the CQL query that produced them in index.json. It never calls
 * the network; a query without a recording fails loudly.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\LearningMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-recorded-fixtures-no-live-calls-in-tests
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\LearningMaterial;

/**
 * Recorded Edurep SRU transport.
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-recorded-fixtures-no-live-calls-in-tests
 */
final class EdurepFixtureTransport implements SruTransport {
	public const RECORDED_AT = '2026-11-05T12:00:00+00:00';

	/**
	 * The directory.
	 *
	 * @var string
	 */
	private readonly string $directory;

	/**
	 * Constructor.
	 *
	 * @param string|null $directory The fixture directory, the app's tests/fixtures/edurep when null.
	 */
	public function __construct(?string $directory = null) {
		$this->directory = ($directory ?? dirname(__DIR__, 3) . '/tests/fixtures/edurep');
	}//end __construct()

	/**
	 * Serve the recorded body for a request's query.
	 *
	 * @param array<string,string> $parameters The SRU query parameters.
	 *
	 * @return string The recorded body.
	 *
	 * @throws SourceUnavailableException When the query has no recording.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-recorded-fixtures-no-live-calls-in-tests
	 */
	public function fetch(array $parameters): string {
		$file = basename($this->recordings()[$parameters['query'] ?? ''] ?? '');
		$path = $this->directory . '/' . $file;
		if ($file === '' || is_file($path) === false) {
			throw new SourceUnavailableException(source: LomRecordReader::SOURCE, lastSuccess: null);
		}

		return (string)file_get_contents($path);
	}//end fetch()

	/**
	 * The recordings index: CQL query to fixture file name.
	 *
	 * @return array<string,string> The index.
	 */
	private function recordings(): array {
		$path = $this->directory . '/index.json';
		$index = [];
		if (is_file($path) === true) {
			$index = json_decode((string)file_get_contents($path), true);
		}

		if (is_array($index) === false || is_array($index['responses'] ?? null) === false) {
			return [];
		}

		return array_filter($index['responses'], 'is_string');
	}//end recordings()

	/**
	 * When the recordings were made.
	 *
	 * @return string ISO 8601 time.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-recorded-fixtures-no-live-calls-in-tests
	 */
	public function lastSuccess(): string {
		return self::RECORDED_AT;
	}//end lastSuccess()
}//end class
