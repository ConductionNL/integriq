<?php

/**
 * Unit tests for DsoRequestTranslator.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/dso-connector-adapter/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\Dso;

use OCA\OpenConnector\Exception\DsoTranslationException;
use OCA\OpenConnector\Service\Dso\DsoRequestTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Translation matrix: full/partial Verzoeken, and the literal-leak guard.
 *
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-inbound-verzoek-translation-with-a-literal-leak-guard-req-002
 */
class DsoVerzoekTranslatorTest extends TestCase {

	/**
	 * @var DsoRequestTranslator
	 */
	private DsoRequestTranslator $translator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->translator = new DsoRequestTranslator();

	}//end setUp()

	/**
	 * A full aanvraag Verzoek (activiteit omschrijving, projectbeschrijving,
	 * aanvrager bsn) translates to a complete, populated mapping.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#scenario-a-full-aanvraag-verzoek-translates-to-mapped
	 */
	public function testFullAanvraagVerzoekTranslatesCompletely(): void {
		$request = [
			'verzoekId' => 'dso-12345',
			'type' => 'aanvraag',
			'activiteiten' => [['code' => 'bouwen-01', 'omschrijving' => 'Bouwen van een woning']],
			'projectbeschrijving' => 'Nieuwbouw eengezinswoning',
			'aanvrager' => ['bsn' => '999993653', 'kvkNummer' => null],
		];

		$result = $this->translator->translate(request: $request);

		$this->assertSame('dso-12345', $result['verzoekId']);
		$this->assertSame('aanvraag', $result['type']);
		$this->assertSame('Bouwen van een woning', $result['mappedTitle']);
		$this->assertStringContainsString('Bouwen van een woning', $result['mappedSummary']);
		$this->assertStringContainsString('Nieuwbouw eengezinswoning', $result['mappedSummary']);
		$this->assertSame('omgevingsloket', $result['mappedChannel']);
		$this->assertSame('hoog', $result['mappedPriority']);
		$this->assertSame('999993653', $result['requester']['bsn']);

	}//end testFullAanvraagVerzoekTranslatesCompletely()

	/**
	 * A partial Verzoek — no activiteit omschrijving, no
	 * projectbeschrijving, no aanvrager — still translates successfully
	 * with safe fallbacks (never a hard failure for merely-thin data).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#scenario-a-partial-melding-verzoek-still-translates-with-fallbacks
	 */
	public function testPartialMeldingVerzoekTranslatesWithFallbacks(): void {
		$request = [
			'verzoekId' => 'dso-partial-1',
			'type' => 'melding',
			'activiteiten' => [['code' => 'kappen-01']],
		];

		$result = $this->translator->translate(request: $request);

		$this->assertSame('kappen-01', $result['mappedTitle']);
		$this->assertSame('kappen-01', $result['mappedSummary']);
		$this->assertSame('normaal', $result['mappedPriority']);
		$this->assertNull($result['requester']['bsn']);

	}//end testPartialMeldingVerzoekTranslatesWithFallbacks()

	/**
	 * A Verzoek with no activiteiten at all still translates — the title
	 * falls back to a type-based generic, the summary to a documented
	 * placeholder (never a fabricated activiteit label).
	 *
	 * @return void
	 */
	public function testVerzoekWithNoActiviteitenUsesGenericFallback(): void {
		$request = ['verzoekId' => 'dso-empty-1', 'type' => 'informatieverzoek', 'activiteiten' => []];

		$result = $this->translator->translate(request: $request);

		$this->assertSame('DSO informatieverzoek', $result['mappedTitle']);
		$this->assertSame('Verzoek zonder activiteitomschrijving.', $result['mappedSummary']);

	}//end testVerzoekWithNoActiviteitenUsesGenericFallback()

	/**
	 * Literal-leak guard: a Verzoek missing verzoekId MUST throw, never
	 * fabricate a correlation reference.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#scenario-a-verzoek-with-no-verzoekid-is-refused-not-fabricated
	 */
	public function testMissingVerzoekIdThrows(): void {
		$this->expectException(DsoTranslationException::class);
		$this->expectExceptionMessageMatches('/verzoekId/');

		$this->translator->translate(request: ['type' => 'aanvraag']);

	}//end testMissingVerzoekIdThrows()

	/**
	 * Literal-leak guard: an empty-string verzoekId MUST also throw (not
	 * merely a missing key).
	 *
	 * @return void
	 */
	public function testEmptyVerzoekIdThrows(): void {
		$this->expectException(DsoTranslationException::class);

		$this->translator->translate(request: ['verzoekId' => '', 'type' => 'aanvraag']);

	}//end testEmptyVerzoekIdThrows()

	/**
	 * An unrecognised Verzoek type MUST throw.
	 *
	 * @return void
	 */
	public function testUnrecognisedTypeThrows(): void {
		$this->expectException(DsoTranslationException::class);

		$this->translator->translate(request: ['verzoekId' => 'dso-1', 'type' => 'onbekend-type']);

	}//end testUnrecognisedTypeThrows()

	/**
	 * Every non-aanvraag type resolves to "normaal" priority.
	 *
	 * @param string $type The Verzoek type.
	 *
	 * @return void
	 *
	 * @dataProvider normalPriorityTypeProvider
	 */
	public function testNonAanvraagTypesResolveToNormalPriority(string $type): void {
		$result = $this->translator->translate(request: ['verzoekId' => 'dso-1', 'type' => $type]);
		$this->assertSame('normaal', $result['mappedPriority']);

	}//end testNonAanvraagTypesResolveToNormalPriority()

	/**
	 * Provides every non-aanvraag Verzoek type.
	 *
	 * @return array<string, array<string>>
	 */
	public static function normalPriorityTypeProvider(): array {
		return [
			'melding' => ['melding'],
			'informatieverzoek' => ['informatieverzoek'],
			'vooroverleg' => ['vooroverleg'],
		];

	}//end normalPriorityTypeProvider()
}//end class
