<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  FilePickupConfigFields: the pickup settings of a synchronization whose
  source type is `sftp` or `ftps`, shown by SyncConfigWidget.

  FilePickup and FilePickupSynchronization read everything from the
  synchronization's `sourceConfig`. Each control writes one key under the
  exact name they read: path, pattern, target, filesFolder, fileFormat,
  csvDelimiter, after, archivePath and stableSeconds. Keys this component does
  not know are kept.

  With target `files` (the default) the files land in the synchronization
  owner's Files, in `filesFolder`; with target `mapping` the records in each
  file (CSV, JSON or XML) go through the synchronization's mapping. Either way
  the remote file is moved or deleted only after that has succeeded.
-->
<template>
	<div class="sync-pickup">
		<NcTextField
			:id="uid + '-path'"
			:label="t('integriq', 'Remote folder')"
			:modelValue="text('path')"
			:helperText="
				t(
					'integriq',
					'The folder on the server to pick files up from, relative to the source\'s root path. Leave empty for the root path itself.',
				)
			"
			@update:modelValue="(value) => write('path', value.trim())" />

		<NcTextField
			:id="uid + '-pattern'"
			:label="t('integriq', 'File name pattern')"
			:modelValue="text('pattern')"
			placeholder="*"
			:helperText="
				t(
					'integriq',
					'Only files whose name matches are picked up, for example besluiten-*.csv. Leave empty for every file.',
				)
			"
			@update:modelValue="(value) => write('pattern', value.trim())" />

		<NcSelect
			:inputId="uid + '-target'"
			:inputLabel="t('integriq', 'Picked-up files go to')"
			:modelValue="targetOption"
			:options="targetOptions"
			:clearable="false"
			@update:modelValue="(option) => write('target', option?.id === 'mapping' ? 'mapping' : '')" />

		<NcTextField
			v-if="target === 'files'"
			:id="uid + '-files-folder'"
			:label="t('integriq', 'Nextcloud folder') + ' *'"
			:modelValue="text('filesFolder')"
			:error="text('filesFolder') === ''"
			:helperText="
				t(
					'integriq',
					'The folder in the Files of this synchronization\'s owner that the files are written to. A file already there is never overwritten.',
				)
			"
			@update:modelValue="(value) => write('filesFolder', value.trim())" />

		<NcSelect
			v-if="target === 'mapping'"
			:inputId="uid + '-file-format'"
			:inputLabel="t('integriq', 'File format')"
			:modelValue="formatOption"
			:options="formatOptions"
			:clearable="false"
			@update:modelValue="(option) => write('fileFormat', option?.id === 'auto' ? '' : option?.id)" />

		<NcTextField
			v-if="target === 'mapping' && (fileFormat === 'csv' || fileFormat === 'auto')"
			:id="uid + '-csv-delimiter'"
			:label="t('integriq', 'CSV delimiter')"
			:modelValue="text('csvDelimiter')"
			:helperText="t('integriq', 'One character between the columns of a CSV file, or the word tab. Default a comma.')"
			@update:modelValue="(value) => write('csvDelimiter', value)" />

		<NcSelect
			:inputId="uid + '-after'"
			:inputLabel="target === 'mapping' ? t('integriq', 'After a file is read') : t('integriq', 'After a file is stored')"
			:modelValue="afterOption"
			:options="afterOptions"
			:clearable="false"
			@update:modelValue="(option) => write('after', option?.id ?? 'move')" />

		<NcTextField
			v-if="after === 'move'"
			:id="uid + '-archive-path'"
			:label="t('integriq', 'Archive folder') + ' *'"
			:modelValue="text('archivePath')"
			:error="text('archivePath') === ''"
			:helperText="
				t(
					'integriq',
					'The folder on the server the file is moved to, relative to the source\'s root path.',
				)
			"
			@update:modelValue="(value) => write('archivePath', value.trim())" />

		<NcTextField
			:id="uid + '-stable-seconds'"
			:label="t('integriq', 'Wait before picking up (seconds)')"
			:modelValue="stableSeconds"
			type="number"
			:helperText="
				t(
					'integriq',
					'A file changed more recently than this is left for the next run, so a file still being written is not fetched half. Default 60.',
				)
			"
			@update:modelValue="writeStableSeconds" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcSelect, NcTextField } from '@nextcloud/vue'

/** What happens to the remote file after it is stored, FilePickup::AFTER. */
export const AFTER_VALUES = Object.freeze(['move', 'delete', 'keep'])

/** Where picked-up files go, FilePickupSynchronization::TARGETS. */
export const TARGET_VALUES = Object.freeze(['files', 'mapping'])

/** The file formats a mapping pickup reads, PickedFileRecords::FORMATS, plus `auto` (by extension). */
export const FORMAT_VALUES = Object.freeze(['auto', 'csv', 'json', 'jsonl', 'xml'])

let fieldSeq = 0

