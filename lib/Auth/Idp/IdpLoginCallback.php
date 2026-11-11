<?php

/**
 * Integriq IdpLoginCallback: one login being finished at the broker — the
 * provider and the assertion that came back, the request it answers, the
 * consumed state, the clock and, once mapped, the trust it carries.
 *
 * @category Auth
 * @package  OCA\Integriq\Auth\Idp
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
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-the-callback-returns-the-browser-with-a-one-time-code-req-idp-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

/**
 * One login callback in progress. Built and used by IdpLoginService only.
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-the-callback-returns-the-browser-with-a-one-time-code-req-idp-002
 */
final class IdpLoginCallback {
	/**
	 * The request the assertion answers.
	 *
	 * @var string
	 */
	public string $requestId = '';

	/**
	 * The consumed state.
	 *
	 * @var array<string,string>
	 */
	public array $state = [];

	/**
	 * The clock, injectable for tests.
	 *
	 * @var integer|null
	 */
	public ?int $now = null;

	/**
	 * The trust the assertion carries, once mapped.
	 *
	 * @var string
	 */
	public string $trust = '';

	/**
	 * Constructor.
	 *
	 * @param string              $provider  The provider id from the route.
	 * @param array<string,mixed> $assertion The normalised assertion.
	 */
	public function __construct(
		public readonly string $provider,
		public readonly array $assertion,
	) {
	}//end __construct()
}//end class
