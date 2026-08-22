<?php

/**
 * Integriq Mapping Twig Extension.
 *
 * Registers Twig filters and functions for executing mappings, encoding
 * helpers and source/file lookups exposed to template authors.
 *
 * @category Twig
 * @package  OCA\Integriq\Twig
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @deprecated Mapping evaluation consolidated into OpenRegister (2026-08-03).
 * Every pure transformation function moved to
 * `OCA\OpenRegister\Twig\MappingRuntime`; the three that need Integriq's
 * own services — callSource, getTargetIdByOriginId, getOriginIdByTargetId — are
 * contributed to that engine via `RegisterMappingFunctionsEvent`
 * (see Listener\MappingFunctionRegistrationListener).
 *
 * This copy is retained only while Integriq's own MappingService still
 * exists. Do NOT add functions here: a function registered on this environment
 * is invisible to every mapping evaluated through OpenRegister, which is now
 * the engine everything else uses.
 *
 * NOTE: Twig\AuthenticationExtension is NOT deprecated. It templates outbound
 * request authentication in CallService and is genuinely this app's concern.
 */

namespace OCA\Integriq\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig extension exposing the mapping helper filters and functions.
 */
class MappingExtension extends AbstractExtension {
	/**
	 * Return the Twig filters registered by this extension.
	 *
	 * @return array<int, TwigFilter>
	 */
	public function getFilters(): array {
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
	 * SECURITY NOTE: `callSource` is intentionally NOT registered here. It was
	 * removed from the template surface in the
	 * wave-3 security hardening pass because:
	 *   - `callSource` performs an outbound HTTP call from within a mapping
	 *     template; any admin that can author a mapping could exfiltrate data
	 *     through SSRF-style calls to internal services.
	 * `executeMapping` is registered because existing trusted synchronization
	 * mappings depend on composing smaller mapping recipes from Twig.
	 *
	 * `getFileContents` and `getFiles` are kept because they are already
	 * scoped to a specific objectId — files returned are those attached to the
	 * given object, so they cannot be used to read arbitrary filesystem paths.
	 */
	public function getFunctions(): array {
		return [
			new TwigFunction(name: 'generateUuid', callable: [MappingRuntime::class, 'generateUuid']),
			new TwigFunction(name: 'executeMapping', callable: [MappingRuntime::class, 'executeMapping']),
			new TwigFunction(name: 'getFileContents', callable: [MappingRuntime::class, 'getFileContents']),
			new TwigFunction(name: 'getFiles', callable: [MappingRuntime::class, 'getFiles']),
			new TwigFunction(name: 'getTargetIdByOriginId', callable: [MappingRuntime::class, 'getTargetIdByOriginId']),
			new TwigFunction(name: 'getOriginIdByTargetId', callable: [MappingRuntime::class, 'getOriginIdByTargetId']),
		];

	}//end getFunctions()
}//end class
