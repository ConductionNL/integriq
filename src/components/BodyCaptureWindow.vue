<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  BodyCaptureWindow: the investigation window on the source detail page
  (manifest `config.bodyWidgets`, component `BodyCaptureWindow`;
  outbound-call-log-investigation-window-page-and-allowlist, Task 6).

  Reads the source CnDetailPage already loaded off `cnSectionContext`. While a
  window is open it says "Bodies are stored until {time}" and offers "Stop
  early" (`DELETE /api/sources/{id}/body-capture`). Without one, an
  administrator gets "Open investigation window", which opens
  BodyCaptureWindowDialog. A user who is not an administrator sees the state
  and no button; the endpoints refuse them as well (REQ-OCD-008).

  @spec openspec/changes/outbound-call-log-investigation-window-page-and-allowlist/specs/outbound-call-log/spec.md#requirement-the-source-page-shows-the-window-and-says-when-no-body-was-stored-req-ocd-011
-->
<template>
	<div class="body-capture" data-testid="body-capture-window">
		<div class="body-capture__row">
			<span v-if="isOpen" data-testid="body-capture-state">
				{{ t('integriq', 'Bodies are stored until {time}', { time: untilLabel }) }}
			</span>
			<span v-else data-testid="body-capture-state">
				{{ t('integriq', 'No investigation window is open. Call bodies are not stored.') }}
			</span>
			<span v-if="isOpen && reason" class="body-capture__reason">
				{{ t('integriq', 'Reason: {reason}', { reason }) }}
			</span>
			<template v-if="isAdmin && objectId">
				<NcButton
					v-if="isOpen"
					:disabled="busy"
					data-testid="body-capture-stop"
					@click="stop">
					{{ t('integriq', 'Stop early') }}
				</NcButton>
				<NcButton
					v-else
					:disabled="busy"
					data-testid="body-capture-open"
					@click="dialogOpen = true">
					{{ t('integriq', 'Open investigation window') }}
				</NcButton>
			</template>
		</div>

		<BodyCaptureWindowDialog
			v-if="dialogOpen"
			:open="dialogOpen"
			:sourceId="objectId"
			@opened="onOpened"
			@close="dialogOpen = false" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import BodyCaptureWindowDialog from '../dialogs/BodyCaptureWindowDialog.vue'

export default {
	name: 'BodyCaptureWindow',

	components: { BodyCaptureWindowDialog, NcButton },

	inject: {
		cnSectionContext: {
			from: 'cnSectionContext',
			default: () => ({ value: { objectId: null, object: null } }),
		},
	},

	data() {
		return {
			busy: false,
			dialogOpen: false,
			// The window as the last open or stop answered it, over the loaded
			// source, until the page reloads.
			local: null,
			now: Date.now(),
		}
	},

	computed: {
		/**
		 * The loaded source and its object id, off the injected section context.
		 *
		 * @return {{object: object, objectId: string|null}}
		 * @spec openspec/changes/outbound-call-log-investigation-window-page-and-allowlist/specs/outbound-call-log/spec.md#requirement-the-source-page-shows-the-window-and-says-when-no-body-was-stored-req-ocd-011
		 */
		context() {
			const ctx = this.cnSectionContext
			const value = ctx && ctx.value !== undefined ? ctx.value : ctx
			const object = (value && value.object) || {}
			return {
				object,
				objectId: (value && value.objectId) || object.uuid || object.id || null,
			}
		},

		objectId() {
			return this.context.objectId
		},

		/**
		 * The window's end and reason: the last answer, else the loaded source.
		 *
		 * @return {{until: string, reason: string}}
		 * @spec openspec/changes/outbound-call-log-investigation-window-page-and-allowlist/specs/outbound-call-log/spec.md#requirement-the-source-page-shows-the-window-and-says-when-no-body-was-stored-req-ocd-011
		 */
		window() {
			const from = this.local || this.context.object
			return {
				until: from.bodyCaptureUntil || '',
				reason: from.bodyCaptureReason || '',
			}
		},

		/**
		 * Whether the window ends in the future.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/outbound-call-log-investigation-window-page-and-allowlist/specs/outbound-call-log/spec.md#requirement-the-source-page-shows-the-window-and-says-when-no-body-was-stored-req-ocd-011
		 */
		isOpen() {
			const end = Date.parse(this.window.until)
			return Number.isNaN(end) === false && end > this.now
		},

		reason() {
			return this.window.reason
		},

		/**
		 * The window's end in the viewer's locale.
		 *
		 * @return {string}
		 * @spec openspec/changes/outbound-call-log-investigation-window-page-and-allowlist/specs/outbound-call-log/spec.md#requirement-the-source-page-shows-the-window-and-says-when-no-body-was-stored-req-ocd-011
		 */
		untilLabel() {
			return new Date(this.window.until).toLocaleString()
		},

		/**
		 * Whether the viewer is a Nextcloud administrator.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/outbound-call-log-investigation-window-page-and-allowlist/specs/outbound-call-log/spec.md#requirement-the-source-page-shows-the-window-and-says-when-no-body-was-stored-req-ocd-011
		 */
		isAdmin() {
			return typeof window.OC?.isUserAdmin === 'function' && window.OC.isUserAdmin() === true
		},
	},

	methods: {
		t,

		/**
		 * Take the opened window from the dialog's answer.
		 *
		 * @param {object} data The server's answer.
		 * @spec openspec/changes/outbound-call-log-investigation-window-page-and-allowlist/specs/outbound-call-log/spec.md#requirement-the-source-page-shows-the-window-and-says-when-no-body-was-stored-req-ocd-011
		 */
		onOpened(data) {
			this.local = data || {}
			this.now = Date.now()
			this.dialogOpen = false
			showSuccess(t('integriq', 'Investigation window opened'))
		},

		/**
		 * Close the window now.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/outbound-call-log-investigation-window-page-and-allowlist/specs/outbound-call-log/spec.md#requirement-the-source-page-shows-the-window-and-says-when-no-body-was-stored-req-ocd-011
		 */
		async stop() {
			this.busy = true
			try {
				const res = await axios.delete(
					generateUrl(`/apps/integriq/api/sources/${this.objectId}/body-capture`),
				)
				this.local = res.data || {}
				this.now = Date.now()
				showSuccess(t('integriq', 'Investigation window closed'))
			} catch (err) {
				const detail = err?.response?.data?.error || ''
				showError(
					t('integriq', 'The investigation window could not be closed.')
						+ (detail ? ` ${detail}` : ''),
				)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.body-capture {
	padding: 8px 0;
}

.body-capture__row {
	display: flex;
	gap: 12px;
	align-items: center;
	flex-wrap: wrap;
}

.body-capture__reason {
	color: var(--color-text-maxcontrast);
}
</style>
