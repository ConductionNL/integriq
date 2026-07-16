<?php

/**
 * OpenConnector StUF-ZKN Namespace Constants.
 *
 * Centralises the XML namespace URIs this bridge assumes for StUF-ZKN 3.10
 * (VNG/EGEM) SOAP envelopes — shared by every translator/envelope builder in
 * `lib/Service/StufZkn/` so a future correction only touches one file.
 *
 * ASSUMED — no live StUF-ZKN endpoint was available to verify against in
 * this environment. These are the namespace URIs published in the StUF
 * 3.01 core standard and the StUF-ZKN 3.10 sector model (EGEM/VNG); see
 * design.md "StUF element/attribute assumptions" for the full grounding
 * and every field this bridge reads/writes under them.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\StufZkn
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
 * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\StufZkn;

/**
 * StUF-ZKN 3.10 XML namespace URIs assumed by this bridge.
 *
 * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md
 */
final class StufZknNamespaces
{

    /**
     * SOAP 1.1 envelope namespace.
     *
     * @var string
     */
    public const SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';

    /**
     * StUF 3.01 core namespace — carries the reusable `stuurgegevens`
     * building blocks (`berichtcode`, `zender`, `ontvanger`,
     * `referentienummer`, `tijdstipBericht`, `crossRefnummer`,
     * `entiteittype`, `mutatiesoort`, `indicatorOvername`) and the
     * `noValue`/`verwerkingssoort` attributes.
     *
     * @var string
     */
    public const STUF = 'http://www.egem.nl/StUF/StUF0301';

    /**
     * StUF-ZKN 3.10 sector namespace — carries the `zakLk01`/`edcLk01`
     * message wrappers, `stuurgegevens`/`parameters`/`object` elements, and
     * every zaak/document domain field (`identificatie`, `omschrijving`,
     * `zaaktype`, `titel`, ...).
     *
     * @var string
     */
    public const ZKN = 'http://www.egem.nl/StUF/sector/zkn/0310';

    /**
     * XML Schema instance namespace — carries `xsi:nil`.
     *
     * @var string
     */
    public const XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    /**
     * Private constructor — constants-only class.
     */
    private function __construct()
    {

    }//end __construct()
}//end class
