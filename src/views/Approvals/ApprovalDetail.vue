<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  ApprovalDetail — a single approval_request's detail + approve/reject
  actions (manifest `type: custom`, `component: ApprovalDetail`, route
  `/approvals/:id`).

  Approve and Reject are standard NcButton components (not icon-only), so
  they are keyboard-reachable per WCAG AA. Reject reveals an inline required
  comment field and stays disabled until a non-empty comment is entered
  (approval-workflow REQ-004 / REQ-007). Approve's comment is optional. Both
  hit the bespoke two-layer-authorized `/api/approvals/{id}/{approve|reject}`
  routes. No inline NcModal/NcDialog is used, so the interaction stays within
  the page without tripping the modal-isolation gate.

  @spec openspec/specs/approval-workflow/spec.md
-->
<template>
	<div class="approvalDetail">
		<NcButton type="tertiary" class="approvalDetail__back" @click="goBack">
			{{ t('openconnector', 'Back to approvals') }}
		</NcButton>

		<NcLoadingIcon v-if="loading" :size="32" class="approvalDetail__loading" />

		<div v-else-if="request" class="approvalDetail__body">
			<div class="approvalDetail__meta">
				<h2>{{ t('openconnector', 'Approval request') }}</h2>
				<span
					class="approvalDetail__badge"
					:class="`approvalDetail__badge--${request.status}`">
					{{ request.status }}
				</span>
			</div>

			<dl class="approvalDetail__fields">
				<dt>{{ t('openconnector', 'Approver group') }}</dt>
				<dd>{{ request.approverGroup || '—' }}</dd>
				<dt>{{ t('openconnector', 'Requester') }}</dt>
				<dd>{{ request.requester || '—' }}</dd>
				<dt>{{ t('openconnector', 'Created') }}</dt>
				<dd>{{ request.createdAt || '—' }}</dd>
				<dt>{{ t('openconnector', 'Expires') }}</dt>
				<dd>{{ request.expiresAt || '—' }}</dd>
				<dt>{{ t('openconnector', 'On reject') }}</dt>
				<dd>{{ request.onReject || '—' }}</dd>
				<dt>{{ t('openconnector', 'On timeout') }}</dt>
				<dd>{{ request.onTimeout || '—' }}</dd>
				<template v-if="request.snapshotPreview">
					<dt>{{ t('openconnector', 'Request') }}</dt>
					<dd>
						{{ request.snapshotPreview.method || '—' }}
						{{ request.snapshotPreview.path || '' }}
					</dd>
				</template>
			</dl>

			<!-- Audit trail (visible for resolved requests) -->
			<div
				v-if="request.approverUserId || request.comment"
				class="approvalDetail__audit"
				data-testid="audit-trail">
				<h3>{{ t('openconnector', 'Audit') }}</h3>
				<p v-if="request.approverUserId">
					{{
						t('openconnector', 'Resolved by {who}', {
							who: request.approverUserId,
						})
					}}
					<span v-if="request.approvedAt">— {{ request.approvedAt }}</span>
					<span v-else-if="request.rejectedAt"
						>— {{ request.rejectedAt }}</span
					>
				</p>
				<p v-if="request.comment">
					<strong>{{ t('openconnector', 'Comment') }}:</strong>
					{{ request.comment }}
				</p>
				<p v-if="request.resumeResult">
					<strong>{{ t('openconnector', 'Resume result') }}:</strong>
					{{ request.resumeResult }}
				</p>
			</div>

			<!-- Actions (only while pending) -->
			<div v-if="request.status === 'pending'" class="approvalDetail__actions">
				<label
					class="approvalDetail__commentLabel"
					:for="'approval-comment-' + uid">
					{{
						t(
							'openconnector',
							'Comment (required to reject, optional to approve)',
						)
					}}
				</label>
				<textarea
					:id="'approval-comment-' + uid"
					v-model="comment"
					class="approvalDetail__comment"
					rows="3"
					:placeholder="t('openconnector', 'Add a note…')" />
				<div class="approvalDetail__buttons">
					<NcButton type="primary" :disabled="busy" @click="approve">
						{{ t('openconnector', 'Approve') }}
					</NcButton>
					<NcButton
						type="error"
						:disabled="busy || !comment.trim()"
						data-testid="reject-button"
						@click="reject">
						{{ t('openconnector', 'Reject') }}
					</NcButton>
				</div>
			</div>
		</div>

		<NcEmptyContent
			v-else
			:name="t('openconnector', 'Approval request not found')"
			:description="
				t(
					'openconnector',
					'It may have been removed or you are not authorized to view it.',
				)
			">
			<template #icon>
				<AlertCircleOutline :size="48" />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'

