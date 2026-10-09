<?php

/**
 * Integriq Webhook Trust Migrator.
 *
 * Moves a webhook's signature trust from its legacy `source`
 * (`configuration.webhookSignature`) into the webhook's one consumer, with an
 * empty account. No step guesses an identity: until an administrator chooses
 * the account, deliveries are refused with 503 and the administrators get one
 * notification. The Open Formulieren migration introduced this; every other
 * webhook on the consumer model now uses the same code.
 *
 * Idempotent: when the webhook's consumer exists, or no enabled source
 * carries a webhook secret, nothing is written. The source is left as it is.
 *
 * The consumer write runs through OpenRegister's system operation context,
 * which the `runAsSystem()` docblock allows for "installation, migration,
 * repair, and seeding of the application's own shipped data". It never runs
 * during a request. The source read is an engine read of admin configuration.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-an-upgrade-moves-the-source-trust-into-the-consumer
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Intake;

use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCA\Integriq\Service\SystemWrite;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;

/**
 * Creates a webhook's consumer from its legacy source trust.
 *
 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-an-upgrade-moves-the-source-trust-into-the-consumer
 */
class WebhookTrustMigrator {

	/**
	 * The schema that held the trust before.
	 *
	 * @var string
	 */
	private const SCHEMA_SOURCE = 'source';

	/**
	 * Constructor.
	 *
	 * @param WebhookConnection   $webhooks      Finds the webhook's existing consumer.
	 * @param OrObjectService     $objectService Reads the legacy source and writes the consumer.
	 * @param DsoConnectionAlerts $alerts        Asks the administrators to choose the account.
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/design.md
	 */
	public function __construct(
		private readonly WebhookConnection $webhooks,
		private readonly OrObjectService $objectService,
		private readonly DsoConnectionAlerts $alerts,
	) {

	}//end __construct()

	/**
	 * Create the webhook's consumer from the legacy source trust, once.
	 *
	 * @param WebhookProfile $profile     The webhook.
	 * @param string|null    $name        The consumer name; the profile label plus "webhook" by default.
	 * @param string|null    $description The consumer description; a generic one by default.
	 *
	 * @return bool True when a consumer was created.
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-an-upgrade-moves-the-source-trust-into-the-consumer
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SystemWrite exposes only a static
	 * entry point, as in MigrateDsoStamConnection.
	 */
	public function migrate(WebhookProfile $profile, ?string $name = null, ?string $description = null): bool {
		if ($this->webhooks->findConsumers(profile: $profile) !== []) {
			return false;
		}

		$trust = $this->legacyTrust(profile: $profile);
		if ($trust === null) {
			return false;
		}

		$consumer = [
			'name' => ($name ?? $profile->label . ' webhook'),
			'description' => ($description ?? 'Signed deliveries of ' . $profile->label . '. Every delivery is stored as the account in userId.'),
			'authorizationType' => $profile->authorizationType,
			'authorizationConfiguration' => $trust,
			'userId' => '',
		];

		SystemWrite::run(
			what: 'the ' . $profile->label . ' connection migration',
			operation: fn () => $this->objectService->saveObject(
				object: $consumer,
				register: DsoConnection::REGISTER,
				schema: DsoConnection::SCHEMA_CONSUMER
			)
		);

		$this->alerts->notify(reason: DsoConnectionAlerts::REASON_CHOOSE_ACCOUNT, channel: $profile->channel);

		return true;

	}//end migrate()

	/**
	 * The webhook trust of the first enabled legacy source that has a secret.
	 *
	 * @param WebhookProfile $profile The webhook.
	 *
	 * @return array{scheme: string, secret: string, header: string, toleranceSeconds: int}|null
	 */
	private function legacyTrust(WebhookProfile $profile): ?array {
		if ($profile->legacySourceType === '') {
			return null;
		}

		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => DsoConnection::REGISTER,
					'schema' => self::SCHEMA_SOURCE,
					'type' => $profile->legacySourceType,
				],
			],
			_rbac: false,
			_multitenancy: false
		);
		$results = ($matches['results'] ?? $matches);

		foreach ($results as $source) {
			if ($source instanceof ObjectEntity === false) {
				continue;
			}

			$trust = $this->trustOf(profile: $profile, data: $this->readRaw(source: $source)->getObject());
			if ($trust !== null) {
				return $trust;
			}
		}

		return null;

	}//end legacyTrust()

	/**
	 * The webhook trust of one source, or null when it is disabled, another channel's or has no secret.
	 *
	 * @param WebhookProfile       $profile The webhook.
	 * @param array<string, mixed> $data    The raw source data.
	 *
	 * @return array{scheme: string, secret: string, header: string, toleranceSeconds: int}|null
	 */
	private function trustOf(WebhookProfile $profile, array $data): ?array {
		$configuration = ($data['configuration'] ?? []);
		if (($data['isEnabled'] ?? true) === false || is_array($configuration) === false) {
			return null;
		}

		if ($profile->legacyChannelId !== null && (string)($configuration['channelId'] ?? '') !== $profile->legacyChannelId) {
			return null;
		}

		$signature = ($configuration['webhookSignature'] ?? []);
		if (is_array($signature) === false || (string)($signature['secret'] ?? '') === '') {
			return null;
		}

		return [
			'scheme' => (string)($signature['scheme'] ?? $profile->defaultScheme),
			'secret' => (string)$signature['secret'],
			'header' => (string)($signature['header'] ?? $profile->defaultHeader),
			'toleranceSeconds' => (int)($signature['toleranceSeconds'] ?? 300),
		];

	}//end trustOf()

	/**
	 * Re-read a source raw, so a write-only secret comes back.
	 *
	 * @param ObjectEntity $source The source as listed.
	 *
	 * @return ObjectEntity The raw source, or the listed one when the re-read finds nothing.
	 */
	private function readRaw(ObjectEntity $source): ObjectEntity {
		$raw = $this->objectService->find(
			id: (string)$source->getUuid(),
			register: DsoConnection::REGISTER,
			schema: self::SCHEMA_SOURCE,
			_rbac: false,
			_multitenancy: false,
			_render: false
		);
		if ($raw instanceof ObjectEntity === false) {
			return $source;
		}

		return $raw;

	}//end readRaw()
}//end class
