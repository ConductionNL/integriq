<?php

/**
 * Unit test for CloudEventListener when the self-reference schema ids cannot be resolved.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\EventListener\CloudEventListener;
use OCA\Integriq\Service\Event\EventLoopGuard;
use OCA\Integriq\Service\EventService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An unresolvable self-reference guard fails open, loudly: the event is still forwarded and a warning names the cause.
 */
#[CoversClass(CloudEventListener::class)]
#[UsesClass(EventLoopGuard::class)]
final class CloudEventListenerSelfSchemaFailureTest extends TestCase {

	/**
	 * Clear the listener's memoised self-schema ids before and after the test.
	 *
	 * The listener keeps the resolved ids in a static, so an earlier test that
	 * resolved them would keep this one from ever reaching the failing lookup,
	 * and the ids this test leaves behind would leak into the next.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		self::resetSelfSchemaIds();

	}//end setUp()

	/**
	 * Clear the memoised ids again for the tests that follow.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		self::resetSelfSchemaIds();
		parent::tearDown();

	}//end tearDown()

	/**
	 * Reset CloudEventListener::$selfSchemaIds to unresolved.
	 *
	 * @return void
	 */
	private static function resetSelfSchemaIds(): void {
		$prop = (new \ReflectionClass(CloudEventListener::class))->getProperty('selfSchemaIds');
		$prop->setAccessible(true);
		$prop->setValue(null, null);

	}//end resetSelfSchemaIds()

	/**
	 * The schema ids cannot be read: the object is forwarded and the inert guard is warned about.
	 *
	 * @return void
	 */
	public function testAnUnresolvableGuardForwardsAndWarns(): void {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setRegister('65');
		$object->setSchema('25');
		$object->setObject(['type' => 'person']);

		$eventService = $this->createMock(EventService::class);
		$eventService->method('hasActiveSubscriptions')->willReturn(true);
		$eventService->method('getSelfSchemaIds')->willThrowException(new RuntimeException('register lookup failed'));
		$eventService->expects($this->once())->method('handleObjectCreated')->with($object);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[CloudEventListener] could not resolve self-reference schema ids; the recursion guard is INERT: register lookup failed'
		);

		(new CloudEventListener($eventService, $logger, new EventLoopGuard()))->handle(new ObjectCreatedEvent($object));
	}//end testAnUnresolvableGuardForwardsAndWarns()
}//end class
