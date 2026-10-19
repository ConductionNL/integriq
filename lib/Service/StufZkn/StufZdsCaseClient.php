<?php

/**
 * Integriq StUF-ZDS Case Client.
 *
 * The ZDS client for cases: creeerZaak, actualiseerZaak, geefZaakdetails,
 * genereerZaakIdentificatie and vrijBericht against the case system a
 * StUF-ZKN source names. Each request is checked against the source's circuit
 * breaker and built here, then handed to {@see StufZdsOutbound}, which audits,
 * sends, classifies, retries and announces how a kennisgeving ended.
 *
 * Ported from dossiq's StufAdapterService (decision 193): integriq is the
 * standards adapter, dossiq keeps which case type maps to which zaaktype.
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
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-operations
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

use OCA\Integriq\Exception\StufZknProviderException;
use OCA\Integriq\Exception\StufZknTranslationException;

/**
 * Builds StUF-ZDS case messages and hands them to the outbound side.
 *
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-operations
 */
class StufZdsCaseClient {

	/**
	 * Constructor.
	 *
	 * @param StufZdsMessageBuilder     $builder     Builds the envelopes.
	 * @param StufZdsOutbound           $outbound    Sends, classifies, retries, audits.
	 * @param StufZdsResponseReader     $reader      Reads a zakLa01.
	 * @param StufZdsCircuitBreaker     $breaker     The per-source circuit breaker.
	 * @param StufZdsCredentialResolver $credentials Decrypts the UsernameToken.
	 * @param StufZdsMessageLog         $log         Reads the source.
	 */
	public function __construct(
		private readonly StufZdsMessageBuilder $builder,
		private readonly StufZdsOutbound $outbound,
		private readonly StufZdsResponseReader $reader,
		private readonly StufZdsCircuitBreaker $breaker,
		private readonly StufZdsCredentialResolver $credentials,
		private readonly StufZdsMessageLog $log,
	) {

	}//end __construct()

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
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-operations
	 */
	public function creeerZaak(string $sourceId, array $case, string $zaaktypeOmschrijving, array $context=[]): array {
		$source = $this->restSource(sourceId: $sourceId, context: $context);
		if ($this->breaker->allows(sourceId: $source['id']) === false) {
			return $this->circuitOpen();
		}

		$caseId = null;
		if (($source['configuration']['caseIdentificationStrategy'] ?? '') === 'vooraf') {
			$allocated = $this->genereerZaakIdentificatie(sourceId: $sourceId, context: $context);
			if ($allocated['success'] === false) {
				return $allocated;
			}

			$caseId = $allocated['caseIdentification'];
		}

		$message = $this->builder->caseCreation(
			case: $case,
			zaaktypeOmschrijving: $zaaktypeOmschrijving,
			routing: $this->routing(source: $source),
			caseIdentification: $caseId,
			opts: $context
		);

		$result = $this->outbound->kennisgeving(source: $source, operation: 'creeerZaak', message: $message, context: $context);
		if ($result['caseIdentification'] === null && $result['success'] === true) {
			$result['caseIdentification'] = $caseId;
		}

		return $result;
	}//end creeerZaak()

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
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-operations
	 */
	public function actualiseerZaak(string $sourceId, string $caseIdentification, array $changes, array $context=[]): array {
		if (trim($caseIdentification) === '') {
			throw new StufZknTranslationException(message: 'actualiseerZaak needs the zaak identificatie the case system knows the case by.');
		}

		$source = $this->restSource(sourceId: $sourceId, context: $context);
		if ($this->breaker->allows(sourceId: $source['id']) === false) {
			return $this->circuitOpen();
		}

		$message = $this->builder->caseUpdate(caseIdentification: $caseIdentification, changes: $changes, routing: $this->routing(source: $source));
		$context['caseIdentification'] = $caseIdentification;

		return $this->outbound->kennisgeving(source: $source, operation: 'actualiseerZaak', message: $message, context: $context);
	}//end actualiseerZaak()

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
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-operations
	 */
	public function vrijBericht(string $sourceId, string $name, array $payload, array $context=[]): array {
		$source   = $this->restSource(sourceId: $sourceId, context: $context);
		$template = null;
		foreach ((array)($source['configuration']['freeMessageTemplates'] ?? []) as $candidate) {
			if (is_array($candidate) === true && ($candidate['name'] ?? '') === $name) {
				$template = $candidate;
				break;
			}
		}

		if ($template === null) {
			throw new StufZknTranslationException(message: sprintf('vrijBericht "%s" is not registered on source "%s".', $name, $source['id']));
		}

		if ($this->breaker->allows(sourceId: $source['id']) === false) {
			return $this->circuitOpen();
		}

		$message = $this->builder->freeMessage(template: $template, payload: $payload, routing: $this->routing(source: $source));
		$context['caseIdentification'] = (string)($payload['caseIdentification'] ?? ($context['caseIdentification'] ?? ''));

		return $this->outbound->kennisgeving(source: $source, operation: 'vrijBericht', message: $message, context: $context);
	}//end vrijBericht()

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
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-operations
	 */
	public function geefZaakdetails(string $sourceId, string $caseIdentification, array $wantedElements=[], array $context=[]): array {
		$source = $this->restSource(sourceId: $sourceId, context: $context);
		if ($this->breaker->allows(sourceId: $source['id']) === false) {
			return ($this->circuitOpen() + ['case' => null]);
		}

		$message = $this->builder->caseDetailsQuery(
			caseIdentification: $caseIdentification,
			routing: $this->routing(source: $source),
			wantedElements: $wantedElements
		);
		$context['caseIdentification'] = $caseIdentification;
		$result = $this->outbound->question(source: $source, operation: 'geefZaakdetails', message: $message, context: $context);

		$result['case'] = null;
		if ($result['success'] === true) {
			$result['case'] = $this->reader->caseDetails(xml: $result['answer']);
		}

		unset($result['answer']);
		return $result;
	}//end geefZaakdetails()

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
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-operations
	 */
	public function genereerZaakIdentificatie(string $sourceId, array $context=[]): array {
		$source = $this->restSource(sourceId: $sourceId, context: $context);
		if ($this->breaker->allows(sourceId: $source['id']) === false) {
			return $this->circuitOpen();
		}

		$message = $this->builder->caseIdentificationRequest(routing: $this->routing(source: $source));
		$result  = $this->outbound->question(source: $source, operation: 'genereerZaakIdentificatie', message: $message, context: $context);
		unset($result['answer']);

		if ($result['success'] === true && $result['caseIdentification'] === null) {
			$result['success'] = false;
			$result['fout']    = [
				'code' => 'NO_IDENTIFICATION',
				'omschrijving' => 'The Du02 answer names no zaak identificatie',
				'details' => '',
				'kind' => 'permanent'
			];
		}

		return $result;
	}//end genereerZaakIdentificatie()

