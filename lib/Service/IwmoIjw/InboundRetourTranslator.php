<?php

/**
 * OpenConnector iWMO/iJW Inbound Retour Translator.
 *
 * Translates a retour XML envelope (berichttype 302, 304, 305, 306, 307,
 * 308, or 322 — Wmo or Jw prefixed) into an OR case status update. See
 * design.md "Message-shape assumptions" / "Inbound retour field table" for
 * the full mapping and its grounding — NO live GGk/VECOZO connection was
 * available in this environment to verify the exact wire shape against.
 *
 * LITERAL-LEAK GUARD (inbound side): a retour with an empty or missing
 * `stuurgegevens.kenmerk` (the correlation back-reference to the original
 * outbound `referentienummer`) raises {@see IwmoIjwTranslationException}
 * BEFORE any status update is returned — the caller MUST NEVER guess or
 * fall back to an unrelated OR case (see design.md "Literal-leak guard").
 *
 * XXE hardening: the retour XML originates from an external party (a real
 * GGk/VECOZO delivery). Parsing is delegated to the shared
 * {@see \OCA\OpenConnector\Service\Stuf\StufXmlParser} (`LIBXML_NONET`
 * only, never `LIBXML_NOENT`/`LIBXML_DTDLOAD` — external entity expansion
 * stays disabled, libxml's default posture since 2.9) — extracted here as
 * part of `stuf-zkn-bridge` so this class and the sibling StUF-ZKN
 * translator share one XXE-hardening implementation instead of two that
 * could silently drift. A malicious retour body cannot read local files or
 * make outbound requests via XML entities.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\IwmoIjw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-inbound-retour-translation-to-an-or-case-status-update-req-003
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\IwmoIjw;

use OCA\OpenConnector\Exception\IwmoIjwTranslationException;
use OCA\OpenConnector\Service\Stuf\StufXmlParser;
use SimpleXMLElement;

/**
 * Retour XML envelope -> OR case status update.
 *
 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-inbound-retour-translation-to-an-or-case-status-update-req-003
 */
class InboundRetourTranslator
{
    /**
     * Constructor.
     *
     * @param StufXmlParser $xmlParser Shared XXE-hardened XML parser.
     */
    public function __construct(private readonly StufXmlParser $xmlParser=new StufXmlParser())
    {

    }//end __construct()

    /**
     * Recognised retour berichttype numeric suffixes.
     *
     * @var array<int, string>
     */
    private const RECOGNISED_CODES = ['302', '304', '305', '306', '307', '308', '322'];

    /**
     * VNG-style "accepted" result marker (case-insensitive).
     *
     * @var string
     */
    private const RESULTAAT_AKKOORD = 'akkoord';

    /**
     * Translate one retour XML envelope into an OR case status update.
     *
     * @param string $xml The raw retour envelope XML, exactly as received on the wire.
     *
     * @return array{berichttype: string, kenmerk: string, status: string, careStartedAt: string|null,
     *         careStoppedAt: string|null, paymentReference: string|null} The status update.
     *
     * @throws IwmoIjwTranslationException When the XML is malformed, the `kenmerk` is missing/empty,
     *                                     or the berichtcode is not one of the recognised retour codes.
     *
     * @spec openspec/specs/iwmo-ijw-adapter/spec.md#scenario-a-wmo304-acceptance-retour-maps-to-status-accepted
     */
    public function translate(string $xml): array
    {
        $root = $this->parseXml(xml: $xml);

        $berichtcode = trim((string) ($root->stuurgegevens->berichtcode ?? ''));
        if ($berichtcode === '') {
            throw new IwmoIjwTranslationException(message: 'Retour envelope is missing stuurgegevens.berichtcode.');
        }

        $kenmerk = trim((string) ($root->stuurgegevens->kenmerk ?? ''));
        if ($kenmerk === '') {
            throw new IwmoIjwTranslationException(
                message: 'Retour envelope is missing stuurgegevens.kenmerk — refusing to update an unresolvable case.'
            );
        }

        $code = $this->extractNumericCode(berichtcode: $berichtcode);
        if (in_array($code, self::RECOGNISED_CODES, true) === false) {
            throw new IwmoIjwTranslationException(
                message: 'Retour berichtcode "'.$berichtcode.'" is not a recognised retour type.'
            );
        }

        $body = $root->body ?? new SimpleXMLElement('<body/>');

        $update = [
            'berichttype'      => $berichtcode,
            'kenmerk'          => $kenmerk,
            'status'           => $this->resolveStatus(code: $code, body: $body),
            'careStartedAt'    => null,
            'careStoppedAt'    => null,
            'paymentReference' => null,
        ];

        if ($code === '305') {
            $update['careStartedAt'] = $this->nullableText(body: $body, field: 'startdatumWerkelijk');
        }

        if ($code === '307') {
            $update['careStoppedAt'] = $this->nullableText(body: $body, field: 'einddatumWerkelijk');
        }

        if ($code === '322') {
            $update['paymentReference'] = $this->nullableText(body: $body, field: 'betalingReferentie');
        }

        return $update;

    }//end translate()

