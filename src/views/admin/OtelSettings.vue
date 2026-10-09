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
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch, NcTextField } from '@nextcloud/vue'

const URL = '/apps/integriq/api/admin/otel'

export default {
	name: 'OtelSettings',

	components: { NcButton, NcCheckboxRadioSwitch, NcTextField },

	data() {
		return {
			loading: true,
			busy: false,
			error: '',
			form: {
				enabled: false,
				endpoint: '',
				allowLocal: false,
				serviceName: '',
				samplingRatio: 0.1,
				credentialName: '',
				headerName: '',
			},
		}
	},

	computed: {
		samplingPercent: {
			/**
			 * The sampling ratio shown as a percentage.
			 *
			 * @return {string}
			 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-is-configured-by-an-administrator-req-otel-005
			 */
			get() {
				return String(Math.round(this.form.samplingRatio * 100))
			},

			/**
			 * Store a typed percentage as a ratio.
			 *
			 * @param {string} value The percentage.
			 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-is-configured-by-an-administrator-req-otel-005
			 */
			set(value) {
				const percent = Number(value)
				this.form.samplingRatio = Number.isFinite(percent)
					? percent / 100
					: 0
			},
		},
	},

	/**
	 * Read the stored settings.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-is-configured-by-an-administrator-req-otel-005
	 */
	async mounted() {
		try {
			const { data } = await axios.get(generateUrl(URL))
			this.form = { ...this.form, ...data }
		} catch (e) {
			this.error =
				e?.response?.data?.error
				|| t('integriq', 'The setting could not be read.')
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,

		/**
		 * Store the settings; the server refuses an address that is not https unless it is marked as internal.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-is-configured-by-an-administrator-req-otel-005
		 */
		async save() {
			this.busy = true
			this.error = ''
			try {
				const { data } = await axios.put(generateUrl(URL), { ...this.form })
				this.form = { ...this.form, ...data }
				showSuccess(t('integriq', 'Saved.'))
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| t('integriq', 'The setting could not be saved.')
			} finally {
				this.busy = false
			}
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
