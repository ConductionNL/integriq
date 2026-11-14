<?php

/**
 * FTPS sources in the data infrastructure category.
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
 * Explicit TLS with the server certificate and host name verified. Plain FTP
 * is not offered: it sends the password in the clear.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
class FtpsAdapter extends RemoteFilesAdapter {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function getId(): string {
		return 'data-infra-ftps';
	}//end getId()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function getLabel(): string {
		return $this->l10n->t('FTPS server (FTP over TLS, certificate checked)');
	}//end getLabel()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function getProtocol(): string {
		return 'ftps';
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
			'authModes'    => ['basic'],
			'configSchema' => [
				'username'     => 'string',
				'rootPath'     => 'string (every path stays inside it)',
				'credentialId' => 'string (credential broker object holding the password)',
			],
		];
	}//end authRequirements()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function unavailableMessage(): string {
		return $this->l10n->t('FTPS needs PHP curl with TLS support, which is missing on this instance.');
	}//end unavailableMessage()
}//end class