    /**
     * Resolve the OR-facing `status` value for a recognised retour code —
     * see design.md's inbound retour field table.
     *
     * @param string           $code The numeric berichtcode suffix.
     * @param SimpleXMLElement $body The retour's `<body>` element.
     *
     * @return string The resolved status.
     */
    private function resolveStatus(string $code, SimpleXMLElement $body): string
    {
        $resultaat        = strtolower(trim((string) ($body->resultaat ?? '')));
        $resultaatAkkoord = ($resultaat === self::RESULTAAT_AKKOORD);

        $requestOutcome = 'rejected';
        if ($resultaatAkkoord === true) {
            $requestOutcome = 'accepted';
        }

        $invoiceOutcome = 'invoice_rejected';
        if ($this->betaalstatus(body: $body) === self::RESULTAAT_AKKOORD) {
            $invoiceOutcome = 'invoice_processed';
        }

        return match ($code) {
            '302', '304' => $requestOutcome,
            '305' => 'care_started',
            '306' => 'care_start_confirmed',
            '307' => 'care_stopped',
            '308' => 'care_stop_confirmed',
            '322' => $invoiceOutcome,
            default => 'unknown',
        };

    }//end resolveStatus()

    /**
     * Read the `betaalstatus` body field, lower-cased and trimmed.
     *
     * @param SimpleXMLElement $body The retour's `<body>` element.
     *
     * @return string The lower-cased betaalstatus value (empty string when absent).
     */
    private function betaalstatus(SimpleXMLElement $body): string
    {
        return strtolower(trim((string) ($body->betaalstatus ?? '')));

    }//end betaalstatus()

    /**
     * Read an optional body field, returning null instead of an empty string
     * when absent.
     *
     * @param SimpleXMLElement $body  The retour's `<body>` element.
     * @param string           $field The field name to read.
     *
     * @return string|null The trimmed value, or null when absent/empty.
     */
    private function nullableText(SimpleXMLElement $body, string $field): ?string
    {
        $value = trim((string) ($body->{$field} ?? ''));
        if ($value === '') {
            return null;
        }

        return $value;

    }//end nullableText()

    /**
     * Extract the numeric suffix from a `Wmo304`/`Jw304`-style berichtcode.
     *
     * @param string $berichtcode The full berichtcode.
     *
     * @return string The numeric suffix (e.g. `304`).
     */
    private function extractNumericCode(string $berichtcode): string
    {
        return preg_replace('/^[A-Za-z]+/', '', $berichtcode) ?? '';

    }//end extractNumericCode()

    /**
     * Safely parse the retour XML via the shared, XXE-hardened
     * {@see StufXmlParser} (see class docblock).
     *
     * @param string $xml The raw retour envelope XML.
     *
     * @return SimpleXMLElement The parsed `<Bericht>` root element.
     *
     * @throws IwmoIjwTranslationException When the XML is empty or malformed.
     */
    private function parseXml(string $xml): SimpleXMLElement
    {
        if (trim($xml) === '') {
            throw new IwmoIjwTranslationException(message: 'Retour envelope is empty.');
        }

        $root = $this->xmlParser->parse(xml: $xml);
        if ($root === null) {
            throw new IwmoIjwTranslationException(message: 'Retour envelope is not well-formed XML.');
        }

        return $root;

    }//end parseXml()
}//end class
