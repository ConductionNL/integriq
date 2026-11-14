<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  MailImportAction: "Import file (.eml or .msg)" on the mail and intake inbox
  pages, offered only to a user who holds the mail import right.

  It asks `GET /api/mail-intake/import/options` first, so a user without the
  right sees no action rather than one that answers 403. After an import the
  page is read again, so the new message is listed.

  @spec openspec/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
-->
<template>
	<div v-if="allowed" class="mailImportAction">
		<NcButton data-testid="mail-import-file" @click="open = true">
			<template #icon>
				<EmailArrowLeftOutlineIcon :size="20" />
			</template>
			{{ t('integriq', 'Import file (.eml or .msg)') }}
		</NcButton>
		<ImportMailFileModal
			:open="open"
			:mailboxes="mailboxes"
			@imported="refresh"
			@close="open = false" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import EmailArrowLeftOutlineIcon from 'vue-material-design-icons/EmailArrowLeftOutline.vue'
import ImportMailFileModal from '../../modals/ImportMailFileModal.vue'
import { fetchMailImportOptions } from '../../modals/v2/intakeRuleApi.js'
import { refreshLogPage } from '../callLog/refreshLogPage.js'

export default {
	name: 'MailImportAction',

	components: { EmailArrowLeftOutlineIcon, ImportMailFileModal, NcButton },

	data() {
		return {
			allowed: false,
			mailboxes: [],
			open: false,
		}
	},

	mounted() {
		this.loadOptions()
	},

	methods: {
		t,

		/**
		 * Ask whether to offer the import, and under which mailboxes.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
		 */
		async loadOptions() {
			try {
				const options = await fetchMailImportOptions()
				this.allowed = options.allowed
				this.mailboxes = options.mailboxes
			} catch {
				this.allowed = false
				this.mailboxes = []
			}
		},

		/**
		 * List the imported message.
		 *
		 * @return {void}
		 */
		refresh() {
			refreshLogPage(this)
		},
	},
}
</script>
