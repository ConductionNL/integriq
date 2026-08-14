<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<CnAdminSettingsShell
		appId="openconnector"
		appName="OpenConnector"
		:showVersionCard="false"
		:showReimport="false">
		<div class="openconnector-admin">
			<ActionAuthMatrix />
			<DsoPkiSettings />
		</div>
	</CnAdminSettingsShell>
</template>

<script>
import { CnAdminSettingsShell } from '@conduction/nextcloud-vue'
import ActionAuthMatrix from './ActionAuthMatrix.vue'
import DsoPkiSettings from './DsoPkiSettings.vue'

/**
 * Root admin settings panel for OpenConnector.
 *
 * Wraps the app's settings in the shared CnAdminSettingsShell (uniform title
 * header + version/support chrome) and renders the ADR-023
 * action-authorization matrix editor plus the DSO STAM PKIoverheid signature
 * configuration editor as its content.
 *
 * The version card is disabled: openconnector's admin getForm() does not
 * provide a `version` initial state, so the card would show "Unknown".
 * Re-import is disabled: openconnector is not a standard AppHost settings app
 * and exposes no `POST /api/settings/load` route (see appinfo/routes.php —
 * the standard /api/settings surface was removed in the OR-cutover).
 *
 * @spec openspec/specs/action-authorization/spec.md#requirement-the-matrix-is-editable-by-an-administrator-and-only-by-one
 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-2
 */
export default {
	name: 'AdminSettings',

	components: {
		CnAdminSettingsShell,
		ActionAuthMatrix,
		DsoPkiSettings,
	},
}
</script>
