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
use OCA\Integriq\Auth\Idp\IdpLoginService;
use OCA\Integriq\Controller\IdpBrokerController;
use OCA\Integriq\Exception\IdpAssertionException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
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
			$service,
			$this->createMock(IdpLoginService::class),
			$this->createMock(IL10N::class),
			$this->createMock(LoggerInterface::class)
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
				$service,
				$this->createMock(IdpLoginService::class),
				$this->createMock(IL10N::class),
				$this->createMock(LoggerInterface::class)
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
			$service,
			$this->createMock(IdpLoginService::class),
			$this->createMock(IL10N::class),
			$this->createMock(LoggerInterface::class)
		);

		$controller->exchange();

		$this->assertSame('the-shared-secret', $captured);

	}//end testABareSecretIsPassedThroughWhole()

	/**
	 * A controller whose login service answers or throws as given.
	 *
	 * @param IdpLoginService $loginService The login service double.
	 * @param array<string,mixed> $params The request parameters.
	 *
	 * @return IdpBrokerController The controller.
	 */
	private function loginController(IdpLoginService $loginService, array $params): IdpBrokerController {
		$request = $this->request('', $params);
		$request->method('getParams')->willReturn($params);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new IdpBrokerController(
			'integriq',
			$request,
			$this->createMock(EnvelopeExchangeService::class),
			$loginService,
			$l10n,
			$this->createMock(LoggerInterface::class)
		);
	}//end loginController()

	/**
	 * A refused start shows integriq's error page and redirects nowhere, and
	 * the page names no reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
	 */
	public function testARefusedStartShowsTheErrorPageAndRedirectsNowhere(): void {
		$service = $this->createMock(IdpLoginService::class);
		$service->method('start')->willThrowException(
			new IdpAssertionException('The return address is not registered for this consumer, so no login starts.')
		);

		$response = $this->loginController($service, ['returnUrl' => 'https://evil.example'])->start('digid');

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('idp-error', $response->getTemplateName());
		$this->assertStringNotContainsString('return address', implode(' ', $response->getParams()));
	}//end testARefusedStartShowsTheErrorPageAndRedirectsNowhere()

	/**
	 * An accepted start sends the browser to the identity provider, with the
	 * request parameters handed through.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
	 */
	public function testAnAcceptedStartRedirectsToTheIdentityProvider(): void {
		$params = [
			'organisation' => 'gemeente-x',
			'consumer' => 'portaliq',
			'trust' => 'substantial',
			'returnUrl' => 'https://portal.example.nl/portal/api/session/broker/callback',
			'relayState' => 'r1',
		];
		$service = $this->createMock(IdpLoginService::class);
		$service->expects($this->once())->method('start')->with('digid', $params)->willReturn('https://idp.example.nl/sso');

		$response = $this->loginController($service, $params)->start('digid');

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('https://idp.example.nl/sso', $response->getRedirectURL());
	}//end testAnAcceptedStartRedirectsToTheIdentityProvider()

	/**
	 * A response that answers no stored state shows the error page; one that
	 * does goes back to the consumer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-the-callback-returns-the-browser-with-a-one-time-code-req-idp-002
	 */
	public function testTheCallbackRedirectsOnlyWhenTheServiceNamesAnAddress(): void {
		$refused = $this->createMock(IdpLoginService::class);
		$refused->method('callback')->willThrowException(new IdpAssertionException('The response answers no login this broker started.'));
		$page = $this->loginController($refused, ['SAMLResponse' => 'x'])->callback('digid');
		$this->assertInstanceOf(TemplateResponse::class, $page);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $page->getStatus());

		$accepted = $this->createMock(IdpLoginService::class);
		$accepted->expects($this->once())->method('callback')->with('digid', ['SAMLResponse' => 'x'])
			->willReturn('https://portal.example.nl/cb?code=c&relayState=r1');
		$redirect = $this->loginController($accepted, ['SAMLResponse' => 'x'])->callback('digid');
		$this->assertInstanceOf(RedirectResponse::class, $redirect);
		$this->assertSame('https://portal.example.nl/cb?code=c&relayState=r1', $redirect->getRedirectURL());
	}//end testTheCallbackRedirectsOnlyWhenTheServiceNamesAnAddress()

}//end class
