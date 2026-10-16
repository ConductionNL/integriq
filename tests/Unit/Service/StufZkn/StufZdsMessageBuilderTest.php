<?php

/**
 * Unit tests for StufZdsMessageBuilder.
 *
 * Ported from dossiq's StufMessageBuilderTest and StufMessageBuilderOutboundTest.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\StufZkn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\StufZkn;

use DOMDocument;
use DOMXPath;
use OCA\Integriq\Exception\StufZknTranslationException;
use OCA\Integriq\Service\StufZkn\StufZdsMessageBuilder;
use OCA\Integriq\Service\StufZkn\StufZknNamespaces;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Service\StufZkn\StufZdsMessageBuilder
 */
class StufZdsMessageBuilderTest extends TestCase {

	/**
	 * The builder.
	 *
	 * @var StufZdsMessageBuilder
	 */
	private StufZdsMessageBuilder $builder;

	/**
	 * A routing with every field set.
	 *
	 * @var array
	 */
	private array $routing;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->builder = new StufZdsMessageBuilder();
		$this->routing = $this->builder->routing(
			source: [
				'configuration' => [
					'organisatie' => '0363',
					'applicatie' => 'dossiq',
					'ontvangerOrganisatie' => '0363',
					'ontvangerApplicatie' => 'ZSH',
					'ontvangerGebruiker' => 'zds',
				],
			],
			wsse: ['username' => 'stuf-user', 'password' => 's3cr<t'],
		);
	}//end setUp()

	/**
	 * Parse an envelope, failing the test when it is not well-formed.
	 *
	 * @param string $xml The envelope.
	 *
	 * @return DOMXPath An xpath over it with soapenv/stuf/zkn/bg/wsse bound.
	 */
	private function xpath(string $xml): DOMXPath {
		$document = new DOMDocument();
		$this->assertTrue($document->loadXML($xml), 'The envelope is well-formed XML');
		$xpath = new DOMXPath($document);
		$xpath->registerNamespace('soapenv', StufZknNamespaces::SOAP);
		$xpath->registerNamespace('stuf', StufZknNamespaces::STUF);
		$xpath->registerNamespace('zkn', StufZknNamespaces::ZKN);
		$xpath->registerNamespace('bg', StufZdsMessageBuilder::NS_BG);
		$xpath->registerNamespace('wsse', StufZdsMessageBuilder::NS_WSSE);
		return $xpath;
	}//end xpath()

	/**
	 * A creeerZaak carries the zaaktype the caller mapped, the zaak entity and the routing quartet.
	 *
	 * @return void
	 */
	public function testCaseCreationCarriesTheMappedZaaktypeAndRouting(): void {
		$message = $this->builder->caseCreation(
			case: ['omschrijving' => 'Bezwaar & beroep', 'startdatum' => '20261011', 'betrokkenen' => [['bsn' => '999993653', 'role' => 'heeftAlsInitiator']]],
			zaaktypeOmschrijving: 'Bezwaarschrift behandelen',
			routing: $this->routing,
		);

		$xpath = $this->xpath($message['xml']);
		$this->assertSame('Bezwaarschrift behandelen', $xpath->evaluate('string(//zkn:zakLk01/zkn:object/zkn:zaaktype/zkn:omschrijving)'));
		$this->assertSame('Bezwaar & beroep', $xpath->evaluate('string(//zkn:zakLk01/zkn:object/zkn:omschrijving)'));
		$this->assertSame('T', $xpath->evaluate('string(//zkn:object/@stuf:verwerkingssoort)'));
		$this->assertSame('999993653', $xpath->evaluate('string(//zkn:heeftAlsInitiator/zkn:gerelateerde/bg:inp.bsn)'));
		$this->assertSame('Lk01', $xpath->evaluate('string(//stuf:berichtcode)'));
		$this->assertSame('creeerZaak', $xpath->evaluate('string(//stuf:functie)'));
		$this->assertSame('dossiq', $xpath->evaluate('string(//stuf:zender/stuf:applicatie)'));
		$this->assertSame('ZSH', $xpath->evaluate('string(//stuf:ontvanger/stuf:applicatie)'));
		$this->assertSame('zds', $xpath->evaluate('string(//stuf:ontvanger/stuf:gebruiker)'));
		$this->assertSame($message['referentienummer'], $xpath->evaluate('string(//stuf:referentienummer)'));
		$this->assertSame(0.0, $xpath->evaluate('count(//zkn:object/zkn:identificatie)'));
	}//end testCaseCreationCarriesTheMappedZaaktypeAndRouting()

	/**
	 * A pre-allocated identificatie goes into the object.
	 *
	 * @return void
	 */
	public function testCaseCreationCarriesAPreAllocatedIdentificatie(): void {
		$message = $this->builder->caseCreation(case: [], zaaktypeOmschrijving: 'Melding', routing: $this->routing, caseIdentification: 'ZAAK-2026-1');
		$this->assertSame('ZAAK-2026-1', $this->xpath($message['xml'])->evaluate('string(//zkn:object/zkn:identificatie)'));
	}//end testCaseCreationCarriesAPreAllocatedIdentificatie()

	/**
	 * A case without a zaaktype is refused before anything is built.
	 *
	 * @return void
	 */
	public function testCaseWithoutZaaktypeIsRefused(): void {
		$this->expectException(StufZknTranslationException::class);
		$this->builder->caseCreation(case: ['omschrijving' => 'x'], zaaktypeOmschrijving: ' ', routing: $this->routing);
	}//end testCaseWithoutZaaktypeIsRefused()

	/**
	 * Documents are left out unless asked for, and base64-encoded when they are.
	 *
	 * @return void
	 */
	public function testDocumentsAreOmittedUnlessRequestedAndEncodedWhenRequested(): void {
		$case = ['documenten' => [['name' => 'brief.pdf', 'mime' => 'application/pdf', 'bytes' => 'PDF-BYTES']]];

		$without = $this->builder->caseCreation(case: $case, zaaktypeOmschrijving: 'Melding', routing: $this->routing);
		$this->assertSame(0.0, $this->xpath($without['xml'])->evaluate('count(//zkn:heeftRelevant)'));

		$with  = $this->builder->caseCreation(case: $case, zaaktypeOmschrijving: 'Melding', routing: $this->routing, opts: ['includeDocuments' => true]);
		$xpath = $this->xpath($with['xml']);
		$this->assertSame(base64_encode('PDF-BYTES'), $xpath->evaluate('string(//zkn:heeftRelevant//stuf:bestandsinhoud)'));
		$this->assertSame('brief.pdf', $xpath->evaluate('string(//zkn:heeftRelevant//stuf:bestandsnaam)'));
	}//end testDocumentsAreOmittedUnlessRequestedAndEncodedWhenRequested()

	/**
	 * Documents over the ceiling are refused before anything is sent.
	 *
	 * @return void
	 */
	public function testOversizedDocumentsAreRefused(): void {
		$this->expectException(StufZknTranslationException::class);
		$this->builder->caseCreation(
			case: ['documenten' => [['bytes' => str_repeat('a', 11)]]],
			zaaktypeOmschrijving: 'Melding',
			routing: $this->routing,
			opts: ['includeDocuments' => true, 'payloadLimitBytes' => 10],
		);
	}//end testOversizedDocumentsAreRefused()

	/**
	 * An actualiseerZaak sends only the updatable fields that were given.
	 *
	 * @return void
	 */
	public function testCaseUpdateIsASparseLk02(): void {
		$message = $this->builder->caseUpdate(caseIdentification: 'ZAAK-1', changes: ['einddatum' => '20261231', 'omschrijving' => null, 'status' => 'x'], routing: $this->routing);
		$xpath   = $this->xpath($message['xml']);
		$this->assertSame('ZAAK-1', $xpath->evaluate('string(//zkn:zakLk02/zkn:object/zkn:identificatie)'));
		$this->assertSame('20261231', $xpath->evaluate('string(//zkn:zakLk02/zkn:object/zkn:einddatum)'));
		$this->assertSame(0.0, $xpath->evaluate('count(//zkn:zakLk02/zkn:object/zkn:omschrijving)'));
		$this->assertSame(0.0, $xpath->evaluate('count(//zkn:zakLk02/zkn:object/zkn:status)'));
		$this->assertSame('W', $xpath->evaluate('string(//zkn:object/@stuf:verwerkingssoort)'));
	}//end testCaseUpdateIsASparseLk02()

	/**
	 * A geefZaakdetails asks for the zaak and the scope elements.
	 *
	 * @return void
	 */
	public function testCaseDetailsQueryCarriesIdentificatieAndScope(): void {
		$message = $this->builder->caseDetailsQuery(caseIdentification: 'ZAAK-9', routing: $this->routing, wantedElements: ['omschrijving', 'heeftStatus']);
		$xpath   = $this->xpath($message['xml']);
		$this->assertSame('ZAAK-9', $xpath->evaluate('string(//zkn:zakLv01/zkn:gelijk/zkn:identificatie)'));
		$this->assertSame(1.0, $xpath->evaluate('count(//zkn:scope/zkn:object/zkn:heeftStatus)'));
		$this->assertSame('Lv01', $xpath->evaluate('string(//stuf:berichtcode)'));
	}//end testCaseDetailsQueryCarriesIdentificatieAndScope()

	/**
	 * A genereerZaakIdentificatie is the ZDS 1.2 Di02.
	 *
	 * @return void
	 */
	public function testCaseIdentificationRequestIsADi02(): void {
		$xpath = $this->xpath($this->builder->caseIdentificationRequest(routing: $this->routing)['xml']);
		$this->assertSame(1.0, $xpath->evaluate('count(//soapenv:Body/zkn:genereerZaakIdentificatie_Di02)'));
		$this->assertSame('Di02', $xpath->evaluate('string(//stuf:berichtcode)'));
		$this->assertSame('genereerZaakIdentificatie', $xpath->evaluate('string(//stuf:functie)'));
	}//end testCaseIdentificationRequestIsADi02()

	/**
	 * A vrij bericht is named by its template and carries the payload.
	 *
	 * @return void
	 */
	public function testFreeMessageIsNamedByItsTemplate(): void {
		$message = $this->builder->freeMessage(template: ['name' => 'meldBesluit', 'verplichteVelden' => ['besluit']], payload: ['besluit' => 'toegekend'], routing: $this->routing);
		$xpath   = $this->xpath($message['xml']);
		$this->assertSame('toegekend', $xpath->evaluate('string(//zkn:meldBesluit_Du01/zkn:parameters/zkn:besluit)'));
		$this->assertSame('meldBesluit', $xpath->evaluate('string(//stuf:functie)'));
	}//end testFreeMessageIsNamedByItsTemplate()

	/**
	 * A vrij bericht without a required field is refused.
	 *
	 * @return void
	 */
	public function testFreeMessageRequiresTheTemplatesFields(): void {
		$this->expectException(StufZknTranslationException::class);
		$this->builder->freeMessage(template: ['name' => 'meldBesluit', 'verplichteVelden' => ['besluit']], payload: [], routing: $this->routing);
	}//end testFreeMessageRequiresTheTemplatesFields()

	/**
	 * The UsernameToken is in the header, escaped; without a user there is no header.
	 *
	 * @return void
	 */
	public function testUsernameTokenHeaderOnlyWhenTheSourceNamesAUser(): void {
		$xpath = $this->xpath($this->builder->caseIdentificationRequest(routing: $this->routing)['xml']);
		$this->assertSame('stuf-user', $xpath->evaluate('string(//soapenv:Header/wsse:Security/wsse:UsernameToken/wsse:Username)'));
		$this->assertSame('s3cr<t', $xpath->evaluate('string(//wsse:Password)'));

		$bare = $this->routing;
		$bare['wsse'] = null;
		$this->assertSame(0.0, $this->xpath($this->builder->caseIdentificationRequest(routing: $bare)['xml'])->evaluate('count(//soapenv:Header)'));
	}//end testUsernameTokenHeaderOnlyWhenTheSourceNamesAUser()

	/**
	 * A token can be added at send time to an envelope built without one, and an envelope never gets two.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-credentials-stay-encrypted
	 */
	public function testUsernameTokenCanBeAddedAtSendTime(): void {
		$bare = $this->routing;
		$bare['wsse'] = null;
		$builder = $this->builder;
		$xml = $builder->caseIdentificationRequest(routing: $bare)['xml'];
		$this->assertStringNotContainsString('UsernameToken', $xml);

		$withToken = $builder->withUsernameToken(envelopeXml: $xml, wsse: ['username' => 'stuf-user', 'password' => 's3cr<t']);
		$xpath = $this->xpath($withToken);
		$this->assertSame('stuf-user', $xpath->evaluate('string(//soapenv:Header/wsse:Security/wsse:UsernameToken/wsse:Username)'));
		$this->assertSame('s3cr<t', $xpath->evaluate('string(//wsse:Password)'));
		$this->assertSame(1, (int)$xpath->evaluate('count(//soapenv:Body)'));

		$this->assertSame($xml, $builder->withUsernameToken(envelopeXml: $xml, wsse: null), 'No token, no change');
		$this->assertSame($withToken, $builder->withUsernameToken(envelopeXml: $withToken, wsse: ['username' => 'other', 'password' => 'x']), 'An envelope with a header is left alone');
	}//end testUsernameTokenCanBeAddedAtSendTime()

	/**
	 * A referentienummer is 26 Crockford-base32 characters and two are never the same.
	 *
	 * @return void
	 */
	public function testReferentienummerShapeAndUniqueness(): void {
		$seen = [];
		for ($i = 0; $i < 50; $i++) {
			$ref = $this->builder->generateReferentienummer();
			$this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $ref);
			$seen[$ref] = true;
		}

		$this->assertCount(50, $seen);
	}//end testReferentienummerShapeAndUniqueness()

	/**
	 * tijdstipBericht is seventeen digits.
	 *
	 * @return void
	 */
	public function testTimestampIsSeventeenDigits(): void {
		$this->assertMatchesRegularExpression('/^\d{17}$/', $this->builder->currentTimestamp());
	}//end testTimestampIsSeventeenDigits()
}//end class
