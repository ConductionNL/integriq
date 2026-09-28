<?php

/**
 * The payment paths keep working once payment_intent is locked down.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/messaging-payments-review/design.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\Payment\LogPaymentProvider;
use OCA\Integriq\Service\Payment\MolliePaymentProvider;
use OCA\Integriq\Service\PaymentIntentService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An OpenRegister object service that behaves like the lockdown for a caller
 * who is neither admin nor owner: the provider webhook (a public page, no
 * session) or a delegated `payments.create` holder.
 *
 * `source` is admin-only (`99-source-lockdown.json`) and `payment_intent`
 * denies to everyone but admins and the owner (`99-payment-intent-lockdown.json`).
 * A read with `_rbac: true` on either returns nothing and a write throws, as
 * OpenRegister's permission handler does; with `_rbac: false` the caller is
 * the engine in system context and is served.
 */
class LockdownSimulatingObjectService extends OrObjectService {

	/**
	 * Schemas the simulated caller may not touch under RBAC.
	 *
	 * @var array<int, string>
	 */
	private const LOCKED = ['source', 'payment_intent'];

	/**
	 * Stored rows per schema.
	 *
	 * @var array<string, array<int, ObjectEntity>>
	 */
	public array $rows = [];

	/**
	 * Every write that reached storage.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saved = [];

	/**
	 * Constructor, without the stub's.
	 */
	public function __construct() {
	}//end __construct()

	/**
	 * List rows, denied under RBAC for a locked schema.
	 *
	 * @param array $config The query config.
	 * @param bool $_rbac Whether RBAC applies.
	 * @param bool $_multitenancy Whether multitenancy applies.
	 *
	 * @return array
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$schema = (string)($config['filters']['schema'] ?? '');
		if ($_rbac === true && in_array($schema, self::LOCKED, true) === true) {
			return ['results' => [], 'total' => 0];
		}

		$rows = ($this->rows[$schema] ?? []);

		return ['results' => $rows, 'total' => count($rows)];
	}//end findAll()

	/**
	 * Save a row, refused under RBAC for a locked schema.
	 *
	 * @param mixed $object The object.
	 * @param string|null $register The register.
	 * @param string|null $schema The schema.
	 * @param string|null $uuid The uuid.
	 * @param bool $_rbac Whether RBAC applies.
	 * @param bool $_multitenancy Whether multitenancy applies.
	 * @param bool $silent Silent save.
	 * @param bool $_validation Validate.
	 *
	 * @return ObjectEntity
	 */
	public function saveObject(
		$object,
		?string $register = null,
		?string $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $silent = false,
		bool $_validation = true,
	): ObjectEntity {
		if ($_rbac === true && in_array((string)$schema, self::LOCKED, true) === true) {
			throw new RuntimeException('Permission denied: create/update on ' . $schema);
		}

		$this->saved[] = ['schema' => $schema, 'uuid' => $uuid, 'object' => $object];
		$entity = new ObjectEntity();
		$entity->setObject((array)$object);
		$entity->setUuid($uuid ?? 'pi-new');

		return $entity;
	}//end saveObject()
}//end class

/**
 * Integriq#2145 (payment_intent, messaging-payments-review D5): locking the
 * schema must not lock out the app's own machinery. The provider webhook runs
 * with no session and a `payments.create` holder need not be an admin, so the
 * service reads and writes both schemas in system context; the ADR-023 action
 * check and the webhook signature are what gate them.
 *
 * @spec openspec/changes/messaging-payments-review/design.md
 */
class PaymentIntentServiceLockdownTest extends TestCase {

	/**
	 * The lockdown double.
	 *
	 * @var LockdownSimulatingObjectService
	 */
	private LockdownSimulatingObjectService $objectService;

	/**
	 * The log provider double.
	 *
	 * @var LogPaymentProvider&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logProvider;

	/**
	 * The service under test.
	 *
	 * @var PaymentIntentService
	 */
	private PaymentIntentService $service;

	/**
	 * Build an entity.
	 *
	 * @param array $data The data.
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data, string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		$entity->setUuid($uuid);

		return $entity;
	}//end entity()

	/**
	 * Set up a locked instance with one payment source and one open payment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = new LockdownSimulatingObjectService();
		$this->objectService->rows['source'] = [
			$this->entity(['slug' => 'payment-sandbox', 'type' => 'payment', 'configuration' => ['provider' => 'log']], 'src-1'),
		];
		$this->objectService->rows['payment_intent'] = [
			$this->entity(
				['sourceSlug' => 'payment-sandbox', 'providerPaymentId' => 'MOCK-PAY-1', 'paymentStatus' => 'open', 'lastOutcome' => null],
				'pi-uuid-1'
			),
		];

		$this->logProvider = $this->createMock(LogPaymentProvider::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->service = new PaymentIntentService(
			$this->objectService,
			$this->logProvider,
			$this->createMock(MolliePaymentProvider::class),
			$this->createMock(EventService::class),
			$l10n,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * The sessionless webhook still finds the payment and applies the outcome.
	 *
	 * @return void
	 */
	public function testTheWebhookStillAppliesTheOutcome(): void {
		$this->logProvider->method('fetchPaymentStatus')->willReturn(
			['providerPaymentId' => 'MOCK-PAY-1', 'paymentStatus' => 'paid']
		);

		$result = $this->service->handleWebhook(providerPaymentId: 'MOCK-PAY-1');

		$this->assertSame('applied', $result['result'], 'the locked-down payment was not found or not written');
		$this->assertSame('captured', $this->objectService->saved[0]['object']['lastOutcome']);
		$this->assertSame('pi-uuid-1', $this->objectService->saved[0]['uuid']);
	}//end testTheWebhookStillAppliesTheOutcome()

	/**
	 * A non-admin `payments.create` holder can still create a payment.
	 *
	 * @return void
	 */
	public function testANonAdminCreateStillPersists(): void {
		$this->logProvider->method('createPayment')->willReturn(
			['providerPaymentId' => 'MOCK-PAY-2', 'paymentStatus' => 'open', 'checkoutUrl' => 'https://pay.example/2', 'extras' => []]
		);

		$result = $this->service->createPayment(
			payload: [
				'amount' => ['value' => '10.00', 'currency' => 'EUR'],
				'description' => 'Invoice INV-2',
				'redirectUrl' => 'https://example.com/return',
			]
		);

		$this->assertSame('MOCK-PAY-2', $result['providerPaymentId']);
		$this->assertSame('payment_intent', $this->objectService->saved[0]['schema']);
	}//end testANonAdminCreateStillPersists()
}//end class
