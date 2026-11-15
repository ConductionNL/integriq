<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<!--
  ConnectionAlertSettings: the group whose members hear about an opened
  connection alert and about the other integriq alerts, failed jobs, deliveries
  and payments among them (app setting connection_alert_group). Until an
  administrator names another group, the admin group is told; clearing the
  field puts the admin group back.

  @spec openspec/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
-->
<template>
	<section
		class="integriq-admin__section"
		data-testid="admin-connection-alert-group-section">
		<h3>{{ t('integriq', 'Who hears about connection alerts') }}</h3>
		<p class="integriq-admin__hint">
			{{
				t(
					'integriq',
					'Members of this group get a notification when a connection, job or delivery fails or passes an alert threshold. Leave it empty to tell the admin group.',
				)
			}}
		</p>

		<div v-if="error" class="integriq-admin__action-error" role="alert">
			{{ error }}
		</div>

		<p v-if="loading" class="integriq-admin__hint">
			{{ t('integriq', 'Loading the setting…') }}
		</p>
		<div v-else class="connection-alert-group__row">
			<NcTextField
				v-model="group"
				data-testid="admin-connection-alert-group"
				:label="t('integriq', 'Group id')"
				@keyup.enter="save" />
			<NcButton
				variant="primary"
				data-testid="admin-connection-alert-group-save"
				:disabled="busy"
				@click="save">
				{{ t('integriq', 'Save') }}
			</NcButton>
		</div>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcTextField } from '@nextcloud/vue'
import adminSetting from '../../mixins/adminSetting.js'

const URL = '/apps/integriq/api/admin/connection-alert-group'

export default {
	name: 'ConnectionAlertSettings',

	components: { NcButton, NcTextField },

	mixins: [adminSetting],

	data() {
		return {
			group: '',
			saved: '',
		}
	},

	computed: {
		/**
		 * @return {string} The setting endpoint.
		 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
		 */
		settingUrl() {
			return URL
		},
	},

	methods: {
		t,

		/**
		 * Show what the server holds.
		 *
		 * @param {object|null} data The stored setting.
		 * @return {void}
		 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
		 */
		applyStored(data) {
			this.saved = data?.group || ''
			this.group = this.saved
		},

		/**
		 * What to store.
		 *
		 * @return {object} The request body.
		 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
		 */
		settingBody() {
			return { group: this.group.trim() }
		},
	},
}
</script>

<style scoped>
.connection-alert-group__row {
	display: flex;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
	max-width: 480px;
}
</style>
