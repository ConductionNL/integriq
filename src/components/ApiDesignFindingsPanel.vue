<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  ApiDesignFindingsPanel: the API design findings of one API product
  (gateway-api-design-rules-check, tasks 3 and 4).

  Shows the stored findings of the product, grouped by severity with errors
  first and a link to each rule. "Check design" runs
  `POST /api/products/{productId}/design-check` and shows what it found,
  plus the rules a person has to check. An open error can be waived with a
  reason (WaiveDesignFindingDialog); a waived one shows who waived it and why.

  @spec openspec/changes/archive/2026-11-25-gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
-->
<template>
	<section class="apiDesignFindings" data-testid="api-design-findings">
		<div class="apiDesignFindings__header">
			<h3>{{ t('integriq', 'API design rules') }}</h3>
			<NcButton
				variant="secondary"
				:disabled="checking"
				data-testid="api-design-check"
				@click="runCheck">
				{{ checking ? t('integriq', 'Checking…') : t('integriq', 'Check design') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="error" type="error" data-testid="api-design-error">
			{{ error }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="24" />

		<template v-else>
			<p v-if="findings.length === 0" class="apiDesignFindings__empty" data-testid="api-design-empty">
				{{ checked ? t('integriq', 'No rule found a problem.') : t('integriq', 'This product has not been checked yet.') }}
			</p>

			<div
				v-for="group in groups"
				:key="group.severity"
				class="apiDesignFindings__group"
				:data-testid="`api-design-group-${group.severity}`">
				<h4>{{ severityLabel(group.severity) }} ({{ group.findings.length }})</h4>
				<ul class="apiDesignFindings__list">
					<li
						v-for="finding in group.findings"
						:key="key(finding)"
						:class="{ 'apiDesignFindings__item--waived': finding.status === 'waived' }">
						<a
							v-if="finding.url"
							:href="finding.url"
							target="_blank"
							rel="noopener noreferrer">{{ finding.ruleId }}</a>
						<strong v-else>{{ finding.ruleId }}</strong>
						<span>{{ finding.message }}</span>
						<code v-if="finding.path" class="apiDesignFindings__path">{{ finding.path }}</code>
						<span v-if="finding.status === 'waived'" class="apiDesignFindings__waived">
							{{ t('integriq', 'Waived by {user}: {reason}', { user: finding.waivedBy || '—', reason: finding.waiveReason || '—' }) }}
						</span>
						<NcButton
							v-else-if="blocking(finding)"
							variant="tertiary"
							:data-testid="`api-design-waive-${key(finding)}`"
							@click="waiving = finding">
							{{ t('integriq', 'Waive') }}
						</NcButton>
					</li>
				</ul>
			</div>

			<div v-if="manual.length > 0" class="apiDesignFindings__group" data-testid="api-design-manual">
				<h4>{{ t('integriq', 'To check by hand ({count})', { count: manual.length }) }}</h4>
				<ul class="apiDesignFindings__list">
					<li v-for="rule in manual" :key="rule.id || rule.ruleId">
						<a
							v-if="rule.url"
							:href="rule.url"
							target="_blank"
							rel="noopener noreferrer">{{ rule.id || rule.ruleId }}</a>
						<strong v-else>{{ rule.id || rule.ruleId }}</strong>
						<span>{{ rule.title }}</span>
					</li>
				</ul>
			</div>
		</template>

		<WaiveDesignFindingDialog
			:open="waiving !== null"
			:finding="waiving"
			@waived="onWaived"
			@close="waiving = null" />
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import WaiveDesignFindingDialog from '../dialogs/WaiveDesignFindingDialog.vue'
import { findingId, groupBySeverity, isBlocking, replaceFinding } from '../views/ApiProducts/apiDesignFindings.js'

export default {
	name: 'ApiDesignFindingsPanel',

	components: { NcButton, NcLoadingIcon, NcNoteCard, WaiveDesignFindingDialog },

	props: {
		/** The API product's object id. */
		productId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			findings: [],
			manual: [],
			loading: false,
			checking: false,
			checked: false,
			error: '',
			waiving: null,
		}
	},

	computed: {
		/**
		 * The findings by severity, errors first.
		 *
		 * @return {Array<{severity: string, findings: Array<object>}>}
		 * @spec openspec/changes/archive/2026-11-25-gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
		 */
		groups() {
			return groupBySeverity(this.findings)
		},
	},

	/**
	 * Load the stored findings of the product.
	 *
	 * @return {void}
	 * @spec openspec/changes/archive/2026-11-25-gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	mounted() {
		this.loadFindings()
	},

	methods: {
		t,

		/**
		 * The stored findings of the product, from its last check.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-11-25-gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
		 */
		async loadFindings() {
			this.loading = true
			try {
				const res = await axios.get(
					generateUrl('/apps/openregister/api/objects/integriq/api_design_finding'),
					{ params: { product: this.productId, _limit: 500 } },
				)
				const rows = res.data?.results ?? (Array.isArray(res.data) ? res.data : [])
				this.findings = rows.filter((row) => String(row.product ?? '') === this.productId)
				this.checked = this.findings.length > 0
			} catch {
				this.findings = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Check the product's API description now and show what was found.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-11-25-gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
		 */
		async runCheck() {
			this.checking = true
			this.error = ''
			try {
				const res = await axios.post(
					generateUrl(`/apps/integriq/api/products/${encodeURIComponent(this.productId)}/design-check`),
				)
				this.findings = Array.isArray(res.data?.findings) ? res.data.findings : []
				this.manual = Array.isArray(res.data?.manual) ? res.data.manual : []
				this.checked = true
			} catch (err) {
				this.error = err?.response?.data?.error || t('integriq', 'The API product could not be checked.')
			} finally {
				this.checking = false
			}
		},

		/**
		 * Show a waived finding as the server returned it.
		 *
		 * @param {object} finding The waived finding
		 * @return {void}
		 * @spec openspec/changes/archive/2026-11-25-gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-a-product-with-an-open-error-is-not-published-req-adrc-003
		 */
		onWaived(finding) {
			this.findings = replaceFinding(this.findings, finding)
			this.waiving = null
		},

		/**
		 * Whether a finding still blocks publication.
		 *
		 * @param {object} finding The finding
		 * @return {boolean}
		 * @spec openspec/changes/archive/2026-11-25-gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-a-product-with-an-open-error-is-not-published-req-adrc-003
		 */
		blocking(finding) {
			return isBlocking(finding)
		},

		/**
		 * A stable key for a finding.
		 *
		 * @param {object} finding The finding
		 * @return {string}
		 * @spec openspec/changes/archive/2026-11-25-gateway-api-design-rules-check/tasks.md
		 */
		key(finding) {
			return findingId(finding) || `${finding.ruleId}:${finding.path}`
		},

		/**
		 * The heading of a severity group.
		 *
		 * @param {string} severity The severity
		 * @return {string}
		 * @spec openspec/changes/archive/2026-11-25-gateway-api-design-rules-check/tasks.md
		 */
		severityLabel(severity) {
			return {
				error: t('integriq', 'Errors'),
				warning: t('integriq', 'Warnings'),
				info: t('integriq', 'Notes'),
			}[severity]
		},
	},
}
</script>

<style scoped>
.apiDesignFindings__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
}

.apiDesignFindings__list {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.apiDesignFindings__list li {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.apiDesignFindings__path {
	color: var(--color-text-maxcontrast);
}

.apiDesignFindings__item--waived,
.apiDesignFindings__waived,
.apiDesignFindings__empty {
	color: var(--color-text-maxcontrast);
}
</style>
