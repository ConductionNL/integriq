<?php

/**
 * ConsumerCredentialUse: matches a presented API key or client certificate
 * against a consumer's listed credentials, and records when one was last used.
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

use DateTimeImmutable;
use OCA\Integriq\Service\Auth\ClientCertificate;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * The use of a consumer's listed credentials.
 *
 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-consumer-holds-several-credentials-and-no-plaintext-key-req-cred-001
 */
class ConsumerCredentialUse {

	/**
	 * Constructor.
	 *
	 * @param ObjectService       $objectService Reads and writes the consumer.
	 * @param ConsumerCredentials $credentials   The credentials list rules.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ConsumerCredentials $credentials,
	) {
	}//end __construct()

	/**
	 * Whether a listed, live API key credential of the consumer is this key; its use is recorded.
	 *
	 * @param ObjectEntity      $consumer The consumer.
	 * @param string            $uuid     The consumer's uuid, where the use is written.
	 * @param string            $apiKey   The presented key.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-consumer-holds-several-credentials-and-no-plaintext-key-req-cred-001
	 */
	public function matchesApiKey(ObjectEntity $consumer, string $uuid, string $apiKey): bool {
		$now   = new DateTimeImmutable();
		$index = $this->credentials->matchingApiKey(credentials: ($consumer->getObject()[ConsumerCredentials::FIELD] ?? null), key: $apiKey, now: $now);
		if ($index === null) {
			return false;
		}

		$this->recordUse(consumer: $consumer, uuid: $uuid, index: $index, now: $now);
		return true;
	}//end matchesApiKey()

	/**
	 * The first consumer a listed, live client certificate credential of which pins the certificate; its use is recorded.
	 *
	 * @param list<ObjectEntity> $consumers   The consumers, read unrendered.
	 * @param ClientCertificate  $certificate The presented, web-server-verified certificate.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-consumer-holds-several-credentials-and-no-plaintext-key-req-cred-001
	 */
	public function certificateHolder(array $consumers, ClientCertificate $certificate): ?ObjectEntity {
		$now = new DateTimeImmutable();
		foreach ($consumers as $consumer) {
			$index = $this->credentials->matchingCertificate(
				credentials: ($consumer->getObject()[ConsumerCredentials::FIELD] ?? null),
				certificate: $certificate,
				now: $now
			);
			if ($index !== null) {
				$this->recordUse(consumer: $consumer, uuid: (string)($consumer->getUuid() ?? ($consumer->getObject()['uuid'] ?? '')), index: $index, now: $now);
				return $consumer;
			}
		}

		return null;
	}//end certificateHolder()

	/**
	 * Write `lastUsedAt` on the credential a call used, at most once a minute.
	 *
	 * A failed write never refuses the call: the key matched, and when it was
	 * last used is bookkeeping for the administrator.
	 *
	 * @param ObjectEntity      $consumer The consumer the key belongs to.
	 * @param string            $uuid     The consumer's uuid.
	 * @param int               $index    The credential's index in the list.
	 * @param DateTimeImmutable $now      The moment of the call.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-key-can-be-replaced-without-downtime-req-cred-003
	 */
	private function recordUse(ObjectEntity $consumer, string $uuid, int $index, DateTimeImmutable $now): void {
		$credential = ($consumer->getObject()[ConsumerCredentials::FIELD][$index] ?? null);
		$touched    = $this->touchedCredential(credential: $credential, now: $now);
		if ($touched === null) {
			return;
		}

		if ($uuid === '') {
			return;
		}

		try {
			$this->writeLastUsed(uuid: $uuid, index: $index, credentialId: ($credential['id'] ?? null), lastUsedAt: $touched['lastUsedAt']);
		} catch (Throwable) {
			return;
		}
	}//end recordUse()

	/**
	 * The credential with `lastUsedAt` moved to now, or null when there is nothing to write.
	 *
	 * @param mixed             $credential The stored credential.
	 * @param DateTimeImmutable $now        The moment of the call.
	 *
	 * @return array<string, mixed>|null
	 */
	private function touchedCredential(mixed $credential, DateTimeImmutable $now): ?array {
		if (is_array($credential) === false) {
			return null;
		}

		return $this->credentials->touched(credential: $credential, now: $now);
	}//end touchedCredential()

	/**
	 * Write `lastUsedAt` on the credential at the index, when it is still the same credential.
	 *
	 * Written from the raw object: the list is saved whole, so a rendered read
	 * without the hashes would drop every other key.
	 *
	 * @param string $uuid         The consumer's uuid.
	 * @param int    $index        The credential's index in the list.
	 * @param mixed  $credentialId The credential's id as it was matched.
	 * @param mixed  $lastUsedAt   The new `lastUsedAt`.
	 *
	 * @return void
	 */
	private function writeLastUsed(string $uuid, int $index, mixed $credentialId, mixed $lastUsedAt): void {
		$raw  = $this->objectService->find(
			id: $uuid,
			options: new ObjectFindOptions(register: 'integriq', schema: 'consumer', render: false),
			_rbac: false,
			_multitenancy: false
		);
		$data = $raw?->getObject();
		if (is_array($data) === false || (($data[ConsumerCredentials::FIELD][$index]['id'] ?? null) !== $credentialId)) {
			return;
		}

		$data[ConsumerCredentials::FIELD][$index]['lastUsedAt'] = $lastUsedAt;
		$this->objectService->saveObject(
			object: $data,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: 'integriq', schema: 'consumer'),
				uuid: $uuid,
				policy: new SavePolicy(silent: true)
			),
			_rbac: false,
			_multitenancy: false
		);
	}//end writeLastUsed()
}//end class
