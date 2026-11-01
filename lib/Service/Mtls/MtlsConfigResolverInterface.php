<?php

/**
 * Contract of the MtlsConfigResolver.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Mtls
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

namespace OCA\Integriq\Service\Mtls;

use OCA\Integriq\Exception\MtlsConfigurationException;

/**
 * Public operations of {@see MtlsConfigResolver} that other components call.
 */
interface MtlsConfigResolverInterface {
	/**
	 * Whether the given `authentication` configuration block selects mTLS dispatch.
	 *
	 * @param array<string, mixed> $authConfig The source's `configuration.authentication` object.
	 *
	 * @return boolean
	 *
	 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-each-adapter-routes-through-the-mtls-transport-only-when-configured-proving-no-orphaned-capability-req-004
	 */
	public function isMtlsConfigured(array $authConfig): bool;

	/**
	 * Resolve `authentication.mtls` into a validated, in-memory certificate bundle.
	 *
	 * @param array<string, mixed> $authConfig The source's `configuration.authentication` object.
	 *
	 * @return MtlsCertificateBundle The validated, decrypted material.
	 *
	 * @throws MtlsConfigurationException Fail-closed on any missing/invalid/expired/undecryptable material.
	 *
	 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-shared-mtls-transport-builds-guzzle-tls-options-from-encrypted-at-rest-certificate-material-req-001
	 */
	public function resolve(array $authConfig): MtlsCertificateBundle;

	/**
	 * The expiry of the stored, encrypted mTLS certificate, without validating the rest.
	 *
	 * @param array<string, mixed> $authConfig The source's `configuration.authentication` object.
	 *
	 * @return integer|null The certificate's notAfter as a unix time, or null without readable inline material.
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-when-its-certificate-expires-req-crun-007
	 */
	public function certificateNotAfter(array $authConfig): ?int;
}
