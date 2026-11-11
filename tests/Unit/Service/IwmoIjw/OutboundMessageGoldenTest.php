<?php

/**
 * Golden test pinning the full envelope OutboundMessageTranslator renders.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\IwmoIjw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/iwmo-ijw-adapter/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\IwmoIjw;

use OCA\Integriq\Exception\IwmoIjwTranslationException;
use OCA\Integriq\Service\IwmoIjw\OutboundMessageTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Pins the rendered XML (reference and time masked), the berichttype and reference shape,
 * and every refusal message, for both kinds and both domains.
 */
class OutboundMessageGoldenTest extends TestCase {

	/**
	 * Translate and mask the generated reference and the message time.
	 *
	 * @param array  $caseObject The case fields.
	 * @param string $kind       The bericht kind.
	 * @param string $domain     The domain.
	 *
	 * @return array{0: string, 1: string} The berichttype and the masked XML.
	 */
	private function render(array $caseObject, string $kind, string $domain): array {
		$out = (new OutboundMessageTranslator())->translate($caseObject, $kind, $domain);
		$this->assertMatchesRegularExpression('/^(WMO|JW)-[0-9a-f]{16}$/', $out['ref']);
		$this->assertStringContainsString('<referentienummer>' . $out['ref'] . '</referentienummer>', $out['xml']);
		$xml = str_replace($out['ref'], 'REF', $out['xml']);
		$xml = (string)preg_replace('#<tijdstipBericht>[^<]+</tijdstipBericht>#', '<tijdstipBericht>TS</tijdstipBericht>', $xml);

		return [$out['berichttype'], $xml];

	}//end render()

	/**
	 * Both kinds, both domains, the optional einddatum, and escaping.
	 *
	 * @return void
	 */
	public function testRenderedEnvelopes(): void {
		$head = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<Bericht><stuurgegevens><berichtcode>%s</berichtcode>'
			. '<zender><code>0363</code></zender><ontvanger><code>AGB&amp;1</code></ontvanger>'
			. '<referentienummer>REF</referentienummer><tijdstipBericht>TS</tijdstipBericht></stuurgegevens><body>';
		$toewijzing = ['bsn' => '999', 'productcode' => 'P<1>', 'ingangsdatum' => '2026-01-01', 'omvang' => 3, 'leveringsvorm' => 'ZIN', 'aanbiederAgbCode' => 'AGB&1', 'gemeentecode' => '0363', 'extra' => 'x'];

		$this->assertSame(
			['Wmo303', sprintf($head, 'Wmo303') . '<bsn>999</bsn><productcode>P&lt;1&gt;</productcode><ingangsdatum>2026-01-01</ingangsdatum><omvang>3</omvang><leveringsvorm>ZIN</leveringsvorm></body></Bericht>' . "\n"],
			$this->render($toewijzing, 'toewijzing', 'wmo')
		);
		$this->assertSame(
			['Jw303', sprintf($head, 'Jw303') . '<bsn>999</bsn><productcode>P&lt;1&gt;</productcode><ingangsdatum>2026-01-01</ingangsdatum><einddatum>2026-12-31</einddatum><omvang>3</omvang><leveringsvorm>ZIN</leveringsvorm></body></Bericht>' . "\n"],
			$this->render(array_merge($toewijzing, ['einddatum' => '2026-12-31']), 'toewijzing', 'jw')
		);
		$this->assertStringNotContainsString('einddatum', $this->render(array_merge($toewijzing, ['einddatum' => '0']), 'toewijzing', 'wmo')[1]);

		$declaratie = ['toewijzingReferentie' => 'T-1', 'productcode' => 'P', 'factuurnummer' => 'F"1', 'bedrag' => 12.5, 'periodeStart' => 'a', 'periodeEind' => 'b', 'aanbiederAgbCode' => 'AGB&1', 'gemeentecode' => '0363', 'einddatum' => 'ignored'];
		$this->assertSame(
			['Wmo321', sprintf($head, 'Wmo321') . '<toewijzingReferentie>T-1</toewijzingReferentie><productcode>P</productcode><factuurnummer>F"1</factuurnummer><bedrag>12.5</bedrag><periodeStart>a</periodeStart><periodeEind>b</periodeEind></body></Bericht>' . "\n"],
			$this->render($declaratie, 'declaratie', 'wmo')
		);
		$this->assertSame('Jw321', $this->render($declaratie, 'declaratie', 'jw')[0]);

	}//end testRenderedEnvelopes()

	/**
	 * Every refusal, in check order.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$refusal = function (array $caseObject, string $kind, string $domain): string {
			try {
				(new OutboundMessageTranslator())->translate($caseObject, $kind, $domain);
			} catch (IwmoIjwTranslationException $exception) {
				return $exception->getMessage();
			}

			return 'none';
		};

		$this->assertSame('Unsupported bericht kind "melding".', $refusal([], 'melding', 'nope'));
		$this->assertSame('Unsupported domain "nope" — expected "wmo" or "jw".', $refusal([], 'toewijzing', 'nope'));
		$this->assertSame(
			'Required field "bsn" is missing or empty for a "toewijzing" bericht — refusing to build an envelope with unresolved data.',
			$refusal(['bsn' => ' '], 'toewijzing', 'wmo')
		);
		$this->assertSame(
			'Rendered envelope still contains an unresolved template marker — refusing to send.',
			$refusal(['toewijzingReferentie' => '{{x}}', 'productcode' => 'P', 'factuurnummer' => 'F', 'bedrag' => 1, 'periodeStart' => 'a', 'periodeEind' => 'b', 'aanbiederAgbCode' => 'A', 'gemeentecode' => 'G'], 'declaratie', 'jw')
		);

	}//end testRefusals()
}//end class
