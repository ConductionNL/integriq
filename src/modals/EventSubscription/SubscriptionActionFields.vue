<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
  SubscriptionActionFields — slotted override for CnFormDialog's `#form`
  content on the Webhooks (event_subscription) index page. Mirrors the
  lib's auto-widget switch for the schema's own fields (JobFormFields.vue /
  SourceFormFields.vue precedent) and adds the delivery-action authoring UX
  the schema-driven generic renderer cannot express declaratively:

    - An "action kind" picker (Webhook / Synchronization / Job —
      nextcloud-event-hub REQ-008). Webhook keeps using the schema's own
      `sink` field (unchanged); Synchronization/Job reveal a target picker
      that writes `formData.action = { kind, synchronizationId|jobId }`.
    - An optional, collapsible "Retry policy" block (REQ-009) writing
      `formData.retryPolicy = { baseSeconds?, factor?, capSeconds?,
      maxRetries? }` — every key independently optional; an unset key falls
      back to the class defaults server-side.

  `action`/`retryPolicy` are deliberately NOT part of the Webhooks page's
  manifest `includeFields` (they are free-form `type: object` schema
  properties with no declarative widget) — this component owns their entire
  authoring surface, the same way SourceFormFields owns the brokered-
  credential block outside of `visibleFields`.

  Wired by CnPageRenderer when the manifest declares
  `pages[].slots = { 'form-fields': 'SubscriptionActionFields' }` (registry.js
  maps the name to this component) on the "Webhooks" page.

  @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
  @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-retrybackoff-policy-must-be-independently-configurable-req-009
-->
<template>
	<div class="cn-subscription-action-fields">
		<div
			v-for="field in fields"
			:key="field.key"
			class="cn-subscription-action-fields__field">
			<div
				v-if="field.key === 'types'"
				class="cn-subscription-action-fields__textarea-wrapper">
				<label
					:for="'cn-subscription-form-' + field.key"
					class="cn-subscription-action-fields__label">
					{{ field.label || t('openconnector', 'Event types')
					}}{{ field.required ? ' *' : '' }}
				</label>
				<textarea
					:id="'cn-subscription-form-' + field.key"
					class="cn-subscription-action-fields__textarea"
					:value="typesText"
					rows="3"
					:placeholder="
						t(
							'openconnector',
							'One CloudEvents type per line, e.g. com.nextcloud.files.node.created',
						)
					"
					@input="onTypesInput($event.target.value)" />
				<span class="cn-subscription-action-fields__helper">
					{{
						field.description
						|| t(
							'openconnector',
							'CloudEvents type filters this subscription matches (any-of). Leave empty to match every type.',
						)
					}}
				</span>
			</div>

			<NcCheckboxRadioSwitch
				v-else-if="field.widget === 'checkbox'"
				:modelValue="!!formData[field.key]"
				:disabled="field.readOnly"
				type="switch"
				@update:modelValue="(value) => updateField(field.key, value)">
				{{ field.label }}{{ field.required ? ' *' : '' }}
			</NcCheckboxRadioSwitch>

			<NcTextField
				v-else
				:label="field.label + (field.required ? ' *' : '')"
				:modelValue="
					formData[field.key] != null ? String(formData[field.key]) : ''
				"
				:helperText="errors[field.key] || field.description"
				:error="!!errors[field.key]"
				:disabled="field.readOnly"
				:placeholder="field.description"
				@update:modelValue="(value) => updateField(field.key, value)" />
		</div>

		<!-- Delivery action block (REQ-008). -->
		<div
			class="cn-subscription-action-fields__field cn-subscription-action-fields__section">
			<label
				for="cn-subscription-action-kind"
				class="cn-subscription-action-fields__label">
				{{ t('openconnector', 'Delivery action') }}
			</label>
			<NcSelect
				inputId="cn-subscription-action-kind"
				:inputLabel="t('openconnector', 'Delivery action')"
				:aria-label-combobox="t('openconnector', 'Delivery action')"
				:modelValue="selectedKindOption"
				:options="kindOptions"
				:clearable="false"
				@update:modelValue="onKindPick" />
			<span class="cn-subscription-action-fields__helper">
				{{
					t(
						'openconnector',
						'A matched event either POSTs to the sink above (Webhook), runs a synchronization, or runs a job. All three are tracked, retried, and dead-letterable the same way.',
					)
				}}
			</span>

			<template v-if="actionKind === 'synchronization'">
				<label
					for="cn-subscription-action-target"
					class="cn-subscription-action-fields__label">
					{{ t('openconnector', 'Synchronization') }}
				</label>
				<NcSelect
					inputId="cn-subscription-action-target"
					:inputLabel="t('openconnector', 'Synchronization')"
					:aria-label-combobox="t('openconnector', 'Synchronization')"
					:modelValue="selectedSynchronization"
					:options="synchronizationOptions"
					:loading="synchronizationsLoading"
					:clearable="false"
					:placeholder="t('openconnector', 'Select a synchronization')"
					@update:modelValue="onSynchronizationPick" />
			</template>

			<template v-else-if="actionKind === 'job'">
				<label
					for="cn-subscription-action-target"
					class="cn-subscription-action-fields__label">
					{{ t('openconnector', 'Job') }}
				</label>
				<NcSelect
					inputId="cn-subscription-action-target"
					:inputLabel="t('openconnector', 'Job')"
					:aria-label-combobox="t('openconnector', 'Job')"
					:modelValue="selectedJob"
					:options="jobOptions"
					:loading="jobsLoading"
					:clearable="false"
					:placeholder="t('openconnector', 'Select a job')"
					@update:modelValue="onJobPick" />
			</template>
		</div>

		<!-- Retry policy block (REQ-009) — optional, independently overridable. -->
		<div
			class="cn-subscription-action-fields__field cn-subscription-action-fields__section">
			<NcCheckboxRadioSwitch
				:modelValue="retryPolicyEnabled"
				type="switch"
				@update:modelValue="onToggleRetryPolicy">
				{{ t('openconnector', 'Custom retry policy') }}
			</NcCheckboxRadioSwitch>
			<span class="cn-subscription-action-fields__helper">
				{{
					t(
						'openconnector',
						'Overrides the default backoff (60s, x4, 6h cap, 5 retries). Any field left blank falls back to the default.',
					)
				}}
			</span>

			<template v-if="retryPolicyEnabled">
				<div class="cn-subscription-action-fields__retry-grid">
					<NcTextField
						:label="t('openconnector', 'Base seconds')"
						:modelValue="retryPolicyValue('baseSeconds')"
						type="number"
						@update:modelValue="
							(value) => onRetryPolicyField('baseSeconds', value)
						" />
					<NcTextField
						:label="t('openconnector', 'Factor')"
						:modelValue="retryPolicyValue('factor')"
						type="number"
						@update:modelValue="
							(value) => onRetryPolicyField('factor', value)
						" />
					<NcTextField
						:label="t('openconnector', 'Cap seconds')"
						:modelValue="retryPolicyValue('capSeconds')"
						type="number"
						@update:modelValue="
							(value) => onRetryPolicyField('capSeconds', value)
						" />
					<NcTextField
						:label="t('openconnector', 'Max retries')"
						:modelValue="retryPolicyValue('maxRetries')"
						type="number"
						@update:modelValue="
							(value) => onRetryPolicyField('maxRetries', value)
						" />
				</div>
			</template>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'