let uidCounter = 0

export default {
	name: 'ApprovalDetail',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		AlertCircleOutline,
	},

	data() {
		return {
			uid: ++uidCounter,
			request: null,
			loading: false,
			busy: false,
			comment: '',
		}
	},

	computed: {
		/** @spec openspec/specs/approval-workflow/spec.md */
		requestId() {
			return this.$route?.params?.id
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		/**
		 * Navigate back to the approvals list.
		 *
		 * @spec openspec/specs/approval-workflow/spec.md
		 */
		goBack() {
			this.$router.push('/approvals')
		},

		/**
		 * Fetch this request's detail from the two-layer-authorized endpoint.
		 *
		 * @spec openspec/specs/approval-workflow/spec.md
		 */
		async load() {
			this.loading = true
			try {
				const res = await axios.get(
					generateUrl(
						`/apps/openconnector/api/approvals/${this.requestId}`,
					),
				)
				this.request = res.data
			} catch (err) {
				this.request = null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Approve the request (optional comment), resuming the suspended chain.
		 *
		 * @spec openspec/specs/approval-workflow/spec.md
		 */
		async approve() {
			this.busy = true
			try {
				await axios.post(
					generateUrl(
						`/apps/openconnector/api/approvals/${this.requestId}/approve`,
					),
					{ comment: this.comment },
				)
				showSuccess(t('openconnector', 'Approved'))
				await this.load()
			} catch (err) {
				const detail = err?.response?.data?.error || err?.message || ''
				showError(
					t('openconnector', 'Approve failed')
						+ (detail ? `: ${detail}` : ''),
				)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Reject the request with a mandatory comment.
		 *
		 * @spec openspec/specs/approval-workflow/spec.md
		 */
		async reject() {
			if (!this.comment.trim()) {
				return
			}
			this.busy = true
			try {
				await axios.post(
					generateUrl(
						`/apps/openconnector/api/approvals/${this.requestId}/reject`,
					),
					{ comment: this.comment },
				)
				showSuccess(t('openconnector', 'Rejected'))
				await this.load()
			} catch (err) {
				const detail = err?.response?.data?.error || err?.message || ''
				showError(
					t('openconnector', 'Reject failed')
						+ (detail ? `: ${detail}` : ''),
				)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.approvalDetail {
	padding: 20px;
	max-width: 720px;
}

.approvalDetail__back {
	margin-bottom: 16px;
}

.approvalDetail__meta {
	display: flex;
	gap: 12px;
	align-items: center;
	margin-bottom: 16px;
}

.approvalDetail__badge {
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	font-weight: 600;
}

.approvalDetail__badge--pending {
	background: var(--color-warning, #e9a13b);
	color: var(--color-primary-text);
}

.approvalDetail__badge--approved {
	background: var(--color-success, #46ba61);
	color: var(--color-primary-text);
}

.approvalDetail__badge--rejected,
.approvalDetail__badge--dead_letter {
	background: var(--color-error, #e9322d);
	color: var(--color-primary-text);
}

.approvalDetail__badge--expired {
	background: var(--color-text-maxcontrast);
	color: var(--color-main-background);
}

.approvalDetail__fields {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 6px 16px;
	margin-bottom: 20px;
}

.approvalDetail__fields dt {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.approvalDetail__audit {
	margin-bottom: 20px;
	padding: 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.approvalDetail__commentLabel {
	display: block;
	font-weight: bold;
	margin-bottom: 6px;
}

.approvalDetail__comment {
	width: 100%;
	padding: 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
	margin-bottom: 12px;
}

.approvalDetail__buttons {
	display: flex;
	gap: 8px;
}

.approvalDetail__loading {
	margin: 32px auto;
}
</style>
