<?php

/**
 * Integriq — the Berichtenbox client that sends nothing (the default).
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Berichtenbox
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

namespace OCA\Integriq\Adapters\Berichtenbox;

/**
 * No network. DI returns this class while `logius.berichtenbox.feature_flag` is
 * off, and the provider marks every send through it as simulated. It never
 * reports a result, so a simulated letter stays `sent`; it never pretends a
 * letter was delivered. A BSN is accepted and never echoed or logged.
 *
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-the-feature-flag-selects-the-binding-and-a-flagged-instance-without-credentials-refuses-req-dpa-005
 */
final class BerichtenboxClientMock extends BerichtenboxClient {
	/**
	 * Flavour identifier.
	 *
	 * @return string Always `mock`.
	 */
	public function flavour(): string {
		return 'mock';
	}//end flavour()

	/**
	 * The mock needs nothing beyond what the provider already asks.
	 *
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return array<int,string> Always empty.
	 */
	public function configurationRefusals(array $config): array {
		unset($config);
		return [];
	}//end configurationRefusals()

	/**
	 * Every BSN is taken, simulated.
	 *
	 * @param array<int,string> $bsns The BSNs.
	 * @param string $berichtType The BerichtType (ignored).
	 * @param array<string,mixed> $config The source configuration (ignored).
	 *
	 * @return array<string,bool>
	 */
	public function checkSubscriptions(array $bsns, string $berichtType, array $config): array {
		unset($berichtType, $config);
		return array_fill_keys(array_map('strval', $bsns), true);
	}//end checkSubscriptions()

	/**
	 * A synthetic transport id; nothing leaves the instance.
	 *
	 * @param BerichtenboxBatch $batch The batch (ignored).
	 * @param array<string,mixed> $config The source configuration (ignored).
	 *
	 * @return string The id.
	 */
	public function deliver(BerichtenboxBatch $batch, array $config): string {
		unset($config);
		return 'mock-' . $batch->berichtId;
	}//end deliver()

	/**
	 * No results, ever.
	 *
	 * @param array<string,mixed> $config The source configuration (ignored).
	 *
	 * @return array<string,array{code:string,stadium:string,resultMessageId:string}>
	 */
	public function results(array $config): array {
		unset($config);
		return [];
	}//end results()

	/**
	 * No transport events, ever.
	 *
	 * @param array<string,mixed> $config The source configuration (ignored).
	 *
	 * @return array<string,string>
	 */
	public function transportEvents(array $config): array {
		unset($config);
		return [];
	}//end transportEvents()

	/**
	 * Nothing to mark.
	 *
	 * @param string $resultMessageId The id (ignored).
	 * @param array<string,mixed> $config The source configuration (ignored).
	 *
	 * @return void
	 */
	public function resultProcessed(string $resultMessageId, array $config): void {
		unset($resultMessageId, $config);
	}//end resultProcessed()

	/**
	 * Nothing to mark.
	 *
	 * @param string $transportMessageId The id (ignored).
	 * @param array<string,mixed> $config The source configuration (ignored).
	 *
	 * @return void
	 */
	public function eventProcessed(string $transportMessageId, array $config): void {
		unset($transportMessageId, $config);
	}//end eventProcessed()
}//end class
