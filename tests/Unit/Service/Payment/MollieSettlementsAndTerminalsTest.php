<?php

/**
 * MolliePaymentProvider settlement, terminal and point-of-sale tests
 *
 * The Mollie binding against Mollie-shaped responses in
 * tests/fixtures/mollie (REQ-LPP-008, REQ-LPP-009): the settlement pull
 * follows the next link, skips a settlement not yet paid out and stops at
 * the date bound; the settlement `st_example0001` (14 payments, one refund,
 * EUR 4.06 fees) comes back with 15 lines that, less the fees, add up to the
 * net EUR 585.49; the terminal list; and a point-of-sale payment sent to a
 * terminal without a checkout link.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Payment
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

namespace OCA\Integriq\Tests\Unit\Service\Payment;

use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Exception\PaymentProviderException;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\Payment\MolliePaymentProvider;
use OCA\Integriq\Service\Payment\SettlementMapper;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the Mollie settlement, terminal and point-of-sale paths.
 */
class MollieSettlementsAndTerminalsTest extends TestCase {

	private const BASE = 'https://api.mollie.example/v2';

	/**
	 * The source configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $configuration = [
		'provider' => 'mollie',
		'baseUrl' => self::BASE,
		'authentication' => ['credentialRef' => ['credentialId' => 'cred-uuid']],
	];

	/**
	 * Every request, as [method, url, json].
	 *
	 * @var array<int, array{0: string, 1: string, 2: mixed}>
	 */
	private array $requests = [];

	/**
	 * Build the binding answering each URL from the fixture map.
	 *
	 * @param array<string, string> $routes URL => fixture name.
	 *
	 * @return MolliePaymentProvider
	 */
	private function provider(array $routes): MolliePaymentProvider {
		$broker = $this->createMock(BrokeredCallService::class);
		$broker->method('hasCredentialRef')->willReturn(true);
		$broker->method('prepare')->willReturn(['credentialId' => 'cred-uuid', 'actingUserId' => null]);
		$broker->method('dispatch')->willReturnCallback(
			function (string $credentialId, ?string $actingUserId, string $method, string $url, array $config) use ($routes): Response {
				$this->requests[] = [$method, $url, ($config['json'] ?? null)];
				if (isset($routes[$url]) === false) {
					return new Response(404, [], '{"status":404}');
				}

				return new Response(200, [], (string)file_get_contents(__DIR__ . '/../../../fixtures/mollie/' . $routes[$url] . '.json'));
			}
		);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		return new MolliePaymentProvider($broker, $l, new NullLogger());
	}//end provider()

	/**
	 * The routes of the settlement fixtures.
	 *
	 * @return array<string, string>
	 */
	private function settlementRoutes(): array {
		return [
			self::BASE . '/settlements?limit=250' => 'settlements-page1',
			self::BASE . '/settlements?from=stl_old0001&limit=250' => 'settlements-page2',
			self::BASE . '/settlements/stl_jDk30akdN/payments?limit=250' => 'settlement-payments',
			self::BASE . '/settlements/stl_jDk30akdN/refunds?limit=250' => 'settlement-refunds',
			self::BASE . '/settlements/stl_jDk30akdN/chargebacks?limit=250' => 'settlement-chargebacks',
		];
	}//end settlementRoutes()

	/**
	 * The paid-out settlement comes back with 15 lines that, less the fees,
	 * add up to the net; the open one and the one before the bound do not.
	 *
	 * @return void
	 */
	public function testAMollieSettlementIsReportedWithItsLines(): void {
		$settlements = $this->provider($this->settlementRoutes())->fetchSettlements($this->configuration, '2026-09-01T00:00:00Z');

		$this->assertCount(1, $settlements);
		$settlement = $settlements[0];
		$this->assertSame('st_example0001', $settlement['settlementReference']);
		$this->assertSame('2026-10-02T09:41:44+00:00', $settlement['settledAt']);
		$this->assertSame('EUR', $settlement['currency']);
		$this->assertSame('612.40', $settlement['amountGross']);
		$this->assertSame('22.85', $settlement['amountRefunds']);
		$this->assertSame('0.00', $settlement['amountChargebacks']);
		$this->assertSame('4.06', $settlement['amountFees']);
		$this->assertSame('585.49', $settlement['amountNet']);
		$this->assertCount(15, $settlement['transactions']);
		$this->assertSame(['providerPaymentId' => 'tr_ex0003', 'type' => 'refund', 'amount' => '-22.85', 'fee' => '0.00'], $settlement['transactions'][14]);
		$this->assertSame(['providerPaymentId' => 'tr_ex0001', 'type' => 'payment', 'amount' => '43.75', 'fee' => '0.00'], $settlement['transactions'][0]);

		$mapper = new SettlementMapper();
		$lines = array_sum(array_map(fn (array $line): int => $mapper->toCents($line['amount']), $settlement['transactions']));
		$this->assertSame($mapper->toCents('585.49'), $lines - $mapper->toCents($settlement['amountFees']), 'Lines less fees add up to the net.');

		$urls = array_column($this->requests, 1);
		$this->assertContains(self::BASE . '/settlements?from=stl_old0001&limit=250', $urls, 'The pull follows the next link.');
		$this->assertNotContains(self::BASE . '/settlements/stl_open0001/payments?limit=250', $urls, 'An open settlement is not read.');
		$this->assertNotContains(self::BASE . '/settlements/stl_old0001/payments?limit=250', $urls, 'A settlement before the bound is not read.');
	}//end testAMollieSettlementIsReportedWithItsLines()

