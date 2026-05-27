<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div class="openconnector-admin__section" data-testid="admin-action-auth-section">
		<h3>{{ t('openconnector', 'Action authorization') }}</h3>
		<p class="openconnector-admin__hint">
			{{ t('openconnector', 'Decide which Nextcloud groups may invoke each OpenConnector action (ADR-023). Admins always pass. Every action defaults to admin-only — tick a group to broaden it.') }}
		</p>

		<div v-if="error" class="openconnector-admin__action-error" role="alert">
			{{ error }}
		</div>

		<p v-if="loading" class="openconnector-admin__hint">
			{{ t('openconnector', 'Loading action matrix…') }}
		</p>

		<div v-else class="openconnector-admin__matrix-wrapper">
			<table class="openconnector-admin__matrix">
				<thead>
					<tr>
						<th scope="col">
							{{ t('openconnector', 'Action') }}
						</th>
						<th
							v-for="group in displayGroups"
							:key="group"
							scope="col"
							class="openconnector-admin__matrix-group">
							{{ group }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="action in actions" :key="action">
						<th scope="row" class="openconnector-admin__matrix-action">
							{{ action }}
						</th>
						<td
							v-for="group in displayGroups"
							:key="`${action}-${group}`"
							class="openconnector-admin__matrix-cell">
							<NcCheckboxRadioSwitch
								:checked="isChecked(action, group)"
								:disabled="group === 'admin'"
								:aria-label="t('openconnector', 'Allow group {group} to perform {action}', { group, action })"
								@update:checked="toggle(action, group, $event)" />
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="openconnector-admin__matrix-actions">
			<NcButton
				type="primary"
				data-testid="admin-action-matrix-save"
				:disabled="loading || saving"
				@click="save">
				{{ saving ? t('openconnector', 'Saving…') : t('openconnector', 'Save action matrix') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Admin editor for the ADR-023 action-authorization matrix.
 *
 * Renders one row per declared action and one column per Nextcloud group.
 * Each cell is a checkbox: ticking it adds the group to the action's allowed
 * list. The synthetic `admin` column is always-on and disabled because
 * Nextcloud admins always pass ActionAuthService::requireAction().
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 */
export default {
	name: 'ActionAuthMatrix',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
	},

	data() {
		return {
			loading: true,
			saving: false,
			error: '',
			actions: [],
			groups: [],
			// matrix: { '<action>': ['group', ...], ... }
			matrix: {},
		}
	},

	computed: {
		// `admin` is always shown first as a disabled, always-on column.
		/** @spec openspec/architecture/adr-023-action-authorization.md */
		displayGroups() {
			const rest = this.groups.filter(g => g !== 'admin')
			return ['admin', ...rest]
		},
	},

	/** @spec openspec/architecture/adr-023-action-authorization.md */
	async mounted() {
		await this.load()
	},

	methods: {
		/** @spec openspec/architecture/adr-023-action-authorization.md */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(generateUrl('/apps/openconnector/api/admin/action-matrix'))
				this.actions = Array.isArray(data.actions) ? data.actions : []
				this.groups = Array.isArray(data.groups) ? data.groups : []
				// Clone the matrix into a plain editable map keyed by action.
				const next = {}
				const source = data.matrix && typeof data.matrix === 'object' ? data.matrix : {}
				for (const action of this.actions) {
					const allowed = Array.isArray(source[action]) ? source[action] : []
					next[action] = [...allowed]
				}
				this.matrix = next
			} catch (e) {
				console.error('Failed to load action matrix', e)
				this.error = this.t('openconnector', 'Failed to load the action matrix.')
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/architecture/adr-023-action-authorization.md */
		isChecked(action, group) {
			// Admins always pass regardless of the stored list.
			if (group === 'admin') {
				return true
			}
			const allowed = this.matrix[action] || []
			return allowed.includes(group)
		},

		/** @spec openspec/architecture/adr-023-action-authorization.md */
		toggle(action, group, checked) {
			// The admin column is fixed and never persisted as a toggle.
			if (group === 'admin') {
				return
			}
			const allowed = Array.isArray(this.matrix[action]) ? [...this.matrix[action]] : []
			const index = allowed.indexOf(group)
			if (checked === true && index === -1) {
				allowed.push(group)
			} else if (checked === false && index !== -1) {
				allowed.splice(index, 1)
			}
			this.matrix = { ...this.matrix, [action]: allowed }
		},

		/** @spec openspec/architecture/adr-023-action-authorization.md */
		async save() {
			this.saving = true
			try {
				// Persist `admin` plus any explicitly ticked groups so the
				// stored posture stays admin-inclusive and human-readable.
				const payload = {}
				for (const action of this.actions) {
					const extra = (this.matrix[action] || []).filter(g => g !== 'admin')
					payload[action] = ['admin', ...extra]
				}
				const { data } = await axios.put(
					generateUrl('/apps/openconnector/api/admin/action-matrix'),
					{ matrix: payload },
				)
				const saved = data && data.matrix && typeof data.matrix === 'object' ? data.matrix : {}
				const next = {}
				for (const action of this.actions) {
					const allowed = Array.isArray(saved[action]) ? saved[action] : []
					next[action] = [...allowed]
				}
				this.matrix = next
				showSuccess(this.t('openconnector', 'Action matrix saved.'))
			} catch (e) {
				console.error('Failed to save action matrix', e)
				showError(this.t('openconnector', 'Failed to save the action matrix.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.openconnector-admin__hint {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.openconnector-admin__action-error {
	background: var(--color-error);
	color: var(--color-primary-element-text);
	padding: 8px 12px;
	border-radius: var(--border-radius);
	margin-bottom: 16px;
}

.openconnector-admin__matrix-wrapper {
	overflow-x: auto;
	margin-bottom: 16px;
}

.openconnector-admin__matrix {
	border-collapse: collapse;
	width: 100%;
}

.openconnector-admin__matrix th,
.openconnector-admin__matrix td {
	border: 1px solid var(--color-border);
	padding: 6px 10px;
	text-align: left;
}

.openconnector-admin__matrix-group {
	text-align: center;
	white-space: nowrap;
}

.openconnector-admin__matrix-action {
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.85em;
	white-space: nowrap;
}

.openconnector-admin__matrix-cell {
	text-align: center;
}

.openconnector-admin__matrix-actions {
	display: flex;
	justify-content: flex-end;
}
</style>
