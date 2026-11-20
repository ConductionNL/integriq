<?php

/**
 * ConsumerCredentialList: reads a consumer unrendered and adds or removes an
 * entry of its credentials list, in system context.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Consumer;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use Throwable;

/**
 * A consumer's credentials list, as stored.
 *
 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-consumer-holds-several-credentials-and-no-plaintext-key-req-cred-001
 */
class ConsumerCredentialList {

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $objectService Reads and saves the consumer.
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The consumer's raw object, hashes included, or null.
	 *
	 * @param string $id The consumer's uuid.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-consumer-holds-several-credentials-and-no-plaintext-key-req-cred-001
	 */
	public function find(string $id): ?ObjectEntity {
		try {
			$consumer = $this->objectService->find(
				id: $id,
				options: new ObjectFindOptions(register: 'integriq', schema: 'consumer', render: false),
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException) {
			return null;
		}

		if (($consumer instanceof ObjectEntity) === false || is_array($consumer->getObject()) === false) {
			return null;
		}

		return $consumer;
	}//end find()

	/**
	 * The stored credentials list, entries that are not objects dropped.
	 *
	 * @param array<string, mixed> $data The consumer's raw data.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-consumer-holds-several-credentials-and-no-plaintext-key-req-cred-001
	 */
	public function entries(array $data): array {
		$list = ($data[ConsumerCredentials::FIELD] ?? []);
		if (is_array($list) === false) {
			return [];
		}

		return array_values(array_filter($list, 'is_array'));
	}//end entries()

	/**
	 * Append an entry to the consumer's credentials and save.
	 *
	 * @param ObjectEntity         $consumer The consumer.
	 * @param array<string, mixed> $entry    The new credential.
	 *
	 * @return bool Whether the save landed.
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-consumer-holds-several-credentials-and-no-plaintext-key-req-cred-001
	 */
	public function add(ObjectEntity $consumer, array $entry): bool {
		$data = $consumer->getObject();
		$data[ConsumerCredentials::FIELD] = array_merge($this->entries(data: $data), [$entry]);

		return $this->save(consumer: $consumer, data: $data);
	}//end add()

	/**
	 * Remove one entry from the consumer's credentials list.
	 *
	 * @param ObjectEntity $consumer     The consumer, read unrendered.
	 * @param string       $credentialId The entry id.
	 *
	 * @return bool|null True when removed, false when the save failed, null when the consumer holds no such entry.
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-consumer-holds-several-credentials-and-no-plaintext-key-req-cred-001
	 */
	public function remove(ObjectEntity $consumer, string $credentialId): ?bool {
		$data = $consumer->getObject();
		$list = $this->entries(data: $data);
		$kept = array_values(array_filter($list, static fn (array $credential): bool => ($credential['id'] ?? null) !== $credentialId));
		if (count($kept) === count($list)) {
			return null;
		}

		$data[ConsumerCredentials::FIELD] = $kept;

		return $this->save(consumer: $consumer, data: $data);
	}//end remove()

	/**
	 * Save the consumer.
	 *
	 * @param ObjectEntity         $consumer The consumer.
	 * @param array<string, mixed> $data     Its new data.
	 *
	 * @return bool Whether the save landed.
	 */
	private function save(ObjectEntity $consumer, array $data): bool {
		try {
			$this->objectService->saveObject(
				object: $data,
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'integriq', schema: 'consumer'), uuid: $consumer->getUuid()),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable) {
			return false;
		}

		return true;
	}//end save()
}//end class