export default {
	name: 'FilePickupConfigFields',

	components: {
		NcSelect,
		NcTextField,
	},

	props: {
		/** The synchronization's sourceConfig. */
		config: { type: Object, default: () => ({}) },
	},

	emits: ['update:config'],

	data() {
		return {
			uid: 'sync-pickup-' + ++fieldSeq,
		}
	},

	computed: {
		/**
		 * The stored after value, `move` when unset, as FilePickup reads it.
		 *
		 * @return {string} move, delete or keep (or an unknown stored value)
		 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
		 */
		after() {
			return String(this.config?.after ?? 'move') || 'move'
		},

		/**
		 * The after choices, labelled.
		 *
		 * @return {object[]} the options
		 * @spec exclude static labels, presentation only
		 */
		afterOptions() {
			const labels = {
				move: t('integriq', 'Move it to the archive folder'),
				delete: t('integriq', 'Delete it from the server'),
				keep: t('integriq', 'Leave it on the server'),
			}
			return AFTER_VALUES.map((id) => ({ id, label: labels[id] }))
		},

		/**
		 * The option for the stored after value; an unknown value is shown as itself.
		 *
		 * @return {object} the option
		 * @spec exclude trivial select-value projection, presentation only
		 */
		afterOption() {
			return (
				this.afterOptions.find((option) => option.id === this.after) ?? {
					id: this.after,
					label: this.after,
				}
			)
		},

		/**
		 * The stored target, `files` when unset, as FilePickupSynchronization reads it.
		 *
		 * @return {string} files or mapping (or an unknown stored value)
		 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
		 */
		target() {
			return String(this.config?.target ?? 'files') || 'files'
		},

		/**
		 * The target choices, labelled.
		 *
		 * @return {object[]} the options
		 * @spec exclude static labels, presentation only
		 */
		targetOptions() {
			const labels = {
				files: t('integriq', 'A folder in Nextcloud Files'),
				mapping: t('integriq', 'The mapping, one object per record'),
			}
			return TARGET_VALUES.map((id) => ({ id, label: labels[id] }))
		},

		/**
		 * The option for the stored target; an unknown value is shown as itself.
		 *
		 * @return {object} the option
		 * @spec exclude trivial select-value projection, presentation only
		 */
		targetOption() {
			return this.targetOptions.find((option) => option.id === this.target) ?? { id: this.target, label: this.target }
		},

		/**
		 * The stored file format, `auto` when unset.
		 *
		 * @return {string} one of FORMAT_VALUES (or an unknown stored value)
		 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
		 */
		fileFormat() {
			return String(this.config?.fileFormat ?? 'auto').toLowerCase() || 'auto'
		},

		/**
		 * The file format choices, labelled.
		 *
		 * @return {object[]} the options
		 * @spec exclude static labels, presentation only
		 */
		formatOptions() {
			const labels = {
				auto: t('integriq', 'By file extension'),
				csv: 'CSV',
				json: 'JSON',
				jsonl: t('integriq', 'JSON lines'),
				xml: 'XML',
			}
			return FORMAT_VALUES.map((id) => ({ id, label: labels[id] }))
		},

		/**
		 * The option for the stored file format; an unknown value is shown as itself.
		 *
		 * @return {object} the option
		 * @spec exclude trivial select-value projection, presentation only
		 */
		formatOption() {
			return this.formatOptions.find((option) => option.id === this.fileFormat) ?? { id: this.fileFormat, label: this.fileFormat }
		},

		/**
		 * The stability window as text for the field.
		 *
		 * @return {string} the seconds, or '' when unset
		 * @spec exclude trivial projection of the form value, presentation only
		 */
		stableSeconds() {
			const value = this.config?.stableSeconds
			return value === undefined || value === null ? '' : String(value)
		},
	},

	methods: {
		t,

		/**
		 * One stored key as text.
		 *
		 * @param {string} key the sourceConfig key
		 * @return {string} the value, or ''
		 * @spec exclude trivial projection of the form value, presentation only
		 */
		text(key) {
			const value = this.config?.[key]
			return value === undefined || value === null ? '' : String(value)
		},

		/**
		 * Write one key and emit the whole configuration. An empty value
		 * removes the key, so the pickup's own default applies.
		 *
		 * @param {string} key the sourceConfig key FilePickup reads
		 * @param {string|number} value the value
		 * @return {void}
		 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
		 */
		write(key, value) {
			const next = { ...(this.config ?? {}) }
			if (value === '' || value === null || value === undefined) {
				delete next[key]
			} else {
				next[key] = value
			}
			this.$emit('update:config', next)
		},

		/**
		 * Write the stability window as a whole, non-negative number of seconds.
		 *
		 * @param {string} value the typed value
		 * @return {void}
		 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
		 */
		writeStableSeconds(value) {
			const trimmed = String(value ?? '').trim()
			if (trimmed === '') {
				this.write('stableSeconds', '')
				return
			}
			const seconds = Math.max(0, Math.floor(Number(trimmed)))
			if (Number.isFinite(seconds)) {
				this.write('stableSeconds', seconds)
			}
		},
	},
}
</script>

<style scoped>
.sync-pickup {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
</style>
