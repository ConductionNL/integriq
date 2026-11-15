// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * Why a replay or discard failed, with the server's or the client's reason.
 *
 * @param {string} verb 'replay' or 'discard'
 * @param {Error} err the failed request
 * @return {string} the translated message
 */
function verbFailedText(verb, err) {
	const detail = err?.response?.data?.error || err?.message || ''
	const text =
		verb === 'replay'
			? t('integriq', 'Replay failed')
			: t('integriq', 'Discard failed')
	return text + (detail ? `: ${detail}` : '')
}

/**
 * Shared behaviour of the dead-letter detail modals: the attempt timeline,
 * the payload viewer and the confirmed replay or discard. The component
 * provides `record` (the dead-letter row), `verbPath(id, verb)` (the
 * app-relative URL of the verb) and `doneText(verb)` (the success toast).
 *
 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-inspection-req-dlr-008
 */
export default {
	emits: ['close', 'changed'],

	data() {
		return {
			confirming: null,
			busy: false,
		}
	},

	computed: {
		/**
		 * The attempt audit trail for the timeline.
		 *
		 * @return {Array}
		 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-inspection-req-dlr-008
		 */
		attempts() {
			return Array.isArray(this.record?.attempts) ? this.record.attempts : []
		},

		/**
		 * Pretty-printed payload for the viewer.
		 *
		 * @return {string}
		 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-inspection-req-dlr-008
		 */
		prettyPayload() {
			try {
				return JSON.stringify(this.record?.payload ?? {}, null, 2)
			} catch {
				return String(this.record?.payload ?? '')
			}
		},
	},

	methods: {
		/**
		 * Commit the confirmed verb (replay/discard) on this row.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-audited-manual-replay-of-a-dead-lettered-sync-item-req-dlr-009
		 */
		async commit() {
			const verb = this.confirming
			const id = this.record?.uuid || this.record?.id
			if (!verb || !id) {
				return
			}
			try {
				await this.send(verb, id)
			} catch (err) {
				showError(verbFailedText(verb, err))
			} finally {
				this.busy = false
				this.confirming = null
			}
		},

		/**
		 * Mark the modal busy, send the verb, then announce it and tell the parent.
		 *
		 * @param {string} verb 'replay' or 'discard'
		 * @param {string|number} id the row's uuid or id
		 * @return {Promise<void>}
		 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-audited-manual-replay-of-a-dead-lettered-sync-item-req-dlr-009
		 */
		async send(verb, id) {
			this.busy = true
			await axios.post(generateUrl(this.verbPath(id, verb)))
			showSuccess(this.doneText(verb))
			this.$emit('changed')
			this.$emit('close')
		},
	},
}
