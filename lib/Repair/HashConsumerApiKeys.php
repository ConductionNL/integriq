<?php

/**
 * Integriq — move each consumer's plaintext API key into a hashed credential.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Repair
 * @package  OCA\Integriq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCA\Integriq\Service\Consumer\ConsumerCredentials;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hashes `authorizationConfiguration.apiKey` into a first `credentials` entry
 * labelled "migrated", then nulls the plaintext.
 *
 * Per consumer, in two saves: the hashed entry first, the plaintext removal
 * only after that save succeeded. A failure halfway leaves that consumer with
 * its plaintext key, so its calls keep passing and the next run finishes it.
 * Idempotent: a consumer without a plaintext key is skipped, and a key whose
 * hash is already in the list is not added twice. Never throws.
 *
 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-consumer-holds-several-credentials-and-no-plaintext-key-req-cred-001
 */
class HashConsumerApiKeys implements IRepairStep {

	/**
	 * OpenRegister's object service, resolved only when OpenRegister is present.
	 */
	private const OR_OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * The register of integriq's consumers.
	 */
	private const REGISTER = 'integriq';

	/**
	 * The consumer schema.
	 */
	private const SCHEMA = 'consumer';

	/**
	 * The label of a migrated key.
	 */
	public const MIGRATED_LABEL = 'migrated';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface  $container   Resolves OpenRegister's object service.
	 * @param ConsumerCredentials $credentials Hashes the keys.
	 * @param LoggerInterface     $logger      Logs per-consumer failures, never a key.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly ConsumerCredentials $credentials,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-consumer-holds-several-credentials-and-no-plaintext-key-req-cred-001
	 */
	public function getName(): string {
		return 'Move consumer API keys into hashed credentials';
	}//end getName()

	/**
	 * Hash every consumer's plaintext key into its credentials list.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-consumer-holds-several-credentials-and-no-plaintext-key-req-cred-001
	 */
	public function run(IOutput $output): void {
		if (class_exists('\\' . self::OR_OBJECT_SERVICE) === false) {
			$output->info('Integriq: OpenRegister not available; skipping consumer key hashing.');
			return;
		}

		try {
			$objectService = $this->container->get(self::OR_OBJECT_SERVICE);
			$found = $objectService->findAll(
				config: ['filters' => ['register' => self::REGISTER, 'schema' => self::SCHEMA]],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$output->warning('Integriq: could not read consumers for key hashing: ' . get_class($e));
			return;
		}

		$migrated = 0;
		$failed = 0;
		foreach (($found['results'] ?? $found) as $consumer) {
			if (is_object($consumer) === false || method_exists($consumer, 'getUuid') === false) {
				continue;
			}

			$outcome = $this->migrateOne(objectService: $objectService, uuid: (string)$consumer->getUuid());
			if ($outcome === true) {
				$migrated++;
			} elseif ($outcome === false) {
				$failed++;
			}
		}

		$output->info(sprintf('Integriq: hashed %d consumer API key(s), %d failed.', $migrated, $failed));
	}//end run()

	/**
	 * Migrate one consumer.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $uuid          The consumer's uuid.
	 *
	 * @return bool|null True when migrated, false on a failure, null when there was nothing to do.
	 */
	private function migrateOne(object $objectService, string $uuid): ?bool {
		if ($uuid === '') {
			return null;
		}

		try {
			$data = $this->rawData(objectService: $objectService, uuid: $uuid);
			$key = ($data['authorizationConfiguration']['apiKey'] ?? null);
			if ($data === null || is_string($key) === false || $key === '') {
				return null;
			}

			$list = ($data[ConsumerCredentials::FIELD] ?? []);
			if (is_array($list) === false || array_is_list($list) === false) {
				$list = [];
			}

			$hash = $this->credentials->hashKey(key: $key);
			if (in_array($hash, array_column(array_filter($list, 'is_array'), 'keyHash'), true) === false) {
				$list[] = $this->credentials->apiKeyEntry(key: $key, label: self::MIGRATED_LABEL);
				$data[ConsumerCredentials::FIELD] = $list;
				$this->save(objectService: $objectService, uuid: $uuid, data: $data);
			}

			// Only now, with the hashed entry saved, does the plaintext go.
			$data = $this->rawData(objectService: $objectService, uuid: $uuid);
			$saved = ($data[ConsumerCredentials::FIELD] ?? []);
			if ($data === null || is_array($saved) === false || in_array($hash, array_column(array_filter($saved, 'is_array'), 'keyHash'), true) === false) {
				$this->logger->warning('[integriq] consumer key hashing: entry not found after save, plaintext kept', ['uuid' => $uuid]);
				return false;
			}

			// NULL, not unset: saveObject merges, so an omitted key would be kept.
			$data['authorizationConfiguration']['apiKey'] = null;
			$this->save(objectService: $objectService, uuid: $uuid, data: $data);
		} catch (Throwable $e) {
			$this->logger->warning('[integriq] consumer key hashing failed, plaintext kept', ['uuid' => $uuid, 'errorClass' => get_class($e)]);
			return false;
		}//end try

		return true;
	}//end migrateOne()

	/**
	 * The consumer's raw stored data, write-only fields included.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $uuid          The consumer's uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function rawData(object $objectService, string $uuid): ?array {
		$entity = $objectService->find(id: $uuid, register: self::REGISTER, schema: self::SCHEMA, _rbac: false, _multitenancy: false, _render: false);
		if (is_object($entity) === false || method_exists($entity, 'getObject') === false) {
			return null;
		}

		$data = $entity->getObject();
		if (is_array($data) === false) {
			return null;
		}

		return $data;
	}//end rawData()

	/**
	 * Save the consumer's data.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $uuid          The consumer's uuid.
	 * @param array<string, mixed> $data          The data.
	 *
	 * @return void
	 */
	private function save(object $objectService, string $uuid, array $data): void {
		$objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);
	}//end save()
}//end class
