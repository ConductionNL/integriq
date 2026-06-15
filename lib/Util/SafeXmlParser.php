<?php
/**
 * OpenConnector SafeXmlParser utility.
 *
 * Centralises all SimpleXML parsing behind a defensive wrapper that:
 *   - pins the libxml external-entity loader to null before every parse
 *     (defense-in-depth: prevents XXE even if another call site leaks the
 *     permissive loader that SOAPService installs during WSDL resolution), and
 *   - passes LIBXML_NONET so libxml cannot open network connections while
 *     resolving entities.
 *
 * @category Util
 * @package  OCA\OpenConnector\Util
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Util;

use DOMDocument;
use SimpleXMLElement;

/**
 * Stateless helper for safe XML parsing.
 *
 * All call sites that need to parse untrusted or semi-trusted XML content
 * should use this class instead of calling simplexml_load_string() directly,
 * so that the XXE / SSRF defence is applied consistently.
 *
 * @spec openspec/changes/retrofit-2026-05-28-xml-xxe-hardening/tasks.md#task-1
 */
class SafeXmlParser
{
    /**
     * Parse an XML string safely, returning a SimpleXMLElement or false.
     *
     * Guarantees:
     *   1. The libxml external-entity loader is set to null for the duration
     *      of the parse — preventing file-read and SSRF via entity expansion.
     *   2. LIBXML_NONET is passed so libxml cannot open network connections.
     *   3. The previous entity loader is unconditionally restored afterwards
     *      via a finally block, so a parse exception cannot leave the loader
     *      in an unsafe state.
     *
     * @param string $data    The XML string to parse.
     * @param string $class   The SimpleXMLElement sub-class to use (default SimpleXMLElement).
     * @param int    $options Additional LIBXML_* flags (LIBXML_NONET is always added).
     *
     * @return SimpleXMLElement|false Returns a SimpleXMLElement on success, false on failure.
     *
     * @psalm-return SimpleXMLElement|false
     *
     * @spec openspec/changes/retrofit-2026-05-28-xml-xxe-hardening/tasks.md#task-1
     */
    public static function parse(
        string $data,
        string $class='SimpleXMLElement',
        int $options=0,
    ): SimpleXMLElement|false {
        // Pin the external-entity loader to null before parsing.
        $previousLoader = libxml_get_external_entity_loader();
        libxml_set_external_entity_loader(static fn (): null => null);

        try {
            return simplexml_load_string($data, $class, ($options | LIBXML_NONET));
        } finally {
            // Restore whatever loader was in place before, so this helper is
            // transparent to callers that legitimately use a custom loader.
            libxml_set_external_entity_loader($previousLoader);
        }
    }//end parse()

    /**
     * Load an XML string into a DOMDocument safely.
     *
     * Guarantees:
     *   1. The libxml external-entity loader is set to null for the duration
     *      of the load — preventing file-read and SSRF via entity expansion,
     *      including during any permissive-loader window opened by the caller.
     *   2. LIBXML_NONET is always added to the options so libxml cannot open
     *      network connections while resolving entities.
     *   3. The previous entity loader is unconditionally restored via finally.
     *
     * @param DOMDocument $dom     The DOMDocument instance to load the XML into.
     * @param string      $data    The XML string to load.
     * @param int         $options Additional LIBXML_* flags (LIBXML_NONET always added).
     *
     * @return bool Returns true on success, false on failure (mirrors DOMDocument::loadXML).
     *
     * @psalm-suppress MixedMethodCall
     *
     * @spec openspec/changes/retrofit-2026-05-28-xml-xxe-hardening/tasks.md#task-1
     */
    public static function loadDom(DOMDocument $dom, string $data, int $options=0): bool
    {
        // Pin the external-entity loader to null before loading.
        $previousLoader = libxml_get_external_entity_loader();
        libxml_set_external_entity_loader(static fn (): null => null);

        try {
            return $dom->loadXML($data, ($options | LIBXML_NONET));
        } finally {
            // Restore whatever loader was in place before.
            libxml_set_external_entity_loader($previousLoader);
        }
    }//end loadDom()
}//end class
