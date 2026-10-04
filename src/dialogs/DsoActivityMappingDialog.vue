<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  DsoActivityMappingDialog: add or edit one DSO activity mapping row on the
  admin settings page (change dso-activity-mapping-table, REQ-DSO-012).

  The parent saves the row through OpenRegister and passes back a refusal as
  `error`; this dialog only edits the draft and refuses to emit one the
  schema would refuse.

  @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#requirement-administrators-maintain-the-activity-table-in-the-app-req-dso-012
-->
<template>
	<NcDialog
		:open="open"
		:name="
			draft.id
				? t('integriq', 'Edit DSO activity')
				: t('integriq', 'Add DSO activity')
		"
		size="normal"
		data-testid="dso-activity-dialog"
		@update:open="onOpenChanged">
		<div class="dso-activity-dialog">
			<NcTextField
				v-model="draft.activityName"
				:label="t('integriq', 'Activity name')"
				data-testid="dso-activity-name" />
			<NcTextField
				v-model="draft.imowId"
				:label="t('integriq', 'Imow-id')"
				:helperText="
					t(
						'integriq',
						'Matched first. For example nl.imow-gm0000.activiteit.Bouwen.',
					)
				"
				data-testid="dso-activity-imow-id" />
			<NcTextField
				v-model="draft.activityId"
				:label="t('integriq', 'Activity id')"
				:helperText="t('integriq', 'Matched when a verzoek has no imow-id.')"
				data-testid="dso-activity-activity-id" />

			<h4>{{ t('integriq', 'Case types') }}</h4>
			<div
				v-for="(caseType, index) in draft.caseTypes"
				:key="'case-type-' + index"
				class="dso-activity-dialog__line"
				data-testid="dso-activity-case-type">
				<NcTextField
					v-model="caseType.reference"
					:label="t('integriq', 'Case type reference')"
					data-testid="dso-activity-case-type-reference" />
				<NcTextField
					v-model="caseType.title"
					:label="t('integriq', 'Case type name')"
					data-testid="dso-activity-case-type-title" />
				<NcTextField
					v-model="caseType.department"
					:label="t('integriq', 'Department')"
					data-testid="dso-activity-case-type-department" />
				<NcButton
					variant="tertiary"
					:disabled="draft.caseTypes.length === 1"
					:aria-label="t('integriq', 'Remove this case type')"
					@click="draft.caseTypes.splice(index, 1)">
					{{ t('integriq', 'Remove') }}
				</NcButton>
			</div>
			<NcButton
				variant="secondary"
				data-testid="dso-activity-add-case-type"
				@click="draft.caseTypes.push(emptyCaseType())">
				{{ t('integriq', 'Add case type') }}
			</NcButton>

			<NcSelect
				:modelValue="strategyOption(draft.samenloopStrategy)"
				:options="strategyOptions"
				:inputLabel="t('integriq', 'Samenloop')"
				:clearable="false"
				label="label"
				data-testid="dso-activity-samenloop"
				@update:modelValue="
					(option) => (draft.samenloopStrategy = option?.id || 'deelzaken')
				" />

			<h4>{{ t('integriq', 'Samenloop rules') }}</h4>
			<p class="dso-activity-dialog__hint">
				{{
					t(
						'integriq',
						'A rule decides what happens when this activity arrives together with one other activity.',
					)
				}}
			</p>
			<div
				v-for="(rule, index) in draft.samenloopRules"
				:key="'rule-' + index"
				class="dso-activity-dialog__line"
				data-testid="dso-activity-rule">
				<NcTextField
					v-model="rule.withImowId"
					:label="t('integriq', 'Imow-id of the other activity')"
					data-testid="dso-activity-rule-imow-id" />
				<NcSelect
					:modelValue="strategyOption(rule.strategy)"
					:options="strategyOptions"
					:inputLabel="t('integriq', 'Samenloop for this pair')"
					:clearable="false"
					label="label"
					@update:modelValue="
						(option) => (rule.strategy = option?.id || 'deelzaken')
					" />
				<NcButton
					variant="tertiary"
					:aria-label="t('integriq', 'Remove this rule')"
					@click="draft.samenloopRules.splice(index, 1)">
					{{ t('integriq', 'Remove') }}
				</NcButton>
			</div>
			<NcButton
				variant="secondary"
				data-testid="dso-activity-add-rule"
				@click="
					draft.samenloopRules.push({
						withImowId: '',
						strategy: 'gecombineerd',
					})
				">
				{{ t('integriq', 'Add samenloop rule') }}
			</NcButton>

			<NcCheckboxRadioSwitch
				v-model="draft.isActive"
				type="switch"
				data-testid="dso-activity-active">
				{{ t('integriq', 'Active') }}
			</NcCheckboxRadioSwitch>
			<NcTextField
				v-model="draft.note"
				:label="t('integriq', 'Note')"
				data-testid="dso-activity-note" />

			<NcNoteCard
				v-for="problem in shownProblems"
				:key="problem"
				type="warning">
				{{ problem }}
			</NcNoteCard>
			<NcNoteCard v-if="error" type="error" data-testid="dso-activity-error">
				{{ error }}
			</NcNoteCard>

			<div class="dso-activity-dialog__actions">
				<NcButton variant="tertiary" @click="close">
					{{ t('integriq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="saving"
					data-testid="dso-activity-save"
					@click="save">
					{{ t('integriq', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import {
	draftFromRow,
	draftProblems,
	emptyCaseType,
	payloadFromDraft,
} from '../views/admin/dsoActivityMapping.js'

export default {
	name: 'DsoActivityMappingDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		open: { type: Boolean, default: false },
		row: { type: Object, default: () => ({}) },
		error: { type: String, default: '' },
		saving: { type: Boolean, default: false },
	},

	emits: ['close', 'save'],

	data() {
		return {
			draft: draftFromRow(this.row),
			tried: false,
		}
	},

	computed: {
		strategyOptions() {
			return [
				{
					id: 'deelzaken',
					label: t(
						'integriq',
						'Deelzaken: one case per activity under a main case',
					),
				},
				{
					id: 'gecombineerd',
					label: t('integriq', 'Gecombineerd: one combined case'),
				},
			]
		},

		shownProblems() {
			return this.tried ? draftProblems(this.draft) : []
		},
	},

	watch: {
		row(row) {
			this.draft = draftFromRow(row)
			this.tried = false
		},
	},

	methods: {
		t,
		emptyCaseType,

		/**
		 * The select option for a strategy id.
		 *
		 * @param {string} id The strategy.
		 * @return {object}
		 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-4.1
		 */
		strategyOption(id) {
			return (
				this.strategyOptions.find((option) => option.id === id)
				|| this.strategyOptions[0]
			)
		},

		/**
		 * Emit the payload when the draft is one the schema accepts.
		 *
		 * @return {void}
		 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-4.1
		 */
		save() {
			this.tried = true
			if (draftProblems(this.draft).length > 0) {
				return
			}
			this.$emit('save', {
				id: this.draft.id,
				payload: payloadFromDraft(this.draft),
			})
		},

		/**
		 * Close the dialog.
		 *
		 * @return {void}
		 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-4.1
		 */
		close() {
			this.$emit('close')
		},

		/**
		 * Close when NcDialog closes itself.
		 *
		 * @param {boolean} open Whether the dialog is open.
		 * @return {void}
		 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-4.1
		 */
		onOpenChanged(open) {
			if (!open) {
				this.close()
			}
		},
	},
}
</script>

<style scoped>
.dso-activity-dialog {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.dso-activity-dialog__line {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
}

.dso-activity-dialog__hint {
	color: var(--color-text-maxcontrast);
}

.dso-activity-dialog__actions {
	display: flex;
	justify-content: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
