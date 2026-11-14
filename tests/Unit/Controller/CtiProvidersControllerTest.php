<?php

/**
 * Unit tests for CtiProvidersController.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\CtiProvidersController;
use OCA\Integriq\Service\Kiss\CallEventNormaliser;
use OCA\Integriq\Service\Kiss\CtiSourceResolver;
use OCA\Integriq\Service\Kiss\LogCtiProvider;
use OCA\Integriq\Service\Kiss\WebhookCtiProvider;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The source form's binding picker is built from what this endpoint answers.
 *
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
 */
class CtiProvidersControllerTest extends TestCase {

	/**
	 * A controller over the real resolver and its two real bindings.
	 *
	 * @param boolean $authenticated Whether a user is signed in.
	 *
	 * @return CtiProvidersController
	 */
	private function controller(bool $authenticated = true): CtiProvidersController {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user    = null;
		if ($authenticated === true) {
			$user = $this->createMock(originalClassName: IUser::class);
		}

		$session->method('getUser')->willReturn($user);

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'nl:'.$text);

		$resolver = new CtiSourceResolver(
			objectService: $this->getMockBuilder(ObjectService::class)->disableOriginalConstructor()->getMock(),
			logProvider: new LogCtiProvider(new CallEventNormaliser(), $this->createMock(originalClassName: LoggerInterface::class)),
			webhookProvider: new WebhookCtiProvider(new CallEventNormaliser(), $this->createMock(originalClassName: WebhookSignatureService::class)),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		return new CtiProvidersController(
			appName: 'integriq',
			request: $this->createMock(originalClassName: IRequest::class),
			resolver: $resolver,
			userSession: $session,
			l: $l10n
		);

	}//end controller()

	/**
	 * A signed-in user gets every binding, each with the configuration it reads.
	 *
	 * @return void
	 */
	public function testListsEveryBindingWithItsSchema(): void {
		$response = $this->controller()->providers();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$providers = $response->getData()['providers'];
		$this->assertSame(['log', 'webhook'], array_column($providers, 'id'));
		$this->assertSame(['sharedSecret'], $providers[0]['configSchema']['required']);
		$this->assertSame(
			['signatureSecret', 'sharedSecret', 'eventsPath', 'fieldMapping', 'kindMapping'],
			array_keys($providers[1]['configSchema']['properties'])
		);

	}//end testListsEveryBindingWithItsSchema()

	/**
	 * Without a signed-in user the answer is 401 and lists nothing.
	 *
	 * @return void
	 */
	public function testRefusesWithoutAUser(): void {
		$response = $this->controller(authenticated: false)->providers();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'nl:Not authenticated'], $response->getData());

	}//end testRefusesWithoutAUser()

}//end class
