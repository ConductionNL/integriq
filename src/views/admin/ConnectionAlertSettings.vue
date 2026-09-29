<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<!--
  ConnectionAlertSettings: the group whose members hear about an opened
  connection alert (app setting connection_alert_group). No group is named on
  a fresh install, so an alert shows on the Connection alerts page only until
  an administrator names one.

  @spec openspec/changes/observability-connection-run-summary/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
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
					'When a source or synchronization passes one of its alert thresholds, the members of this group get a notification. Leave it empty and nobody is notified; the alert still shows on the Connection alerts page.',
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
		<p
			v-if="!loading && saved === ''"
			class="integriq-admin__hint"
			data-testid="admin-connection-alert-group-none">
			{{ t('integriq', 'No group is named, so nobody is notified.') }}
		</p>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcTextField } from '@nextcloud/vue'

const URL = '/apps/integriq/api/admin/connection-alert-group'

export default {
	name: 'ConnectionAlertSettings',

	components: { NcButton, NcTextField },

	data() {
		return {
			loading: true,
			busy: false,
			error: '',
			group: '',
			saved: '',
		}
	},

	/**
	 * Read the named group.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/observability-connection-run-summary/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
	 */
	async mounted() {
		try {
			const { data } = await axios.get(generateUrl(URL))
			this.group = data?.group || ''
			this.saved = this.group
		} catch (e) {
			this.error = e?.response?.data?.error || t('integriq', 'The setting could not be read.')
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,

		/**
		 * Store the group, or clear it; the server refuses a group that does not exist.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/observability-connection-run-summary/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
		 */
		async save() {
			this.busy = true
			this.error = ''
			try {
				const { data } = await axios.put(generateUrl(URL), { group: this.group.trim() })
				this.saved = data?.group || ''
				this.group = this.saved
				showSuccess(t('integriq', 'Saved.'))
			} catch (e) {
				this.error = e?.response?.data?.error || t('integriq', 'The setting could not be saved.')
			} finally {
				this.busy = false
			}
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
