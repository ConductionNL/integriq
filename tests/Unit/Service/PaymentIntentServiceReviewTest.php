<?php

/**
 * Unit tests for the payments review additions to PaymentIntentService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/messaging-payments-review/tasks.md#task-2
 * @spec openspec/changes/messaging-payments-review/tasks.md#task-3
 * @spec openspec/changes/messaging-payments-review/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Exception\PaymentProviderException;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\Payment\LogPaymentProvider;
use OCA\Integriq\Service\Payment\MolliePaymentProvider;
use OCA\Integriq\Service\PaymentIntentService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Refresh, the provider list and check, and the refusal of an unknown provider.
 *
 * @spec openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md
 */
class PaymentIntentServiceReviewTest extends TestCase {

	/**
	 * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * @var MolliePaymentProvider|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $mollieProvider;

	/**
	 * @var EventService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $eventService;

	/**
	 * @var PaymentIntentService
	 */
	private PaymentIntentService $service;

	/**
	 * Set up a service over the real log binding and a stubbed Mollie binding.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->mollieProvider = $this->createMock(originalClassName: MolliePaymentProvider::class);
		$this->eventService = $this->createMock(originalClassName: EventService::class);
		$l = $this->createMock(originalClassName: IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$this->service = new PaymentIntentService(
			objectService: $this->objectService,
			logProvider: new LogPaymentProvider(),
			mollieProvider: $this->mollieProvider,
			eventService: $this->eventService,
			l: $l,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * A real ObjectEntity with the given data.
	 *
	 * @param array  $data The object data.
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
	 * Answer findAll with the source for the source schema and the record otherwise.
	 *
	 * @param ObjectEntity      $source The payment source.
	 * @param ObjectEntity|null $record The payment_intent, or null for none.
	 *
	 * @return void
	 */
	private function stubFindAll(ObjectEntity $source, ?ObjectEntity $record): void {
		$this->objectService->method('findAll')->willReturnCallback(
			static function (array $config) use ($source, $record): array {
				if (($config['filters']['schema'] ?? null) === 'source') {
					return ['results' => [$source], 'total' => 1];
				}

				if ($record === null) {
					return ['results' => [], 'total' => 0];
				}

				return ['results' => [$record], 'total' => 1];
			}
		);

	}//end stubFindAll()

	/**
	 * A payment payload that passes validation.
	 *
	 * @return array
	 */
	private function payload(): array {
		return [
			'amount' => ['value' => '12.50', 'currency' => 'EUR'],
			'description' => 'Ouderbijdrage',
			'redirectUrl' => 'https://school.example/return',
			'webhookUrl' => 'https://school.example/webhook',
		];

	}//end payload()

	/**
	 * A mistyped provider stops the payment with its name, and nothing is stored (REQ-PRV-004).
	 *
	 * @return void
	 */
	public function testCreatePaymentRefusesAnUnknownProviderAndStoresNothing(): void {
		$source = $this->entity(data: ['slug' => 'ideal', 'type' => 'payment', 'configuration' => ['provider' => 'Mollie']], uuid: 'src-1');
		$this->stubFindAll(source: $source, record: null);

		$this->objectService->expects($this->never())->method('saveObject');
		$this->mollieProvider->expects($this->never())->method('createPayment');

		$this->expectException(exception: PaymentProviderException::class);
		$this->expectExceptionMessage(message: 'Unknown payment provider: "Mollie". Known providers: log, mollie.');

		$this->service->createPayment(payload: $this->payload());

	}//end testCreatePaymentRefusesAnUnknownProviderAndStoresNothing()

	/**
	 * A source with no provider value keeps running on the log binding (REQ-PRV-004).
	 *
	 * @return void
	 */
	public function testCreatePaymentWithoutProviderRunsTheLogBinding(): void {
		$source = $this->entity(data: ['slug' => 'ideal', 'type' => 'payment', 'configuration' => []], uuid: 'src-1');
		$this->stubFindAll(source: $source, record: null);

		$this->mollieProvider->expects($this->never())->method('createPayment');
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(
				$this->callback(
					callback: static fn (array $object): bool => $object['provider'] === 'log'
						&& str_starts_with($object['providerPaymentId'], 'MOCK-PAY-')
				)
			)
			->willReturn($this->entity(data: [], uuid: 'pi-1'));

		$result = $this->service->createPayment(payload: $this->payload());

		$this->assertTrue(condition: $result['dormant']);
		$this->assertStringStartsWith(prefix: 'https://sandbox.payment.example/checkout/MOCK-PAY-', string: $result['checkoutUrl']);

	}//end testCreatePaymentWithoutProviderRunsTheLogBinding()

