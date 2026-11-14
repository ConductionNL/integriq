<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  SourceResidencyDialog: "Set residency" on a source. Mounted once in
  ModalHost; opened from the Sources page row action and from a row of the
  outbound destinations page.

  An administrator picks the country where the source's data is processed and
  writes down the evidence (a processing agreement, a hosting statement). The
  save stores it with basis `set-by-admin`, the administrator's uid and the
  time, so a residency an app declares never overwrites it.

  @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-a-source-records-its-residency-with-a-basis-and-evidence-req-res-001
-->
<template>
	<NcDialog
		:open="open"
		:name="t('integriq', 'Set residency')"
		size="normal"
		data-testid="source-residency-dialog"
		@update:open="onOpenChanged">
		<div class="source-residency">
			<p class="source-residency__muted">
				{{
					t(
						'integriq',
						'Where does {source} process the data integriq sends it? Record the country and the evidence for it.',
						{ source: sourceName },
					)
				}}
			</p>

			<NcSelect
				v-model="country"
				data-testid="source-residency-country"
				:inputLabel="t('integriq', 'Country')"
				:options="options"
				:clearable="false" />

			<NcTextField
				v-model="evidence"
				data-testid="source-residency-evidence"
				:label="t('integriq', 'Evidence')"
				:helperText="
					t(
						'integriq',
						'For example the processing agreement or the hosting statement that names the location.',
					)
				" />

			<p
				v-if="current"
				class="source-residency__muted"
				data-testid="source-residency-current">
				{{ currentText }}
			</p>

			<NcNoteCard
				v-if="error"
				type="error"
				data-testid="source-residency-error">
				{{ error }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton @click="close">
				{{ t('integriq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				data-testid="source-residency-save"
				:disabled="!canSave"
				@click="save">
				{{ t('integriq', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { getLanguage, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { rowId } from '../handlers/rowId.js'
import closableDialog from '../mixins/closableDialog.js'
import { countryOptions } from '../views/EgressDestinations/residency.js'

export default {
	name: 'SourceResidencyDialog',

	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	mixins: [closableDialog],

	props: {
		/** Whether the dialog is shown. */
		open: {
			type: Boolean,
			default: false,
		},

		/** The source: an index row, or `{ id, name, residency }`. */
		source: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			country: null,
			evidence: '',
			busy: false,
			error: '',
		}
	},

	computed: {
		/** @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-a-source-records-its-residency-with-a-basis-and-evidence-req-res-001 */
		options() {
			return countryOptions(getLanguage())
		},

		/** @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-a-source-records-its-residency-with-a-basis-and-evidence-req-res-001 */
		sourceName() {
			return this.source?.name || t('integriq', 'this source')
		},

		/** @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-a-source-records-its-residency-with-a-basis-and-evidence-req-res-001 */
		current() {
			const residency = this.source?.residency
			return residency && typeof residency.country === 'string' && residency.country !== '' ? residency : null
		},

		/** @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-a-source-records-its-residency-with-a-basis-and-evidence-req-res-001 */
		currentText() {
			if (this.current?.basis === 'set-by-admin') {
				return t('integriq', 'Now {country}, set by an administrator.', { country: this.current.country })
			}
			return t('integriq', 'Now {country}, declared by the app that uses this source. Saving here replaces it, and the app can no longer change it.', {
				country: this.current?.country ?? '',
			})
		},

		/** @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-a-source-records-its-residency-with-a-basis-and-evidence-req-res-001 */
		canSave() {
			return !this.busy && this.country !== null && this.evidence.trim() !== '' && rowId(this.source) !== null
		},
	},

	watch: {
		open: {
			immediate: true,
			handler(isOpen) {
				if (isOpen) {
					this.reset()
				}
			},
		},
	},

	methods: {
		t,

		/**
		 * Start from the source's current residency.
		 *
		 * @return {void}
		 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-a-source-records-its-residency-with-a-basis-and-evidence-req-res-001
		 */
		reset() {
			const code = this.current?.country ?? null
			this.country = this.options.find((option) => option.id === code) ?? null
			this.evidence = this.current?.evidence ?? ''
			this.error = ''
			this.busy = false
		},

		/**
		 * Store the residency as the administrator's.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-a-source-records-its-residency-with-a-basis-and-evidence-req-res-001
		 */
		async save() {
			if (!this.canSave) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				const id = encodeURIComponent(String(rowId(this.source)))
				const response = await axios.put(generateUrl(`/apps/integriq/api/sources/${id}/residency`), {
					country: this.country.id,
					evidence: this.evidence.trim(),
				})
				this.$emit('saved', { source: this.source, residency: response.data?.residency ?? null })
				this.close()
			} catch (exception) {
				this.error = exception?.response?.data?.error || t('integriq', 'The residency could not be saved.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.source-residency {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.source-residency__muted {
	color: var(--color-text-maxcontrast);
}
</style>
