<?php

/**
 * Contract of the MappingService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
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

namespace OCA\Integriq\Service;

use Exception;
use OCA\OpenRegister\Db\Mapping as OrMapping;
use OCA\OpenRegister\Db\ObjectEntity;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;

/**
 * Public operations of {@see MappingService} that other components call.
 *
 * The method signatures repeat those of MappingService unchanged, so the findings
 * phpmd reports on them belong to that class and are suppressed here.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
interface MappingServiceInterface {
	/**
	 * Replaces strings in array keys, helpful for characters like . in array keys.
	 *
	 * @param array $array The array to encode the array keys for.
	 * @param string $toReplace The character to encode.
	 * @param string $replacement The encoded character.
	 *
	 * @return array The array with encoded array keys
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	public function encodeArrayKeys(array $array, string $toReplace, string $replacement): array;

	/**
	 * Renders a Twig template string using the mapping Twig environment.
	 *
	 * @param string $template The Twig template to render.
	 * @param array $context The context available inside the template.
	 *
	 * @return string The rendered template result.
	 * @throws LoaderError|SyntaxError Twig exceptions
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	public function renderTemplateString(string $template, array $context = []): string;

	/**
	 * Resolve a mapping by uuid, id or slug, then by imported identifiers.
	 *
	 * OpenRegister's find() throws DoesNotExistException for an identifier it
	 * cannot resolve. That used to end the lookup before the `slug`/`reference`
	 * fallback could run, so a mapping known only by its imported reference
	 * never resolved (mapping-woo-index-field-mapping D4).
	 *
	 * @param string $reference The uuid, id, slug or reference.
	 *
	 * @return ObjectEntity|null The mapping object, or null when nothing matches.
	 *
	 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-sibling-app-runs-a-mapping-by-slug-through-a-typed-event-req-woom-001
	 */
	public function findMapping(string $reference): ?ObjectEntity;

	/**
	 * Maps (transforms) an array (input) to a different array (output).
	 *
	 * @param OrMapping|ObjectEntity|array|string|int $mapping The mapping recipe (polymorphic).
	 * @param array $input The array to be mapped.
	 * @param bool $list Whether we want a list instead of a single item.
	 *
	 * @return array The result (output) of the mapping process.
	 *
	 * @throws LoaderError|SyntaxError Twig Exceptions.
	 *
	 * @spec openspec/specs/openconnector-direct-or-usage/spec.md
	 */
	public function executeMapping(OrMapping|ObjectEntity|array|string|int $mapping, array $input, bool $list = false): array;

	/**
	 * Converts a coordinate string to an array of coordinates.
	 *
	 * @param string $coordinates A string containing coordinates.
	 *
	 * @return array An array of coordinates.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	public function coordinateStringToArray(string $coordinates): array;

	/**
	 * Retrieves a single mapping by its ID/UUID/slug.
	 *
	 * Post chain-C cutover this routes directly through OpenRegister's ObjectService.
	 * The returned OR Mapping value object exposes the same methods the previous
	 * `OCA\Integriq\Db\Mapping` shape did (`getMapping`, `getPassThrough`, ...).
	 *
	 * @param string $mappingId The unique identifier of the mapping to retrieve.
	 *
	 * @return OrMapping The hydrated mapping value object.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If mapping is not found.
	 *
	 * @spec openspec/specs/openconnector-direct-or-usage/spec.md
	 */
	public function getMapping(string $mappingId): OrMapping;

	/**
	 * Retrieves all available mappings.
	 *
	 * Routes directly through OpenRegister's ObjectService.
	 *
	 * @return array<OrMapping> An array containing all hydrated mapping value objects.
	 *
	 * @spec openspec/specs/openconnector-direct-or-usage/spec.md
	 */
	public function getMappings(): array;

	/**
	 * Translate VNG list-filter query semantics onto OpenRegister search filters.
	 *
	 * Dialect-agnostic search-compiler mechanic (REQ-006). Double-underscore
	 * lookup keys (`field__icontains`, `field__gte`, ...) are translated to a
	 * nested `[field => [operator => value]]` OpenRegister filter fragment; a
	 * bare key with no `__` suffix is passed through as an equality filter.
	 * The VNG `partijIdentificator__codeSoortObjectId` /
	 * `partijIdentificator__objectId` pair is folded into a single identity
	 * filter via {@see translatePartijIdentificatorFilter()} so a `bsn`-typed
	 * lookup always resolves against the stored hash, never a raw value.
	 *
	 * @param array<string, mixed> $filters Raw request filters (already query-string-parsed).
	 *
	 * @return array<string, mixed> OpenRegister-shaped search filters.
	 *
	 * @throws Exception When a lookup key uses an operator this compiler does not recognise.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	public function translateVngFilterOperators(array $filters): array;

	/**
	 * Fold a `partijIdentificator` codeSoort + objectId pair into one identity filter.
	 *
	 * When the identity type is `bsn`, the supplied objectId (expected to be a
	 * raw BSN from the caller's perspective) is SHA-256-hashed before being
	 * placed in the filter, matching the AVG BSN policy's storage shape (only
	 * the hash is ever persisted — see {@see \OCA\Integriq\Rule\AvgBsnPolicyRule}).
	 * Non-BSN identity types pass through unchanged.
	 *
	 * @param string $codeKind The VNG identity type code (e.g. `bsn`, `rsin`, `vestigingsnummer`).
	 * @param string $objectId The identity value as supplied by the caller.
	 *
	 * @return array{codeSoortObjectId: string, objectId: string} The folded identity filter.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	public function translatePartyIdentifierFilter(string $codeKind, string $objectId): array;

	/**
	 * Embed named relations inline (VNG `expand=` semantics), bounded by depth.
	 *
	 * Dialect-agnostic search-compiler mechanic (REQ-007). Each entry in
	 * `$expand` is a dot-path (e.g. `digitaleAdressen` or
	 * `betrokkene.digitaleAdressen`); the first segment names a key already
	 * present on `$data` (holding a UUID, or a list of UUIDs) which is
	 * resolved to the full related object via OpenRegister. Nesting deeper
	 * than `$maxDepth` stops expanding and the response documents the
	 * truncation under `_truncatedExpand` rather than fanning out
	 * unboundedly.
	 *
	 * @param array<string, mixed> $data The result (single item) to expand relations on.
	 * @param array<int, string> $expand Dot-path relation keys requested via `expand=`.
	 * @param int $maxDepth Maximum expansion depth (default per orchestrator ruling: 2).
	 *
	 * @return array<string, mixed> $data with the requested relations embedded inline.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	public function expandRelations(array $data, array $expand, int $maxDepth = 2): array;
}