	/**
	 * Read the source and refuse one that would send nothing, or that belongs to another app.
	 *
	 * A source that names its app in `configuration.owningApp` takes case commands from that
	 * app only, so with two case apps installed one cannot send for the other's source.
	 *
	 * @param string $sourceId The source id.
	 * @param array  $context  The command context, read for `sourceApp`.
	 *
	 * @return array The source, `id` set.
	 *
	 * @throws StufZknProviderException When the source is missing, in log mode, or owned by another app.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
	 */
	private function restSource(string $sourceId, array $context=[]): array {
		$source = $this->log->source(sourceId: $sourceId);
		if (($source['configuration']['provider'] ?? 'log') !== 'rest') {
			throw new StufZknProviderException(
				message: 'The StUF-ZKN source is not in rest mode (configuration.provider); the log provider sends nothing, so no case message was sent.'
			);
		}

		$owningApp = trim((string)($source['configuration']['owningApp'] ?? ''));
		if ($owningApp !== '' && (string)($context['sourceApp'] ?? '') !== $owningApp) {
			throw new StufZknProviderException(
				message: sprintf(
					'The StUF-ZKN source belongs to the app "%s" (configuration.owningApp); a case command from another app is not sent.',
					$owningApp
				)
			);
		}

		return $source;
	}//end restSource()

	/**
	 * The routing (zender, ontvanger) of a source; the UsernameToken is added at send time.
	 *
	 * @param array $source The source.
	 *
	 * @return array The routing.
	 *
	 * @throws StufZknProviderException When the source names no organisaties or its password cannot be read.
	 */
	private function routing(array $source): array {
		// The UsernameToken is NOT built into the envelope: the envelope is persisted for a retry, so
		// StufZdsOutbound adds the token at send time. Resolving it here only fails fast on a password
		// that is missing or cannot be decrypted, before any row is opened.
		$this->credentials->usernameToken(sourceConfiguration: (array)($source['configuration'] ?? []));
		$routing = $this->builder->routing(source: $source, wsse: null);
		if ($routing['zender']['organisatie'] === '' || $routing['ontvanger']['organisatie'] === '') {
			throw new StufZknProviderException(
				message: 'The StUF-ZKN source names no organisatie (configuration.organisatie) or no ontvangerOrganisatie; a StUF message needs both.'
			);
		}

		return $routing;
	}//end routing()

	/**
	 * The result of a request refused because the source's circuit is open.
	 *
	 * @return array{success:bool,retrying:bool,referentienummer:string,messageId:string,caseIdentification:string|null,fout:array<string,string>}
	 */
	private function circuitOpen(): array {
		return [
			'success' => false,
			'retrying' => false,
			'referentienummer' => '',
			'messageId' => '',
			'caseIdentification' => null,
			'fout' => [
				'code' => 'CIRCUIT_OPEN',
				'omschrijving' => 'The case system failed repeatedly; requests pause for five minutes',
				'details' => '',
				'kind' => 'transient',
			],
		];
	}//end circuitOpen()
}//end class
