<?php

/**
 * Unit tests for the consent and account paths of RestPsd2AggregatorProvider.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Psd2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Psd2;

use DateTime;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Exception\Psd2ProviderException;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\Psd2\RestPsd2AggregatorProvider;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the requisition response guard, the consent validity window and
 * account discovery of the REST PSD2 provider.
 *
 * @covers \OCA\Integriq\Service\Psd2\RestPsd2AggregatorProvider
 * @uses   \OCA\Integriq\Service\Helper\BrokeredCall
 * @uses   \OCA\Integriq\Service\Helper\BrokeredJsonDispatch
 * @uses   \OCA\Integriq\Exception\Psd2ProviderException
 */
class RestPsd2AggregatorProviderConsentTest extends TestCase {

	/**
	 * @var BrokeredCallService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $brokeredCallService;

	/**
	 * @var RestPsd2AggregatorProvider
	 */
	private RestPsd2AggregatorProvider $provider;

	/**
	 * URLs dispatched through the broker, in order.
	 *
	 * @var array<int, string>
	 */
	private array $dispatchedUrls = [];

	/**
	 * Base configuration with a trailing slash on the base URL.
	 *
	 * @var array
	 */
	private array $configuration = [
		'baseUrl' => 'https://aggregator.example/psd2/',
		'authentication' => ['credentialRef' => ['credentialId' => 'cred-uuid']],
	];

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->brokeredCallService = $this->createMock(BrokeredCallService::class);
		$this->brokeredCallService->method('hasCredentialRef')->willReturn(true);
		$this->brokeredCallService->method('prepare')->willReturn(['credentialId' => 'cred-uuid', 'actingUserId' => null]);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$this->provider = new RestPsd2AggregatorProvider(
			$this->brokeredCallService,
			$l,
			$this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * Route every dispatch through a URL-keyed response map and record the URLs.
	 *
	 * @param array<string, array> $bodies Response bodies keyed by a URL suffix.
	 *
	 * @return void
	 */
	private function respondWith(array $bodies): void {
		$this->brokeredCallService->method('dispatch')->willReturnCallback(
			function (string $credentialId, ?string $actingUserId, string $method, string $url, array $config) use ($bodies): Response {
				$this->dispatchedUrls[] = $method . ' ' . $url;
				foreach ($bodies as $suffix => $body) {
					if (str_ends_with($url, $suffix) === true) {
						return new Response(200, [], json_encode($body));
					}
				}

				return new Response(404, [], 'unexpected');
			}
		);

	}//end respondWith()

	/**
	 * Whole days between now and the ISO 8601 expiry, rounded.
	 *
	 * @param string $expiresAt The expiry timestamp.
	 *
	 * @return integer The number of days.
	 */
	private function daysUntil(string $expiresAt): int {
		$seconds = (new DateTime($expiresAt))->getTimestamp() - (new DateTime())->getTimestamp();

		return (int)round($seconds / 86400);

	}//end daysUntil()

	/**
	 * A requisition response without a link is refused, not returned half-filled.
	 *
	 * @return void
	 */
	public function testCreateRequisitionWithoutLinkThrows(): void {
		$this->respondWith(['/requisitions/' => ['id' => 'REQ-1']]);

		$this->expectException(Psd2ProviderException::class);
		$this->expectExceptionMessage('returned no usable id/link');

		$this->provider->createRequisition(
			sourceConfiguration: $this->configuration,
			institutionId: 'BANK_NL',
			redirectUrl: 'https://nc.example/return'
		);

	}//end testCreateRequisitionWithoutLinkThrows()

	/**
	 * An empty requisition id is refused even when a link is present.
	 *
	 * @return void
	 */
	public function testCreateRequisitionWithEmptyIdThrows(): void {
		$this->respondWith(['/requisitions/' => ['id' => '', 'link' => 'https://bank.example/sca']]);

		$this->expectException(Psd2ProviderException::class);
		$this->expectExceptionMessage('returned no usable id/link');

		$this->provider->createRequisition(
			sourceConfiguration: $this->configuration,
			institutionId: 'BANK_NL',
			redirectUrl: 'https://nc.example/return'
		);

	}//end testCreateRequisitionWithEmptyIdThrows()

	/**
	 * A non-string id (numeric JSON) is refused.
	 *
	 * @return void
	 */
	public function testCreateRequisitionWithNumericIdThrows(): void {
		$this->respondWith(['/requisitions/' => ['id' => 42, 'link' => 'https://bank.example/sca']]);

		$this->expectException(Psd2ProviderException::class);

		$this->provider->createRequisition(
			sourceConfiguration: $this->configuration,
			institutionId: 'BANK_NL',
			redirectUrl: 'https://nc.example/return'
		);

	}//end testCreateRequisitionWithNumericIdThrows()

	/**
	 * The base URL is trimmed of its trailing slash before the path is appended.
	 *
	 * @return void
	 */
	public function testCreateRequisitionPostsToTrimmedBaseUrl(): void {
		$this->respondWith(['/requisitions/' => ['id' => 'REQ-1', 'link' => 'https://bank.example/sca']]);

		$this->provider->createRequisition(
			sourceConfiguration: $this->configuration,
			institutionId: 'BANK_NL',
			redirectUrl: 'https://nc.example/return'
		);

		$this->assertSame(['POST https://aggregator.example/psd2/requisitions/'], $this->dispatchedUrls);

	}//end testCreateRequisitionPostsToTrimmedBaseUrl()

	/**
	 * A configured validity window sets the consent expiry that many days ahead.
	 *
	 * @return void
	 */
	public function testFinaliseConsentHonoursConfiguredValidity(): void {
		$this->respondWith(['/requisitions/REQ-1/' => ['id' => 'REQ-1', 'accounts' => []]]);

		$consent = $this->provider->finaliseConsent(
			sourceConfiguration: array_merge($this->configuration, ['accessValidForDays' => 30]),
			reference: 'REQ-1'
		);

		$this->assertSame(30, $this->daysUntil($consent['consentExpiresAt']));
		$this->assertSame([], $consent['accounts']);
		$this->assertSame('REQ-1', $consent['consentReference']);
		$this->assertNull($consent['consentToken']);

	}//end testFinaliseConsentHonoursConfiguredValidity()

	/**
	 * A zero or negative validity window falls back to the 90-day default.
	 *
	 * @return void
	 */
	public function testFinaliseConsentFallsBackToDefaultForNonPositiveValidity(): void {
		$this->respondWith(['/requisitions/REQ-1/' => ['id' => 'REQ-1', 'accounts' => []]]);

		$zero = $this->provider->finaliseConsent(
			sourceConfiguration: array_merge($this->configuration, ['accessValidForDays' => 0]),
			reference: 'REQ-1'
		);
		$negative = $this->provider->finaliseConsent(
			sourceConfiguration: array_merge($this->configuration, ['accessValidForDays' => -5]),
			reference: 'REQ-1'
		);

		$this->assertSame(90, $this->daysUntil($zero['consentExpiresAt']));
		$this->assertSame(90, $this->daysUntil($negative['consentExpiresAt']));

	}//end testFinaliseConsentFallsBackToDefaultForNonPositiveValidity()

	/**
	 * listAccounts fetches the requisition, then one details call per account,
	 * and normalises each row.
	 *
	 * @return void
	 */
	public function testListAccountsResolvesEachAccountDetail(): void {
		$this->respondWith(
			[
				'/requisitions/REQ-1/' => ['id' => 'REQ-1', 'accounts' => ['ACC-1', 'ACC 2']],
				'/accounts/ACC-1/details/' => ['account' => ['iban' => 'NL91ABNA0417164300', 'bic' => 'ABNANL2A', 'currency' => 'EUR']],
				'/accounts/ACC%202/details/' => ['account' => ['iban' => 'NL02RABO0123456789']],
			]
		);

		$accounts = $this->provider->listAccounts(sourceConfiguration: $this->configuration, consentReference: 'REQ-1');

		$this->assertSame(
			[
				'GET https://aggregator.example/psd2/requisitions/REQ-1/',
				'GET https://aggregator.example/psd2/accounts/ACC-1/details/',
				'GET https://aggregator.example/psd2/accounts/ACC%202/details/',
			],
			$this->dispatchedUrls
		);
		$this->assertSame(
			[
				[
					'iban' => 'NL91ABNA0417164300',
					'bic' => 'ABNANL2A',
					'currency' => 'EUR',
					'aggregatorAccountId' => 'ACC-1',
				],
				[
					'iban' => 'NL02RABO0123456789',
					'bic' => '',
					'currency' => '',
					'aggregatorAccountId' => 'ACC 2',
				],
			],
			$accounts
		);

	}//end testListAccountsResolvesEachAccountDetail()

	/**
	 * A requisition with no accounts makes no details calls and returns an empty list.
	 *
	 * @return void
	 */
	public function testListAccountsWithoutAccountsReturnsEmptyList(): void {
		$this->respondWith(['/requisitions/REQ-1/' => ['id' => 'REQ-1']]);

		$accounts = $this->provider->listAccounts(sourceConfiguration: $this->configuration, consentReference: 'REQ-1');

		$this->assertSame([], $accounts);
		$this->assertSame(['GET https://aggregator.example/psd2/requisitions/REQ-1/'], $this->dispatchedUrls);

	}//end testListAccountsWithoutAccountsReturnsEmptyList()
}//end class
