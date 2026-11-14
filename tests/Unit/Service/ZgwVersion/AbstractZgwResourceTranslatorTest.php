<?php

/**
 * Unit tests for the literal-leak guards of AbstractZgwResourceTranslator.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\ZgwVersion
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/zgw-version-translation/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\ZgwVersion;

use OCA\Integriq\Exception\ZgwLiteralLeakException;
use OCA\Integriq\Service\ZgwVersion\AbstractZgwResourceTranslator;
use PHPUnit\Framework\TestCase;

/**
 * A translator whose v1.6 direction runs the three guards, with the
 * required fields, enum field and array field given per test.
 */
class GuardProbeTranslator extends AbstractZgwResourceTranslator {

	/**
	 * Build the probe.
	 *
	 * @param string[] $required   Required fields.
	 * @param string   $enumField  The enum-guarded field.
	 * @param string   $arrayField The array-guarded field.
	 */
	public function __construct(
		private array $required = [],
		private string $enumField = 'vertrouwelijkheidaanduiding',
		private string $arrayField = 'besluittypen',
	) {
	}//end __construct()

	/**
	 * The resource name used in messages.
	 *
	 * @return string
	 */
	public function getResource(): string {
		return 'probe';
	}//end getResource()

	/**
	 * Run the guards and return the payload unchanged.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return array<string, mixed>
	 */
	public function translateToV16(array $payload): array {
		$this->requireFields(payload: $payload, required: $this->required);
		$this->guardEnum(payload: $payload, field: $this->enumField, allowed: self::VERTROUWELIJKHEID_VALUES);
		$this->guardArrayField(payload: $payload, field: $this->arrayField);
		return $payload;
	}//end translateToV16()

	/**
	 * Identity.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return array<string, mixed>
	 */
	public function translateToV1x(array $payload): array {
		return $payload;
	}//end translateToV1x()
}//end class

/**
 * Tests for the shared guard helpers.
 *
 * @covers \OCA\Integriq\Service\ZgwVersion\AbstractZgwResourceTranslator
 * @uses   \OCA\Integriq\Exception\ZgwLiteralLeakException
 *
 * @spec openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
 */
class AbstractZgwResourceTranslatorTest extends TestCase {

	/**
	 * A payload passing every guard comes back unchanged.
	 *
	 * @return void
	 */
	public function testValidPayloadPassesEveryGuard(): void {
		$payload = ['url' => 'u', 'vertrouwelijkheidaanduiding' => 'zeer_geheim', 'besluittypen' => ['a']];

		$this->assertSame($payload, (new GuardProbeTranslator(required: ['url']))->translateToV16($payload));
	}//end testValidPayloadPassesEveryGuard()

	/**
	 * Absent or null optional enum and array fields are not rejected.
	 *
	 * @return void
	 */
	public function testAbsentOrNullOptionalFieldsAreAccepted(): void {
		$probe = new GuardProbeTranslator();

		$this->assertSame([], $probe->translateToV16([]));
		$this->assertSame(
			['vertrouwelijkheidaanduiding' => null, 'besluittypen' => null],
			$probe->translateToV16(['vertrouwelijkheidaanduiding' => null, 'besluittypen' => null])
		);
	}//end testAbsentOrNullOptionalFieldsAreAccepted()

	/**
	 * A missing required field is refused, naming the first one missing.
	 *
	 * @return void
	 */
	public function testMissingRequiredFieldIsRefusedByName(): void {
		$this->expectException(ZgwLiteralLeakException::class);
		$this->expectExceptionMessage('Resource "probe" is missing required field "zaaktype".');

		(new GuardProbeTranslator(required: ['url', 'zaaktype', 'status']))->translateToV16(['url' => 'u']);
	}//end testMissingRequiredFieldIsRefusedByName()

	/**
	 * A required field present but null counts as missing.
	 *
	 * @return void
	 */
	public function testNullRequiredFieldIsRefused(): void {
		$this->expectException(ZgwLiteralLeakException::class);
		$this->expectExceptionMessage('missing required field "url"');

		(new GuardProbeTranslator(required: ['url']))->translateToV16(['url' => null]);
	}//end testNullRequiredFieldIsRefused()

	/**
	 * Every documented confidentiality value is accepted.
	 *
	 * @return void
	 */
	public function testEveryDocumentedConfidentialityValueIsAccepted(): void {
		$probe  = new GuardProbeTranslator();
		$values = ['openbaar', 'beperkt_openbaar', 'intern', 'zaakvertrouwelijk', 'vertrouwelijk', 'confidentieel', 'geheim', 'zeer_geheim'];

		foreach ($values as $value) {
			$this->assertSame(
				['vertrouwelijkheidaanduiding' => $value],
				$probe->translateToV16(['vertrouwelijkheidaanduiding' => $value])
			);
		}
	}//end testEveryDocumentedConfidentialityValueIsAccepted()

	/**
	 * An out-of-set enum value is refused with field and value named.
	 *
	 * @return void
	 */
	public function testOutOfSetEnumValueIsRefused(): void {
		$this->expectException(ZgwLiteralLeakException::class);
		$this->expectExceptionMessage(
			'Resource "probe" field "vertrouwelijkheidaanduiding" carries an out-of-set enum value "Openbaar"'
		);

		(new GuardProbeTranslator())->translateToV16(['vertrouwelijkheidaanduiding' => 'Openbaar']);
	}//end testOutOfSetEnumValueIsRefused()

	/**
	 * The enum comparison is strict: a non-string value never matches.
	 *
	 * @return void
	 */
	public function testEnumComparisonIsStrict(): void {
		$this->expectException(ZgwLiteralLeakException::class);
		$this->expectExceptionMessage('out-of-set enum value "0"');

		(new GuardProbeTranslator())->translateToV16(['vertrouwelijkheidaanduiding' => 0]);
	}//end testEnumComparisonIsStrict()

	/**
	 * A bare scalar where an array is required is refused with its value named.
	 *
	 * @return void
	 */
	public function testScalarInArrayFieldIsRefused(): void {
		$this->expectException(ZgwLiteralLeakException::class);
		$this->expectExceptionMessage(
			'Resource "probe" field "besluittypen" MUST be an array, got a bare scalar ("https://example.org/bt/1")'
		);

		(new GuardProbeTranslator())->translateToV16(['besluittypen' => 'https://example.org/bt/1']);
	}//end testScalarInArrayFieldIsRefused()

	/**
	 * An empty array is a valid array value.
	 *
	 * @return void
	 */
	public function testEmptyArrayFieldIsAccepted(): void {
		$this->assertSame(['besluittypen' => []], (new GuardProbeTranslator())->translateToV16(['besluittypen' => []]));
	}//end testEmptyArrayFieldIsAccepted()
}//end class
