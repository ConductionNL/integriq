<?php

/**
 * Unit tests for IdpBrokerController.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Auth\Idp\EnvelopeExchangeService;
use OCA\Integriq\Controller\IdpBrokerController;
use OCA\Integriq\Exception\IdpAssertionException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests the exchange endpoint.
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
 */
class IdpBrokerControllerTest extends TestCase {

	/**
	 * A request carrying the given header and parameters.
	 *
	 * @param string $authorization The Authorization header.
	 * @param array<string,string> $params The request parameters.
	 *
	 * @return IRequest The request.
	 */
	private function request(string $authorization, array $params): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			function (string $name) use ($authorization) {
				if (strtolower($name) === 'authorization') {
					return $authorization;
				}

				return '';
			}
		);
		$request->method('getParam')->willReturnCallback(
			function (string $key, $default = null) use ($params) {
				return ($params[$key] ?? $default);
			}
		);

		return $request;
	}//end request()

	/**
	 * A successful exchange returns the envelope and nothing else.
	 *
	 * @return void
	 */
	public function testASuccessfulExchangeReturnsTheEnvelope(): void {
		$captured = [];
		$service = $this->createMock(EnvelopeExchangeService::class);
		$service->method('redeemCode')->willReturnCallback(
			function (string $code, string $consumer, string $presentedSecret) use (&$captured) {
				$captured = ['code' => $code, 'consumer' => $consumer, 'secret' => $presentedSecret];
				return 'header.payload.signature';
			}
		);

		$controller = new IdpBrokerController(
			'integriq',
			$this->request('Bearer the-shared-secret', ['code' => 'the-code', 'consumer' => 'portaliq']),
			$service
		);

		$response = $controller->exchange();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['envelope' => 'header.payload.signature'], $response->getData());
		$this->assertSame('the-code', $captured['code']);
		$this->assertSame('portaliq', $captured['consumer']);
		$this->assertSame('the-shared-secret', $captured['secret'], 'The Bearer scheme was not stripped.');

	}//end testASuccessfulExchangeReturnsTheEnvelope()

	/**
	 * Every refusal is one undifferentiated 401 whose body names no check.
	 *
	 * @return void
	 */
	public function testEveryRefusalIsTheSameUndifferentiated401(): void {
		$reasons = [
			'The exchange request is refused.',
			'The one-time code is unknown, expired or already redeemed, so it is refused.',
			'Broker not configured: the idp-broker feature flag is off.',
			'This artefact has already been used, so it is refused.',
		];

		foreach ($reasons as $reason) {
			$service = $this->createMock(EnvelopeExchangeService::class);
			$service->method('redeemCode')->willThrowException(new IdpAssertionException($reason));

			$controller = new IdpBrokerController(
				'integriq',
				$this->request('Bearer whatever', ['code' => 'c', 'consumer' => 'portaliq']),
				$service
			);

			$response = $controller->exchange();

			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			$this->assertSame(['error' => 'unauthorized'], $response->getData());
			$this->assertStringNotContainsString(
				$reason,
				(string)json_encode($response->getData()),
				'The refusal reason leaked into the response body.'
			);
		}

	}//end testEveryRefusalIsTheSameUndifferentiated401()

	/**
	 * A header without the Bearer scheme is passed through whole, so a
	 * consumer sending a bare secret still authenticates.
	 *
	 * @return void
	 */
	public function testABareSecretIsPassedThroughWhole(): void {
		$captured = '';
		$service = $this->createMock(EnvelopeExchangeService::class);
		$service->method('redeemCode')->willReturnCallback(
			function (string $code, string $consumer, string $presentedSecret) use (&$captured) {
				$captured = $presentedSecret;
				return 'a.b.c';
			}
		);

		$controller = new IdpBrokerController(
			'integriq',
			$this->request('the-shared-secret', ['code' => 'c', 'consumer' => 'portaliq']),
			$service
		);

		$controller->exchange();

		$this->assertSame('the-shared-secret', $captured);

	}//end testABareSecretIsPassedThroughWhole()

}//end class
