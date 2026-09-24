// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Cell-formatter registry for integriq's manifest-driven index pages.
//
// Each entry is `(value, row, property) => string`, referenced by id from
// `pages[].config.columns[].formatter` in src/manifest.json and resolved by
// CnDataTable / CnCellRenderer through the `formatters` prop CnAppRoot takes.
//
// connection-registry (hydra umbrella design D8): `connectionStatus` and
// `connectionSettingsLabel` are the two formatters every adopting app carries
// a local copy of until @conduction/nextcloud-vue ships them as built-ins.
// Keep this copy word for word with the other apps' copies.

import { translate as t } from '@nextcloud/l10n'

// The seven stored status values and their English source labels. The label is
// translated on each call rather than here: a module-level t() runs before the
// catalogue is registered and would freeze every label in English.
const CONNECTION_STATUS_LABELS = {
	configured: 'Configured',
	limited: 'Limited',
	unconfigured: 'Not configured',
	simulated: 'Simulated',
	disabled: 'Switched off',
	unavailable: 'Not available',
	error: 'Error',
}

/**
 * Render a connection's stored status as its label.
 *
 * @param {string} value The stored status value.
 * @return {string} The translated label, or the raw value when unknown.
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-integriq-shows-all-connections-on-one-admin-page-req-conn-006
 */
export function connectionStatus(value) {
	const label = CONNECTION_STATUS_LABELS[value]
	return label ? t('integriq', label) : (value ?? '')
}

/**
 * Render the settings column: "Open settings" when the row has a settings
 * link, nothing when it has none. Paired with the built-in `link` widget,
 * an empty href falls through to plain (empty) text, so no link is offered
 * to a section that does not exist.
 *
 * @param {string} value The row's settingsUrl.
 * @return {string} The label, or an empty string.
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-integriq-shows-all-connections-on-one-admin-page-req-conn-006
 */
export function connectionSettingsLabel(value) {
	return value ? t('integriq', 'Open settings') : ''
}

export default {
	connectionStatus,
	connectionSettingsLabel,
}
