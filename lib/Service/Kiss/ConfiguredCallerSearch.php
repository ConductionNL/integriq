<?php

/**
 * Integriq Configured Caller Search.
 *
 * The {@see CallerSearchInterface} binding the container hands the caller
 * directory. A CTI source names the klantinteracties source its callers are
 * looked up in (`configuration.callerLookupSource`, a KISS source id); this
 * reads that source and asks its REST binding.
 *
 * The CTI webhook has no session, so the named source is read in system
 * context, exactly as {@see CtiSourceResolver} reads the CTI source itself.
 * It is never "the first active KISS source": in system context that could be
 * another organisation's directory, and an agent would read a stranger's name
 * off the panel. A CTI source naming no source, or one that is missing,
 * disabled, not a KISS source or on the log sandbox, gives no candidates, so
 * every caller reads as unknown, which is true and visible.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Kiss;

use OCA\Integriq\Service\KissSyncService;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Throwable;

/**
 * Looks callers up in the klantinteracties source a CTI source names.
 */
class ConfiguredCallerSearch implements CallerSearchInterface {

	/**
	 * The CTI source configuration key naming the klantinteracties source.
	 */
	public const SOURCE_KEY = 'callerLookupSource';

	/**
	 * Constructor.
	 *
	 * @param OrObjectService        $objectService Reads the named source.
	 * @param KlantinteractiesClient $client        The REST binding that can search.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
		private readonly KlantinteractiesClient $client,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @param array  $sourceConfiguration The CTI source's configuration.
	 * @param string $e164                The caller's number in E.164.
	 *
	 * @return array<int, array<string, mixed>> The candidate parties.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
	 */
	public function findPartiesByPhoneNumber(array $sourceConfiguration, string $e164): array {
		$directory = $this->directoryConfiguration(ctiConfiguration: $sourceConfiguration);
		if ($directory === null) {
			return [];
		}

		return $this->client->findPartiesByPhoneNumber(sourceConfiguration: $directory, e164: $e164);

	}//end findPartiesByPhoneNumber()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The CTI source's configuration.
	 * @param array $party               The matched party.
	 *
	 * @return array<int, string> The case references, newest first.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
	 */
	public function findOpenCasesForParty(array $sourceConfiguration, array $party): array {
		$directory = $this->directoryConfiguration(ctiConfiguration: $sourceConfiguration);
		if ($directory === null) {
			return [];
		}

		return $this->client->findOpenCasesForParty(sourceConfiguration: $directory, party: $party);

	}//end findOpenCasesForParty()

	/**
	 * The configuration of the klantinteracties source the CTI source names,
	 * when it is an enabled KISS source on the REST binding.
	 *
	 * @param array $ctiConfiguration The CTI source's configuration.
	 *
	 * @return array<string, mixed>|null The KISS source's configuration, or null.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
	 */
	private function directoryConfiguration(array $ctiConfiguration): ?array {
		$sourceId = trim((string) ($ctiConfiguration[self::SOURCE_KEY] ?? ''));
		if ($sourceId === '') {
			return null;
		}

		$source = $this->readSource(sourceId: $sourceId);
		if ($source === null
			|| (string) ($source['type'] ?? '') !== KissSyncService::SOURCE_TYPE
			|| ($source['isEnabled'] ?? true) === false
		) {
			return null;
		}

		$configuration = ($source['configuration'] ?? []);
		if (is_array($configuration) === false || ($configuration['provider'] ?? '') !== 'rest') {
			// The log sandbox cannot search; asking it would only ever say nobody.
			return null;
		}

		return $configuration;

	}//end directoryConfiguration()

	/**
	 * The named source, read in system context, or null.
	 *
	 * @param string $sourceId The source id.
	 *
	 * @return array<string, mixed>|null The source object.
	 */
	private function readSource(string $sourceId): ?array {
		try {
			$entity = $this->objectService->find(
				id: $sourceId,
				register: RawSourceResolver::REGISTER,
				schema: RawSourceResolver::SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		$source = $entity->getObject();
		if (is_array($source) === false) {
			return null;
		}

		return $source;

	}//end readSource()

}//end class
