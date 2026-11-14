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
