<?php

/**
 * Integriq StUF-ZDS Document Delivery.
 *
 * Puts one document into a StUF-ZDS case system the way ZDS 1.2 prescribes:
 * first `genereerDocumentIdentificatie_Di02` (the case system hands out the
 * identificatie, read from its Du02 answer), then `voegZaakdocumentToe_Lk01`
 * with that identificatie, the metadata and the file inline, related to the
 * case. The identificatie is what the caller writes back.
 *
 * Both messages go to the source's `configuration.baseUrl`, unless the source
 * names the ZDS services apart: `vrijeBerichtenUrl` (Di02) and
 * `ontvangAsynchroonUrl` (Lk01). A source in `log` mode (the StUF-ZKN
 * bridge's sandbox default) is refused: the log provider sends nothing, and a
 * made-up identificatie written back would claim a document that does not
 * exist.
 *
 * @category Service
 * @package  OCA\Integriq\Service\StufZkn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

use OCA\Integriq\Exception\StufZknProviderException;
use OCA\Integriq\Exception\StufZknTranslationException;

/**
 * genereerDocumentIdentificatie + voegZaakdocumentToe over one StUF-ZKN source.
 *
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
 */
class StufZdsDocumentDelivery {

	/**
	 * The ZDS 1.2 SOAPAction of the identificatie request.
	 *
	 * @var string
	 */
	public const ACTION_IDENTIFICATION = '"' . StufZknNamespaces::ZKN . '/genereerDocumentIdentificatie_Di02"';

	/**
	 * The ZDS 1.2 SOAPAction of the document kennisgeving.
	 *
	 * @var string
	 */
	public const ACTION_ADD_DOCUMENT = '"' . StufZknNamespaces::ZKN . '/voegZaakdocumentToe_Lk01"';

	/**
	 * The sending application this bridge names in `stuurgegevens.zender` unless the source names one.
	 *
	 * @var string
	 */
	private const APPLICATION = 'integriq';

	/**
	 * Constructor.
	 *
	 * @param StufZknClient              $client     The REST/mTLS StUF client.
	 * @param OutboundDocumentTranslator $translator Builds and reads the ZDS messages.
	 */
	public function __construct(
		private readonly StufZknClient $client,
		private readonly OutboundDocumentTranslator $translator,
	) {

	}//end __construct()

	/**
	 * Deliver one document to the case system named by a StUF-ZKN source.
	 *
	 * @param array                                                 $source            The StUF-ZKN source object, read raw (its token intact).
	 * @param array                                                 $document          The mapped delivery (ZGW field names).
	 * @param array{content:string,filename:string,mimeType:string} $file              The file.
	 * @param string                                                $zaakIdentificatie The case.
	 * @param string                                                $documenttype      The document type description (`dct.omschrijving`).
	 *
	 * @return array{identificatie:string,referentienummer:string,zaakIdentificatie:string}
	 *
	 * @throws StufZknProviderException    When the source cannot deliver or the case system refuses.
	 * @throws StufZknTranslationException When a required value is missing or the Du02 has no identificatie.
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
	 */
	public function deliver(array $source, array $document, array $file, string $zaakIdentificatie, string $documenttype): array {
		$configuration = $this->restConfiguration(source: $source);
		$zender        = $this->zender(configuration: $configuration);
		$ontvanger     = $this->ontvanger(source: $source);
		$this->assertOrganisaties(zender: $zender, ontvanger: $ontvanger);

		$delivery = new CaseDocumentDelivery(
			document: $document,
			zaakIdentificatie: $zaakIdentificatie,
			documenttype: $documenttype,
			file: $file,
			zender: $zender,
			ontvanger: $ontvanger
		);

		// Refuse an incomplete document before the case system hands out an identificatie for it.
		$this->translator->documentMessage(delivery: $delivery, documentId: 'probe');

		$identificatie = $this->requestIdentificatie(configuration: $configuration, delivery: $delivery);
		$message       = $this->translator->documentMessage(delivery: $delivery, documentId: $identificatie);
		$this->sendDocument(configuration: $configuration, message: $message);

		return [
			'identificatie' => $identificatie,
			'referentienummer' => $message['referentienummer'],
			'zaakIdentificatie' => $zaakIdentificatie,
		];
	}//end deliver()

