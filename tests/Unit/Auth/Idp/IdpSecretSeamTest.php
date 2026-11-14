<?php

/**
 * The identity provider secret seam: read through the broker at use time, and every way it refuses.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-identity-provider-secrets-are-read-from-keepiq-and-fail-closed-req-idpc-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use OCA\Integriq\Auth\Idp\IdpSecretSeam;
use OCA\Integriq\Exception\IdpSecretRefusedException;
use OCA\Integriq\Service\BrokeredCallServiceInterface;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Auth\Idp\IdpSecretSeam
 * @covers \OCA\Integriq\Exception\IdpSecretRefusedException
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-identity-provider-secrets-are-read-from-keepiq-and-fail-closed-req-idpc-004
 */
class IdpSecretSeamTest extends TestCase {

	/**
	 * The calls the fake broker received.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * The secrets the fake broker answers, one per call.
	 *
	 * @var array<int,mixed>
	 */
	private array $answers = [];

	/**
	 * The app manager.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * The container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Keepiq enabled, the broker in the container, a logger that is checked per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('isEnabledForAnyone')->willReturnCallback(static fn (string $app): bool => $app === 'keepiq');
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->with(IdpSecretSeam::BROKER_CLASS)->willReturn($this->broker());
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A broker that records its calls and answers the queued secrets in turn.
	 *
	 * @return object
	 */
	private function broker(): object {
		$test = $this;

		return new class($test) {
			/**
			 * Constructor.
			 *
			 * @param IdpSecretSeamTest $test The test that records the calls.
			 */
			public function __construct(private readonly IdpSecretSeamTest $test) {
			}//end __construct()

			/**
			 * Record the call and answer the next queued secret.
			 *
			 * @param mixed ...$args The broker arguments.
			 *
			 * @return mixed
			 */
			public function resolveInjectable(mixed ...$args): mixed {
				return $this->test->answer(args: $args);
			}//end resolveInjectable()
		};
	}//end broker()

	/**
	 * Record a broker call and answer the next queued secret (or throw it).
	 *
	 * @param array<int,mixed> $args The broker arguments.
	 *
	 * @return mixed
	 */
	public function answer(array $args): mixed {
		$this->calls[] = $args;
		$answer = array_shift($this->answers);
		if ($answer instanceof \Throwable) {
			throw $answer;
		}

		return $answer;
	}//end answer()

	/**
	 * The seam under test.
	 *
	 * @return IdpSecretSeam
	 */
	private function seam(): IdpSecretSeam {
		return new IdpSecretSeam(appManager: $this->appManager, container: $this->container, logger: $this->logger);
	}//end seam()

	/**
	 * Read a secret, expecting a refusal with the given reason.
	 *
	 * @param string $reason    The expected refusal reason.
	 * @param string $reference The credential reference.
	 *
	 * @return IdpSecretRefusedException
	 */
	private function refusal(string $reason, string $reference = 'cred-signing'): IdpSecretRefusedException {
		try {
			$this->seam()->read(registration: 'reg-1', organisation: 'org-a', role: 'signing', reference: $reference);
		} catch (IdpSecretRefusedException $refusal) {
			$this->assertSame($reason, $refusal->reason);
			return $refusal;
		}

		$this->fail('The seam did not refuse.');
	}//end refusal()

	/**
	 * A secret is read through the broker with integriq as the app and the organisation acting.
	 *
	 * @return void
	 */
	public function testASecretIsReadThroughTheBrokerForTheOrganisation(): void {
		$this->answers = ['-----BEGIN PRIVATE KEY-----'];

		$secret = $this->seam()->read(registration: 'reg-1', organisation: 'org-a', role: 'signing', reference: ' cred-signing ');

		$this->assertSame('-----BEGIN PRIVATE KEY-----', $secret);
		$this->assertSame([['cred-signing', BrokeredCallServiceInterface::APP_ID, null, 'org-a']], $this->calls);
	}//end testASecretIsReadThroughTheBrokerForTheOrganisation()

	/**
	 * Without Keepiq the seam refuses before it asks the broker anything.
	 *
	 * @return void
	 */
	public function testWithoutKeepiqTheSeamRefusesBeforeTheBroker(): void {
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->expects($this->once())->method('isEnabledForAnyone')->with('keepiq')->willReturn(false);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->expects($this->never())->method('get');

		$refusal = $this->refusal(reason: IdpSecretSeam::REASON_KEEPIQ_MISSING);

		$this->assertSame('The signing secret of identity provider registration reg-1 cannot be read: Keepiq is not enabled.', $refusal->getMessage());
		$this->assertSame([], $this->calls);
	}//end testWithoutKeepiqTheSeamRefusesBeforeTheBroker()

	/**
	 * A container that cannot build the broker, or a broker without resolveInjectable(), refuses.
	 *
	 * @return void
	 */
	public function testAMissingBrokerRefuses(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willThrowException(new RuntimeException('not registered'));
		$this->refusal(reason: IdpSecretSeam::REASON_BROKER_MISSING);

		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturn(new \stdClass());
		$this->refusal(reason: IdpSecretSeam::REASON_BROKER_MISSING);
	}//end testAMissingBrokerRefuses()

	/**
	 * An empty reference refuses without asking the broker.
	 *
	 * @return void
	 */
	public function testAnEmptyReferenceRefusesWithoutAskingTheBroker(): void {
		$this->refusal(reason: IdpSecretSeam::REASON_SECRET_MISSING, reference: '  ');

		$this->assertSame([], $this->calls);
	}//end testAnEmptyReferenceRefusesWithoutAskingTheBroker()

	/**
	 * An unknown reference (the broker throws) and an empty value both refuse,
	 * and the log names the registration and the role, never a value or the reference.
	 *
	 * @return void
	 */
	public function testAnUnknownOrEmptySecretRefusesAndLogsTheRoleOnly(): void {
		$logged = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);
		$this->answers = [new RuntimeException('unknown credential cred-signing with value hunter2'), '   '];

		$this->refusal(reason: IdpSecretSeam::REASON_SECRET_MISSING);
		$this->refusal(reason: IdpSecretSeam::REASON_SECRET_MISSING);

		$this->assertSame(
			[
				[
					'[IdpSecretSeam] The signing secret of identity provider registration reg-1 cannot be read: the credential broker refused it (RuntimeException).',
					['registration' => 'reg-1', 'role' => 'signing', 'reason' => 'secret-missing'],
				],
				[
					'[IdpSecretSeam] The signing secret of identity provider registration reg-1 cannot be read: the credential resolves to nothing.',
					['registration' => 'reg-1', 'role' => 'signing', 'reason' => 'secret-missing'],
				],
			],
			$logged
		);
		$this->assertStringNotContainsString('hunter2', (string)json_encode($logged));
		$this->assertStringNotContainsString('cred-signing', (string)json_encode($logged));
	}//end testAnUnknownOrEmptySecretRefusesAndLogsTheRoleOnly()

	/**
	 * Nothing is cached: a value rotated between two reads is used on the second.
	 *
	 * @return void
	 */
	public function testARotatedValueIsUsedOnTheNextRead(): void {
		$this->answers = ['old-key', 'new-key'];
		$seam = $this->seam();

		$this->assertSame('old-key', $seam->read(registration: 'reg-1', organisation: 'org-a', role: 'signing', reference: 'cred-signing'));
		$this->assertSame('new-key', $seam->read(registration: 'reg-1', organisation: 'org-a', role: 'signing', reference: 'cred-signing'));
		$this->assertCount(2, $this->calls);
	}//end testARotatedValueIsUsedOnTheNextRead()
}//end class
