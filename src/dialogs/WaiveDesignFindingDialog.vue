<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  WaiveDesignFindingDialog: waive one API design finding with a reason
  (gateway-api-design-rules-check, task 4).

  Sends `POST /api/products/design-findings/{findingId}/waive` with the
  reason. The server refuses an empty reason; its refusal is shown as is.
  Emits `waived` with the finding the server returned, and `close`.

  @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-a-product-with-an-open-error-is-not-published-req-adrc-003
-->
<template>
	<NcDialog
		:open="open"
		:name="t('integriq', 'Waive finding')"
		size="normal"
		data-testid="waive-finding-dialog"
		@update:open="onOpenChanged">
		<div class="waive-finding-dialog">
			<p v-if="finding">
				<strong>{{ finding.ruleId }}</strong>: {{ finding.message }}
			</p>
			<p>
				{{ t('integriq', 'A waived error no longer stops the product from being published. The reason is kept with the finding.') }}
			</p>

			<NcTextField
				v-model="reason"
				:label="t('integriq', 'Reason')"
				data-testid="waive-finding-reason" />

			<NcNoteCard v-if="error" type="error" data-testid="waive-finding-error">
				{{ error }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton :disabled="busy" @click="close">
				{{ t('integriq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy || reason.trim() === ''"
				data-testid="waive-finding-submit"
				@click="submit">
				{{ t('integriq', 'Waive') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcNoteCard, NcTextField } from '@nextcloud/vue'
import { findingId } from '../views/ApiProducts/apiDesignFindings.js'

export default {
	name: 'WaiveDesignFindingDialog',

	components: { NcButton, NcDialog, NcNoteCard, NcTextField },

	props: {
		/** Whether the dialog is shown. */
		open: {
			type: Boolean,
			default: false,
		},

		/** The finding to waive. */
		finding: {
			type: Object,
			default: null,
		},
	},

	emits: ['waived', 'close'],

	data() {
		return {
			reason: '',
			busy: false,
			error: '',
		}
	},

	methods: {
		t,

		/**
		 * Waive the finding with the reason given.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-a-product-with-an-open-error-is-not-published-req-adrc-003
		 */
		async submit() {
			this.busy = true
			this.error = ''
			try {
				const res = await axios.post(
					generateUrl(`/apps/integriq/api/products/design-findings/${encodeURIComponent(findingId(this.finding))}/waive`),
					{ reason: this.reason.trim() },
				)
				this.reason = ''
				this.$emit('waived', res.data)
			} catch (err) {
				this.error = err?.response?.data?.error || t('integriq', 'The finding could not be waived.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Close without waiving.
		 *
		 * @return {void}
		 * @spec openspec/changes/gateway-api-design-rules-check/tasks.md
		 */
		close() {
			this.error = ''
			this.$emit('close')
		},

		/**
		 * Close when the dialog asks to.
		 *
		 * @param {boolean} value Whether it should stay open
		 * @return {void}
		 * @spec openspec/changes/gateway-api-design-rules-check/tasks.md
		 */
		onOpenChanged(value) {
			if (value === false) {
				this.close()
			}
		},
	},
}
</script>

<style scoped>
.waive-finding-dialog {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}
</style>
