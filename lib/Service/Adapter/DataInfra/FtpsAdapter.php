<?php

/**
 * FTPS server data-infra adapter.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\DataInfra
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\DataInfra;

/**
 * A partner's FTPS server as a source (REQ-SFTP-001).
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
class FtpsAdapter extends AbstractFileTransferAdapter {

	/**
	 * Adapter id.
	 *
	 * @return string The id.
	 */
	public function getId(): string {
		return 'data-infra-ftps';

	}//end getId()

	/**
	 * Label.
	 *
	 * @return string The label.
	 */
	public function getLabel(): string {
		return $this->l10n->t('FTPS server with explicit TLS');

	}//end getLabel()

	/**
	 * The protocol.
	 *
	 * @return string The protocol.
	 */
	public function protocol(): string {
		return 'ftps';

	}//end protocol()

	/**
	 * The default port.
	 *
	 * @return int The port.
	 */
	protected function defaultPort(): int {
		return 21;

	}//end defaultPort()

	/**
	 * Whether a pinned key is mandatory.
	 *
	 * @return bool The answer.
	 */
	protected function requiresPin(): bool {
		return false;

	}//end requiresPin()
}//end class