	/**
	 * A status refresh of a payment on an unknown provider fails with its name (REQ-PRV-004).
	 *
	 * @return void
	 */
	public function testRefreshOnAnUnknownProviderFailsNamingIt(): void {
		$record = $this->entity(data: ['sourceSlug' => 'ideal', 'providerPaymentId' => 'MOCK-PAY-7', 'lastOutcome' => null], uuid: 'pi-7');
		$source = $this->entity(data: ['slug' => 'ideal', 'type' => 'payment', 'configuration' => ['provider' => 'stripe']], uuid: 'src-1');
		$this->objectService->method('find')->willReturn($record);
		$this->stubFindAll(source: $source, record: $record);

		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(exception: PaymentProviderException::class);
		$this->expectExceptionMessage(message: '"stripe"');

		$this->service->refreshPayment(paymentIntentId: 'pi-7');

	}//end testRefreshOnAnUnknownProviderFailsNamingIt()

	/**
	 * A refresh runs the webhook's re-derive: paid becomes captured with one event (REQ-PRV-002).
	 *
	 * @return void
	 */
	public function testRefreshSettlesAPaidPaymentAndEmitsOnce(): void {
		$record = $this->entity(
			data: ['sourceSlug' => 'ideal', 'providerPaymentId' => 'MOCK-PAY-9', 'paymentStatus' => 'open', 'lastOutcome' => null],
			uuid: 'pi-9'
		);
		$source = $this->entity(
			data: ['slug' => 'ideal', 'type' => 'payment', 'configuration' => ['provider' => 'log', 'mockStatuses' => ['MOCK-PAY-9' => 'paid']]],
			uuid: 'src-1'
		);
		$this->objectService->expects($this->once())
			->method('find')
			->with('pi-9', $this->equalTo(value: new ObjectFindOptions(register: 'integriq', schema: 'payment_intent')), false, false)
			->willReturn($record);
		$this->stubFindAll(source: $source, record: $record);

		$this->objectService->expects($this->once())
			->method('saveObject')
			->with($this->callback(callback: static fn (array $object): bool => $object['paymentStatus'] === 'paid' && $object['lastOutcome'] === 'captured'))
			->willReturn($record);
		$this->eventService->expects($this->once())
			->method('emitCloudEvent')
			->with(PaymentIntentService::EVENT_TYPE_STATUS, '/payments/MOCK-PAY-9', 'MOCK-PAY-9', $this->anything());

		$result = $this->service->refreshPayment(paymentIntentId: 'pi-9');

		$this->assertSame(expected: ['result' => 'applied', 'outcome' => 'captured', 'paymentStatus' => 'paid'], actual: $result);

	}//end testRefreshSettlesAPaidPaymentAndEmitsOnce()

	/**
	 * A second refresh of an already captured payment emits nothing (REQ-PRV-002).
	 *
	 * @return void
	 */
	public function testASecondRefreshEmitsNothing(): void {
		$record = $this->entity(
			data: ['sourceSlug' => 'ideal', 'providerPaymentId' => 'MOCK-PAY-9', 'paymentStatus' => 'paid', 'lastOutcome' => 'captured'],
			uuid: 'pi-9'
		);
		$source = $this->entity(
			data: ['slug' => 'ideal', 'type' => 'payment', 'configuration' => ['mockStatuses' => ['MOCK-PAY-9' => 'paid']]],
			uuid: 'src-1'
		);
		$this->objectService->method('find')->willReturn($record);
		$this->stubFindAll(source: $source, record: $record);
		$this->objectService->method('saveObject')->willReturn($record);

		$this->eventService->expects($this->never())->method('emitCloudEvent');

		$result = $this->service->refreshPayment(paymentIntentId: 'pi-9');

		$this->assertSame(expected: ['result' => 'noop', 'outcome' => 'captured', 'paymentStatus' => 'paid'], actual: $result);

	}//end testASecondRefreshEmitsNothing()

	/**
	 * An unknown payment id is not found, with no provider call or write.
	 *
	 * @return void
	 */
	public function testRefreshOfAnUnknownPaymentIsNotFound(): void {
		$this->objectService->method('find')->willThrowException(new DoesNotExistException('none'));
		$this->objectService->expects($this->never())->method('findAll');
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertSame(
			expected: ['result' => 'not-found', 'outcome' => null, 'paymentStatus' => null],
			actual: $this->service->refreshPayment(paymentIntentId: 'missing')
		);

	}//end testRefreshOfAnUnknownPaymentIsNotFound()

