<?php

/**
 * Unit tests for SmsProviderRegistry and UnknownSmsProvider.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Sms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Sms;

use OCA\Integriq\Exception\SmsProviderException;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\Sms\CmComSmsProvider;
use OCA\Integriq\Service\Sms\LogSmsProvider;
use OCA\Integriq\Service\Sms\MessageBirdSmsProvider;
use OCA\Integriq\Service\Sms\SmsProviderRegistry;
use OCA\Integriq\Service\Sms\TwilioSmsProvider;
use OCA\Integriq\Service\Sms\UnknownSmsProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The registry resolves provider values against the real bindings.
 *
 * @covers \OCA\Integriq\Service\Sms\SmsProviderRegistry
 * @covers \OCA\Integriq\Service\Sms\UnknownSmsProvider
 * @uses   \OCA\Integriq\Service\Sms\BrokeredSmsProvider
 * @uses   \OCA\Integriq\Service\Sms\CmComSmsProvider
 * @uses   \OCA\Integriq\Service\Sms\MessageBirdSmsProvider
 * @uses   \OCA\Integriq\Service\Sms\TwilioSmsProvider
 * @uses   \OCA\Integriq\Service\Sms\LogSmsProvider
 * @uses   \OCA\Integriq\Exception\SmsProviderException
 *
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-an-unknown-sms-provider-value-is-refused-req-smsp-002
 */
class SmsProviderRegistryTest extends TestCase {

	/**
	 * A registry over the real log and vendor bindings.
	 *
	 * @return SmsProviderRegistry The registry.
	 */
	private function registry(): SmsProviderRegistry {
		$broker = $this->createMock(BrokeredCallService::class);
		$logger = $this->createMock(LoggerInterface::class);

		return new SmsProviderRegistry(
			logProvider: new LogSmsProvider(),
			cmCom: new CmComSmsProvider($broker, $logger),
			messageBird: new MessageBirdSmsProvider($broker, $logger),
			twilio: new TwilioSmsProvider($broker, $logger)
		);
	}//end registry()

	/**
	 * No provider value means the log binding.
	 *
	 * @return void
	 */
	public function testNoValueResolvesToLog(): void {
		$registry = $this->registry();

		$this->assertInstanceOf(LogSmsProvider::class, $registry->resolve(providerId: null));
		$this->assertInstanceOf(LogSmsProvider::class, $registry->resolve(providerId: ''));
	}//end testNoValueResolvesToLog()

	/**
	 * Each vendor id resolves to its binding.
	 *
	 * @return void
	 */
	public function testEachIdResolvesToItsBinding(): void {
		$registry = $this->registry();

		$this->assertInstanceOf(CmComSmsProvider::class, $registry->resolve(providerId: 'cmcom'));
		$this->assertInstanceOf(MessageBirdSmsProvider::class, $registry->resolve(providerId: 'messagebird'));
		$this->assertInstanceOf(TwilioSmsProvider::class, $registry->resolve(providerId: 'twilio'));
		$this->assertSame(['log', 'cmcom', 'messagebird', 'twilio'], $registry->ids());
		$this->assertSame($registry->ids(), array_keys($registry->all()));
	}//end testEachIdResolvesToItsBinding()

	/**
	 * A mistyped value resolves to a binding that refuses to send, naming the value and the known ids.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#scenario-a-mistyped-provider-shows-as-a-failure-not-a-success
	 */
	public function testAMistypedValueRefusesToSendNamingIt(): void {
		$provider = $this->registry()->resolve(providerId: 'twillio');

		$this->assertInstanceOf(UnknownSmsProvider::class, $provider);
		$this->assertSame('twillio', $provider->getProviderId());

		try {
			$provider->send([], '+31612345678', 'x');
			$this->fail('an unknown provider must not send');
		} catch (SmsProviderException $exception) {
			$this->assertSame('unknown_provider', $exception->getErrorCode());
			$this->assertSame(
				'Unknown SMS provider "twillio"; the SMS source must name one of: log, cmcom, messagebird, twilio.',
				$exception->getMessage()
			);
		}

		$this->expectException(SmsProviderException::class);
		$provider->fetchStatus([], 'id');
	}//end testAMistypedValueRefusesToSendNamingIt()
}//end class
