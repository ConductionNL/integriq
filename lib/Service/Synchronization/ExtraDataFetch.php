<?php

/**
 * One extra-data fetch for an object while SynchronizationService resolves and runs it.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Synchronization
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

namespace OCA\Integriq\Service\Synchronization;

/**
 * Carries the synchronization, the extra-data configuration, the object and
 * the endpoint being resolved between the stages of
 * SynchronizationService::fetchExtraDataForObject(), so each stage names the
 * fetch instead of every value it needs.
 *
 * @spec openspec/specs/synchronization-engine/spec.md
 */
class ExtraDataFetch {

	/**
	 * The origin id the static endpoint is filled with.
	 *
	 * @var mixed
	 */
	public mixed $originId = null;

	/**
	 * The endpoint the extra data is fetched from.
	 *
	 * @var mixed
	 */
	public mixed $endpoint = null;

	/**
	 * Constructor.
	 *
	 * @param array $synchronization The synchronization; its source config may lose the configured key.
	 * @param array $config          The extra-data configuration.
	 * @param array $object          The object the extra data is fetched for.
	 */
	public function __construct(
		public array $synchronization,
		public readonly array $config,
		public readonly array $object,
	) {
	}//end __construct()
}//end class
