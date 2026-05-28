<?php
/**
 * OpenConnector Mapping Twig Extension.
 *
 * Registers Twig filters and functions for executing mappings, encoding
 * helpers and source/file lookups exposed to template authors.
 *
 * @category Twig
 * @package  OCA\OpenConnector\Twig
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig extension exposing the mapping helper filters and functions.
 */
class MappingExtension extends AbstractExtension
{
    /**
     * Return the Twig filters registered by this extension.
     *
     * @return array<int, TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('b64enc', [MappingRuntime::class, 'b64enc']),
            new TwigFilter('b64dec', [MappingRuntime::class, 'b64dec']),
            new TwigFilter('json_decode', [MappingRuntime::class, 'json_decode']),
            new TwigFilter('slugify', [MappingRuntime::class, 'createSlug']),
        ];

    }//end getFilters()

    /**
     * Return the Twig functions registered by this extension.
     *
     * @return array<int, TwigFunction>
     *
     * SECURITY NOTE: `callSource` and `executeMapping` are intentionally NOT
     * registered here.  They were removed from the template surface in the
     * wave-3 security hardening pass because:
     *   - `callSource` performs an outbound HTTP call from within a mapping
     *     template; any admin that can author a mapping could exfiltrate data
     *     through SSRF-style calls to internal services.
     *   - `executeMapping` allows arbitrary mapping chaining, which makes it
     *     impossible to reason about the maximum blast radius of a mapping.
     *
     * If either function is genuinely needed in future, it must be gated behind
     * an explicit "trusted-ops" feature flag that is documented in the OpenSpec
     * and reviewed for SSRF/RCE blast radius before re-enabling.
     *
     * `getFileContents` and `getFiles` are kept because they are already
     * scoped to a specific objectId — files returned are those attached to the
     * given object, so they cannot be used to read arbitrary filesystem paths.
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction(name: 'generateUuid', callable: [MappingRuntime::class, 'generateUuid']),
            new TwigFunction(name: 'getFileContents', callable: [MappingRuntime::class, 'getFileContents']),
            new TwigFunction(name: 'getFiles', callable: [MappingRuntime::class, 'getFiles']),
        ];

    }//end getFunctions()
}//end class