const KIND_OPTIONS = [
	{ id: 'webhook', label: 'Webhook' },
	{ id: 'synchronization', label: 'Synchronization' },
	{ id: 'job', label: 'Job' },
]

export default {
	name: 'SubscriptionActionFields',

	components: {
		NcTextField,
		NcSelect,
		NcCheckboxRadioSwitch,
	},

	props: {
		/** Resolved field descriptors from CnFormDialog. */
		fields: { type: Array, default: () => [] },
		/** Reactive form data object owned by CnFormDialog. */
		formData: { type: Object, default: () => ({}) },
		/** Per-field error map from CnFormDialog. */
		errors: { type: Object, default: () => ({}) },
		/**
		 * Mutator forwarded from CnFormDialog. Signature: (key, value).
		 * Drives the dialog's reactivity + dirty tracking.
		 */
		updateField: { type: Function, required: true },
	},

	data() {
		return {
			synchronizationOptions: [],
			synchronizationsLoading: false,
			jobOptions: [],
			jobsLoading: false,
		}
	},

	computed: {
		/**
		 * `types[]` rendered as one-per-line text for the textarea.
		 *
		 * @return {string}
		 * @spec exclude presentation-only array<->textarea projection
		 */
		typesText() {
			const types = this.formData?.types
			return Array.isArray(types) ? types.join('\n') : ''
		},

		/**
		 * The subscription's effective action kind, defaulting to 'webhook'
		 * when `action` is absent (REQ-008 back-compat default).
		 *
		 * @return {string}
		 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
		 */
		actionKind() {
			return this.formData?.action?.kind || 'webhook'
		},

		/**
		 * The three dispatch kinds REQ-008 fixes: webhook, synchronization, job.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
		 */
		kindOptions() {
			return KIND_OPTIONS
		},

		/**
		 * The `NcSelect` model for the kind picker. Falls back to the first
		 * option (webhook) rather than null, so the select is never rendered
		 * unset for a subscription whose `action.kind` is absent — which is the
		 * back-compat default REQ-008 assigns.
		 *
		 * @return {object}
		 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
		 */
		selectedKindOption() {
			return (
				KIND_OPTIONS.find((option) => option.id === this.actionKind)
				|| KIND_OPTIONS[0]
			)
		},

		/**
		 * The `NcSelect` model for a `synchronization`-kind target. Falls back
		 * to a synthetic `{id, label: id}` when the stored id is not in the
		 * fetched options, so a subscription pointing at a Synchronization the
		 * picker cannot currently list still shows its binding instead of
		 * appearing unset — saving from an unset picker would silently drop the
		 * dispatch target.
		 *
		 * @return {object|null}
		 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
		 */
		selectedSynchronization() {
			const id = this.formData?.action?.synchronizationId
			if (!id) return null
			return (
				this.synchronizationOptions.find((option) => option.id === id) || {
					id,
					label: id,
				}
			)
		},

		/**
		 * The `NcSelect` model for a `job`-kind target, with the same
		 * synthetic-fallback rule as {@link selectedSynchronization}.
		 *
		 * @return {object|null}
		 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
		 */
		selectedJob() {
			const id = this.formData?.action?.jobId
			if (!id) return null
			return (
				this.jobOptions.find((option) => option.id === id) || {
					id,
					label: id,
				}
			)
		},

		/**
		 * Whether the subscription declares a `retryPolicy` block at all.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-retrybackoff-policy-must-be-independently-configurable-req-009
		 */
		retryPolicyEnabled() {
			return (
				this.formData?.retryPolicy != null
				&& typeof this.formData.retryPolicy === 'object'
			)
		},
	},

	watch: {
		/**
		 * Lazily loads the target list for the newly chosen kind, once. The
		 * two lists are fetched separately and only on demand: a webhook
		 * subscription never needs either, and REQ-008 scopes each kind's
		 * picker to its own entity type.
		 *
		 * @param {string} value The newly selected action kind.
		 * @return {void}
		 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
		 */
		actionKind(value) {
			if (
				value === 'synchronization'
				&& this.synchronizationOptions.length === 0
			) {
				this.fetchSynchronizations()
			} else if (value === 'job' && this.jobOptions.length === 0) {
				this.fetchJobs()
			}
		},
	},

	/**
	 * Loads the target list for the kind an EXISTING subscription already
	 * carries. Without this the watcher alone would never fire when editing —
	 * `actionKind` does not change on open — and the picker would render empty
	 * for a subscription that already has a target.
	 *
	 * @return {void}
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
	 */
	created() {
		if (this.actionKind === 'synchronization') {
			this.fetchSynchronizations()
		} else if (this.actionKind === 'job') {
			this.fetchJobs()
		}
	},

	methods: {
		t,

		/**
		 * Parse the one-per-line `types` textarea back into an array.
		 *
		 * @param {string} raw The raw textarea value.
		 * @return {void}
		 * @spec exclude presentation-only textarea<->array projection
		 */
		onTypesInput(raw) {
			const types = raw
				.split('\n')
				.map((line) => line.trim())
				.filter((line) => line.length > 0)
			this.updateField('types', types)
		},

		/**
		 * Write the picked action kind, seeding a minimal `action` block.
		 * Switching to 'webhook' clears synchronizationId/jobId (they have
		 * no effect for that kind).
		 *
		 * @param {object} option The picked kind option.
		 * @return {void}
		 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
		 */
		onKindPick(option) {
			const kind = option?.id || 'webhook'
			if (kind === 'webhook') {
				this.updateField('action', { kind })
				return
			}
			const current =
				this.formData?.action && typeof this.formData.action === 'object'
					? this.formData.action
					: {}
			this.updateField('action', { ...current, kind })
		},

		/**
		 * Write the picked synchronization target.
		 *
		 * @param {object} option The picked synchronization option.
		 * @return {void}
		 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
		 */
		onSynchronizationPick(option) {
			this.updateField('action', {
				kind: 'synchronization',
				synchronizationId: option?.id ? String(option.id) : null,
			})
		},

		/**
		 * Write the picked job target.
		 *
		 * @param {object} option The picked job option.
		 * @return {void}
		 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
		 */
		onJobPick(option) {
			this.updateField('action', {
				kind: 'job',
				jobId: option?.id ? String(option.id) : null,
			})
		},

		/**
		 * Toggle the custom retry-policy block. Turning it off clears the
		 * field entirely (server falls back to the class defaults).
		 *
		 * @param {boolean} value The new switch state.
		 * @return {void}
		 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-retrybackoff-policy-must-be-independently-configurable-req-009
		 */
		onToggleRetryPolicy(value) {
			this.updateField('retryPolicy', value ? {} : null)
		},

		/**
		 * Current string value of one retryPolicy key for its NcTextField.
		 *
		 * @param {string} key The retryPolicy key.
		 * @return {string}
		 * @spec exclude presentation-only value projection
		 */
		retryPolicyValue(key) {
			const value = this.formData?.retryPolicy?.[key]
			return value != null ? String(value) : ''
		},

		/**
		 * Write one retryPolicy key; blank clears that key (falls back to
		 * the server-side default) without disabling the whole block.
		 *
		 * @param {string} key The retryPolicy key.
		 * @param {string} raw The raw text input value.
		 * @return {void}
		 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-retrybackoff-policy-must-be-independently-configurable-req-009
		 */
		onRetryPolicyField(key, raw) {
			const current =
				this.formData?.retryPolicy
				&& typeof this.formData.retryPolicy === 'object'
					? { ...this.formData.retryPolicy }
					: {}
			if (raw === '' || raw === null || raw === undefined) {
				delete current[key]
			} else {
				const parsed = Number(raw)
				current[key] = Number.isFinite(parsed) ? parsed : undefined
			}
			this.updateField('retryPolicy', current)
		},

		/**
		 * Load synchronization options for the action target picker.
		 *
		 * @return {Promise<void>}
		 * @spec exclude data-fetch helper — presentation only
		 */
		async fetchSynchronizations() {
			this.synchronizationsLoading = true
			try {
				const response = await axios.get(
					generateUrl(
						'/apps/openregister/api/objects/openconnector/synchronization',
					),
					// `_limit`, not `limit` — an unprefixed param is a PROPERTY
					// FILTER in OpenRegister and silently returns `total: 0`
					// under HTTP 200. See FlowDetailPage.fetchPickerOptions().
					{ params: { _limit: 500 } },
				)
				const list = Array.isArray(response.data?.results)
					? response.data.results
					: Array.isArray(response.data)
						? response.data
						: []
				this.synchronizationOptions = list.map((item) => ({
					id: String(item.id || item.uuid),
					label: item.name || item.title || item.id,
				}))
			} catch (err) {
				// eslint-disable-next-line no-console
				console.warn(
					'[SubscriptionActionFields] synchronization fetch failed',
					err,
				)
				this.synchronizationOptions = []
			} finally {
				this.synchronizationsLoading = false
			}
		},

		/**
		 * Load job options for the action target picker.
		 *
		 * @return {Promise<void>}
		 * @spec exclude data-fetch helper — presentation only
		 */
		async fetchJobs() {
			this.jobsLoading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/objects/openconnector/job'),
					// `_limit`, not `limit` — an unprefixed param is a PROPERTY
					// FILTER in OpenRegister and silently returns `total: 0`
					// under HTTP 200. See FlowDetailPage.fetchPickerOptions().
					{ params: { _limit: 500 } },
				)
				const list = Array.isArray(response.data?.results)
					? response.data.results
					: Array.isArray(response.data)
						? response.data
						: []
				this.jobOptions = list.map((item) => ({
					id: String(item.id || item.uuid),
					label: item.name || item.title || item.id,
				}))
			} catch (err) {
				// eslint-disable-next-line no-console
				console.warn('[SubscriptionActionFields] job fetch failed', err)
				this.jobOptions = []
			} finally {
				this.jobsLoading = false
			}
		},
	},
}
</script>

<style scoped>
.cn-subscription-action-fields {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.cn-subscription-action-fields__field {
	display: flex;
	flex-direction: column;
}

.cn-subscription-action-fields__section {
	gap: 8px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, var(--border-radius));
	background: var(--color-background-hover);
}

.cn-subscription-action-fields__textarea-wrapper {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.cn-subscription-action-fields__label {
	font-weight: bold;
	margin-bottom: 4px;
}

.cn-subscription-action-fields__textarea {
	width: 100%;
	padding: 8px;
	font-family: var(--font-face-monospace, monospace);
	font-size: 13px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
}

.cn-subscription-action-fields__helper {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.cn-subscription-action-fields__retry-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
	gap: 8px;
}
</style>
