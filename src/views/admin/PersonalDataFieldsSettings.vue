<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<!--
  PersonalDataFieldsSettings: field names an administrator adds to the
  personal data that is masked in logged message bodies (app setting
  personal_data_fields). The default list (BSN, name, address, date of birth,
  e-mail address, telephone number) always applies and is shown read-only.

  @spec openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
-->
<template>
	<section
		class="integriq-admin__section"
		data-testid="admin-personal-data-fields-section">
		<h3>{{ t('integriq', 'Personal data in logs') }}</h3>
		<p class="integriq-admin__hint">
			{{
				t(
					'integriq',
					'The values of these fields are masked in the call log, trace snapshots and outbound message records before they are stored. The message that is sent is not changed.',
				)
			}}
		</p>

		<div v-if="error" class="integriq-admin__action-error" role="alert">
			{{ error }}
		</div>

		<p v-if="loading" class="integriq-admin__hint">
			{{ t('integriq', 'Loading the setting…') }}
		</p>
		<template v-else>
			<p class="integriq-admin__hint" data-testid="admin-personal-data-defaults">
				{{ t('integriq', 'Always masked: {fields}', { fields: defaults.join(', ') }) }}
			</p>
			<div class="personal-data-fields__row">
				<NcTextField
					v-model="fields"
					data-testid="admin-personal-data-fields"
					:label="t('integriq', 'Extra field names, separated by commas')"
					@keyup.enter="save" />
				<NcButton
					variant="primary"
					data-testid="admin-personal-data-fields-save"
					:disabled="busy"
					@click="save">
					{{ t('integriq', 'Save') }}
				</NcButton>
			</div>
		</template>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcTextField } from '@nextcloud/vue'
import adminSetting from '../../mixins/adminSetting.js'

const URL = '/apps/integriq/api/admin/personal-data-fields'

export default {
	name: 'PersonalDataFieldsSettings',

	components: { NcButton, NcTextField },

	mixins: [adminSetting],

	data() {
		return {
			defaults: [],
			fields: '',
		}
	},

	computed: {
		/**
		 * @return {string} The setting endpoint.
		 * @spec openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
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
		 * @spec openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
		 */
		applyStored(data) {
			this.defaults = Array.isArray(data?.defaults) ? data.defaults : []
			this.fields = Array.isArray(data?.added) ? data.added.join(', ') : ''
		},

		/**
		 * What to store.
		 *
		 * @return {object} The request body.
		 * @spec openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
		 */
		settingBody() {
			return {
				fields: this.fields
					.split(',')
					.map((field) => field.trim())
					.filter((field) => field !== ''),
			}
		},
	},
}
</script>

<style scoped>
.personal-data-fields__row {
	display: flex;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
	max-width: 640px;
}
</style>
