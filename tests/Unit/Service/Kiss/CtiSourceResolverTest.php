<?php

/**
 * Which CTI source a request names, and which binding handles it.
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
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Kiss;

use OCA\Integriq\Service\Kiss\CallEventNormaliser;
use OCA\Integriq\Service\Kiss\CtiSourceResolver;
use OCA\Integriq\Service\Kiss\LogCtiProvider;
use OCA\Integriq\Service\Kiss\WebhookCtiProvider;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Service\Kiss\CtiSourceResolver
 * @uses   \OCA\Integriq\Service\Kiss\LogCtiProvider
 * @uses   \OCA\Integriq\Service\Kiss\WebhookCtiProvider
 */
class CtiSourceResolverTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objects;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The log binding.
	 *
	 * @var LogCtiProvider
	 */
	private LogCtiProvider $log;

	/**
	 * The webhook binding.
	 *
	 * @var WebhookCtiProvider
	 */
	private WebhookCtiProvider $webhook;

	/**
	 * The resolver under test.
	 *
	 * @var CtiSourceResolver
	 */
	private CtiSourceResolver $resolver;

	/**
	 * Set up the resolver over the real bindings.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects  = $this->createMock(ObjectService::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
		$this->log      = new LogCtiProvider(new CallEventNormaliser(), $this->createMock(LoggerInterface::class));
		$this->webhook  = new WebhookCtiProvider(new CallEventNormaliser(), $this->createMock(WebhookSignatureService::class));
		$this->resolver = new CtiSourceResolver($this->objects, $this->log, $this->webhook, $this->logger);
	}//end setUp()

	/**
	 * The object service answers this source body for the id.
	 *
	 * @param array<string, mixed> $body The source body.
	 *
	 * @return void
	 */
	private function stores(array $body): void {
		$this->objects->expects($this->once())->method('find')
			->with('src-1', 'integriq', 'source', false, false)
			->willReturn(ObjectServiceMockBuilder::objectEntity($this, $body, 'src-1'));
	}//end stores()

	/**
	 * An enabled CTI source is found, read past RBAC and multitenancy.
	 *
	 * @return void
	 */
	public function testAnEnabledCtiSourceIsFound(): void {
		$body = ['type' => 'cti', 'configuration' => ['provider' => 'log']];
		$this->stores($body);

		$this->assertSame($body, $this->resolver->find('src-1'));
	}//end testAnEnabledCtiSourceIsFound()

	/**
	 * A source of another type, or a disabled one, is not a CTI source.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function notCti(): array {
		return [
			'another type' => [['type' => 'rest', 'isEnabled' => true]],
			'no type' => [['isEnabled' => true]],
			'disabled' => [['type' => 'cti', 'isEnabled' => false]],
		];
	}//end notCti()

	/**
	 * A source that is not an enabled CTI source is not found.
	 *
	 * @param array<string, mixed> $body The source body.
	 *
	 * @dataProvider notCti
	 *
	 * @return void
	 */
	public function testANonCtiOrDisabledSourceIsNotFound(array $body): void {
		$this->stores($body);

		$this->assertNull($this->resolver->find('src-1'));
	}//end testANonCtiOrDisabledSourceIsNotFound()

	/**
	 * A blank id is not looked up; a missing or failing lookup is not found.
	 *
	 * @return void
	 */
	public function testABlankIdOrAFailedLookupFindsNothing(): void {
		$this->objects->expects($this->exactly(2))->method('find')
			->willReturnOnConsecutiveCalls(null, $this->throwException(new RuntimeException('no such object')));

		$this->assertNull($this->resolver->find('  '));
		$this->assertNull($this->resolver->find('src-1'));
		$this->assertNull($this->resolver->find('src-2'));
	}//end testABlankIdOrAFailedLookupFindsNothing()

	/**
	 * The binding is picked by the provider the source configuration names.
	 *
	 * @return void
	 */
	public function testTheBindingIsTheOneTheSourceNames(): void {
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame($this->log, $this->resolver->provider(['configuration' => ['provider' => 'log']]));
		$this->assertSame($this->webhook, $this->resolver->provider(['configuration' => ['provider' => 'webhook']]));
	}//end testTheBindingIsTheOneTheSourceNames()

	/**
	 * An unknown or missing binding is logged and none is returned.
	 *
	 * @return void
	 */
	public function testAnUnknownBindingIsLogged(): void {
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(static function (string $message) use (&$warnings): void {
			$warnings[] = $message;
		});

		$this->assertNull($this->resolver->provider(['configuration' => ['provider' => 'asterisk']]));
		$this->assertNull($this->resolver->provider([]));
		$this->assertSame(
			['[CtiSourceResolver] no CTI binding named "asterisk"', '[CtiSourceResolver] no CTI binding named ""'],
			$warnings
		);
	}//end testAnUnknownBindingIsLogged()
}//end class
