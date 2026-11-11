<?php

/**
 * One outbound call being prepared for dispatch.
 *
 * Kept beside CallRequest so the phases of preparing a call (guards,
 * configuration merge, credential resolution, normalisation) take the
 * preparation instead of the source, its data, the configuration and the
 * error expiry one by one. Never leaves CallService.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Call
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

namespace OCA\Integriq\Service\Call;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The call being prepared: its raw source, the request, the working source data and
 * configuration, and the prepared call it hands to dispatch.
 *
 * @spec openspec/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
 */
final class CallPreparation {

	/**
	 * The prepared call handed to dispatch (or its short circuit).
	 *
	 * @var array<string, mixed>
	 */
	public array $prepared = [];

	/**
	 * The source data as the phases change it.
	 *
	 * @var array<string, mixed>
	 */
	public array $sourceData = [];

	/**
	 * The call configuration as the phases change it.
	 *
	 * @var array<string, mixed>
	 */
	public array $config = [];

	/**
	 * Expiry for error log entries.
	 *
	 * @var DateTime|null
	 */
	public ?DateTime $errorExpires = null;

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $source  The source, re-read raw for dispatch.
	 * @param CallRequest  $request What the call asks for.
	 */
	public function __construct(
		public readonly ObjectEntity $source,
		public readonly CallRequest $request,
	) {

	}//end __construct()
}//end class
