<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
  IntakeMessageRowActions: the row actions of a message in the intake inbox.

  Wired through `pages[].slots["row-actions"]` on IntakeMessages.
  - "Route again" on a held or failed message. Routing runs once, on arrival,
    so a message held before its rule existed stays held until someone chooses
    this. It posts to `POST /api/intake/messages/{id}/route`.
  - "Assign to case" on a held or routed message, for a handler with
    `intake.assign`: the handler names the case, and
    `POST /api/intake/messages/{id}/assign` links the message and its
    attachments to it and records who assigned it.
  - "Reply" on a message from a channel that can reply, for a handler with
    `intake.reply`: `POST /api/intake/messages/{id}/reply` sends the text on
    the channel the message arrived on.
  A user without the right does not see the action; which rights the user has
  is asked once per page load (`GET /api/intake/messages/rights`). Each action
  reads the inbox again afterwards.

  @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-held-message-is-routed-again-on-request-with-its-files-req-ire-003
  @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
-->
<template>
	<div v-if="routable || assignable || answerable" class="intakeMessageRowActions">
		<NcActions :aria-label="t('integriq', 'Message actions')">
			<NcActionButton
				v-if="routable"
				:closeAfterClick="true"
				:disabled="routing"
				data-testid="intake-message-route-again"
				@click="onRouteAgain">
				<template #icon>
					<SignDirectionIcon :size="20" />
				</template>
				{{ t('integriq', 'Route again') }}
			</NcActionButton>
			<NcActionButton
				v-if="assignable"
				:closeAfterClick="true"
				data-testid="intake-message-assign"
				@click="openDialog('assign')">
				<template #icon>
					<BriefcaseArrowLeftRightOutlineIcon :size="20" />
				</template>
				{{ t('integriq', 'Assign to case') }}
			</NcActionButton>
			<NcActionButton
				v-if="answerable"
				:closeAfterClick="true"
				data-testid="intake-message-reply"
				@click="openDialog('reply')">
				<template #icon>
					<ReplyOutlineIcon :size="20" />
				</template>
				{{ t('integriq', 'Reply') }}
			</NcActionButton>
		</NcActions>

		<NcDialog
			v-if="dialog !== ''"
			:name="dialog === 'assign' ? t('integriq', 'Assign to case') : t('integriq', 'Reply')"
			:noClose="sending"
			data-testid="intake-message-action-dialog"
			@closing="closeDialog">
			<NcTextField
				v-if="dialog === 'assign'"
				v-model="caseRef"
				:label="t('integriq', 'Case reference')"
				data-testid="intake-message-case-ref" />
			<NcTextArea
				v-else
				v-model="replyText"
				:label="t('integriq', 'Your reply')"
				data-testid="intake-message-reply-text" />
			<template #actions>
				<NcButton :disabled="sending" @click="closeDialog">
					{{ t('integriq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="sending || !canSubmit"
					data-testid="intake-message-action-submit"
					@click="onSubmit">
					{{ dialog === 'assign' ? t('integriq', 'Assign') : t('integriq', 'Send') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcActionButton, NcActions, NcButton, NcDialog, NcTextArea, NcTextField } from '@nextcloud/vue'
import BriefcaseArrowLeftRightOutlineIcon from 'vue-material-design-icons/BriefcaseArrowLeftRightOutline.vue'
import ReplyOutlineIcon from 'vue-material-design-icons/ReplyOutline.vue'
import SignDirectionIcon from 'vue-material-design-icons/SignDirection.vue'
import { refusalOf, routeAgain } from '../../modals/v2/intakeRuleApi.js'
import { ruleId } from '../../modals/v2/intakeRuleDraft.js'
import { refreshLogPage } from '../callLog/refreshLogPage.js'
import {
	assignMessage,
	fetchInboxRights,
	fetchReplyChannels,
	isAnswerable,
	isAssignable,
	replyToMessage,
} from './inboxActions.js'
import { describeOutcome, isRoutable } from './routeAgainOutcome.js'

export default {
	name: 'IntakeMessageRowActions',
	components: {
		BriefcaseArrowLeftRightOutlineIcon,
		NcActionButton,
		NcActions,
		NcButton,
		NcDialog,
		NcTextArea,
		NcTextField,
		ReplyOutlineIcon,
		SignDirectionIcon,
	},

	props: {
		row: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			routing: false,
			rights: { assign: false, reply: false },
			replyChannels: new Set(),
			dialog: '',
			caseRef: '',
			replyText: '',
			sending: false,
		}
	},

	computed: {
		/**
		 * Whether this message can be routed again.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-held-message-is-routed-again-on-request-with-its-files-req-ire-003
		 */
		routable() {
			return isRoutable(this.row) && ruleId(this.row) !== ''
		},

		/**
		 * Whether the user may assign this message to a case.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
		 */
		assignable() {
			return this.rights.assign && isAssignable(this.row) && ruleId(this.row) !== ''
		},

		/**
		 * Whether the user may reply to this message on its channel.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
		 */
		answerable() {
			return this.rights.reply && isAnswerable(this.row, this.replyChannels) && ruleId(this.row) !== ''
		},

		/**
		 * Whether the open dialog has something to send.
		 *
		 * @return {boolean}
		 */
		canSubmit() {
			const value = this.dialog === 'assign' ? this.caseRef : this.replyText
			return value.trim() !== ''
		},
	},

	created() {
		this.loadRights()
	},

	methods: {
		t,
		/**
		 * Read which actions the user may use, and which channels can reply.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
		 */
		async loadRights() {
			this.rights = await fetchInboxRights()
			if (this.rights.reply) {
				this.replyChannels = await fetchReplyChannels()
			}
		},

		/**
		 * Open the assign or the reply dialog, empty.
		 *
		 * @param {string} kind `assign` or `reply`.
		 * @return {void}
		 */
		openDialog(kind) {
			this.caseRef = ''
			this.replyText = ''
			this.dialog = kind
		},

		/**
		 * Close the dialog, unless something is being sent.
		 *
		 * @return {void}
		 */
		closeDialog() {
			if (!this.sending) {
				this.dialog = ''
			}
		},

		/**
		 * Route the message again and say what happened.
		 *
		 * @return {Promise<boolean>} Whether it was routed.
		 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-held-message-is-routed-again-on-request-with-its-files-req-ire-003
		 */
		async onRouteAgain() {
			this.routing = true
			let outcome
			try {
				outcome = describeOutcome(await routeAgain(ruleId(this.row)))
			} catch (error) {
				outcome = { routed: false, text: refusalOf(error, t('integriq', 'The message could not be routed again.')) }
			} finally {
				this.routing = false
			}
			if (outcome.routed) {
				showSuccess(outcome.text)
			} else {
				showError(outcome.text)
			}
			refreshLogPage(this)
			return outcome.routed
		},

		/**
		 * Send what the open dialog holds and say what happened.
		 *
		 * @return {Promise<boolean>} Whether it went through.
		 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
		 */
		async onSubmit() {
			if (!this.canSubmit) {
				return false
			}
			this.sending = true
			let done = false
			try {
				if (this.dialog === 'assign') {
					const result = await assignMessage(ruleId(this.row), this.caseRef.trim())
					showSuccess(t('integriq', 'Assigned to {case}', { case: result?.targetRef || this.caseRef.trim() }))
					done = true
				} else {
					const result = await replyToMessage(ruleId(this.row), this.replyText)
					done = result?.status === 'sent'
					if (done) {
						showSuccess(t('integriq', 'Reply sent'))
					} else {
						showError(result?.detail || t('integriq', 'The reply was not sent.'))
					}
				}
			} catch (error) {
				const fallback = this.dialog === 'assign'
					? t('integriq', 'The message could not be assigned.')
					: t('integriq', 'The reply was not sent.')
				showError(refusalOf(error, fallback))
			} finally {
				this.sending = false
			}
			if (done) {
				this.dialog = ''
				refreshLogPage(this)
			}
			return done
		},
	},
}
</script>
