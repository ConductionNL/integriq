<?php

/**
 * Contract of the SenderIdentityService.
 *
 * @category Contract
 * @package  OCA\Integriq\Outbound\Identity
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

namespace OCA\Integriq\Outbound\Identity;

use OCA\OpenRegister\Db\ObjectEntity;
use RuntimeException;

/**
 * Public operations of {@see SenderIdentityService} that other components call.
 */
interface SenderIdentityServiceInterface {
	/**
	 * Quote only the message being replied to.
	 *
	 * @var string
	 */
	public const QUOTING_LAST = 'last-message';

	/**
	 * Resolve the identity a message leaves under.
	 *
	 * @param string|null $identityId The identity the message named, or null.
	 *
	 * @return array{identity:array<string,mixed>,id:string,fallback:bool} The identity, and
	 *         whether the instance default was used because the message named none.
	 *
	 * @throws RuntimeException When the message names no identity and this instance has no default.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on SenderIdentityService::resolve()
	 */
	public function resolve(?string $identityId): array;

	/**
	 * The signing key for one identity, and why there is none when there is none.
	 *
	 * Signing reads OUTSIDE RBAC, and only here. `resolve()`, `all()` and
	 * `defaultIdentity()` stay RBAC-scoped because SenderIdentityController
	 * renders them to a person, and `sender_identity` is locked down
	 * (register.d/99-sender-identity-lockdown.json). Widening those would hand a
	 * caller exactly what that lockdown refuses.
	 *
	 * The system-context read is needed for a different reason, and it takes TWO
	 * flags rather than one. `smimePrivateKey` is `writeOnly`, and OpenRegister
	 * strips write-only values in the RENDER pass — `RenderObject::doWriteOnly`
	 * is computed from the schema alone and has no `_rbac` term, so `_rbac: false`
	 * does not defeat it and `find()` renders by default. The read therefore
	 * passes `_render: false` as well; without it an identity whose key has not
	 * yet been migrated reads as EMPTY to the signing path and the message goes
	 * out unsigned under a reason that looks like an unconfigured identity. That
	 * is the transitional path; a migrated identity resolves through the broker
	 * and never touches the inline field.
	 *
	 * @param array<string,mixed> $identity The identity object as read.
	 * @param string $identityId The identity's uuid, for the system-context read.
	 *
	 * @return array{key: string|null, state: string} The material, and the state
	 *         explaining its absence. Never carries a secret in the state.
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-011-signing-keeps-working-once-the-key-is-brokered
	 */
	public function signingMaterial(array $identity, string $identityId): array;

	/**
	 * The instance default identity, when there is one.
	 *
	 * @return ObjectEntity|null The default, or null.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on SenderIdentityService::defaultIdentity()
	 */
	public function defaultIdentity(): ?ObjectEntity;

	/**
	 * Every identity on this instance.
	 *
	 * @return array<int,ObjectEntity> The identities.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on SenderIdentityService::all()
	 */
	public function all(): array;

	/**
	 * What a message's envelope looks like under one identity.
	 *
	 * @param array<string,mixed> $identity The identity.
	 *
	 * @return array<string,string> The From, Reply-To and signature the recipient sees.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on SenderIdentityService::envelopeFor()
	 */
	public function envelopeFor(array $identity): array;
}
