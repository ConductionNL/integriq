<?php

/**
 * Unit tests for SmsProvidersController.
 *
 * The SMS provider picker is built from the registry: every binding the
 * instance carries, with its name and the configuration it needs.
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
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-a-caller-picks-the-sms-source-and-the-form-picks-the-provider-req-smsp-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\SmsProvidersController;
use OCA\Integriq\Service\Sms\CmComSmsProvider;
use OCA\Integriq\Service\Sms\LogSmsProvider;
use OCA\Integriq\Service\Sms\MessageBirdSmsProvider;
use OCA\Integriq\Service\Sms\RestNotifyNlProvider;
use OCA\Integriq\Service\Sms\SmsProviderRegistry;
use OCA\Integriq\Service\Sms\TwilioSmsProvider;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the SMS provider description endpoint.
 */
#[CoversClass(SmsProvidersController::class)]
#[UsesClass(SmsProviderRegistry::class)]
class SmsProvidersControllerTest extends TestCase {

	/**
	 * A mocked binding.
	 *
	 * @param string $class  The binding class.
	 * @param string $id     Its provider id.
	 * @param string $name   Its display name.
	 * @param array  $schema Its config schema.
	 *
	 * @return object The binding.
	 */
	private function binding(string $class, string $id, string $name, array $schema = []): object {
		$binding = $this->createMock(originalClassName: $class);
		$binding->method('getProviderId')->willReturn($id);
		$binding->method('getProviderName')->willReturn($name);
		$binding->method('getConfigSchema')->willReturn($schema);
		if (method_exists($binding, 'signsStatusCallbacks') === true) {
			$binding->method('signsStatusCallbacks')->willReturn($id !== 'cmcom');
		}

		return $binding;

	}//end binding()

	/**
	 * A controller over a registry of the five bindings.
	 *
	 * @param boolean $authenticated Whether a user is signed in.
	 *
	 * @return SmsProvidersController The controller.
	 */
	private function controller(bool $authenticated = true): SmsProvidersController {
		$registry = new SmsProviderRegistry(
			logProvider: $this->binding(class: LogSmsProvider::class, id: 'log', name: 'Log'),
			notifyNl: $this->binding(class: RestNotifyNlProvider::class, id: 'notifynl', name: 'NotifyNL'),
			cmCom: $this->binding(
				class: CmComSmsProvider::class,
				id: 'cmcom',
				name: 'CM.com',
				schema: ['type' => 'object', 'required' => ['senderId'], 'properties' => ['senderId' => ['type' => 'string']]]
			),
			messageBird: $this->binding(class: MessageBirdSmsProvider::class, id: 'messagebird', name: 'MessageBird'),
			twilio: $this->binding(class: TwilioSmsProvider::class, id: 'twilio', name: 'Twilio')
		);

		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($authenticated === true) {
			$user = $this->createMock(originalClassName: IUser::class);
		}

		$session->method('getUser')->willReturn($user);

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new SmsProvidersController(
			appName: 'integriq',
			request: $this->createMock(originalClassName: IRequest::class),
			registry: $registry,
			userSession: $session,
			l: $l10n
		);

	}//end controller()

	/**
	 * The picker gets the five providers, log first, each with its name and config schema.
	 *
	 * @return void
	 */
	public function testTheFiveProvidersAreDescribedWithNameAndConfiguration(): void {
		$response = $this->controller()->providers();
		$providers = $response->getData()['providers'];

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['log', 'notifynl', 'cmcom', 'messagebird', 'twilio'], array_column($providers, 'providerId'));
		$this->assertSame(['Log', 'NotifyNL', 'CM.com', 'MessageBird', 'Twilio'], array_column($providers, 'name'));
		$this->assertSame(
			['type' => 'object', 'required' => ['senderId'], 'properties' => ['senderId' => ['type' => 'string']]],
			$providers[2]['configSchema']
		);
		$this->assertSame([], $providers[0]['configSchema']);
		$this->assertSame([null, null, ['signed' => false], ['signed' => true], ['signed' => true]], array_column($providers, 'statusCallback'));

	}//end testTheFiveProvidersAreDescribedWithNameAndConfiguration()

	/**
	 * An anonymous caller is refused and learns nothing about the bindings.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$response = $this->controller(authenticated: false)->providers();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());

	}//end testAnAnonymousCallerIsRefused()

}//end class
