<?php

/**
 * Unit tests for WatchedFileArrivedEvent: who may accept a file, and the
 * public surface that filinq's contract test copies.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-hands-a-file-to-a-named-intake-through-a-typed-event-req-sftp-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Event;

use OCA\Integriq\Event\WatchedFileArrivedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * @covers \OCA\Integriq\Event\WatchedFileArrivedEvent
 */
final class WatchedFileArrivedEventTest extends TestCase {

	/**
	 * The named intake accepts once with a reference, and the result says so.
	 *
	 * @return void
	 */
	public function testTheNamedIntakeMayAccept(): void {
		$event = self::event();
		$this->assertSame(expected: ['accepted' => false, 'intakeApp' => 'filinq', 'reference' => null], actual: $event->getResult());

		$this->assertTrue(condition: $event->accept(appId: 'filinq', reference: ' batch-77 '));
		$this->assertSame(expected: ['accepted' => true, 'intakeApp' => 'filinq', 'reference' => 'batch-77'], actual: $event->getResult());
	}//end testTheNamedIntakeMayAccept()

	/**
	 * Another app id is refused and the result is unchanged.
	 *
	 * @return void
	 */
	public function testAnotherAppIdIsRefused(): void {
		$event = self::event();

		$this->assertFalse(condition: $event->accept(appId: 'dossiq', reference: 'case-12'));
		$this->assertFalse(condition: $event->accept(appId: 'Filinq', reference: 'batch-77'));
		$this->assertSame(expected: ['accepted' => false, 'intakeApp' => 'filinq', 'reference' => null], actual: $event->getResult());
	}//end testAnotherAppIdIsRefused()

	/**
	 * An empty reference, or one of spaces only, is refused.
	 *
	 * @return void
	 */
	public function testAnEmptyReferenceIsRefused(): void {
		$event = self::event();

		$this->assertFalse(condition: $event->accept(appId: 'filinq', reference: '  '));
		$this->assertFalse(condition: $event->accept(appId: 'filinq', reference: ''));
		$this->assertFalse(condition: $event->getResult()['accepted']);
	}//end testAnEmptyReferenceIsRefused()

	/**
	 * A second acceptance is refused and the first reference stays.
	 *
	 * @return void
	 */
	public function testASecondAcceptIsRefused(): void {
		$event = self::event();
		$event->accept(appId: 'filinq', reference: 'batch-77');

		$this->assertFalse(condition: $event->accept(appId: 'filinq', reference: 'batch-78'));
		$this->assertSame(expected: 'batch-77', actual: $event->getResult()['reference']);
	}//end testASecondAcceptIsRefused()

	/**
	 * The public surface is exactly the contract of REQ-SFTP-005, as literals.
	 *
	 * @return void
	 */
	public function testThePublicSurfaceIsTheContract(): void {
		$class = new ReflectionClass(objectOrClass: WatchedFileArrivedEvent::class);
		$this->assertTrue(condition: $class->isSubclassOf(class: Event::class));

		$constructor = $class->getConstructor();
		$this->assertSame(
			expected: [
				'synchronizationId' => 'string',
				'sourceId'          => 'string',
				'intakeApp'         => 'string',
				'fileId'            => 'int',
				'path'              => 'string',
				'ownerUid'          => 'string',
			],
			actual: self::parameters(method: $constructor)
		);

		$getters = [
			'getSynchronizationId' => 'string',
			'getSourceId'          => 'string',
			'getIntakeApp'         => 'string',
			'getFileId'            => 'int',
			'getPath'              => 'string',
			'getOwnerUid'          => 'string',
			'getResult'            => 'array',
		];
		foreach ($getters as $name => $type) {
			$method = $class->getMethod(name: $name);
			$this->assertSame(expected: [], actual: self::parameters(method: $method), message: $name);
			$this->assertSame(expected: $type, actual: self::typeName(type: $method->getReturnType()), message: $name);
		}

		$accept = $class->getMethod(name: 'accept');
		$this->assertSame(expected: ['appId' => 'string', 'reference' => 'string'], actual: self::parameters(method: $accept));
		$this->assertSame(expected: 'bool', actual: self::typeName(type: $accept->getReturnType()));

		$own = array_filter(
			$class->getMethods(filter: ReflectionMethod::IS_PUBLIC),
			static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === WatchedFileArrivedEvent::class
		);
		$names = array_map(static fn (ReflectionMethod $method): string => $method->getName(), $own);
		sort($names);
		$this->assertSame(
			expected: ['__construct', 'accept', 'getFileId', 'getIntakeApp', 'getOwnerUid', 'getPath', 'getResult', 'getSourceId', 'getSynchronizationId'],
			actual: $names
		);
	}//end testThePublicSurfaceIsTheContract()

	/**
	 * The getters answer what the constructor was given.
	 *
	 * @return void
	 */
	public function testTheGettersAnswerTheConstructorArguments(): void {
		$event = self::event();

		$this->assertSame(
			expected: ['sync-1', 'source-1', 'filinq', 42, 'Scans/in/scan.pdf', 'scanner'],
			actual: [
				$event->getSynchronizationId(),
				$event->getSourceId(),
				$event->getIntakeApp(),
				$event->getFileId(),
				$event->getPath(),
				$event->getOwnerUid(),
			]
		);
	}//end testTheGettersAnswerTheConstructorArguments()

	/**
	 * An event for filinq.
	 *
	 * @return WatchedFileArrivedEvent
	 */
	private static function event(): WatchedFileArrivedEvent {
		return new WatchedFileArrivedEvent(
			synchronizationId: 'sync-1',
			sourceId: 'source-1',
			intakeApp: 'filinq',
			fileId: 42,
			path: 'Scans/in/scan.pdf',
			ownerUid: 'scanner'
		);
	}//end event()

	/**
	 * A method's parameters, name to type.
	 *
	 * @param ReflectionMethod|null $method The method.
	 *
	 * @return array<string, string>
	 */
	private static function parameters(?ReflectionMethod $method): array {
		$parameters = [];
		foreach (($method?->getParameters() ?? []) as $parameter) {
			$parameters[$parameter->getName()] = self::typeName(type: $parameter->getType());
		}

		return $parameters;
	}//end parameters()

	/**
	 * A type's name.
	 *
	 * @param mixed $type The reflected type.
	 *
	 * @return string
	 */
	private static function typeName(mixed $type): string {
		if ($type instanceof ReflectionNamedType) {
			return $type->getName();
		}

		return '';
	}//end typeName()
}//end class
