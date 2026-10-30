<?php

/**
 * Contract of the PkiOverheidCredentialResolver.
 *
 * @category Contract
 * @package  OCA\Integriq\Adapters\Digikoppeling
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

namespace OCA\Integriq\Adapters\Digikoppeling;

use OCA\Integriq\Exception\DigikoppelingException;

/**
 * Public operations of {@see PkiOverheidCredentialResolver} that other components call.
 */
interface PkiOverheidCredentialResolverInterface {
	/**
	 * Resolve PKIoverheid signing material for a `certificateRef`.
	 *
	 * @param string $certificateRef The broker credentialRef naming the PKIoverheid credential.
	 *
	 * @return array{certificatePem: string, privateKeyPem: string} The in-process signing material.
	 *
	 * @throws DigikoppelingException Fail-closed when the ref is empty, the broker is unavailable, or it
	 *                                cannot issue in-process signing material (never a plaintext fallback).
	 *
	 * @spec openspec/specs/digikoppeling-adapter/spec.md — Requirement: PKIoverheid keys resolved via the broker, never plaintext (REQ-DK-005)
	 */
	public function resolveSigningMaterial(string $certificateRef): array;
}
