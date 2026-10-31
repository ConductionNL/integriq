<?php

/**
 * Contract of the ObjectenTokenService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Objecten
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

namespace OCA\Integriq\Service\Objecten;

/**
 * Public operations of {@see ObjectenTokenService} that other components call.
 *
 * The method signatures repeat those of ObjectenTokenService unchanged, so the findings
 * phpmd reports on them belong to that class and are suppressed here.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
interface ObjectenTokenServiceInterface {
	/**
	 * No token at all.
	 *
	 * @var string
	 */
	public const NO_TOKEN = 'no-token';

	/**
	 * A token nobody issued.
	 *
	 * @var string
	 */
	public const UNKNOWN_TOKEN = 'unknown-token';

	/**
	 * An objecttype this instance does not publish.
	 *
	 * @var string
	 */
	public const UNKNOWN_OBJECTTYPE = 'unknown-objecttype';

	/**
	 * An objecttype this token does not name.
	 *
	 * @var string
	 */
	public const REFUSED_OBJECTTYPE = 'refused-objecttype';

	/**
	 * A write with a read-only permission.
	 *
	 * @var string
	 */
	public const READ_ONLY = 'read-only';

	/**
	 * Allowed.
	 *
	 * @var string
	 */
	public const ALLOWED = 'allowed';

	/**
	 * Load the token declarations.
	 *
	 * 🔴 A DECLARATION CARRYING A LITERAL KEY IS REFUSED. The requirement says
	 * the key must be resolved through the credential broker and must not
	 * appear in an endpoint-configuration export — and the way a key ends up in
	 * an export is somebody pasting it into the configuration, so the
	 * configuration must not accept one.
	 *
	 * @param array<int, mixed> $declarations The tokens.
	 *
	 * @return array<int, string> The reasons any declaration was refused.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function load(array $declarations): array;

	/**
	 * The objecttype uuid a caller's `type` names, from a bare uuid or the standard's objecttype URL.
	 *
	 * @param string $reference The `type` the caller sent.
	 *
	 * @return string The uuid the permissions are keyed by.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function objecttypeFrom(string $reference): string;

	/**
	 * What this request may do, as a verdict.
	 *
	 * @param string|null $authorization The `Authorization` header.
	 * @param string      $objecttype    The objecttype uuid, empty when the route names none.
	 * @param bool        $writing       Whether the request writes.
	 *
	 * @return array{verdict: string, status: int, principal: string} The verdict.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function verdictFor(?string $authorization, string $objecttype, bool $writing = false): array;

	/**
	 * The key from an `Authorization` header, or null.
	 *
	 * 🔑 THE SCHEME IS MATCHED CASE-INSENSITIVELY AND THE KEY IS NOT. HTTP
	 * auth-scheme names are case-insensitive by RFC 7235, and a client sending
	 * `token abc` is conformant; the key after it is opaque and must be
	 * compared exactly.
	 *
	 * @param string|null $authorization The header.
	 *
	 * @return string|null The key.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function keyFrom(?string $authorization): ?string;
}
