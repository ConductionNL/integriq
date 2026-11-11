<?php

/**
 * Integriq Pre-Check Run
 *
 * One PreCheckService::ask() call: the pre-check's configuration, what the
 * act is about, and the HTTP status the attempt is recorded with.
 *
 * @category Service
 * @package  OCA\Integriq\Outbound\Call
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/outbound-call-log/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Call;

/**
 * The state of one pre-check; built and read by PreCheckService only.
 *
 * @spec openspec/specs/outbound-call-log/spec.md
 */
final class PreCheckRun {

	/**
	 * The HTTP status the attempt is recorded with; zero when there was none.
	 *
	 * @var int
	 */
	public int $statusCode = 0;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $configuration The pre-check: `url`, `timeoutSeconds`, `headers`, `mock`, `fixture`.
	 * @param array<string,mixed> $payload       What the act is about.
	 */
	public function __construct(
		public readonly array $configuration,
		public readonly array $payload,
	) {

	}//end __construct()

	/**
	 * How long to wait for the outside system, in seconds.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md
	 */
	public function timeout(): int {
		return (int)($this->configuration['timeoutSeconds'] ?? PreCheckService::DEFAULT_TIMEOUT_SECONDS);

	}//end timeout()
}//end class
