<?php

/**
 * Contract of the StufZdsCaseClient.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\StufZkn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

use OCA\Integriq\Exception\StufZknProviderException;
use OCA\Integriq\Exception\StufZknTranslationException;

/**
 * Public operations of {@see StufZdsCaseClient} that other components call.
 */
interface StufZdsCaseClientInterface {
	/**
	 * Create a zaak (zakLk01). With `caseIdentificationStrategy: vooraf` on the source, an identificatie is asked first.
	 *
	 * @param string $sourceId             The StUF-ZKN source.
	 * @param array  $case                 omschrijving, startdatum, betrokkenen[], documenten[].
	 * @param string $zaaktypeOmschrijving The zaaktype the calling app maps the case type to.
	 * @param array  $context              correlationId, sourceApp, includeDocuments, payloadLimitBytes.
	 *
	 * @return array{success:bool,retrying:bool,referentienummer:string,messageId:string,caseIdentification:string|null,fout:array<string,string>|null}
	 *
	 * @throws StufZknProviderException    When the source cannot send.
	 * @throws StufZknTranslationException When the case cannot be expressed.
	 *
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-case-operations
	 */
	public function creeerZaak(string $sourceId, array $case, string $zaaktypeOmschrijving, array $context=[]): array;

	/**
	 * Update a zaak (zakLk02) with the fields that changed.
	 *
	 * @param string $sourceId           The StUF-ZKN source.
	 * @param string $caseIdentification The zaak identificatie in the case system.
	 * @param array  $changes            omschrijving, einddatum, resultaattoelichting.
	 * @param array  $context            correlationId, sourceApp.
	 *
	 * @return array{success:bool,retrying:bool,referentienummer:string,messageId:string,caseIdentification:string|null,fout:array<string,string>|null}
	 *
	 * @throws StufZknProviderException    When the source cannot send.
	 * @throws StufZknTranslationException When no zaak identificatie is given.
	 *
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-case-operations
	 */
	public function actualiseerZaak(string $sourceId, string $caseIdentification, array $changes, array $context=[]): array;

	/**
	 * Send a vrij bericht from a template the source registers under `configuration.freeMessageTemplates`.
	 *
	 * @param string $sourceId The StUF-ZKN source.
	 * @param string $name     The template name.
	 * @param array  $payload  The values.
	 * @param array  $context  correlationId, sourceApp, caseIdentification.
	 *
	 * @return array{success:bool,retrying:bool,referentienummer:string,messageId:string,caseIdentification:string|null,fout:array<string,string>|null}
	 *
	 * @throws StufZknProviderException    When the source cannot send.
	 * @throws StufZknTranslationException When the template is not registered or a required field is missing.
	 *
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-case-operations
	 */
	public function vrijBericht(string $sourceId, string $name, array $payload, array $context=[]): array;

	/**
	 * Ask the case system for the details of a zaak (zakLv01, answered synchronously).
	 *
	 * @param string   $sourceId           The StUF-ZKN source.
	 * @param string   $caseIdentification The zaak identificatie.
	 * @param string[] $wantedElements     The zkn elements to ask for.
	 * @param array    $context            correlationId, sourceApp.
	 *
	 * @return array{success:bool,retrying:bool,referentienummer:string,messageId:string,caseIdentification:string|null,fout:array<string,string>|null,case:array<string,mixed>|null}
	 *
	 * @throws StufZknProviderException When the source cannot send.
	 *
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-case-operations
	 */
	public function geefZaakdetails(string $sourceId, string $caseIdentification, array $wantedElements=[], array $context=[]): array;

	/**
	 * Ask the case system for a new zaak identificatie (genereerZaakIdentificatie_Di02 → Du02).
	 *
	 * @param string $sourceId The StUF-ZKN source.
	 * @param array  $context  correlationId, sourceApp.
	 *
	 * @return array{success:bool,retrying:bool,referentienummer:string,messageId:string,caseIdentification:string|null,fout:array<string,string>|null}
	 *
	 * @throws StufZknProviderException When the source cannot send.
	 *
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-case-operations
	 */
	public function genereerZaakIdentificatie(string $sourceId, array $context=[]): array;
}
