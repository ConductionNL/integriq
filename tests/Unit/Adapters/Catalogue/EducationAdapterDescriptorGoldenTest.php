<?php

/**
 * Integriq — education adapter catalogue descriptor golden tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Catalogue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Catalogue;

use OCA\Integriq\Adapters\Digikoppeling\DigikoppelingAdapter;
use OCA\Integriq\Adapters\Oso\OsoAdapter;
use OCA\Integriq\Adapters\Rod\RodAdapter;
use OCA\Integriq\Adapters\UwlrEduV\UwlrEduVAdapter;
use OCA\Integriq\Adapters\Verzuimloket\VerzuimloketAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins every catalogue answer of the ROD, Verzuimloket, OSO, UWLR/Edu-V and
 * Digikoppeling descriptors, key order included.
 */
final class EducationAdapterDescriptorGoldenTest extends TestCase {

	/**
	 * Each descriptor with its golden description.
	 *
	 * @return array<string,array{0:object,1:string}>
	 */
	public static function descriptors(): array {
		return [
			'rod' => [
				new RodAdapter(),
				<<<'JSON'
				{"id":"rod","label":"DUO ROD","category":"government","addsTopLevelMenu":false,"addsManagementRoute":false,"providers":["log","edukoppeling"],"configSchema":{"type":"object","title":"DUO ROD","properties":{"provider":{"type":"string","enum":["log","edukoppeling"],"default":"log","title":"Provider binding","description":"`log` (default) simulates every send. `edukoppeling` dispatches over the live DUO ROD koppelvlak. It requires a DUO software-vendor certificate reference and is gated until that certificate is held (see decisions.md M3(c))."},"endpoint":{"type":"string","format":"uri","title":"Endpoint URL","description":"DUO ROD Edukoppeling endpoint URL. Required when provider=edukoppeling."},"certificateRef":{"type":"string","title":"PKIoverheid certificate reference","description":"Broker credentialRef for the DUO software-vendor certificate. Never stored here (ADR-007). Required when provider=edukoppeling."},"webhookSignature":{"type":"object","title":"Retour signature","description":"HMAC verification settings for the inbound DUO acknowledgement/retour.","properties":{"scheme":{"type":"string","default":"openconnector"},"secret":{"type":"string"},"header":{"type":"string","default":"X-OpenConnector-Signature"},"toleranceSeconds":{"type":"integer"}}}}}}
				JSON,
			],
			'verzuimloket' => [
				new VerzuimloketAdapter(),
				<<<'JSON'
				{"id":"verzuimloket","label":"DUO Verzuimloket","category":"government","addsTopLevelMenu":false,"addsManagementRoute":false,"providers":["log","edukoppeling"],"configSchema":{"type":"object","title":"DUO Verzuimloket","properties":{"provider":{"type":"string","enum":["log","edukoppeling"],"default":"log","title":"Provider binding","description":"`log` (default) simulates every send. `edukoppeling` dispatches over the live DUO Verzuimloket koppelvlak. It requires a DUO software-vendor certificate reference and is gated until that certificate is held (see decisions.md M3(c))."},"endpoint":{"type":"string","format":"uri","title":"Endpoint URL","description":"DUO Verzuimloket Edukoppeling endpoint URL. Required when provider=edukoppeling."},"certificateRef":{"type":"string","title":"PKIoverheid certificate reference","description":"Broker credentialRef for the DUO software-vendor certificate. Never stored here (ADR-007). Required when provider=edukoppeling."},"webhookSignature":{"type":"object","title":"Retour signature","description":"HMAC verification settings for the inbound DUO acknowledgement/retour.","properties":{"scheme":{"type":"string","default":"openconnector"},"secret":{"type":"string"},"header":{"type":"string","default":"X-OpenConnector-Signature"},"toleranceSeconds":{"type":"integer"}}}}}}
				JSON,
			],
			'oso' => [
				new OsoAdapter(),
				<<<'JSON'
				{"id":"oso","label":"OSO","category":"government","addsTopLevelMenu":false,"addsManagementRoute":false,"providers":["log","kennisnet"],"configSchema":{"type":"object","title":"OSO","properties":{"provider":{"type":"string","enum":["log","kennisnet"],"default":"log","title":"Provider binding","description":"`log` (default) simulates every export. `kennisnet` dispatches over the live OSO koppelvlak. It requires a certificate reference and Kennisnet OSO aansluiting approval (see decisions.md M3(c))."},"endpoint":{"type":"string","format":"uri","title":"Endpoint URL","description":"Kennisnet OSO export endpoint URL. Required when provider=kennisnet."},"certificateRef":{"type":"string","title":"PKIoverheid certificate reference","description":"Broker credentialRef for the certificate. Never stored here (ADR-007). Required when provider=kennisnet."},"webhookSignature":{"type":"object","title":"Inbound signature","description":"HMAC verification settings for the inbound import and export-retour endpoints.","properties":{"scheme":{"type":"string","default":"openconnector"},"secret":{"type":"string"},"header":{"type":"string","default":"X-OpenConnector-Signature"},"toleranceSeconds":{"type":"integer"}}}}}}
				JSON,
			],
			'uwlrEduV' => [
				new UwlrEduVAdapter(),
				<<<'JSON'
				{"id":"uwlr-eduv","label":"UWLR / Edu-V / Basispoort","category":"government","addsTopLevelMenu":false,"addsManagementRoute":false,"providers":["log","uwlr-eduv"],"configSchema":{"type":"object","title":"UWLR / Edu-V / Basispoort","properties":{"provider":{"type":"string","enum":["log","uwlr-eduv"],"default":"log","title":"Provider binding","description":"`log` (default) simulates every send. `uwlr-eduv` dispatches over the live koppelvlak. It requires a certificate reference and, per target, its own certification/aansluiting (see decisions.md M3(c))."},"endpoint":{"type":"string","format":"uri","title":"Endpoint URL","description":"UWLR/Edu-V/Basispoort/Entree-content koppelvlak endpoint URL. Required when provider=uwlr-eduv."},"certificateRef":{"type":"string","title":"PKIoverheid certificate reference","description":"Broker credentialRef for the certificate. Never stored here (ADR-007). Required when provider=uwlr-eduv."},"webhookSignature":{"type":"object","title":"Inbound signature","description":"HMAC verification settings for the shared inbound acknowledgement/retour endpoint.","properties":{"scheme":{"type":"string","default":"openconnector"},"secret":{"type":"string"},"header":{"type":"string","default":"X-OpenConnector-Signature"},"toleranceSeconds":{"type":"integer"}}}}},"targets":["uwlr","edu-v","basispoort","entree-content"]}
				JSON,
			],
			'digikoppeling' => [
				new DigikoppelingAdapter(),
				<<<'JSON'
				{"id":"digikoppeling","label":"Digikoppeling","category":"government","addsTopLevelMenu":false,"addsManagementRoute":false,"profiles":["wus","ebms2"],"configSchema":{"type":"object","title":"Digikoppeling","required":["profile","oin","endpoint","certificateRef"],"properties":{"profile":{"type":"string","enum":["wus","ebms2"],"title":"Transport profile","description":"WUS (synchronous bevragingen) or ebMS2 (asynchronous reliable meldingen)."},"oin":{"type":"string","title":"Partner OIN","description":"Partner organisation identifier (OIN)."},"service":{"type":"string","title":"Service","description":"Digikoppeling service name."},"action":{"type":"string","title":"Action","description":"Digikoppeling action name."},"endpoint":{"type":"string","format":"uri","title":"Endpoint URL","description":"Partner endpoint URL (two-way TLS)."},"certificateRef":{"type":"string","title":"PKIoverheid certificate reference","description":"Broker credentialRef for the PKIoverheid certificate + key. Never stored here (ADR-007)."},"encryption":{"type":"boolean","default":false,"title":"WS-Security encryption","description":"Encrypt the SOAP body for the partner certificate after signing, and decrypt encrypted answers with the organisation key."},"partnerCertificate":{"type":"string","title":"Partner certificate","description":"The partner's public X.509 certificate (PEM) the SOAP body is encrypted for. Required when encryption is on."},"reliableMessaging":{"type":"object","title":"Reliable messaging (ebMS2)","description":"ebMS2 reliable-messaging parameters.","properties":{"retryBudget":{"type":"integer","minimum":0,"title":"Retry budget","description":"Maximum retransmissions before the message is dead-lettered."},"retryIntervalSeconds":{"type":"integer","minimum":1,"title":"Retry interval (seconds)","description":"Seconds between retransmission attempts."}}},"groteBerichtenThresholdBytes":{"type":"integer","minimum":1,"title":"Grote Berichten threshold (bytes)","description":"Payloads larger than this are transferred out-of-band by reference (URL + checksum)."}}}}
				JSON,
			],
		];

	}//end descriptors()

	/**
	 * The descriptor answers exactly what it answered before.
	 *
	 * @param object $adapter The descriptor.
	 * @param string $golden  The golden description as JSON.
	 *
	 * @return void
	 */
	#[DataProvider('descriptors')]
	public function testDescriptionIsUnchanged(object $adapter, string $golden): void {
		$description = [
			'id' => $adapter->id(),
			'label' => $adapter->label(),
			'category' => $adapter->category(),
			'addsTopLevelMenu' => $adapter->addsTopLevelMenu(),
			'addsManagementRoute' => $adapter->addsManagementRoute(),
		];
		foreach (['providers', 'profiles', 'configSchema', 'targets'] as $answer) {
			if (method_exists($adapter, $answer) === true) {
				$description[$answer] = $adapter->$answer();
			}
		}

		$this->assertSame(json_decode($golden, true, 512, JSON_THROW_ON_ERROR), $description);

	}//end testDescriptionIsUnchanged()
}//end class
