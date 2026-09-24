<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  DirectoryRunSummary — what one directory run changed.

  The same component renders a preview and a finished run, because they answer
  the same question and an administrator comparing the two should not have to
  translate between two layouts. A preview says so in as many words rather than
  relying on the counts being zero.

  A consumer that did not answer reads "unknown", never zero: an app that never
  looked and an app that looked and found nothing are different answers, and
  only one of them is safe to act on.

  @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-every-run-says-what-it-changed-req-ds-006
-->
<template>
	<div class="directoryRun" data-testid="directory-run-summary">
		<p
			v-if="record.dryRun"
			class="directoryRun__preview"
			data-testid="directory-run-preview">
			{{ t('integriq', 'Preview only. Nothing was changed.') }}
		</p>

		<p
			v-if="record.guard"
			class="directoryRun__guard"
			data-testid="directory-run-guard">
			{{ record.guard.message }}
		</p>

		<p
			v-if="record.refusal"
			class="directoryRun__refusal"
			data-testid="directory-run-refusal">
			{{ record.refusal.message }}
		</p>

		<dl class="directoryRun__counts" data-testid="directory-run-counts">
			<div>
				<dt>{{ t('integriq', 'Users read') }}</dt>
				<dd>{{ record.usersRead || 0 }}</dd>
			</div>
			<div>
				<dt>{{ t('integriq', 'Groups read') }}</dt>
				<dd>{{ record.groupsRead || 0 }}</dd>
			</div>
			<div>
				<dt>{{ t('integriq', 'Memberships added') }}</dt>
				<dd data-testid="directory-run-added">
					{{
						record.dryRun
							? additions.length
							: record.membershipsAdded || 0
					}}
				</dd>
			</div>
			<div>
				<dt>{{ t('integriq', 'Memberships removed') }}</dt>
				<dd data-testid="directory-run-removed">
					{{
						record.dryRun
							? removals.length
							: record.membershipsRemoved || 0
					}}
				</dd>
			</div>
		</dl>

		<ul
			v-if="additions.length"
			class="directoryRun__list"
			data-testid="directory-run-additions">
			<li v-for="(item, index) in additions" :key="`add-${index}`">
				{{
					t('integriq', '{user} joins {group}', {
						user: item.userId,
						group: item.group,
					})
				}}
			</li>
		</ul>

		<ul
			v-if="removals.length"
			class="directoryRun__list"
			data-testid="directory-run-removals">
			<li v-for="(item, index) in removals" :key="`remove-${index}`">
				{{
					t('integriq', '{user} leaves {group}', {
						user: item.userId,
						group: item.group,
					})
				}}
			</li>
		</ul>

		<ul
			v-if="failures.length"
			class="directoryRun__list"
			data-testid="directory-run-failures">
			<li v-for="(failure, index) in failures" :key="`fail-${index}`">
				{{ failure.userId || failure.index }}: {{ failure.reason }}
			</li>
		</ul>

		<ul
			v-if="openWork.length"
			class="directoryRun__list"
			data-testid="directory-run-open-work">
			<li v-for="entry in openWork" :key="entry.userId">
				{{
					t('integriq', '{user} still holds: {answers}', {
						user: entry.userId,
						answers: entry.answers,
					})
				}}
			</li>
		</ul>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'DirectoryRunSummary',

	props: {
		record: {
			type: Object,
			default: () => ({}),
		},
	},

	computed: {
		/**
		 * The memberships this run added, or would add.
		 *
		 * @return {Array} The additions.
		 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-every-run-says-what-it-changed-req-ds-006
		 */
		additions() {
			return this.record?.additions || []
		},

		/**
		 * The memberships this run removed, or would remove.
		 *
		 * @return {Array} The removals.
		 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-every-run-says-what-it-changed-req-ds-006
		 */
		removals() {
			return this.record?.removals || []
		},

		/**
		 * The items this run could not process, with their reasons.
		 *
		 * @return {Array} The failures.
		 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-every-run-says-what-it-changed-req-ds-006
		 */
		failures() {
			return this.record?.failures || []
		},

		/**
		 * What each leaver still holds, one line per account.
		 *
		 * @return {Array} The open-work report rows.
		 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-leavers-open-work-is-reported-never-silently-dropped-req-ds-004
		 */
		openWork() {
			const report = this.record?.openWork || {}
			return Object.keys(report).map((userId) => ({
				userId,
				answers: Object.keys(report[userId])
					.map((consumer) => `${consumer} ${report[userId][consumer]}`)
					.join(', '),
			}))
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.directoryRun__counts {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
}

.directoryRun__counts dt {
	color: var(--color-text-maxcontrast);
}

.directoryRun__guard,
.directoryRun__refusal {
	color: var(--color-error);
}

.directoryRun__list {
	margin-block: 8px;
}
</style>
