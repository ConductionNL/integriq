<?php

/**
 * A CTI source is only answered when it is an enabled CTI source, and only
 * bound to a provider it names exactly.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Kiss;

use OCA\Integriq\Service\Kiss\CallEventNormaliser;
use OCA\Integriq\Service\Kiss\CtiSourceResolver;
use OCA\Integriq\Service\Kiss\LogCtiProvider;
use OCA\Integriq\Service\Kiss\WebhookCtiProvider;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for CtiSourceResolver.
 *
 * @covers \OCA\Integriq\Service\Kiss\CtiSourceResolver
 * @uses \OCA\Integriq\Service\Kiss\LogCtiProvider
 * @uses \OCA\Integriq\Service\Kiss\WebhookCtiProvider
 */
class CtiSourceResolverTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The real log provider.
	 *
	 * @var LogCtiProvider
	 */
	private LogCtiProvider $logProvider;

	/**
	 * The real webhook provider.
	 *
	 * @var WebhookCtiProvider
	 */
	private WebhookCtiProvider $webhookProvider;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->getMockBuilder(ObjectService::class)->disableOriginalConstructor()->getMock();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logProvider = new LogCtiProvider(new CallEventNormaliser(), $this->createMock(LoggerInterface::class));
		$this->webhookProvider = new WebhookCtiProvider(new CallEventNormaliser(), $this->createMock(WebhookSignatureService::class));
	}//end setUp()

	/**
	 * The resolver under test.
	 *
	 * @return CtiSourceResolver
	 */
	private function resolver(): CtiSourceResolver {
		return new CtiSourceResolver($this->objects, $this->logProvider, $this->webhookProvider, $this->logger);
	}//end resolver()

	/**
	 * An entity whose stored object is the given value.
	 *
	 * @param array $object The stored object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $object): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($object);
		return $entity;
	}//end entity()

	/**
	 * An enabled CTI source is read from the integriq source schema, unscoped.
	 *
	 * @return void
	 */
	public function testFindsAnEnabledCtiSource(): void {
		$source = ['type' => 'cti', 'configuration' => ['provider' => 'log']];
		$this->objects->expects($this->once())->method('find')
			->with('src-1', 'integriq', 'source', false, false)
			->willReturn($this->entity($source));

		$this->assertSame($source, $this->resolver()->find('src-1'));
	}//end testFindsAnEnabledCtiSource()

	/**
	 * Without an isEnabled flag the source counts as enabled.
	 *
	 * @return void
	 */
	public function testAnUnflaggedSourceIsEnabled(): void {
		$this->objects->method('find')->willReturn($this->entity(['type' => 'cti']));
		$this->assertSame(['type' => 'cti'], $this->resolver()->find('src-1'));
	}//end testAnUnflaggedSourceIsEnabled()

	/**
	 * A blank id is not looked up at all.
	 *
	 * @return void
	 */
	public function testABlankIdIsNotLookedUp(): void {
		$this->objects->expects($this->never())->method('find');

		$this->assertNull($this->resolver()->find('  '));
	}//end testABlankIdIsNotLookedUp()

	/**
	 * A failed lookup, a missing entity, another source type and a disabled
	 * source all answer null.
	 *
	 * @return void
	 */
	public function testEveryOtherSourceIsNull(): void {
		$answers = [
			new RuntimeException('not found'),
			null,
			$this->entity(['type' => 'email']),
			$this->entity(['configuration' => []]),
			$this->entity(['type' => 'cti', 'isEnabled' => false]),
		];
		$this->objects->method('find')->willReturnCallback(
			static function () use (&$answers): ?ObjectEntity {
				$answer = array_shift($answers);
				if ($answer instanceof RuntimeException) {
					throw $answer;
				}

				return $answer;
			}
		);
		$resolver = $this->resolver();

		for ($i = 0; $i < 5; $i++) {
			$this->assertNull($resolver->find('src-' . $i), 'answer ' . $i);
		}
	}//end testEveryOtherSourceIsNull()

	/**
	 * The provider named in the configuration is bound, by exact id.
	 *
	 * @return void
	 */
	public function testBindsTheNamedProvider(): void {
		$this->logger->expects($this->never())->method('warning');
		$resolver = $this->resolver();

		$this->assertSame($this->logProvider, $resolver->provider(['configuration' => ['provider' => 'log']]));
		$this->assertSame($this->webhookProvider, $resolver->provider(['configuration' => ['provider' => 'webhook']]));
	}//end testBindsTheNamedProvider()

	/**
	 * An unknown, differently cased or missing provider is no binding, and says so.
	 *
	 * @return void
	 */
	public function testAnUnknownProviderIsNoBinding(): void {
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);
		$resolver = $this->resolver();

		$this->assertNull($resolver->provider(['configuration' => ['provider' => 'sip']]));
		$this->assertNull($resolver->provider(['configuration' => ['provider' => 'Webhook']]));
		$this->assertNull($resolver->provider([]));
		$this->assertSame(
			[
				'[CtiSourceResolver] no CTI binding named "sip"',
				'[CtiSourceResolver] no CTI binding named "Webhook"',
				'[CtiSourceResolver] no CTI binding named ""',
			],
			$warnings
		);
	}//end testAnUnknownProviderIsNoBinding()

	/**
	 * describeAll() lists exactly the bindings provider() resolves, with their own schemas.
	 *
	 * @return void
	 */
	public function testDescribesEveryBindingItResolves(): void {
		$resolver = $this->resolver();

		$described = $resolver->describeAll();

		$this->assertSame(['log', 'webhook'], array_column($described, 'id'));
		$this->assertSame($this->logProvider->getConfigSchema(), $described[0]['configSchema']);
		$this->assertSame($this->webhookProvider->getConfigSchema(), $described[1]['configSchema']);
		foreach ($described as $binding) {
			$this->assertNotNull($resolver->provider(['configuration' => ['provider' => $binding['id']]]));
		}
	}//end testDescribesEveryBindingItResolves()
}//end class
