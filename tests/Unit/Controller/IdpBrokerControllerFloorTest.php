<?php

/**
 * Unit tests for the page a login below the assurance floor ends on.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-substantial-is-the-assurance-floor-req-idpc-006
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Auth\Idp\EnvelopeExchangeService;
use OCA\Integriq\Auth\Idp\IdpLoginService;
use OCA\Integriq\Auth\Idp\TrustLevelMapper;
use OCA\Integriq\Controller\IdpBrokerController;
use OCA\Integriq\Exception\IdpAssertionException;
use OCA\Integriq\Exception\IdpAssuranceBelowFloorException;
use OCA\Integriq\Exception\IdpDisclosureIncompleteException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the floor refusal page of the callback.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-substantial-is-the-assurance-floor-req-idpc-006
 */
#[CoversClass(IdpBrokerController::class)]
#[UsesClass(TrustLevelMapper::class)]
#[UsesClass(IdpAssuranceBelowFloorException::class)]
#[UsesClass(IdpDisclosureIncompleteException::class)]
class IdpBrokerControllerFloorTest extends TestCase {

	/**
	 * Where the page's one action leads.
	 *
	 * @var string
	 */
	private const BACK = 'https://portal.example.nl/cb?error=assurance_below_floor&relayState=portal-relay-42';

	/**
	 * The controller over a login service whose callback refuses below the floor.
	 *
	 * @param string               $provider The provider.
	 * @param string               $level    The canonical level used.
	 * @param LoggerInterface|null $logger   The logger.
	 *
	 * @return IdpBrokerController The controller.
	 */
	private function controller(string $provider, string $level, ?LoggerInterface $logger = null): IdpBrokerController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['SAMLResponse' => 'x']);

		$service = $this->createMock(IdpLoginService::class);
		$service->method('callback')->willThrowException(
			(new IdpAssuranceBelowFloorException(provider: $provider, levelUsed: $level))->withReturnUrl(self::BACK)
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			fn (string $text, array $params = []) => vsprintf($text, $params)
		);

		return new IdpBrokerController(
			appName: 'integriq',
			request: $request,
			exchangeService: $this->createMock(EnvelopeExchangeService::class),
			loginService: $service,
			l10n: $l10n,
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
			trustMapper: new TrustLevelMapper()
		);
	}//end controller()

	/**
	 * A DigiD Midden login shows the floor page: the level used, the level
	 * needed, how to get it, and one way back with the error and relay state.
	 *
	 * @return void
	 */
	public function testADigidMiddenLoginIsExplainedNotHidden(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$response = $this->controller('digid', 'midden', $logger)->callback('digid');

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('idp/refused', $response->getTemplateName());
		$this->assertSame(TemplateResponse::RENDER_AS_GUEST, $response->getRenderAs());
		$params = $response->getParams();
		$this->assertSame('This service needs a stronger login', $params['title']);
		$this->assertSame('You logged in with DigiD Midden. This service needs at least DigiD Substantieel.', $params['message']);
		$this->assertStringContainsString('DigiD app with an identity check', $params['hint']);
		$this->assertSame('Go back', $params['action']);
		$this->assertSame(self::BACK, $params['actionUrl']);
	}//end testADigidMiddenLoginIsExplainedNotHidden()

	/**
	 * eHerkenning and eIDAS get their own level names and their own advice.
	 *
	 * @return void
	 */
	public function testEachProviderGetsItsOwnAdvice(): void {
		$eh = $this->controller('eherkenning', 'eh2+')->callback('eherkenning')->getParams();
		$this->assertSame('You logged in with EH2+. This service needs at least EH3.', $eh['message']);
		$this->assertStringContainsString('eHerkenning means at level EH3 or higher', $eh['hint']);

		$eidas = $this->controller('eidas', 'low')->callback('eidas')->getParams();
		$this->assertSame('You logged in with eIDAS low. This service needs at least eIDAS substantial.', $eidas['message']);
		$this->assertSame('Log in with a means at eIDAS level substantial or high.', $eidas['hint']);
	}//end testEachProviderGetsItsOwnAdvice()

	/**
	 * Any other callback refusal still shows the generic page.
	 *
	 * @return void
	 */
	public function testOtherRefusalsStayGeneric(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);
		$service = $this->createMock(IdpLoginService::class);
		$service->method('callback')->willThrowException(new IdpAssertionException('no state'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$controller = new IdpBrokerController(
			'integriq',
			$request,
			$this->createMock(EnvelopeExchangeService::class),
			$service,
			$l10n,
			$this->createMock(LoggerInterface::class)
		);
		$response = $controller->callback('digid');

		$this->assertSame('idp-error', $response->getTemplateName());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testOtherRefusalsStayGeneric()

	/**
	 * A Yivi login that shared less than it asked is explained on integriq's
	 * own page, with one way back carrying `error=attributes_not_disclosed`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-yivi-envelope-carries-only-the-disclosed-attributes-req-yivi-003
	 */
	public function testAYiviLoginThatSharedLessIsExplained(): void {
		$back = 'https://portal.example.nl/cb?error=attributes_not_disclosed&relayState=portal-relay-42';
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParams')->willReturn(['code' => 'c', 'state' => 's']);
		$service = $this->createMock(originalClassName: IdpLoginService::class);
		$service->method('callback')->willThrowException((new IdpDisclosureIncompleteException())->withReturnUrl(returnUrl: $back));
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$controller = new IdpBrokerController(
			appName: 'integriq',
			request: $request,
			exchangeService: $this->createMock(originalClassName: EnvelopeExchangeService::class),
			loginService: $service,
			l10n: $l10n,
			logger: $logger,
			trustMapper: new TrustLevelMapper()
		);
		$response = $controller->callback(provider: 'yivi');

		$this->assertInstanceOf(expected: TemplateResponse::class, actual: $response);
		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertSame(expected: 'idp/refused', actual: $response->getTemplateName());
		$params = $response->getParams();
		$this->assertSame(expected: 'You did not share all the requested details', actual: $params['title']);
		$this->assertSame(expected: 'Go back', actual: $params['action']);
		$this->assertSame(expected: $back, actual: $params['actionUrl']);
	}//end testAYiviLoginThatSharedLessIsExplained()
}//end class
