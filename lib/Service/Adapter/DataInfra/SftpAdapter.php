<?php

/**
 * SFTP sources in the data infrastructure category.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\DataInfra
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\DataInfra;

/**
 * Password or key login, with the server's host key pinned by SHA-256
 * fingerprint. Needs phpseclib 3 on the instance.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
class SftpAdapter extends RemoteFilesAdapter {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function getId(): string {
		return 'data-infra-sftp';
	}//end getId()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function getLabel(): string {
		return $this->l10n->t('SFTP server with a pinned host key');
	}//end getLabel()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function getProtocol(): string {
		return 'sftp';
	}//end getProtocol()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function authRequirements(): array {
		return [
			'type'         => 'credential-broker',
			'authModes'    => ['basic', 'ssh-key'],
			'configSchema' => [
				'username'           => 'string',
				'rootPath'           => 'string (every path stays inside it)',
				'hostKeyFingerprint' => 'string (SHA256:..., required)',
				'credentialId'       => 'string (credential broker object holding the password or private key)',
			],
		];
	}//end authRequirements()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function unavailableMessage(): string {
		return $this->l10n->t('SFTP needs the phpseclib 3 library, which is not installed on this instance.');
	}//end unavailableMessage()
}//end class
