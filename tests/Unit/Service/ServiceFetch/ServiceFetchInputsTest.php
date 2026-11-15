<?php

/**
 * Unit tests for ServiceFetchInputs — input checks and path/query rendering.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\ServiceFetch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\ServiceFetch;

use InvalidArgumentException;
use OCA\Integriq\Service\ServiceFetch\ServiceFetchInputs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Design D3 steps 3 and 4.
 *
 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
 */
#[CoversClass(ServiceFetchInputs::class)]
class ServiceFetchInputsTest extends TestCase {

	/**
	 * The declared inputs of the Kadaster example plus one of each other type.
	 */
	private const DECLARED = [
		['name' => 'bsn', 'type' => 'string', 'required' => true],
		['name' => 'nummeraanduiding', 'type' => 'string', 'required' => true],
		['name' => 'peildatum', 'type' => 'date'],
		['name' => 'aantal', 'type' => 'number'],
		['name' => 'actueel', 'type' => 'boolean'],
	];

	/**
	 * Undeclared inputs are dropped and empty optional ones left out.
	 *
	 * @return void
	 */
	public function testOnlyDeclaredInputsAreKept(): void {
		$accepted = (new ServiceFetchInputs())->accepted(
			declared: self::DECLARED,
			given: ['bsn' => '999993653', 'nummeraanduiding' => '0363200000123456', 'peildatum' => '', 'aantal' => '3', 'actueel' => false, 'extra' => 'x']
		);

		$this->assertSame(['bsn' => '999993653', 'nummeraanduiding' => '0363200000123456', 'aantal' => '3', 'actueel' => false], $accepted);
	}//end testOnlyDeclaredInputsAreKept()

	/**
	 * A missing required input or a wrongly typed input is refused by name.
	 *
	 * @return void
	 */
	public function testAMissingOrWronglyTypedInputIsRefusedByName(): void {
		$base = ['bsn' => '999993653', 'nummeraanduiding' => '0363200000123456'];
		$refused = [];
		foreach ([
			'missing bsn' => ['nummeraanduiding' => '1'],
			'empty bsn' => ['bsn' => ''] + $base,
			'date' => $base + ['peildatum' => '2026-02-30'],
			'number' => $base + ['aantal' => 'drie'],
			'boolean' => $base + ['actueel' => 'yes'],
			'array' => ['bsn' => ['999993653']] + $base,
		] as $case => $given) {
			try {
				(new ServiceFetchInputs())->accepted(declared: self::DECLARED, given: $given);
				$refused[$case] = null;
			} catch (InvalidArgumentException $e) {
				$refused[$case] = $e->getMessage();
			}
		}

		$this->assertSame(
			['missing bsn' => 'bsn', 'empty bsn' => 'bsn', 'date' => 'peildatum', 'number' => 'aantal', 'boolean' => 'actueel', 'array' => 'bsn'],
			$refused
		);
	}//end testAMissingOrWronglyTypedInputIsRefusedByName()

	/**
	 * Path values are URL-encoded, so a value cannot add a path segment; an
	 * unknown placeholder renders empty.
	 *
	 * @return void
	 */
	public function testThePathIsRenderedWithEncodedValues(): void {
		$path = (new ServiceFetchInputs())->path(
			path: '/eigendom/{bsn}/objecten/{nummeraanduiding}{unknown}',
			inputs: ['bsn' => '9999 93/653', 'nummeraanduiding' => 'a?b']
		);

		$this->assertSame('/eigendom/9999%2093%2F653/objecten/a%3Fb', $path);
	}//end testThePathIsRenderedWithEncodedValues()

	/**
	 * A rendered path that would leave the source location is refused.
	 *
	 * @return void
	 */
	public function testAPathThatLeavesTheSourceIsRefused(): void {
		$inputs = new ServiceFetchInputs();
		$refused = [];
		foreach (['/a/{x}/b' => ['x' => '..'], '{x}' => ['x' => '.'], 'https://evil.example/{x}' => ['x' => '1'], '//evil.example' => [], '/a\\b' => []] as $template => $values) {
			try {
				$inputs->path(path: $template, inputs: $values);
				$refused[] = false;
			} catch (InvalidArgumentException $e) {
				$refused[] = $e->getMessage();
			}
		}

		$this->assertSame(['path', 'path', 'path', 'path', 'path'], $refused);
		$this->assertSame('/a/..b/c', $inputs->path(path: '/a/{x}/c', inputs: ['x' => '..b']));
	}//end testAPathThatLeavesTheSourceIsRefused()

	/**
	 * Query values are rendered unencoded, booleans as text, empty ones left out.
	 *
	 * @return void
	 */
	public function testTheQueryIsRendered(): void {
		$query = (new ServiceFetchInputs())->query(
			query: ['bsn' => '{bsn}', 'actueel' => '{actueel}', 'leeg' => '{peildatum}', 'vast' => 'ja', 'lijst' => ['x']],
			inputs: ['bsn' => '999 993', 'actueel' => true]
		);

		$this->assertSame(['bsn' => '999 993', 'actueel' => 'true', 'vast' => 'ja'], $query);
	}//end testTheQueryIsRendered()
}//end class
