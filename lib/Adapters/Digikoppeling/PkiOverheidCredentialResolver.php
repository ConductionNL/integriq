<?php

/**
 * Integriq — PKIoverheid signing-material resolver (broker, fail-closed).
 *
 * WS-Security message signing needs the PKIoverheid client certificate and its
 * private key IN-PROCESS. Per REQ-DK-005 / ADR-007 that key material MUST be
 * resolved through the OpenRegister credential broker via a `certificateRef` and
 * MUST NEVER be stored as plaintext in adapter/source config, exports, or logs,
 * and there MUST be no plaintext-key-on-disk fallback.
 *
 * The current broker ({@see \OCA\OpenRegister\Service\Credential\CredentialBrokerService})
 * is a CONSTRAINED PROXY: it performs an outbound call on the credential's
 * behalf but deliberately never hands raw secret material back to a calling
 * app. In-process XML signing cannot use that proxy shape, so until the broker
 * grows an explicit "signing-material" capability this resolver FAILS CLOSED
 * with an actionable configuration error rather than fall back to a plaintext
 * key. That is the honest, spec-compliant behaviour (REQ-DK-005), and it is the
 * one documented deferral of the WUS profile: live PKIoverheid in-process
 * signing is unblocked the moment a broker material-issuing capability exists.
 *
 * The resolver detects such a capability by reflection so it lights up
 * automatically without a code change here when the broker adds it.
 *
 * @category Adapter
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
use Psr\Container\ContainerInterface;

/**
 * Resolves PKIoverheid signing material through the credential broker, or fails closed.
 *
 * @spec openspec/specs/digikoppeling-adapter/spec.md
 */
class PkiOverheidCredentialResolver {

	/**
	 * FQCN of the OpenRegister credential broker (resolved lazily).
	 *
	 * @var string
	 */
	public const BROKER_CLASS = 'OCA\OpenRegister\Service\Credential\CredentialBrokerService';

	/**
	 * Broker method that would issue in-process signing material, if it exists.
	 *
	 * @var string
	 */
	public const MATERIAL_METHOD = 'issueSigningMaterial';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container PSR container used to resolve the broker lazily.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

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
	public function resolveSigningMaterial(string $certificateRef): array {
		if (trim($certificateRef) === '') {
			throw new DigikoppelingException(
				message:
				'Digikoppeling signing requires a PKIoverheid certificateRef — none is configured.'
			);
		}

		$broker = $this->resolveBroker();
		if ($broker === null) {
			throw new DigikoppelingException(
				message:
				'Digikoppeling signing requires the OpenRegister credential broker, which is not available. '
				. 'Configure the certificateRef "' . $certificateRef . '" through the broker.'
			);
		}

		// Fail closed unless the broker can issue in-process signing material.
		// The constrained-proxy broker cannot, by design; this lights up
		// automatically if/when a signing-material capability is added.
		if (method_exists($broker, self::MATERIAL_METHOD) === false) {
			throw new DigikoppelingException(
				message:
				'The credential broker cannot supply in-process PKIoverheid signing material for certificateRef "'
				. $certificateRef . '". Digikoppeling WUS/ebMS2 message signing needs the private key in-process; '
				. 'no plaintext-key-on-disk fallback is permitted (ADR-007). This capability is a documented '
				. 'follow-on: the broker must expose a signing-material issuance path.'
			);
		}

		$material = $broker->{self::MATERIAL_METHOD}($certificateRef);
		if (is_array($material) === false
			|| isset($material['certificatePem'], $material['privateKeyPem']) === false
		) {
			throw new DigikoppelingException(
				message:
				'The credential broker returned no usable signing material for certificateRef "' . $certificateRef . '".'
			);
		}

		return [
			'certificatePem' => (string)$material['certificatePem'],
			'privateKeyPem' => (string)$material['privateKeyPem'],
		];

	}//end resolveSigningMaterial()

	/**
	 * Lazily resolve the broker instance, or null when unavailable.
	 *
	 * Protected so tests can substitute a broker double (the real broker FQCN
	 * does not `class_exists` in the bare unit environment).
	 *
	 * @return object|null
	 */
	protected function resolveBroker(): ?object {
		if (class_exists(self::BROKER_CLASS) === false) {
			return null;
		}

		try {
			return $this->container->get(self::BROKER_CLASS);
		} catch (\Throwable) {
			return null;
		}

	}//end resolveBroker()
}//end class
