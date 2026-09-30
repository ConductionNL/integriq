<?php

/**
 * The declarative id-fetch guard's decision (REQ-EP-010).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/ori-public-serving/specs/endpoint-runtime/spec.md#requirement-declarative-id-fetch-guard-for-single-object-get-req-ep-010
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\EndpointIdFetchGuard;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the four REQ-EP-010 scenarios on the decision itself.
 */
class EndpointIdFetchGuardTest extends TestCase {

	/**
	 * A matching object passes.
	 *
	 * @return void
	 */
	public function testAnObjectMatchingTheFixedFiltersPasses(): void {
		$this->assertTrue((new EndpointIdFetchGuard())->admits(['lifecycle' => 'published', 'title' => 'x'], ['lifecycle' => 'published']));
	}//end testAnObjectMatchingTheFixedFiltersPasses()

	/**
	 * A draft does not pass a published-only endpoint.
	 *
	 * @return void
	 */
	public function testAnObjectFailingTheFixedFiltersDoesNotPass(): void {
		$this->assertFalse((new EndpointIdFetchGuard())->admits(['lifecycle' => 'draft'], ['lifecycle' => 'published']));
	}//end testAnObjectFailingTheFixedFiltersDoesNotPass()

	/**
	 * No fixed filters admits everything.
	 *
	 * @return void
	 */
	public function testNoFixedFiltersAdmitsEverything(): void {
		$this->assertTrue((new EndpointIdFetchGuard())->admits(['lifecycle' => 'draft'], []));
	}//end testNoFixedFiltersAdmitsEverything()

	/**
	 * The discriminator is read from the object, and every filter must pass.
	 *
	 * @return void
	 */
	public function testEveryFilterIsCheckedOnTheObjectsOwnField(): void {
		$guard = new EndpointIdFetchGuard();

		$this->assertFalse($guard->admits(['decisionType' => 'amendment', 'lifecycle' => 'published'], ['decisionType' => 'motion', 'lifecycle' => 'published']));
		$this->assertFalse($guard->admits(['decisionType' => 'motion', 'lifecycle' => 'draft'], ['decisionType' => 'motion', 'lifecycle' => 'published']));
		$this->assertTrue($guard->admits(['decisionType' => 'motion', 'lifecycle' => 'published'], ['decisionType' => 'motion', 'lifecycle' => 'published']));
	}//end testEveryFilterIsCheckedOnTheObjectsOwnField()

	/**
	 * An object without the field does not pass, as on the collection path.
	 *
	 * @return void
	 */
	public function testAnObjectWithoutTheFieldDoesNotPass(): void {
		$this->assertFalse((new EndpointIdFetchGuard())->admits(['title' => 'x'], ['lifecycle' => 'published']));
	}//end testAnObjectWithoutTheFieldDoesNotPass()

	/**
	 * A list of values passes on any of them; a list-valued field never equals a value.
	 *
	 * @return void
	 */
	public function testAListOfValuesPassesOnAnyOfThem(): void {
		$guard = new EndpointIdFetchGuard();

		$this->assertTrue($guard->admits(['lifecycle' => 'closed'], ['lifecycle' => ['published', 'closed']]));
		$this->assertFalse($guard->admits(['lifecycle' => ['published']], ['lifecycle' => 'published']));
		$this->assertTrue($guard->admits(['public' => true], ['public' => 'true']));
		$this->assertFalse($guard->admits(['public' => false], ['public' => 'true']));
	}//end testAListOfValuesPassesOnAnyOfThem()

	/**
	 * An endpoint carrying fixed filters is accepted by the merged register.
	 *
	 * @return void
	 */
	public function testAnEndpointWithFixedFiltersValidatesAgainstTheRegister(): void {
		$endpoint = [
			'name' => 'ORI motions',
			'endpoint' => 'ori/v1/motions/{{id}}',
			'method' => 'GET',
			'targetType' => 'register/schema',
			'targetId' => '1/2',
			'fixedFilters' => ['decisionType' => 'motion', 'lifecycle' => ['published', 'closed']],
		];

		$this->assertSame([], RegisterSchemaValidator::errors('endpoint', $endpoint));
		// 1.2.0 introduced fixedFilters; a later fragment may only move it up.
		$this->assertTrue(version_compare(RegisterSchemaValidator::descriptor()['components']['schemas']['endpoint']['version'], '1.2.0', '>='));
	}//end testAnEndpointWithFixedFiltersValidatesAgainstTheRegister()
}//end class
