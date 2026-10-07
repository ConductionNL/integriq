<?php

/**
 * Integriq — a provider that must be told when a status it reported is stored.
 *
 * @category Service
 * @package  OCA\Integriq\Service\DigitalPost
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

namespace OCA\Integriq\Service\DigitalPost;

/**
 * Some providers hand out a status once: the Berichtenbox result waits at the
 * ebMS adapter until it is marked processed. Marking it before the letter is
 * saved would lose it when the save fails, so the service calls this only
 * after the save succeeded.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-logius-results-decide-the-status-and-a-berichtenbox-letter-is-never-read-req-dpa-011
 */
interface DigitalPostStatusAcknowledger {
	/**
	 * The status reported for this reference is stored; the provider may let go of it.
	 *
	 * @param string $providerReference The provider's reference.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return void
	 */
	public function statusRecorded(string $providerReference, array $config): void;
}//end interface
