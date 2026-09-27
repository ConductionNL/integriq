<?php

/**
 * Integriq SLO curriculum client (mock).
 *
 * Dormant default of {@see SloCurriculumClient}. Serves the recorded
 * responses in `slo-curriculum-recorded.json`: real SLO curriculum records
 * (release tags `curriculum-fo@2026.8`, `curriculum-basis@2026.7`,
 * `curriculum-kerndoelen@2026.7`, `curriculum-examenprogramma@2026.7`,
 * `curriculum-leerdoelenkaarten@2026.7`) in the shape SLO's REST server
 * builds them. No network access, ever. A request without a recording fails
 * loudly instead of returning an empty answer, so a test cannot pass on
 * nothing.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Slo
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
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-client-is-a-mock-by-default-and-live-only-behind-the-flag-req-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Slo;

use OCA\Integriq\Exception\SloCurriculumException;

/**
 * Mock SLO client over the recorded fixture.
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-client-is-a-mock-by-default-and-live-only-behind-the-flag-req-003
 */
final class SloCurriculumClientMock extends SloCurriculumClient {
	/**
	 * Path to the recorded fixture, relative to this class.
	 */
	public const FIXTURE_PATH = __DIR__ . '/slo-curriculum-recorded.json';

	/**
	 * Recorded responses keyed by request key.
	 *
	 * @var array<string,array<string,mixed>>|null
	 */
	private ?array $responses = null;

	/**
	 * Constructor.
	 *
	 * @param string|null $fixturePath Override for the fixture path (tests only).
	 */
	public function __construct(
		private readonly ?string $fixturePath = null,
	) {
	}//end __construct()

	/**
	 * Flavour identifier.
	 *
	 * @return string Always `mock`.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-client-is-a-mock-by-default-and-live-only-behind-the-flag-req-003
	 */
	public function flavour(): string {
		return 'mock';
	}//end flavour()

	/**
	 * Serve the recorded body for a request.
	 *
	 * @param string $path Path under the API base.
	 * @param array<string,scalar> $query Query parameters.
	 * @param string $accept The Accept header (recordings are keyed by path and query only).
	 *
	 * @return string The recorded body.
	 *
	 * @throws SloCurriculumException When the request has no recording (status 404).
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-client-is-a-mock-by-default-and-live-only-behind-the-flag-req-003
	 */
	public function fetch(string $path, array $query = [], string $accept = 'application/json'): string {
		unset($accept);
		$key = self::requestKey(path: $path, query: $query);
		$responses = $this->responses();

		if (isset($responses[$key]) === false) {
			throw new SloCurriculumException(
				message: sprintf('The SLO mock has no recorded response for GET %s. It serves only recorded requests.', $key),
				status: 404
			);
		}

		$body = ($responses[$key]['body'] ?? '');
		if (is_array($body) === true) {
			return (string)json_encode($body, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		}

		return (string)$body;
	}//end fetch()

	/**
	 * Every recorded request key.
	 *
	 * @return array<int,string> Request keys.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-client-is-a-mock-by-default-and-live-only-behind-the-flag-req-003
	 */
	public function recordedKeys(): array {
		return array_map('strval', array_keys($this->responses()));
	}//end recordedKeys()

	/**
	 * Load the recordings once.
	 *
	 * @return array<string,array<string,mixed>> Recordings keyed by request key.
	 */
	private function responses(): array {
		if ($this->responses !== null) {
			return $this->responses;
		}

		$path = ($this->fixturePath ?? self::FIXTURE_PATH);
		$decoded = null;
		if (is_file($path) === true) {
			$decoded = json_decode((string)file_get_contents($path), true);
		}

		$this->responses = [];
		if (is_array($decoded) === true && is_array($decoded['responses'] ?? null) === true) {
			foreach ($decoded['responses'] as $key => $response) {
				if (is_array($response) === true) {
					$this->responses[(string)$key] = $response;
				}
			}
		}

		return $this->responses;
	}//end responses()
}//end class
