<?php

/**
 * SynchronizationService: XML to array conversion and the related-object trigger mutations.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/synchronization/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\SynchronizationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SimpleXMLElement;

/**
 * The private helpers of SynchronizationService that need no collaborator.
 */
#[CoversClass(SynchronizationService::class)]
class SynchronizationServiceXmlAndTriggerHelpersTest extends TestCase {

	/**
	 * Invoke a private method on an instance built without its constructor.
	 *
	 * @param string $name The method.
	 * @param mixed  ...$args Its arguments.
	 *
	 * @return mixed
	 */
	private function call(string $name, mixed ...$args): mixed {
		$reflection = new ReflectionClass(SynchronizationService::class);

		return $reflection->getMethod($name)->invoke($reflection->newInstanceWithoutConstructor(), ...$args);

	}//end call()

	/**
	 * Attributes, namespaced attributes, repeated children and text all survive the conversion.
	 *
	 * @return void
	 */
	public function testXmlBecomesAnArrayWithAttributesRepeatsAndText(): void {
		$xml = new SimpleXMLElement(
			'<zaak xmlns:xlink="http://www.w3.org/1999/xlink" id="z1" xlink:href="https://x.example/z1">'
			. '<status>open</status><rol type="a">Ann</rol><rol type="b">Bo</rol><rol>Cy</rol><leeg/></zaak>'
		);

		$this->assertSame(
			[
				'@attributes' => ['id' => 'z1', 'xlink:href' => 'https://x.example/z1'],
				'status' => ['#text' => 'open'],
				'rol' => [
					['@attributes' => ['type' => 'a'], '#text' => 'Ann'],
					['@attributes' => ['type' => 'b'], '#text' => 'Bo'],
					['#text' => 'Cy'],
				],
				'leeg' => [],
			],
			$this->call('xmlToArray', $xml)
		);

	}//end testXmlBecomesAnArrayWithAttributesRepeatsAndText()

	/**
	 * Text beside children is kept under #text.
	 *
	 * @return void
	 */
	public function testMixedContentKeepsItsText(): void {
		$xml = new SimpleXMLElement('<p>Hallo <b>wereld</b></p>');

		$this->assertSame(['b' => ['#text' => 'wereld'], '#text' => 'Hallo'], $this->call('xmlToArray', $xml));

	}//end testMixedContentKeepsItsText()

	/**
	 * The mutation list allows "all", treats create and update as one group, and needs delete named.
	 *
	 * @return void
	 */
	public function testTheMutationListDecidesWhichMutationsTrigger(): void {
		$this->assertTrue($this->call('isRelatedObjectMutationAllowed', ['ALL'], 'delete'));
		$this->assertTrue($this->call('isRelatedObjectMutationAllowed', ['create'], 'update'));
		$this->assertTrue($this->call('isRelatedObjectMutationAllowed', ['Update'], 'CREATE'));
		$this->assertFalse($this->call('isRelatedObjectMutationAllowed', ['create', 'update'], 'delete'));
		$this->assertTrue($this->call('isRelatedObjectMutationAllowed', ['delete'], 'Delete'));
		$this->assertFalse($this->call('isRelatedObjectMutationAllowed', ['delete'], 'update'));
		$this->assertFalse($this->call('isRelatedObjectMutationAllowed', ['create'], 'archive'));
		$this->assertFalse($this->call('isRelatedObjectMutationAllowed', 'all', 'create'));

	}//end testTheMutationListDecidesWhichMutationsTrigger()

	/**
	 * A trigger config is read by its first relation key only.
	 *
	 * @return void
	 */
	public function testTheTriggerConfigUsesItsFirstRelation(): void {
		$this->assertTrue($this->call('isRelatedTriggerConfigAllowed', ['zaak' => ['update'], 'besluit' => []], 'create'));
		$this->assertFalse($this->call('isRelatedTriggerConfigAllowed', ['zaak' => [], 'besluit' => ['all']], 'create'));
		$this->assertFalse($this->call('isRelatedTriggerConfigAllowed', ['create'], 'create'));
		$this->assertFalse($this->call('isRelatedTriggerConfigAllowed', [' ' => ['all']], 'create'));
		$this->assertFalse($this->call('isRelatedTriggerConfigAllowed', 'zaak', 'create'));

	}//end testTheTriggerConfigUsesItsFirstRelation()
}//end class