	/**
	 * The source's configuration, refused unless it is in rest mode.
	 *
	 * @param array $source The StUF-ZKN source object.
	 *
	 * @return array The configuration.
	 *
	 * @throws StufZknProviderException When the source is in log mode.
	 */
	private function restConfiguration(array $source): array {
		$configuration = (array)($source['configuration'] ?? []);
		if (($configuration['provider'] ?? 'log') !== 'rest') {
			throw new StufZknProviderException(
				message: 'The StUF-ZKN source is not in rest mode (configuration.provider); the log provider sends nothing, so no document was delivered.'
			);
		}

		return $configuration;
	}//end restConfiguration()

	/**
	 * The zender: this app, as the configuration names it.
	 *
	 * @param array $configuration The source's configuration.
	 *
	 * @return array{organisatie: string, applicatie: string} The zender.
	 */
	private function zender(array $configuration): array {
		return [
			'organisatie' => trim((string)($configuration['organisatie'] ?? '')),
			'applicatie' => trim((string)($configuration['applicatie'] ?? self::APPLICATION)),
		];
	}//end zender()

	/**
	 * The ontvanger: the source's own fields first, then its configuration's.
	 *
	 * @param array $source The StUF-ZKN source object.
	 *
	 * @return array{organisatie: string, applicatie: string} The ontvanger.
	 */
	private function ontvanger(array $source): array {
		$configuration = (array)($source['configuration'] ?? []);
		return [
			'organisatie' => trim((string)($source['ontvangerOrganisatie'] ?? ($configuration['ontvangerOrganisatie'] ?? ''))),
			'applicatie' => trim((string)($source['ontvangerApplicatie'] ?? ($configuration['ontvangerApplicatie'] ?? ''))),
		];
	}//end ontvanger()

	/**
	 * Refuse a routing without both organisaties.
	 *
	 * @param array $zender    The zender.
	 * @param array $ontvanger The ontvanger.
	 *
	 * @return void
	 *
	 * @throws StufZknProviderException When either organisatie is empty.
	 */
	private function assertOrganisaties(array $zender, array $ontvanger): void {
		if ($zender['organisatie'] === '' || $ontvanger['organisatie'] === '') {
			throw new StufZknProviderException(
				message: 'The StUF-ZKN source names no organisatie (configuration.organisatie) or no ontvangerOrganisatie; a StUF message needs both.'
			);
		}
	}//end assertOrganisaties()

	/**
	 * Ask the case system for a document identificatie (Di02 → Du02).
	 *
	 * @param array                $configuration The source's configuration.
	 * @param CaseDocumentDelivery $delivery      The delivery, for its zender and ontvanger.
	 *
	 * @return string The identificatie.
	 *
	 * @throws StufZknProviderException    When the case system refuses.
	 * @throws StufZknTranslationException When the Du02 has no identificatie.
	 */
	private function requestIdentificatie(array $configuration, CaseDocumentDelivery $delivery): string {
		$request = $this->translator->identificationRequest(zender: $delivery->zender, ontvanger: $delivery->ontvanger);
		$answer  = $this->client->exchange(
			sourceConfiguration: $configuration,
			soapAction: self::ACTION_IDENTIFICATION,
			envelopeXml: $request['xml'],
			url: (string)($configuration['vrijeBerichtenUrl'] ?? '')
		);

		return $this->translator->identificationFromAnswer(xml: $answer);
	}//end requestIdentificatie()

	/**
	 * Send the voegZaakdocumentToe message.
	 *
	 * @param array                                    $configuration The source's configuration.
	 * @param array{xml: string, referentienummer: string} $message   The rendered message.
	 *
	 * @return void
	 *
	 * @throws StufZknProviderException When the case system refuses.
	 */
	private function sendDocument(array $configuration, array $message): void {
		$this->client->exchange(
			sourceConfiguration: $configuration,
			soapAction: self::ACTION_ADD_DOCUMENT,
			envelopeXml: $message['xml'],
			url: (string)($configuration['ontvangAsynchroonUrl'] ?? '')
		);
	}//end sendDocument()
}//end class
