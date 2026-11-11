<?php

/**
 * Golden tests for StufZdsDocumentDelivery::deliver.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\StufZkn;

use OCA\Integriq\Exception\StufZknProviderException;
use OCA\Integriq\Exception\StufZknTranslationException;
use OCA\Integriq\Service\StufZkn\CaseDocumentDelivery;
use OCA\Integriq\Service\StufZkn\OutboundDocumentTranslator;
use OCA\Integriq\Service\StufZkn\StufZdsDocumentDelivery;
use OCA\Integriq\Service\StufZkn\StufZknClient;
use PHPUnit\Framework\TestCase;

/**
 * Pins the order of every translator and client call, their arguments, the
 * result, and each refusal (log mode, missing organisaties, incomplete document).
 */
class StufZdsDocumentDeliveryGoldenTest extends TestCase {

	/**
	 * Calls, in order.
	 *
	 * @var array<int, array>
	 */
	private array $calls = [];

	/**
	 * A delivery over a recording client and translator.
	 *
	 * @param bool $probeRefuses Whether the probe documentMessage throws.
	 *
	 * @return StufZdsDocumentDelivery The delivery.
	 */
	private function delivery(bool $probeRefuses=false): StufZdsDocumentDelivery {
		$this->calls = [];
		$translator  = $this->createMock(OutboundDocumentTranslator::class);
		$translator->method('documentMessage')->willReturnCallback(
			function (CaseDocumentDelivery $delivery, string $documentId) use ($probeRefuses): array {
				$this->calls[] = [
					'documentMessage',
					$documentId,
					$delivery->document,
					$delivery->zaakIdentificatie,
					$delivery->documenttype,
					$delivery->file,
					$delivery->zender,
					$delivery->ontvanger,
				];
				if ($probeRefuses === true) {
					throw new StufZknTranslationException(message: 'incomplete');
				}

				return ['xml' => '<Lk01 id="'.$documentId.'"/>', 'referentienummer' => 'REF-'.$documentId];
			}
		);
		$translator->method('identificationRequest')->willReturnCallback(
			function (array $zender, array $ontvanger): array {
				$this->calls[] = ['identificationRequest', $zender, $ontvanger];
				return ['xml' => '<Di02/>'];
			}
		);
		$translator->method('identificationFromAnswer')->willReturnCallback(
			function (string $xml): string {
				$this->calls[] = ['identificationFromAnswer', $xml];
				return 'DOC-1';
			}
		);
		$client = $this->createMock(StufZknClient::class);
		$client->method('exchange')->willReturnCallback(
			function (array $sourceConfiguration, string $soapAction, string $envelopeXml, string $url=''): string {
				$this->calls[] = ['exchange', $sourceConfiguration, $soapAction, $envelopeXml, $url];
				return '<Du02/>';
			}
		);

		return new StufZdsDocumentDelivery($client, $translator);

	}//end delivery()

	/**
	 * A source in rest mode.
	 *
	 * @return array The source.
	 */
	private function source(): array {
		return [
			'ontvangerOrganisatie' => ' Gemeente ',
			'configuration' => [
				'provider' => 'rest',
				'organisatie' => ' Org ',
				'ontvangerApplicatie' => 'ZRC',
				'vrijeBerichtenUrl' => 'https://zs.example/vrij',
				'ontvangAsynchroonUrl' => 'https://zs.example/async',
			],
		];

	}//end source()

	/**
	 * The delivery probes, asks an identificatie, sends the document and answers.
	 *
	 * @return void
	 */
	public function testDeliveryOrderAndResult(): void {
		$source   = $this->source();
		$document = ['titel' => 'Brief'];
		$file     = ['content' => 'x', 'filename' => 'a.pdf', 'mimeType' => 'application/pdf'];
		$result   = $this->delivery()->deliver($source, $document, $file, 'ZAAK-1', 'Brief');

		$zender    = ['organisatie' => 'Org', 'applicatie' => 'integriq'];
		$ontvanger = ['organisatie' => 'Gemeente', 'applicatie' => 'ZRC'];
		$config    = $source['configuration'];
		$this->assertSame(['identificatie' => 'DOC-1', 'referentienummer' => 'REF-DOC-1', 'zaakIdentificatie' => 'ZAAK-1'], $result);
		$this->assertSame(
			[
				['documentMessage', 'probe', $document, 'ZAAK-1', 'Brief', $file, $zender, $ontvanger],
				['identificationRequest', $zender, $ontvanger],
				['exchange', $config, StufZdsDocumentDelivery::ACTION_IDENTIFICATION, '<Di02/>', 'https://zs.example/vrij'],
				['identificationFromAnswer', '<Du02/>'],
				['documentMessage', 'DOC-1', $document, 'ZAAK-1', 'Brief', $file, $zender, $ontvanger],
				['exchange', $config, StufZdsDocumentDelivery::ACTION_ADD_DOCUMENT, '<Lk01 id="DOC-1"/>', 'https://zs.example/async'],
			],
			$this->calls
		);

	}//end testDeliveryOrderAndResult()

	/**
	 * The source's own ontvanger fields win over the configuration's; missing URLs are ''.
	 *
	 * @return void
	 */
	public function testRoutingFallbacks(): void {
		$source = [
			'ontvangerApplicatie' => ' DMS ',
			'configuration' => ['provider' => 'rest', 'organisatie' => 'Org', 'applicatie' => ' App ', 'ontvangerOrganisatie' => 'Cfg', 'ontvangerApplicatie' => 'Ignored'],
		];
		$this->delivery()->deliver($source, [], [], 'Z', 'T');

		$this->assertSame(['organisatie' => 'Org', 'applicatie' => 'App'], $this->calls[1][1]);
		$this->assertSame(['organisatie' => 'Cfg', 'applicatie' => 'DMS'], $this->calls[1][2]);
		$this->assertSame('', $this->calls[2][4]);
		$this->assertSame('', $this->calls[5][4]);

	}//end testRoutingFallbacks()

	/**
	 * Every refusal, and that nothing was sent.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$logMode = 'The StUF-ZKN source is not in rest mode (configuration.provider); the log provider sends nothing, so no document was delivered.';
		$noOrg   = 'The StUF-ZKN source names no organisatie (configuration.organisatie) or no ontvangerOrganisatie; a StUF message needs both.';
		$cases   = [
			[[], $logMode],
			[['configuration' => ['provider' => 'log', 'organisatie' => 'O', 'ontvangerOrganisatie' => 'G']], $logMode],
			[['configuration' => ['provider' => 'rest', 'organisatie' => ' ', 'ontvangerOrganisatie' => 'G']], $noOrg],
			[['configuration' => ['provider' => 'rest', 'organisatie' => 'O']], $noOrg],
		];
		foreach ($cases as [$source, $message]) {
			$delivery = $this->delivery();
			try {
				$delivery->deliver($source, [], [], 'Z', 'T');
				$this->fail('Not refused.');
			} catch (StufZknProviderException $exception) {
				$this->assertSame($message, $exception->getMessage());
			}

			$this->assertSame([], $this->calls);
		}

		$delivery = $this->delivery(true);
		try {
			$delivery->deliver($this->source(), [], [], 'Z', 'T');
			$this->fail('Not refused.');
		} catch (StufZknTranslationException $exception) {
			$this->assertSame('incomplete', $exception->getMessage());
		}

		$this->assertCount(1, $this->calls);

	}//end testRefusals()
}//end class
