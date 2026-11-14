<?php

/**
 * Integriq Payment Settlement Service.
 *
 * Reports every provider settlement once (messaging-payment-command-
 * settlements-and-point-of-sale D2). For each payment source whose provider
 * is not `log`, it reads the settlements of the last LOOKBACK_DAYS from the
 * provider binding, and for each one not stored yet it emits one
 * `nl.conduction.payment.settlement` CloudEvent with the settlement and its
 * transaction lines and stores it as a `payment_settlement` object, unique
 * per source and settlement reference. A settlement already stored is
 * skipped, so a rerun sends nothing twice.
 *
 * The event goes out before the record is stored: when the store fails, the
 * next pull sends the settlement again rather than never. A consumer keys on
 * `settlementReference`, so a repeat is harmless and a loss is not.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Payment
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
 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-every-provider-settlement-is-reported-once-with-its-transactions-req-lpp-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Payment;

use OCA\Integriq\Service\CloudEventEmitterInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pulls provider settlements, stores each once and reports it as a CloudEvent.
 *
 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-every-provider-settlement-is-reported-once-with-its-transactions-req-lpp-008
 */
class PaymentSettlementService {

	/**
	 * The OpenRegister register slug integriq stores its objects in.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * The schema of a stored settlement.
	 *
	 * @var string
	 */
	public const SCHEMA_SETTLEMENT = 'payment_settlement';

	/**
	 * The CloudEvent type of a reported settlement.
	 *
	 * @var string
	 */
	public const EVENT_TYPE = 'nl.conduction.payment.settlement';

	/**
	 * How far back each pull reads.
	 *
	 * @var integer
	 */
	public const LOOKBACK_DAYS = 35;

	/**
	 * Constructor.
	 *
	 * @param ORObjectService            $objectService OpenRegister objects (system context).
	 * @param MolliePaymentProvider      $mollie        The Mollie binding.
	 * @param CloudEventEmitterInterface $events        The CloudEvent channel.
	 * @param ITimeFactory               $time          The clock.
	 * @param LoggerInterface            $logger        The logger.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly MolliePaymentProvider $mollie,
		private readonly CloudEventEmitterInterface $events,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Pull every payment source whose provider is not `log`.
	 *
	 * A source that fails is logged and counted; the others still run.
	 *
	 * @return array{sources: int, reported: int, failed: int}
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-every-provider-settlement-is-reported-once-with-its-transactions-req-lpp-008
	 */
	public function pullAll(): array {
		$report = ['sources' => 0, 'reported' => 0, 'failed' => 0];
		foreach ($this->rows(filters: ['schema' => 'source', 'type' => 'payment'], limit: 100) as $source) {
			$provider = $this->providerFor(configuration: ($source['configuration'] ?? []));
			if ($provider === null) {
				continue;
			}

			$report['sources']++;
			try {
				$report['reported'] += $this->pull(source: $source, provider: $provider);
			} catch (Throwable $e) {
				$report['failed']++;
				$this->logger->error(
					'integriq: payment settlements could not be pulled',
					['source' => (string)($source['slug'] ?? ''), 'exception' => $e->getMessage()]
				);
			}
		}

		return $report;

	}//end pullAll()

	/**
	 * Pull one source and report its new settlements.
	 *
	 * @param array                    $source   The payment source payload.
	 * @param PaymentProviderInterface $provider Its provider binding.
	 *
	 * @return int How many settlements were reported.
	 *
	 * @throws \OCA\Integriq\Exception\PaymentProviderException When the provider errors.
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-every-provider-settlement-is-reported-once-with-its-transactions-req-lpp-008
	 */
	public function pull(array $source, PaymentProviderInterface $provider): int {
		$sourceSlug = (string)($source['slug'] ?? '');
		$since = gmdate('Y-m-d\TH:i:s\Z', ($this->time->getTime() - (self::LOOKBACK_DAYS * 86400)));
		$reported = 0;
		foreach ($provider->fetchSettlements(sourceConfiguration: ($source['configuration'] ?? []), since: $since) as $settlement) {
			$reference = (string)($settlement['settlementReference'] ?? '');
			if ($reference === '' || $this->isStored(sourceSlug: $sourceSlug, reference: $reference) === true) {
				continue;
			}

			$this->events->emitCloudEvent(
				type: self::EVENT_TYPE,
				source: '/payments/settlements/' . $reference,
				subject: $reference,
				data: $settlement
			);
			$this->objectService->saveObject(
				object: array_merge(
					$settlement,
					[
						'sourceSlug' => $sourceSlug,
						'provider' => (string)($source['configuration']['provider'] ?? ''),
						'reportedAt' => gmdate('c', $this->time->getTime()),
					]
				),
				register: self::REGISTER,
				schema: self::SCHEMA_SETTLEMENT,
				// System context: the pull runs as a background job, and
				// payment_settlement is locked to administrators.
				_rbac: false,
				_multitenancy: false
			);
			$reported++;
		}//end foreach

		return $reported;

	}//end pull()

	/**
	 * The binding to pull, or null for a source that is not pulled.
	 *
	 * @param mixed $configuration The source configuration.
	 *
	 * @return PaymentProviderInterface|null Null for `log` and unknown providers.
	 */
	private function providerFor(mixed $configuration): ?PaymentProviderInterface {
		if (is_array($configuration) === true && ($configuration['provider'] ?? 'log') === 'mollie') {
			return $this->mollie;
		}

		return null;

	}//end providerFor()

	/**
	 * Whether a source's settlement is already stored.
	 *
	 * @param string $sourceSlug The source slug.
	 * @param string $reference  The settlement reference.
	 *
	 * @return bool
	 */
	private function isStored(string $sourceSlug, string $reference): bool {
		return $this->rows(
			filters: ['schema' => self::SCHEMA_SETTLEMENT, 'sourceSlug' => $sourceSlug, 'settlementReference' => $reference],
			limit: 1
		) !== [];

	}//end isStored()

	/**
	 * Rows of a schema in register `integriq`, as payload arrays.
	 *
	 * @param array<string, mixed> $filters The filters, `schema` included.
	 * @param int                  $limit   The most rows.
	 *
	 * @return list<array<string, mixed>> The payloads.
	 */
	private function rows(array $filters, int $limit): array {
		// Unscoped: the settlement pull job reads its rows as the system, with no user session.
		$found = $this->objectService->findAll(
			config: ['filters' => array_merge(['register' => self::REGISTER], $filters), 'limit' => $limit],
			_rbac: false,
			_multitenancy: false
		);

		$rows = [];
		foreach (($found['results'] ?? $found) as $row) {
			$data = $row;
			if ($row instanceof ObjectEntity) {
				$data = $row->getObject();
			}
			if (is_array($data) === true) {
				$rows[] = $data;
			}
		}

		return $rows;

	}//end rows()
}//end class
