<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<!--
  OtelSettings: where integriq sends its execution traces as OpenTelemetry
  spans (app settings otel_*). An administrator switches export on, names
  the collector, the service name and the share of successful traces to
  send. A failed or replayed trace is always sent. The collector login is a
  credential name from the credential store, never a secret typed here.

  @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-is-configured-by-an-administrator-req-otel-005
-->
<template>
	<section class="integriq-admin__section" data-testid="admin-otel-section">
		<h3>{{ t('integriq', 'Send traces to a monitoring service') }}</h3>
		<p class="integriq-admin__hint">
			{{
				t(
					'integriq',
					'Integriq sends each execution trace to an OpenTelemetry collector you choose. Spans carry names, timing and status, never message content.',
				)
			}}
		</p>

		<div v-if="error" class="integriq-admin__action-error" role="alert">
			{{ error }}
		</div>

		<p v-if="loading" class="integriq-admin__hint">
			{{ t('integriq', 'Loading the setting…') }}
		</p>
		<div v-else class="otel-settings__form">
			<NcCheckboxRadioSwitch
				v-model="form.enabled"
				type="switch"
				data-testid="admin-otel-enabled">
				{{ t('integriq', 'Send traces') }}
			</NcCheckboxRadioSwitch>
			<NcTextField
				v-model="form.endpoint"
				data-testid="admin-otel-endpoint"
				:label="t('integriq', 'Collector address')"
				placeholder="https://otel.example.org:4318" />
			<NcCheckboxRadioSwitch
				v-model="form.allowLocal"
				data-testid="admin-otel-allow-local">
				{{ t('integriq', 'The collector runs in our own network') }}
			</NcCheckboxRadioSwitch>
			<NcTextField
				v-model="form.serviceName"
				data-testid="admin-otel-service-name"
				:label="t('integriq', 'Service name')"
				placeholder="integriq" />
			<NcTextField
				v-model="samplingPercent"
				type="number"
				min="0"
				max="100"
				data-testid="admin-otel-sampling"
				:label="
					t('integriq', 'Share of successful traces to send, in percent')
				" />
			<NcTextField
				v-model="form.credentialName"
				data-testid="admin-otel-credential"
				:label="t('integriq', 'Credential for the collector login')" />
			<NcTextField
				v-model="form.headerName"
				data-testid="admin-otel-header"
				:label="t('integriq', 'Header the login goes in')"
				placeholder="Authorization" />
			<NcButton
				variant="primary"
				data-testid="admin-otel-save"
				:disabled="busy"
				@click="save">
				{{ t('integriq', 'Save') }}
			</NcButton>
		</div>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcTextField } from '@nextcloud/vue'
import adminSetting from '../../mixins/adminSetting.js'

const URL = '/apps/integriq/api/admin/otel'

/**
 * The export settings before the stored ones are read.
 *
 * @return {object} Export switched off, sampling at 10 percent.
 */
function defaultForm() {
	return {
		enabled: false,
		endpoint: '',
		allowLocal: false,
		serviceName: '',
		samplingRatio: 0.1,
		credentialName: '',
		headerName: '',
	}
}

export default {
	name: 'OtelSettings',

	components: { NcButton, NcCheckboxRadioSwitch, NcTextField },

	mixins: [adminSetting],

	data() {
		return {
			form: defaultForm(),
		}
	},

	computed: {
		/**
		 * @return {string} The setting endpoint.
		 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-is-configured-by-an-administrator-req-otel-005
		 */
		settingUrl() {
			return URL
		},

		samplingPercent: {
			/**
			 * The sampling ratio shown as a percentage.
			 *
			 * @return {string}
			 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-is-configured-by-an-administrator-req-otel-005
			 */
			get: function () {
				return String(Math.round(this.form.samplingRatio * 100))
			},

			/**
			 * Store a typed percentage as a ratio.
			 *
			 * @param {string} value The percentage.
			 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-is-configured-by-an-administrator-req-otel-005
			 */
			set: function (value) {
				const percent = Number(value)
				this.form.samplingRatio = Number.isFinite(percent)
					? percent / 100
					: 0
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
		 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-is-configured-by-an-administrator-req-otel-005
		 */
		applyStored(data) {
			this.form = { ...this.form, ...data }
		},

		/**
		 * What to store.
		 *
		 * @return {object} The request body.
		 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-is-configured-by-an-administrator-req-otel-005
		 */
		settingBody() {
			return { ...this.form }
		},
	},
}
</script>

<style scoped>
.otel-settings__form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	max-width: 480px;
}
</style>