	/**
	 * A Mollie error on the pull is a PaymentProviderException.
	 *
	 * @return void
	 */
	public function testAMollieErrorOnThePullThrows(): void {
		$this->expectException(PaymentProviderException::class);
		$this->expectExceptionMessage('HTTP 404');

		$this->provider([])->fetchSettlements($this->configuration, '2026-09-01T00:00:00Z');
	}//end testAMollieErrorOnThePullThrows()

	/**
	 * The terminals come back with id, label and status.
	 *
	 * @return void
	 */
	public function testTheTerminalsAreListed(): void {
		$terminals = $this->provider([self::BASE . '/terminals?limit=250' => 'terminals'])->listTerminals($this->configuration);

		$this->assertSame(
			[
				['terminalId' => 'term_example0001', 'label' => 'Telefoon Anna', 'status' => 'active'],
				['terminalId' => 'term_example0002', 'label' => 'Balie', 'status' => 'inactive'],
			],
			$terminals
		);
	}//end testTheTerminalsAreListed()

	/**
	 * A point-of-sale payment is sent with its terminal and accepted without
	 * a checkout link.
	 *
	 * @return void
	 */
	public function testAPointOfSalePaymentNeedsNoCheckoutLink(): void {
		$result = $this->provider([self::BASE . '/payments' => 'payment-pointofsale'])->createPayment(
			$this->configuration,
			[
				'amount' => ['value' => '23.50', 'currency' => 'EUR'],
				'description' => 'Kassa',
				'redirectUrl' => 'https://portal.example/thanks',
				'webhookUrl' => 'https://cloud.example/apps/integriq/api/payments/webhook',
				'method' => 'pointofsale',
				'terminalId' => 'term_example0001',
			]
		);

		$this->assertSame('tr_pos0001', $result['providerPaymentId']);
		$this->assertSame('', $result['checkoutUrl']);
		$this->assertSame('pointofsale', $result['extras']['method']);
		$this->assertSame('pointofsale', $this->requests[0][2]['method']);
		$this->assertSame('term_example0001', $this->requests[0][2]['terminalId']);
	}//end testAPointOfSalePaymentNeedsNoCheckoutLink()

	/**
	 * Any other method still needs a checkout link, as before.
	 *
	 * @return void
	 */
	public function testAnOnlinePaymentWithoutACheckoutLinkIsStillRefused(): void {
		$this->expectException(PaymentProviderException::class);
		$this->expectExceptionMessage('no usable payment id or checkout URL');

		$this->provider([self::BASE . '/payments' => 'payment-pointofsale'])->createPayment(
			$this->configuration,
			['amount' => ['value' => '23.50', 'currency' => 'EUR'], 'description' => 'x', 'redirectUrl' => 'r', 'webhookUrl' => 'w', 'method' => 'ideal']
		);
	}//end testAnOnlinePaymentWithoutACheckoutLinkIsStillRefused()

	/**
	 * The mapper refuses an amount that is not a decimal number, and keeps
	 * cents exact.
	 *
	 * @return void
	 */
	public function testTheMapperKeepsCentsExact(): void {
		$mapper = new SettlementMapper();
		$this->assertSame(4365, $mapper->toCents('43.65'));
		$this->assertSame(-2285, $mapper->toCents('-22.85'));
		$this->assertSame(1050, $mapper->toCents('10.5'));
		$this->assertSame('-0.07', $mapper->toDecimal(-7));
		$this->assertSame('612.40', $mapper->toDecimal(61240));

		$this->expectException(PaymentProviderException::class);
		$mapper->toCents('1.234');
	}//end testTheMapperKeepsCentsExact()

}//end class
