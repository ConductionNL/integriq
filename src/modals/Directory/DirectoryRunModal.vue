<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  DirectoryRunModal — run or preview one directory connection, and read what it
  changed.

  The modal owns the request rather than the handler, for the reason
  RunActionModal already gives: the run is gated. A run whose removals cross the
  connection's deletion ratio stops before writing and says which ratio it hit,
  and Confirm removals is the administrator taking that decision. A handler that
  fired and toasted could neither show the guard nor offer the confirmation.

  A preview is the same request with dryRun set, rendered by the same summary,
  so an administrator comparing the two does not have to translate between two
  layouts.

  @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-run-can-be-previewed-and-a-large-removal-is-guarded-req-ds-005
-->
<template>
	<NcModal
		v-if="open && source"
		labelId="directoryRunModal"
		size="normal"
		@close="onClose">
		<div class="cn-directory-run" data-testid="directory-run-modal">
			<h2>
				{{
					mode === 'preview'
						? t('integriq', 'Preview run')
						: t('integriq', 'Run now')
				}}
			</h2>
			<p class="cn-directory-run__subject">{{ subjectName }}</p>

			<NcNoteCard v-if="mode === 'preview'" type="info">
				<p>{{ t('integriq', 'Preview only. Nothing was changed.') }}</p>
			</NcNoteCard>

			<NcLoadingIcon v-if="busy" :size="32" />

			<NcNoteCard v-else-if="error" type="error">
				<p data-testid="directory-run-error">{{ error }}</p>
			</NcNoteCard>

			<DirectoryRunSummary v-else-if="record" :record="record" />

			<div class="cn-directory-run__actions">
				<NcButton
					v-if="record && record.guard"
					variant="primary"
					:disabled="busy"
					data-testid="directory-run-confirm"
					@click="start({ confirmRemovals: true })">
					{{ t('integriq', 'Confirm removals') }}
				</NcButton>
				<NcButton
					:disabled="busy"
					data-testid="directory-run-close"
					@click="onClose">
					{{ t('integriq', 'Close') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcModal, NcNoteCard } from '@nextcloud/vue'
import DirectoryRunSummary from '../../components/DirectoryRunSummary.vue'

export default {
	name: 'DirectoryRunModal',

	components: {
		DirectoryRunSummary,
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		source: {
			type: Object,
			default: null,
		},

		mode: {
			type: String,
			default: 'run',
		},
	},

	emits: ['close'],

	data() {
		return {
			busy: false,
			record: null,
			error: '',
		}
	},

	computed: {
		/**
		 * The connection name shown under the title.
		 *
		 * @return {string} The name, or the empty string.
		 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
		 */
		subjectName() {
			return this.source?.name || this.source?.id || ''
		},
	},

	watch: {
		/**
		 * Start a run when the modal opens, on a summary from no earlier run.
		 *
		 * The mode decides which run it is: "preview" asks for a dry run, which
		 * writes nothing and reports both sides.
		 *
		 * @param {boolean} isOpen Whether the modal has just been opened.
		 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-run-can-be-previewed-and-a-large-removal-is-guarded-req-ds-005
		 */
		open(isOpen) {
			if (isOpen) {
				this.record = null
				this.error = ''
				this.start({ dryRun: this.mode === 'preview' })
			}
		},
	},

	methods: {
		t,

		/**
		 * Run or preview the connection.
		 *
		 * @param {object} options Run options: dryRun, confirmRemovals.
		 * @return {Promise<void>} Resolves once the run record is in place.
		 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-run-can-be-previewed-and-a-large-removal-is-guarded-req-ds-005
		 */
		async start(options) {
			const id = this.source?.id || this.source?.uuid
			if (!id) {
				return
			}

			this.busy = true
			this.error = ''
			try {
				const response = await axios.post(
					generateUrl(
						`/apps/integriq/api/directory/connections/${id}/run`,
					),
					{
						dryRun: options.dryRun === true,
						confirmRemovals: options.confirmRemovals === true,
					},
				)
				this.record = response.data
			} catch {
				this.error = t('integriq', 'The run could not be started.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Close the modal.
		 *
		 * @return {void}
		 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-run-can-be-previewed-and-a-large-removal-is-guarded-req-ds-005
		 */
		onClose() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.cn-directory-run {
	padding: 16px;
}

.cn-directory-run__subject {
	color: var(--color-text-maxcontrast);
}

.cn-directory-run__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-block-start: 12px;
}
</style>