	/**
	 * The provider list names log and mollie and says which needs a key (REQ-PRV-003).
	 *
	 * @return void
	 */
	public function testListProvidersNamesEveryBindingAndWhetherItNeedsAKey(): void {
		$this->assertSame(
			expected: [
				['id' => 'log', 'label' => 'Log (sandbox, charges nobody)', 'needsKey' => false],
				['id' => 'mollie', 'label' => 'Mollie', 'needsKey' => true],
			],
			actual: $this->service->listProviders()
		);

	}//end testListProvidersNamesEveryBindingAndWhetherItNeedsAKey()

	/**
	 * The check runs the chosen binding's probe with the draft configuration (REQ-PRV-003).
	 *
	 * @return void
	 */
	public function testCheckProviderRunsTheBindingsProbe(): void {
		$configuration = ['provider' => 'mollie', 'authentication' => ['credentialRef' => ['credentialId' => 'cred-1']]];
		$this->mollieProvider->expects($this->once())
			->method('probe')
			->with($configuration)
			->willReturn(['ok' => true, 'mode' => 'test', 'profile' => 'School', 'message' => null]);

		$this->assertSame(
			expected: ['provider' => 'mollie', 'ok' => true, 'mode' => 'test', 'profile' => 'School', 'message' => null],
			actual: $this->service->checkProvider(configuration: $configuration)
		);

	}//end testCheckProviderRunsTheBindingsProbe()

	/**
	 * The log binding answers ok in log mode; an unknown value answers a refusal naming it.
	 *
	 * @return void
	 */
	public function testCheckProviderAnswersLogAndRefusesAnUnknownValue(): void {
		$this->mollieProvider->expects($this->never())->method('probe');

		$this->assertSame(
			expected: ['provider' => 'log', 'ok' => true, 'mode' => 'log', 'profile' => null, 'message' => null],
			actual: $this->service->checkProvider(configuration: [])
		);

		$refused = $this->service->checkProvider(configuration: ['provider' => 'Mollie']);
		$this->assertFalse(condition: $refused['ok']);
		$this->assertSame(expected: 'Mollie', actual: $refused['provider']);
		$this->assertNull(actual: $refused['mode']);
		$this->assertStringContainsString(needle: '"Mollie"', haystack: (string)$refused['message']);

	}//end testCheckProviderAnswersLogAndRefusesAnUnknownValue()

	/**
	 * The draft cannot choose where the check calls, or how the key is injected:
	 * the probe gets the binding and the bare credentialRef only (REQ-PRV-003).
	 *
	 * @return void
	 */
	public function testCheckProviderPinsTheTargetAndPassesOnlyTheCredentialRef(): void {
		$configuration = [
			'provider' => 'mollie',
			'baseUrl' => 'https://collector.example.invalid/v2',
			'location' => 'https://collector.example.invalid',
			'authentication' => [
				'credentialRef' => ['credentialId' => 'cred-1'],
				'header' => 'X-Leak',
				'injectInto' => 'query',
			],
		];
		$this->mollieProvider->expects($this->once())
			->method('probe')
			->with(['provider' => 'mollie', 'authentication' => ['credentialRef' => ['credentialId' => 'cred-1']]])
			->willReturn(['ok' => true, 'mode' => 'test', 'profile' => null, 'message' => null]);

		$this->assertTrue(condition: $this->service->checkProvider(configuration: $configuration)['ok']);

	}//end testCheckProviderPinsTheTargetAndPassesOnlyTheCredentialRef()

	/**
	 * A draft without a key reaches the probe without one, so the binding refuses it.
	 *
	 * @return void
	 */
	public function testCheckProviderWithoutAKeyPassesNoAuthentication(): void {
		$this->mollieProvider->expects($this->once())
			->method('probe')
			->with(['provider' => 'mollie'])
			->willReturn(['ok' => false, 'mode' => null, 'profile' => null, 'message' => 'missing']);

		$answer = $this->service->checkProvider(
			configuration: ['provider' => 'mollie', 'baseUrl' => 'https://collector.example.invalid', 'authentication' => 'not-an-object']
		);
		$this->assertFalse(condition: $answer['ok']);

	}//end testCheckProviderWithoutAKeyPassesNoAuthentication()
}//end class
