<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  IntakeRuleEditorModal: create, edit and test a routing rule on the "Intake
  routing" page.

  Mounted through the page's `slots["form-dialog"]`. It owns its submit and
  posts to `POST /api/intake/routing-rules` or `PUT
  /api/intake/routing-rules/{id}`, never through the slot's `confirm`: only
  `saveRule()` runs `validateRule()`, which refuses a channel no adapter
  answers to and a mapping onto a field the case type lacks. A refusal is shown
  on the field it names and the dialog stays open. After a save the slot's
  `refresh` reads the list again.

  The channel is picked from `GET /api/intake/channels`, so it cannot be typed;
  the case type from OpenRegister's schemas, grouped by register. "Test" sends
  the draft and a sample message (typed, or a held message from the inbox) to
  `POST /api/intake/routing-rules/test`, which stores nothing.

  @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
  @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-draft-rule-is-tested-against-a-sample-message-without-side-effects-req-ire-002
-->
<template>
	<NcDialog
		v-if="show"
		:name="dialogTitle"
		size="large"
		class="intake-rule-editor-modal"
		:noClose="saving"
		@closing="onCancel">
		<div class="intake-rule-editor" data-testid="intake-rule-editor">
			<NcNoteCard v-if="saveError" type="error">
				<p>{{ saveError }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="loadError" type="warning">
				<p>{{ loadError }}</p>
			</NcNoteCard>

			<div class="intake-rule-editor__grid">
				<NcTextField
					:modelValue="draft.name"
					:label="nameLabel"
					:error="!!nameError"
					:helperText="nameError"
					maxlength="255"
					:disabled="saving"
					data-testid="intake-rule-name"
					@update:modelValue="updateDraft('name', $event)"
					@blur="nameTouched = true" />
				<NcTextField
					:modelValue="String(draft.order)"
					:label="t('integriq', 'Order')"
					type="number"
					:helperText="t('integriq', 'The first enabled rule that matches on a channel wins.')"
					:disabled="saving"
					@update:modelValue="updateDraft('order', $event)" />
			</div>

			<NcTextArea
				:modelValue="draft.description"
				:label="t('integriq', 'Description')"
				:disabled="saving"
				rows="2"
				resize="vertical"
				@update:modelValue="updateDraft('description', $event)" />

			<div class="intake-rule-editor__grid">
				<div class="intake-rule-editor__field" data-testid="intake-rule-channel">
					<NcSelect
						:modelValue="selectedChannel"
						:options="channelOptions"
						:inputLabel="t('integriq', 'Channel') + ' *'"
						label="label"
						:loading="channelsLoading"
						:clearable="false"
						:disabled="saving"
						@update:modelValue="onChannelPicked" />
					<p v-if="fieldErrors.channelId" class="intake-rule-editor__error">
						{{ fieldErrors.channelId }}
					</p>
				</div>
				<div class="intake-rule-editor__field" data-testid="intake-rule-case-type">
					<NcSelect
						:modelValue="selectedCaseType"
						:options="caseTypes"
						:inputLabel="t('integriq', 'Opens (case type)') + ' *'"
						label="label"
						:loading="caseTypesLoading"
						:clearable="false"
						:disabled="saving"
						@update:modelValue="updateDraft('targetSchema', $event ? $event.id : '')" />
					<p v-if="fieldErrors.targetSchema" class="intake-rule-editor__error">
						{{ fieldErrors.targetSchema }}
					</p>
				</div>
			</div>

			<section class="intake-rule-editor__section">
				<h3>{{ t('integriq', 'Condition') }}</h3>
				<p class="intake-rule-editor__hint">
					{{ t('integriq', 'Without a condition the rule matches every message on its channel.') }}
				</p>
				<div class="intake-rule-editor__condition">
					<NcSelect
						:modelValue="draft.conditionField || null"
						:options="sourceOptions"
						:inputLabel="t('integriq', 'Field')"
						:disabled="saving"
						@update:modelValue="updateDraft('conditionField', $event || '')" />
					<NcSelect
						:modelValue="selectedOperator"
						:options="operatorOptions"
						:inputLabel="t('integriq', 'Operator')"
						label="label"
						:clearable="false"
						:disabled="saving || !draft.conditionField"
						@update:modelValue="updateDraft('conditionOperator', $event ? $event.id : 'equals')" />
					<NcTextField
						v-if="draft.conditionOperator !== 'exists'"
						:modelValue="draft.conditionValue"
						:label="t('integriq', 'Value')"
						:disabled="saving || !draft.conditionField"
						@update:modelValue="updateDraft('conditionValue', $event)" />
				</div>
			</section>

			<section class="intake-rule-editor__section">
				<h3>{{ t('integriq', 'Field mapping') }}</h3>
				<p v-if="fieldErrors.fieldMapping" class="intake-rule-editor__error">
					{{ fieldErrors.fieldMapping }}
				</p>
				<div
					v-for="(row, index) in draft.mappings"
					:key="index"
					class="intake-rule-editor__mapping"
					:class="{ 'intake-rule-editor__mapping--refused': row.target !== '' && row.target === mappingErrorTarget }"
					data-testid="intake-rule-mapping-row">
					<NcSelect
						:modelValue="row.target || null"
						:options="targetFieldOptions"
						:inputLabel="t('integriq', 'Case field')"
						:taggable="true"
						:disabled="saving"
						@update:modelValue="updateMapping(index, 'target', $event || '')" />
					<NcSelect
						:modelValue="row.source || null"
						:options="sourceOptions"
						:inputLabel="t('integriq', 'From the message')"
						:disabled="saving"
						@update:modelValue="updateMapping(index, 'source', $event || '')" />
					<NcButton
						variant="tertiary"
						:aria-label="t('integriq', 'Remove mapping')"
						:disabled="saving"
						@click="removeMapping(index)">
						<template #icon>
							<DeleteOutlineIcon :size="20" />
						</template>
					</NcButton>
				</div>
				<NcButton variant="secondary" :disabled="saving" @click="addMapping">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
					{{ t('integriq', 'Add mapping') }}
				</NcButton>
				<div v-if="selectedChannelSupportsLocation" class="intake-rule-editor__field">
					<NcSelect
						:modelValue="draft.locationField || null"
						:options="targetFieldOptions"
						:inputLabel="t('integriq', 'Location field')"
						:taggable="true"
						:disabled="saving"
						@update:modelValue="updateDraft('locationField', $event || '')" />
					<p v-if="fieldErrors.locationField" class="intake-rule-editor__error">
						{{ fieldErrors.locationField }}
					</p>
				</div>
			</section>

			<NcCheckboxRadioSwitch
				:modelValue="draft.isEnabled"
				type="switch"
				:disabled="saving"
				@update:modelValue="updateDraft('isEnabled', $event)">
				{{ t('integriq', 'Enabled') }}
			</NcCheckboxRadioSwitch>

			<section class="intake-rule-editor__section" data-testid="intake-rule-test">
				<h3>{{ t('integriq', 'Test this rule') }}</h3>
				<p class="intake-rule-editor__hint">
					{{ t('integriq', 'Tests the rule as it is drafted against a sample message. Nothing is saved and no case is opened.') }}
				</p>
				<NcSelect
					:modelValue="pickedHeld"
					:options="heldOptions"
					:inputLabel="t('integriq', 'Held message')"
					label="label"
					:loading="heldLoading"
					:disabled="testing"
					@open="loadHeldMessages"
					@update:modelValue="pickedHeld = $event" />
				<NcTextArea
					v-if="!pickedHeld"
					:modelValue="sampleText"
					:label="t('integriq', 'Sample message text')"
					rows="3"
					resize="vertical"
					:disabled="testing"
					@update:modelValue="sampleText = $event" />
				<NcButton variant="secondary" :disabled="testing || !canTest" @click="runTest">
					<template #icon>
						<NcLoadingIcon v-if="testing" :size="20" />
						<PlayOutlineIcon v-else :size="20" />
					</template>
					{{ t('integriq', 'Test') }}
				</NcButton>
				<NcNoteCard v-if="testError" type="error">
					<p>{{ testError }}</p>
				</NcNoteCard>
				<div v-if="testResult" class="intake-rule-editor__result" data-testid="intake-rule-test-result">
					<NcNoteCard :type="testResult.matches ? 'success' : 'warning'">
						<p>{{ testResult.matches ? t('integriq', 'The rule matches this message.') : t('integriq', 'The rule does not match this message.') }}</p>
						<p>{{ winningRuleText }}</p>
					</NcNoteCard>
					<table v-if="payloadRows.length > 0" class="intake-rule-editor__payload">
						<caption>{{ t('integriq', 'What the case would receive') }}</caption>
						<tbody>
							<tr v-for="payloadRow in payloadRows" :key="payloadRow.field">
								<th scope="row">
									{{ payloadRow.field }}
								</th>
								<td>{{ payloadRow.value }}</td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="onCancel">
				{{ t('integriq', 'Cancel') }}
			</NcButton>
			<EditorSaveButton
				:disabled="!canSave"
				:saving="saving"
				:isCreate="isCreate"
				@click="onSave" />
		</template>
	</NcDialog>
</template>

<script>
import { t } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import DeleteOutlineIcon from 'vue-material-design-icons/DeleteOutline.vue'
import PlayOutlineIcon from 'vue-material-design-icons/PlayOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import EditorSaveButton from './EditorSaveButton.vue'
import namedEditorDialog, { NAME_PATTERN } from '../../mixins/namedEditorDialog.js'
import { editorSlotScopeProps } from '../editorSlotScope.js'
import {
	fetchChannels,
	fetchRegistersAndSchemas,
	fetchWaitingMessages,
	refusalOf,
	saveRule,
	testRule,
} from './intakeRuleApi.js'
import {
	buildRulePayload,
	caseTypeOptions,
	draftFromRule,
	OPERATORS,
	refusalField,
	ruleId,
	sampleMessage,
	sourceExpressions,
} from './intakeRuleDraft.js'

/**
 * The field errors of a draft with none.
 *
 * @return {object} No errors.
 */
function noFieldErrors() {
	return { channelId: '', targetSchema: '', fieldMapping: '', locationField: '' }
}

export default {
	name: 'IntakeRuleEditorModal',

	components: {
		DeleteOutlineIcon,
		EditorSaveButton,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
		PlayOutlineIcon,
		PlusIcon,
	},

	mixins: [namedEditorDialog],

	props: {
		...editorSlotScopeProps,

		/** Re-reads the list after this dialog saved through its own endpoint. */
		refresh: {
			type: Function,
			default: null,
		},
	},

	data() {
		return {
			draft: draftFromRule(null),
			nameTouched: false,
			saving: false,
			saveError: '',
			loadError: '',
			fieldErrors: noFieldErrors(),
			mappingErrorTarget: null,
			channels: [],
			channelsLoading: false,
			caseTypes: [],
			caseTypesLoading: false,
			sampleText: '',
			heldMessages: [],
			heldLoading: false,
			heldLoaded: false,
			pickedHeld: null,
			testing: false,
			testError: '',
			testResult: null,
		}
	},

	computed: {
		/**
		 * The dialog's title.
		 *
		 * @return {string}
		 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
		 */
		dialogTitle() {
			return this.isCreate ? t('integriq', 'New routing rule') : t('integriq', 'Edit routing rule')
		},

		/**
		 * The channels as select options; only what the registry describes.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
		 */
		channelOptions() {
			return this.channels.map((channel) => ({
				id: String(channel.channelId),
				label: channel.label ? channel.label + ' (' + channel.channelId + ')' : String(channel.channelId),
			}))
		},

		/**
		 * The picked channel's option.
		 *
		 * @return {object|null}
		 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
		 */
		selectedChannel() {
			return this.channelOptions.find((option) => option.id === this.draft.channelId) || null
		},

		/**
		 * The picked channel's description.
		 *
		 * @return {object|null}
		 */
		channel() {
			return this.channels.find((channel) => String(channel.channelId) === this.draft.channelId) || null
		},

		/**
		 * Whether the picked channel carries a location, so a location field means something.
		 *
		 * @return {boolean}
		 */
		selectedChannelSupportsLocation() {
			return this.channel?.supportsLocation === true || this.draft.locationField !== ''
		},

		/**
		 * The picked case type's option.
		 *
		 * @return {object|null}
		 */
		selectedCaseType() {
			return this.caseTypes.find((option) => option.id === this.draft.targetSchema) || null
		},

		/**
		 * The fields of the picked case type.
		 *
		 * @return {Array<string>}
		 */
		targetFieldOptions() {
			return this.selectedCaseType ? this.selectedCaseType.properties : []
		},

		/**
		 * The source expressions of the picked channel.
		 *
		 * @return {Array<string>}
		 */
		sourceOptions() {
			return sourceExpressions(this.channel)
		},

		/**
		 * The condition operators as select options.
		 *
		 * @return {Array<{id: string, label: string}>}
		 */
		operatorOptions() {
			const labels = {
				equals: t('integriq', 'equals'),
				contains: t('integriq', 'contains'),
				exists: t('integriq', 'exists'),
				in: t('integriq', 'is one of'),
			}
			return OPERATORS.map((id) => ({ id, label: labels[id] }))
		},

		/**
		 * The picked operator's option.
		 *
		 * @return {object|null}
		 */
		selectedOperator() {
			return this.operatorOptions.find((option) => option.id === this.draft.conditionOperator) || null
		},

		/**
		 * The held and failed messages of the picked channel, for a test sample.
		 *
		 * @return {Array<{id: string, label: string, message: object}>}
		 */
		heldOptions() {
			return this.heldMessages
				.filter((message) => this.draft.channelId === '' || message.channelId === this.draft.channelId)
				.map((message) => ({
					id: ruleId(message),
					label: String(message.text || '').slice(0, 80) || String(message.externalId || ruleId(message)),
					message,
				}))
		},

		/**
		 * Whether the draft has what a save needs.
		 *
		 * @return {boolean}
		 */
		canSave() {
			return !this.saving
				&& NAME_PATTERN.test(this.draft.name)
				&& this.draft.channelId !== ''
				&& this.draft.targetSchema !== ''
		},

		/**
		 * Whether the draft can be tested.
		 *
		 * @return {boolean}
		 */
		canTest() {
			return this.draft.channelId !== '' && this.draft.targetSchema !== ''
		},

		/**
		 * The test's payload as rows.
		 *
		 * @return {Array<{field: string, value: string}>}
		 */
		payloadRows() {
			const payload = this.testResult?.payload
			if (!payload || typeof payload !== 'object') {
				return []
			}
			return Object.entries(payload).map(([field, value]) => ({
				field,
				value: typeof value === 'object' && value !== null ? JSON.stringify(value) : String(value ?? ''),
			}))
		},

		/**
		 * Which saved rule wins for the channel today.
		 *
		 * @return {string}
		 */
		winningRuleText() {
			const winner = this.testResult?.winningRule
			if (!winner) {
				return t('integriq', 'No saved rule matches this message today, so it would be held.')
			}
			return t('integriq', 'Today the saved rule "{name}" (order {order}) wins for this message.', {
				name: winner.name,
				order: winner.order,
			})
		},
	},

	methods: {
		t,

		/**
		 * Seed the draft from the row and load the pickers' options.
		 *
		 * @return {void}
		 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
		 */
		seedDraft() {
			this.draft = draftFromRule(this.item)
			this.nameTouched = false
			this.saveError = ''
			this.fieldErrors = noFieldErrors()
			this.mappingErrorTarget = null
			this.testResult = null
			this.testError = ''
			this.pickedHeld = null
			this.sampleText = ''
			this.loadOptions()
		},

		/**
		 * Load the channels and the case types.
		 *
		 * @return {Promise<void>}
		 */
		async loadOptions() {
			this.loadError = ''
			this.channelsLoading = true
			this.caseTypesLoading = true
			const [channels, lists] = await Promise.allSettled([fetchChannels(), fetchRegistersAndSchemas()])
			this.channelsLoading = false
			this.caseTypesLoading = false

			this.channels = channels.status === 'fulfilled' ? channels.value : []
			this.caseTypes = lists.status === 'fulfilled'
				? caseTypeOptions(lists.value.registers, lists.value.schemas)
				: []
			if (channels.status === 'rejected' || lists.status === 'rejected') {
				this.loadError = t('integriq', 'The channels or case types could not be loaded. Reopen the dialog to try again.')
			}
		},

		/**
		 * Load the held messages once, when the sample picker opens.
		 *
		 * @return {Promise<void>}
		 */
		async loadHeldMessages() {
			if (this.heldLoaded || this.heldLoading) {
				return
			}
			this.heldLoading = true
			try {
				this.heldMessages = await fetchWaitingMessages()
				this.heldLoaded = true
			} catch {
				this.heldMessages = []
			} finally {
				this.heldLoading = false
			}
		},

		/**
		 * Write one draft key and clear the error shown on it.
		 *
		 * @param {string} key The key.
		 * @param {string|number|boolean} value The value.
		 * @return {void}
		 */
		updateDraft(key, value) {
			this.draft = { ...this.draft, [key]: value }
			if (this.fieldErrors[key] !== undefined) {
				this.fieldErrors = { ...this.fieldErrors, [key]: '' }
			}
		},

		/**
		 * Pick a channel.
		 *
		 * @param {object|null} option The channel option.
		 * @return {void}
		 */
		onChannelPicked(option) {
			this.updateDraft('channelId', option ? option.id : '')
			this.pickedHeld = null
		},

		/**
		 * Add an empty mapping row.
		 *
		 * @return {void}
		 */
		addMapping() {
			this.draft = { ...this.draft, mappings: [...this.draft.mappings, { target: '', source: '' }] }
		},

		/**
		 * Remove a mapping row.
		 *
		 * @param {number} index The row.
		 * @return {void}
		 */
		removeMapping(index) {
			this.draft = { ...this.draft, mappings: this.draft.mappings.filter((row, i) => i !== index) }
			this.clearMappingError()
		},

		/**
		 * Write one side of a mapping row.
		 *
		 * @param {number} index The row.
		 * @param {string} side 'target' or 'source'.
		 * @param {string} value The value.
		 * @return {void}
		 */
		updateMapping(index, side, value) {
			const mappings = this.draft.mappings.map((row, i) => (i === index ? { ...row, [side]: value } : row))
			this.draft = { ...this.draft, mappings }
			this.clearMappingError()
		},

		/**
		 * Clear the refusal shown on the mapping.
		 *
		 * @return {void}
		 */
		clearMappingError() {
			this.fieldErrors = { ...this.fieldErrors, fieldMapping: '' }
			this.mappingErrorTarget = null
		},

		/**
		 * Save through `saveRule()`; a refusal stays in the dialog on its field.
		 *
		 * @return {Promise<boolean>} Whether the rule was saved.
		 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
		 */
		async onSave() {
			this.nameTouched = true
			if (!this.canSave) {
				return false
			}

			this.saving = true
			this.saveError = ''
			this.fieldErrors = noFieldErrors()
			this.mappingErrorTarget = null
			try {
				await saveRule(ruleId(this.item), buildRulePayload(this.draft))
			} catch (error) {
				this.showRefusal(refusalOf(error, t('integriq', 'The rule could not be saved.')))
				return false
			} finally {
				this.saving = false
			}

			if (typeof this.refresh === 'function') {
				await this.refresh()
			}
			this.close?.()
			return true
		},

		/**
		 * Show a refusal on the field it names, or above the form.
		 *
		 * @param {string} message The refusal.
		 * @return {void}
		 */
		showRefusal(message) {
			const { field, target } = refusalField(message, this.draft)
			if (field === null) {
				this.saveError = message
				return
			}
			this.fieldErrors = { ...noFieldErrors(), [field]: message }
			this.mappingErrorTarget = target
		},

		/**
		 * Test the draft against the sample; nothing is stored.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-draft-rule-is-tested-against-a-sample-message-without-side-effects-req-ire-002
		 */
		async runTest() {
			this.testing = true
			this.testError = ''
			this.testResult = null
			try {
				this.testResult = await testRule(
					buildRulePayload(this.draft),
					sampleMessage(this.sampleText, this.pickedHeld ? this.pickedHeld.message : null),
				)
			} catch (error) {
				this.testError = refusalOf(error, t('integriq', 'The test could not run.'))
			} finally {
				this.testing = false
			}
		},
	},
}
</script>

<style scoped>
.intake-rule-editor {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding-bottom: calc(var(--default-grid-baseline) * 2);
}

.intake-rule-editor__grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
	gap: calc(var(--default-grid-baseline) * 3);
}

.intake-rule-editor__field {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.intake-rule-editor__section {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	border-top: 1px solid var(--color-border);
	padding-top: calc(var(--default-grid-baseline) * 2);
}

.intake-rule-editor__section h3 {
	margin: 0;
	font-size: var(--default-font-size);
	font-weight: bold;
}

.intake-rule-editor__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.intake-rule-editor__condition,
.intake-rule-editor__mapping {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: calc(var(--default-grid-baseline) * 2);
	align-items: end;
}

.intake-rule-editor__mapping {
	grid-template-columns: 1fr 1fr auto;
}

.intake-rule-editor__mapping--refused {
	box-shadow: inset 3px 0 0 0 var(--color-error);
	padding-left: calc(var(--default-grid-baseline) * 2);
}

.intake-rule-editor__error {
	margin: 0;
	color: var(--color-error-text, var(--color-error));
}

.intake-rule-editor__payload {
	width: 100%;
	border-collapse: collapse;
}

.intake-rule-editor__payload caption {
	text-align: start;
	font-weight: bold;
	padding-bottom: var(--default-grid-baseline);
}

.intake-rule-editor__payload th,
.intake-rule-editor__payload td {
	text-align: start;
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
}
</style>
