<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<CnAdminSettingsShell
		appId="integriq"
		appName="Integriq"
		:showVersionCard="false"
		:showReimport="false">
		<div class="integriq-admin">
			<ActionAuthMatrix />
			<DsoPkiSettings />
			<DsoActivityMappingSettings />
			<OpenFormulierenConnectionSettings />
			<WebhookConnectionsSettings />
			<DigitalPostAccountSettings />
			<BerichtenboxSettings />
			<ExpressionSourceSettings />
			<ConnectionAlertSettings />
			<OtelSettings />
		</div>
	</CnAdminSettingsShell>
</template>

<script>
import { CnAdminSettingsShell } from '@conduction/nextcloud-vue'
import ActionAuthMatrix from './ActionAuthMatrix.vue'
import BerichtenboxSettings from './BerichtenboxSettings.vue'
import ConnectionAlertSettings from './ConnectionAlertSettings.vue'
import DigitalPostAccountSettings from './DigitalPostAccountSettings.vue'
import DsoActivityMappingSettings from './DsoActivityMappingSettings.vue'
import DsoPkiSettings from './DsoPkiSettings.vue'
import ExpressionSourceSettings from './ExpressionSourceSettings.vue'
import OpenFormulierenConnectionSettings from './OpenFormulierenConnectionSettings.vue'
import OtelSettings from './OtelSettings.vue'
import WebhookConnectionsSettings from './WebhookConnectionsSettings.vue'

/**
 * Root admin settings panel for Integriq.
 *
 * Wraps the app's settings in the shared CnAdminSettingsShell (uniform title
 * header + version/support chrome) and renders the ADR-023
 * action-authorization matrix editor plus the DSO STAM PKIoverheid signature
 * configuration editor and the environment allowlist expressions may read
 * (allowlisted-expression-sources) as its content.
 *
 * The version card is disabled: integriq's admin getForm() does not
 * provide a `version` initial state, so the card would show "Unknown".
 * Re-import is disabled: integriq is not a standard AppHost settings app
 * and exposes no `POST /api/settings/load` route (see appinfo/routes.php —
 * the standard /api/settings surface was removed in the OR-cutover).
 *
 * @spec openspec/specs/action-authorization/spec.md#requirement-the-matrix-is-editable-by-an-administrator-and-only-by-one
 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-2
 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-4
 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md#requirement-the-allowlist-is-administered-and-every-change-is-recorded-req-evs-003
 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-transport-certificate-is-held-encrypted-and-named-by-reference-req-dpa-004
 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#requirement-administrators-maintain-the-activity-table-on-the-admin-settings-page-req-dso-012
 */
export default {
	name: 'AdminSettings',

	components: {
		CnAdminSettingsShell,
		ActionAuthMatrix,
		BerichtenboxSettings,
		ConnectionAlertSettings,
		DigitalPostAccountSettings,
		DsoActivityMappingSettings,
		DsoPkiSettings,
		ExpressionSourceSettings,
		OpenFormulierenConnectionSettings,
		OtelSettings,
		WebhookConnectionsSettings,
	},
}
</script>
