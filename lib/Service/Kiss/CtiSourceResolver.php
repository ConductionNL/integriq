<?php

/**
 * Integriq CTI Source Resolver.
 *
 * Reads a CTI source and hands back the binding it names.
 *
 * Mirrors {@see \OCA\Integriq\Service\KissSyncService::resolveProvider()}: a
 * switch on `configuration.provider` over constructor-injected bindings. An
 * unknown provider resolves to NOTHING rather than to the log sandbox: falling
 * back would make a typo in a source's configuration look like a working
 * integration that quietly delivers to a log file.
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
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Kiss;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finds a CTI source and the binding that reads its payloads.
 */
class CtiSourceResolver {

	/**
	 * The register the sources live in.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * The source schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_SOURCE = 'source';

	/**
	 * The source type a CTI source carries.
	 *
	 * @var string
	 */
	public const SOURCE_TYPE = 'cti';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService The OpenRegister object store.
	 * @param LogCtiProvider $logProvider The sandbox binding.
	 * @param WebhookCtiProvider $webhookProvider The mapped-webhook binding.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LogCtiProvider $logProvider,
		private readonly WebhookCtiProvider $webhookProvider,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The CTI source for an id, or null.
	 *
	 * Returns null for a source that is not a CTI source, and for a disabled
	 * one. Both are refusals the controller turns into the same 401, so a
	 * disabled source cannot be told from one that never existed.
	 *
	 * @param string $sourceId The source id.
	 *
	 * @return array<string, mixed>|null The source, or null.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	public function find(string $sourceId): ?array {
		if (trim($sourceId) === '') {
			return null;
		}

		try {
			$entity = $this->objectService->find(
				id: $sourceId,
				register: self::REGISTER,
				schema: self::SCHEMA_SOURCE,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		$source = $entity->getObject();
		if (is_array($source) === false) {
			return null;
		}

		if ((string) ($source['type'] ?? '') !== self::SOURCE_TYPE) {
			return null;
		}

		if (($source['isEnabled'] ?? true) === false) {
			return null;
		}

		return $source;

	}//end find()

	/**
	 * The binding a source names, or null when it names none this instance has.
	 *
	 * @param array $source The source.
	 *
	 * @return CtiProviderInterface|null The binding, or null.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	public function provider(array $source): ?CtiProviderInterface {
		$named = (string) (($source['configuration'] ?? [])['provider'] ?? '');

		foreach ($this->bindings() as $binding) {
			if ($named === $binding->getProviderId()) {
				return $binding;
			}
		}

		// NOT a fallback to the log binding. A typo would otherwise look like
		// a working integration that quietly delivers to a log file.
		$this->logger->warning('[CtiSourceResolver] no CTI binding named "'.$named.'"');

		return null;

	}//end provider()

	/**
	 * Every CTI binding this instance has, with the configuration it reads.
	 *
	 * The source form builds its binding picker from this, so the picker
	 * offers exactly what {@see provider()} resolves: a binding added here
	 * shows up there, and a name the form saves is one a source can use.
	 *
	 * @return array<int, array{id: string, configSchema: array<string, mixed>}> One entry per binding.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	public function describeAll(): array {
		return array_map(
			static fn (CtiProviderInterface $binding): array => [
				'id'           => $binding->getProviderId(),
				'configSchema' => $binding->getConfigSchema(),
			],
			$this->bindings()
		);

	}//end describeAll()

	/**
	 * The bindings, in the order a name is matched against them.
	 *
	 * @return array<int, CtiProviderInterface> The bindings.
	 */
	private function bindings(): array {
		return [$this->logProvider, $this->webhookProvider];

	}//end bindings()

}//end class
