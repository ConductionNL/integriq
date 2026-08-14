<?php

/**
 * Unit tests for InboundReturnTranslator.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\IwmoIjw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/iwmo-ijw-adapter/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\IwmoIjw;

use OCA\OpenConnector\Exception\IwmoIjwTranslationException;
use OCA\OpenConnector\Service\IwmoIjw\InboundReturnTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the retour envelope -> OR case status update translator, including the
 * kenmerk-must-resolve guard.
 *
 * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#requirement-inbound-retour-translation-to-an-or-case-status-update-req-003
 */
class InboundRetourTranslatorTest extends TestCase {

	/**
	 * @var InboundReturnTranslator
	 */
	private InboundReturnTranslator $translator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->translator = new InboundReturnTranslator();

	}//end setUp()

	/**
	 * Build a minimal retour envelope XML string.
	 *
	 * @param string $berichtcode The berichtcode (e.g. Wmo304).
	 * @param string $reference The correlation back-reference.
	 * @param string $bodyXml The `<body>` inner XML.
	 *
	 * @return string The rendered retour envelope.
	 */
	private function envelope(string $berichtcode, string $reference, string $bodyXml = ''): string {
		return '<Bericht><stuurgegevens><berichtcode>' . $berichtcode . '</berichtcode>'
			. '<kenmerk>' . $reference . '</kenmerk></stuurgegevens><body>' . $bodyXml . '</body></Bericht>';

	}//end envelope()

	/**
	 * A Wmo304 acceptance retour maps to status accepted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#scenario-a-wmo304-acceptance-retour-maps-to-status-accepted
	 */
	public function testWmo304AcceptanceMapsToAccepted(): void {
		$xml = $this->envelope('Wmo304', 'WMO-ref-1', '<resultaat>akkoord</resultaat>');

		$update = $this->translator->translate($xml);

		$this->assertSame('accepted', $update['status']);
		$this->assertSame('Wmo304', $update['berichttype']);
		$this->assertSame('WMO-ref-1', $update['reference']);

	}//end testWmo304AcceptanceMapsToAccepted()

	/**
	 * A Wmo304 with a non-akkoord resultaat maps to rejected.
	 *
	 * @return void
	 */
	public function testWmo304RejectionMapsToRejected(): void {
		$xml = $this->envelope('Wmo304', 'WMO-ref-1', '<resultaat>geweigerd</resultaat>');

		$update = $this->translator->translate($xml);

		$this->assertSame('rejected', $update['status']);

	}//end testWmo304RejectionMapsToRejected()

	/**
	 * A Wmo302 retour with no explicit resultaat defaults to rejected — never a silent accept.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#scenario-a-wmo302-retour-with-no-explicit-resultaat-defaults-to-rejected
	 */
	public function testWmo302WithNoResultaatDefaultsToRejected(): void {
		$xml = $this->envelope('Wmo302', 'WMO-ref-2');

		$update = $this->translator->translate($xml);

		$this->assertSame('rejected', $update['status']);

	}//end testWmo302WithNoResultaatDefaultsToRejected()

	/**
	 * A Jw302 with an explicit akkoord resultaat maps to accepted (domain-agnostic mapping).
	 *
	 * @return void
	 */
	public function testJw302WithAkkoordMapsToAccepted(): void {
		$xml = $this->envelope('Jw302', 'JW-ref-1', '<resultaat>akkoord</resultaat>');

		$update = $this->translator->translate($xml);

		$this->assertSame('accepted', $update['status']);
		$this->assertSame('Jw302', $update['berichttype']);

	}//end testJw302WithAkkoordMapsToAccepted()

	/**
	 * Wmo305/Wmo307 map to care_started/care_stopped with their timestamps.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#scenario-wmo305wmo307-map-to-care_startedcare_stopped-with-their-timestamps
	 */
	public function testWmo305MapsToCareStartedWithTimestamp(): void {
		$xml = $this->envelope('Wmo305', 'WMO-ref-3', '<startdatumWerkelijk>2026-08-05</startdatumWerkelijk>');

		$update = $this->translator->translate($xml);

		$this->assertSame('care_started', $update['status']);
		$this->assertSame('2026-08-05', $update['careStartedAt']);
		$this->assertNull($update['careStoppedAt']);

	}//end testWmo305MapsToCareStartedWithTimestamp()

	/**
	 * Wmo307 maps to care_stopped with its timestamp.
	 *
	 * @return void
	 */
	public function testWmo307MapsToCareStoppedWithTimestamp(): void {
		$xml = $this->envelope('Wmo307', 'WMO-ref-4', '<einddatumWerkelijk>2026-09-01</einddatumWerkelijk>');

		$update = $this->translator->translate($xml);

		$this->assertSame('care_stopped', $update['status']);
		$this->assertSame('2026-09-01', $update['careStoppedAt']);

	}//end testWmo307MapsToCareStoppedWithTimestamp()

	/**
	 * Wmo306/Wmo308 map to their confirmation statuses.
	 *
	 * @return void
	 */
	public function testWmo306And308MapToConfirmationStatuses(): void {
		$start = $this->translator->translate($this->envelope('Wmo306', 'WMO-ref-5'));
		$stop = $this->translator->translate($this->envelope('Wmo308', 'WMO-ref-6'));

		$this->assertSame('care_start_confirmed', $start['status']);
		$this->assertSame('care_stop_confirmed', $stop['status']);

	}//end testWmo306And308MapToConfirmationStatuses()

	/**
	 * A Wmo322 declaratie retour maps betaalstatus to invoice_processed/invoice_rejected.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#scenario-a-wmo322-declaratie-retour-maps-betaalstatus-to-invoice_processedinvoice_rejected
	 */
	public function testWmo322ProcessedMapsPaymentReference(): void {
		$xml = $this->envelope(
			'Wmo322',
			'WMO-ref-7',
			'<betaalstatus>akkoord</betaalstatus><betalingReferentie>PAY-123</betalingReferentie>'
		);

		$update = $this->translator->translate($xml);

		$this->assertSame('invoice_processed', $update['status']);
		$this->assertSame('PAY-123', $update['paymentReference']);

	}//end testWmo322ProcessedMapsPaymentReference()

	/**
	 * A Wmo322 with a non-akkoord betaalstatus maps to invoice_rejected.
	 *
	 * @return void
	 */
	public function testWmo322RejectedMapsToInvoiceRejected(): void {
		$xml = $this->envelope('Wmo322', 'WMO-ref-8', '<betaalstatus>afgewezen</betaalstatus>');

		$update = $this->translator->translate($xml);

		$this->assertSame('invoice_rejected', $update['status']);

	}//end testWmo322RejectedMapsToInvoiceRejected()

	/**
	 * A retour with an empty kenmerk is rejected before any status update is returned.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#scenario-a-retour-with-no-kenmerk-is-rejected-before-any-or-write
	 */
	public function testEmptyKenmerkRaisesTranslationException(): void {
		$xml = $this->envelope('Wmo304', '', '<resultaat>akkoord</resultaat>');

		$this->expectException(IwmoIjwTranslationException::class);
		$this->translator->translate($xml);

	}//end testEmptyKenmerkRaisesTranslationException()

	/**
	 * A retour missing the kenmerk element entirely is rejected.
	 *
	 * @return void
	 */
	public function testMissingKenmerkElementRaisesTranslationException(): void {
		$xml = '<Bericht><stuurgegevens><berichtcode>Wmo304</berichtcode></stuurgegevens><body/></Bericht>';

		$this->expectException(IwmoIjwTranslationException::class);
		$this->translator->translate($xml);

	}//end testMissingKenmerkElementRaisesTranslationException()

	/**
	 * An unrecognised berichtcode is rejected.
	 *
	 * @return void
	 */
	public function testUnrecognisedBerichtcodeRaisesTranslationException(): void {
		$xml = $this->envelope('Wmo999', 'WMO-ref-9');

		$this->expectException(IwmoIjwTranslationException::class);
		$this->translator->translate($xml);

	}//end testUnrecognisedBerichtcodeRaisesTranslationException()

	/**
	 * Malformed XML is rejected as IwmoIjwTranslationException, not a raw parse error.
	 *
	 * @return void
	 */
	public function testMalformedXmlRaisesTranslationException(): void {
		$this->expectException(IwmoIjwTranslationException::class);
		$this->translator->translate('<Bericht><stuurgegevens>');

	}//end testMalformedXmlRaisesTranslationException()

	/**
	 * An empty string is rejected.
	 *
	 * @return void
	 */
	public function testEmptyXmlRaisesTranslationException(): void {
		$this->expectException(IwmoIjwTranslationException::class);
		$this->translator->translate('');

	}//end testEmptyXmlRaisesTranslationException()

	/**
	 * A DOCTYPE declaration (potential XXE vector) does not cause entity expansion —
	 * the parsed kenmerk stays the literal, un-expanded text.
	 *
	 * @return void
	 */
	public function testDoctypeEntityIsNotExpanded(): void {
		$xml = '<?xml version="1.0"?>'
			. '<!DOCTYPE Bericht [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
			. '<Bericht><stuurgegevens><berichtcode>Wmo304</berichtcode>'
			. '<kenmerk>&xxe;WMO-ref-10</kenmerk></stuurgegevens>'
			. '<body><resultaat>akkoord</resultaat></body></Bericht>';

		$update = $this->translator->translate($xml);

		// The external entity MUST NOT have been resolved to file contents —
		// the kenmerk should not contain typical /etc/passwd content.
		$this->assertStringNotContainsString('root:', $update['reference']);

	}//end testDoctypeEntityIsNotExpanded()
}//end class
