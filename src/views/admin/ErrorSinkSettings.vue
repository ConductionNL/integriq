<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<!--
  ErrorSinkSettings: where integriq forwards the errors of the apps an
  administrator selects (app settings errorsink_*). The sink is a Sentry
  compatible service, addressed by its DSN, or an OpenTelemetry collector
  that takes OTLP logs. The collector login is a credential name from the
  credential store, never a secret typed here.

  @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
-->
<template>
	<section class="integriq-admin__section" data-testid="admin-errorsink-section">
		<h3>{{ t('integriq', 'Send errors to an error service') }}</h3>
		<p class="integriq-admin__hint">
			{{
				t(
					'integriq',
					'Integriq forwards the errors of the apps you select to a Sentry compatible service or to an OpenTelemetry collector. Credentials and citizen service numbers are removed first.',
				)
			}}
		</p>

		<div v-if="error" class="integriq-admin__action-error" role="alert">
			{{ error }}
		</div>

		<p v-if="loading" class="integriq-admin__hint">
			{{ t('integriq', 'Loading the setting…') }}
		</p>
		<div v-else class="errorsink-settings__form">
			<NcCheckboxRadioSwitch
				v-model="form.enabled"
				type="switch"
				data-testid="admin-errorsink-enabled">
				{{ t('integriq', 'Send errors') }}
			</NcCheckboxRadioSwitch>
			<NcSelect
				v-model="form.kind"
				:inputLabel="t('integriq', 'Kind of error service')"
				:options="kindOptions"
				:reduce="(option) => option.value"
				:clearable="false"
				data-testid="admin-errorsink-kind" />
			<NcTextField
				v-model="form.endpoint"
				data-testid="admin-errorsink-endpoint"
				:label="endpointLabel"
				:placeholder="endpointPlaceholder" />
			<NcCheckboxRadioSwitch
				v-model="form.allowLocal"
				data-testid="admin-errorsink-allow-local">
				{{ t('integriq', 'The error service runs in our own network') }}
			</NcCheckboxRadioSwitch>
			<NcSelect
				v-model="form.minLevel"
				:inputLabel="t('integriq', 'Lowest level to send')"
				:options="levelOptions"
				:reduce="(option) => option.value"
				:clearable="false"
				data-testid="admin-errorsink-level" />
			<NcTextField
				v-model="appsText"
				data-testid="admin-errorsink-apps"
				:label="
					t('integriq', 'Apps whose errors are sent, separated by commas')
				"
				placeholder="integriq, openregister, opencatalogi" />
			<template v-if="form.kind === 'otlp-logs'">
				<NcTextField
					v-model="form.credentialName"
					data-testid="admin-errorsink-credential"
					:label="t('integriq', 'Credential for the collector login')" />
				<NcTextField
					v-model="form.headerName"
					data-testid="admin-errorsink-header"
					:label="t('integriq', 'Header the login goes in')"
					placeholder="Authorization" />
			</template>
			<NcButton
				variant="primary"
				data-testid="admin-errorsink-save"
				:disabled="busy"
				@click="save">
				{{ t('integriq', 'Save') }}
			</NcButton>
		</div>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import adminSetting from '../../mixins/adminSetting.js'

const URL = '/apps/integriq/api/admin/errorsink'

/**
 * The error sink settings before the stored ones are read.
 *
 * @return {object} The sink switched off, Sentry, errors of the three default apps.
 */
function defaultForm() {
	return {
		enabled: false,
		kind: 'sentry',
		endpoint: '',
		allowLocal: false,
		minLevel: 3,
		apps: ['integriq', 'openregister', 'opencatalogi'],
		credentialName: '',
		headerName: '',
	}
}

export default {
	name: 'ErrorSinkSettings',

	components: { NcButton, NcCheckboxRadioSwitch, NcSelect, NcTextField },

	mixins: [adminSetting],

	data() {
		return {
			form: defaultForm(),
		}
	},

	computed: {
		/**
		 * @return {string} The setting endpoint.
		 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
		 */
		settingUrl() {
			return URL
		},

		/**
		 * @return {Array<object>} The kinds of error service.
		 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
		 */
		kindOptions() {
			return [
				{
					value: 'sentry',
					label: t('integriq', 'Sentry compatible service'),
				},
				{
					value: 'otlp-logs',
					label: t('integriq', 'OpenTelemetry collector (OTLP logs)'),
				},
			]
		},

		/**
		 * @return {Array<object>} The Nextcloud log levels, from warning up.
		 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
		 */
		levelOptions() {
			return [
				{ value: 2, label: t('integriq', 'Warning') },
				{ value: 3, label: t('integriq', 'Error') },
				{ value: 4, label: t('integriq', 'Fatal') },
			]
		},

		/**
		 * @return {string} The label of the address field for the chosen kind.
		 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
		 */
		endpointLabel() {
			return this.form.kind === 'otlp-logs'
				? t('integriq', 'Collector address')
				: t('integriq', 'DSN of the error service')
		},

		/**
		 * @return {string} An example address for the chosen kind.
		 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
		 */
		endpointPlaceholder() {
			return this.form.kind === 'otlp-logs'
				? 'https://otel.example.org:4318'
				: 'https://key@sentry.example.org/42'
		},

		appsText: {
			/**
			 * The app list shown as text.
			 *
			 * @return {string}
			 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
			 */
			get: function () {
				return (this.form.apps || []).join(', ')
			},

			/**
			 * Store typed text as a list of app ids.
			 *
			 * @param {string} value The comma separated app ids.
			 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
			 */
			set: function (value) {
				this.form.apps = String(value)
					.split(',')
					.map((app) => app.trim())
					.filter((app) => app !== '')
			},
		},
	},

	methods: {
		t,

		/**
		 * Show what the server holds.
		 *
		 * @param {object|null} data The stored setting.
		 * @return {void}
		 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
		 */
		applyStored(data) {
			this.form = { ...this.form, ...data }
		},

		/**
		 * What to store.
		 *
		 * @return {object} The request body.
		 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
		 */
		settingBody() {
			return { ...this.form }
		},
	},
}
</script>

<style scoped>
.errorsink-settings__form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	max-width: 480px;
}
</style>
