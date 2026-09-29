<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  ApprovalChangeSet: what a gated synchronization would create, change and
  remove, as stored on its approval_request (design D5). Tabs for the three
  kinds, a before and after per changed field, and the exact counts even
  when the lists were cut at the stored limit.

  @spec openspec/changes/connectors-inavigator-case-types/specs/synchronization-engine/spec.md#requirement-a-gated-run-stores-its-change-set-on-the-approval-request-req-inav-003
-->
<template>
	<section class="changeSet" data-testid="change-set">
		<h3>{{ t('integriq', 'What an approve would write') }}</h3>
		<p class="changeSet__counts" data-testid="change-set-counts">
			{{ t('integriq', '{count} to create', { count: counts.created }) }},
			{{ t('integriq', '{count} to change', { count: counts.changed }) }},
			{{ t('integriq', '{count} to remove', { count: counts.removed }) }},
			{{ t('integriq', '{count} unchanged', { count: counts.unchanged }) }}
		</p>
		<p
			v-if="changeSet.truncated"
			class="changeSet__note"
			data-testid="change-set-truncated">
			{{
				t('integriq', 'Each list shows the first {limit} objects. The counts are exact.', {
					limit: changeSet.limit,
				})
			}}
		</p>

		<div class="changeSet__tabs" role="tablist" :aria-label="t('integriq', 'Kind of change')">
			<button
				v-for="tab in tabs"
				:id="`change-set-tab-${uid}-${tab.key}`"
				:key="tab.key"
				type="button"
				role="tab"
				class="changeSet__tab"
				:class="{ 'changeSet__tab--active': active === tab.key }"
				:aria-selected="active === tab.key ? 'true' : 'false'"
				:aria-controls="`change-set-panel-${uid}`"
				:data-testid="`change-set-tab-${tab.key}`"
				@click="active = tab.key">
				{{ tab.label }} ({{ counts[tab.key] }})
			</button>
		</div>

		<div
			:id="`change-set-panel-${uid}`"
			role="tabpanel"
			class="changeSet__panel"
			:aria-labelledby="`change-set-tab-${uid}-${active}`"
			data-testid="change-set-panel">
			<p v-if="items.length === 0" class="changeSet__empty">
				{{ t('integriq', 'Nothing in this list.') }}
			</p>
			<ul v-else class="changeSet__list">
				<li v-for="item in items" :key="item.originId" class="changeSet__item">
					<strong>{{ item.originId }}</strong>
					<span v-if="item.targetId" class="changeSet__target">
						{{ t('integriq', 'stored as {id}', { id: item.targetId }) }}
					</span>
					<table v-if="active === 'changed'" class="changeSet__diff">
						<thead>
							<tr>
								<th scope="col">{{ t('integriq', 'Field') }}</th>
								<th scope="col">{{ t('integriq', 'Now') }}</th>
								<th scope="col">{{ t('integriq', 'After approve') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="field in item.fields" :key="field.field">
								<th scope="row">{{ field.field }}</th>
								<td>{{ show(field.before) }}</td>
								<td>{{ show(field.after) }}</td>
							</tr>
						</tbody>
					</table>
					<dl v-else-if="active === 'created'" class="changeSet__fields">
						<template v-for="(value, key) in item.fields" :key="key">
							<dt>{{ key }}</dt>
							<dd>{{ show(value) }}</dd>
						</template>
					</dl>
				</li>
			</ul>
		</div>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

let uidCounter = 0

export default {
	name: 'ApprovalChangeSet',

	props: {
		/** The change set stored on the approval_request (ChangeSetBuilder::build()). */
		changeSet: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			uid: ++uidCounter,
			active: 'created',
		}
	},

	computed: {
		/**
		 * The tabs, in the order an approver reads them.
		 *
		 * @spec openspec/changes/connectors-inavigator-case-types/specs/synchronization-engine/spec.md#requirement-a-gated-run-stores-its-change-set-on-the-approval-request-req-inav-003
		 * @return {Array<{key: string, label: string}>}
		 */
		tabs() {
			return [
				{ key: 'created', label: t('integriq', 'Created') },
				{ key: 'changed', label: t('integriq', 'Changed') },
				{ key: 'removed', label: t('integriq', 'Removed') },
			]
		},

		/**
		 * The exact counts, also past the cut.
		 *
		 * @spec openspec/changes/connectors-inavigator-case-types/specs/synchronization-engine/spec.md#requirement-a-gated-run-stores-its-change-set-on-the-approval-request-req-inav-003
		 * @return {{created: number, changed: number, removed: number, unchanged: number}}
		 */
		counts() {
			const counts = this.changeSet.counts || {}
			return {
				created: counts.created ?? (this.changeSet.created || []).length,
				changed: counts.changed ?? (this.changeSet.changed || []).length,
				removed: counts.removed ?? (this.changeSet.removed || []).length,
				unchanged: counts.unchanged ?? this.changeSet.unchanged ?? 0,
			}
		},

		/**
		 * The objects of the selected tab.
		 *
		 * @spec openspec/changes/connectors-inavigator-case-types/specs/synchronization-engine/spec.md#requirement-a-gated-run-stores-its-change-set-on-the-approval-request-req-inav-003
		 * @return {Array<object>}
		 */
		items() {
			return this.changeSet[this.active] || []
		},
	},

	methods: {
		t,

		/**
		 * A field value as text: strings as they are, the rest as JSON.
		 *
		 * @spec openspec/changes/connectors-inavigator-case-types/specs/synchronization-engine/spec.md#requirement-a-gated-run-stores-its-change-set-on-the-approval-request-req-inav-003
		 * @param {*} value Any JSON value.
		 * @return {string}
		 */
		show(value) {
			if (value === null || value === undefined) {
				return t('integriq', '(empty)')
			}
			if (typeof value === 'string') {
				return value
			}
			return JSON.stringify(value)
		},
	},
}
</script>

<style scoped>
.changeSet {
	margin-bottom: 20px;
}

.changeSet__counts {
	margin-bottom: 8px;
}

.changeSet__note {
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
}

.changeSet__tabs {
	display: flex;
	gap: 4px;
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 12px;
}

.changeSet__tab {
	background: transparent;
	border: none;
	border-bottom: 2px solid transparent;
	color: var(--color-main-text);
	padding: 6px 12px;
	cursor: pointer;
}

.changeSet__tab--active {
	border-bottom-color: var(--color-primary-element);
	font-weight: bold;
}

.changeSet__tab:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.changeSet__list {
	list-style: none;
	padding: 0;
}

.changeSet__item {
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.changeSet__target {
	color: var(--color-text-maxcontrast);
	margin-inline-start: 8px;
}

.changeSet__diff {
	width: 100%;
	margin-top: 6px;
	border-collapse: collapse;
}

.changeSet__diff th,
.changeSet__diff td {
	text-align: start;
	padding: 4px 8px;
	vertical-align: top;
	word-break: break-word;
}

.changeSet__fields {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
	margin-top: 6px;
}

.changeSet__fields dt {
	color: var(--color-text-maxcontrast);
}

.changeSet__empty {
	color: var(--color-text-maxcontrast);
}
</style>
