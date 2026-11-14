<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  RegressionSuitePanel — "Run test cases" for one mapping or synchronization.

  Posts to /api/regression-suites/{subjectType}/{subjectId}/run, which replays
  every stored case without calling the source or writing anything but the
  run record, and shows the pass and fail counts and, per failed case, the
  paths whose value differs. Used on the mapping and synchronization detail
  pages.

  @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-suite-run-replays-every-case-without-calling-the-source-or-writing-req-irt-003
-->
<template>
	<section class="regression-suite" data-testid="regression-suite-panel">
		<header class="regression-suite__header">
			<h3>{{ t('integriq', 'Test cases') }}</h3>
			<NcButton
				variant="secondary"
				:disabled="running || !subjectId"
				data-testid="regression-suite-run"
				@click="run">
				<template #icon>
					<PlaylistCheckIcon :size="20" />
				</template>
				{{ t('integriq', 'Run test cases') }}
			</NcButton>
		</header>

		<p v-if="error" class="regression-suite__error" role="alert">
			{{ error }}
		</p>

		<div v-else-if="result" class="regression-suite__result" data-testid="regression-suite-result">
			<p v-if="result.total === 0">
				{{ t('integriq', 'There are no test cases for this yet.') }}
			</p>
			<p v-else class="regression-suite__counts">
				<span class="regression-suite__count regression-suite__count--passed">
					{{ t('integriq', 'Passed: {count}', { count: result.passed }) }}
				</span>
				<span class="regression-suite__count regression-suite__count--failed">
					{{ t('integriq', 'Failed: {count}', { count: result.failed }) }}
				</span>
			</p>

			<div
				v-for="failure in result.failures || []"
				:key="failure.caseId"
				class="regression-suite__failure"
				data-testid="regression-suite-failure">
				<h4>{{ failure.caseName || failure.caseId }}</h4>
				<p v-if="failure.error" class="regression-suite__error">
					{{ failure.error }}
				</p>
				<table v-if="(failure.differences || []).length > 0" class="regression-suite__diff">
					<thead>
						<tr>
							<th scope="col">
								{{ t('integriq', 'Path') }}
							</th>
							<th scope="col">
								{{ t('integriq', 'Expected') }}
							</th>
							<th scope="col">
								{{ t('integriq', 'Actual') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="difference in failure.differences" :key="difference.path">
							<td><code>{{ difference.path }}</code></td>
							<td><code>{{ formatValue(difference.expected) }}</code></td>
							<td><code>{{ formatValue(difference.actual) }}</code></td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import PlaylistCheckIcon from 'vue-material-design-icons/PlaylistCheck.vue'
import { formatValue } from './regressionCases.js'

export default {
	name: 'RegressionSuitePanel',

	components: {
		NcButton,
		PlaylistCheckIcon,
	},

	props: {
		/** `mapping` or `synchronization`. */
		subjectType: { type: String, required: true },
		/** The uuid or slug the cases name the subject by. */
		subjectId: { type: String, default: '' },
	},

	data() {
		return {
			running: false,
			result: null,
			error: '',
		}
	},

	methods: {
		t,
		formatValue,

		/**
		 * Run every case of the subject and show the run record.
		 *
		 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-suite-run-replays-every-case-without-calling-the-source-or-writing-req-irt-003
		 */
		async run() {
			this.running = true
			this.error = ''
			try {
				const url = generateUrl(
					`/apps/integriq/api/regression-suites/${encodeURIComponent(this.subjectType)}/${encodeURIComponent(this.subjectId)}/run`,
				)
				const res = await axios.post(url)
				this.result = res.data
			} catch (err) {
				this.result = null
				this.error = err?.response?.data?.error || t('integriq', 'The test cases could not be run.')
			} finally {
				this.running = false
			}
		},
	},
}
</script>

<style scoped>
.regression-suite {
	margin-top: 16px;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.regression-suite__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.regression-suite__counts {
	display: flex;
	gap: 12px;
	margin: 8px 0;
	font-weight: 600;
}

.regression-suite__count--passed {
	color: var(--color-success-text);
}

.regression-suite__count--failed {
	color: var(--color-error-text);
}

.regression-suite__failure {
	margin-top: 12px;
}

.regression-suite__error {
	color: var(--color-error-text);
}

.regression-suite__diff {
	width: 100%;
	border-collapse: collapse;
}

.regression-suite__diff th,
.regression-suite__diff td {
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
}
</style>
