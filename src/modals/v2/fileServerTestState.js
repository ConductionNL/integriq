// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What a file server connection test means for the administrator.
 *
 * @param {object|null} result the host-key test response
 * @return {string} `none`, `confirm` (new pin to confirm), `mismatch`, `connected` or `failed`
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
export function fileServerTestState(result) {
	if (!result) {
		return 'none'
	}
	if (!result.matches && result.fingerprint && !result.pinned) {
		return 'confirm'
	}
	if (!result.matches && result.pinned) {
		return 'mismatch'
	}
	if (result.connected) {
		return 'connected'
	}
	return 'failed'
}

/**
 * Whether a source row is an SFTP or FTPS server.
 *
 * @param {object|null} source the source row
 * @return {boolean} true for `sftp` and `ftps`
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
export function isFileServerSource(source) {
	return ['sftp', 'ftps'].includes(source?.type)
}
