<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div
		class="integriq-admin__section"
		data-testid="admin-action-auth-section">
		<h3>{{ t('integriq', 'Action authorization') }}</h3>
		<p class="integriq-admin__hint">
			{{
				t(
					'integriq',
					'Decide which Nextcloud groups may invoke each Integriq action (ADR-023). Admins always pass. Every action defaults to admin-only — tick a group to broaden it.',
				)
			}}
		</p>

		<div v-if="error" class="integriq-admin__action-error" role="alert">
			{{ error }}
		</div>

		<p v-if="loading" class="integriq-admin__hint">
			{{ t('integriq', 'Loading action matrix…') }}
		</p>

		<div v-else class="integriq-admin__matrix-wrapper">
			<table class="integriq-admin__matrix">
				<thead>
					<tr>
						<th scope="col">
							{{ t('integriq', 'Action') }}
						</th>
						<th
							v-for="group in displayGroups"
							:key="group"
							scope="col"
							class="integriq-admin__matrix-group">
							{{ group }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="action in actions" :key="action">
						<th scope="row" class="integriq-admin__matrix-action">
							{{ action }}
						</th>
						<td
							v-for="group in displayGroups"
							:key="`${action}-${group}`"
							class="integriq-admin__matrix-cell">
							<NcCheckboxRadioSwitch
								:modelValue="isChecked(action, group)"
								:disabled="group === 'admin'"
								:aria-label="
									t(
										'integriq',
										'Allow group {group} to perform {action}',
										{ group, action },
									)
								"
								@update:modelValue="toggle(action, group, $event)" />
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="integriq-admin__matrix-actions">
			<NcButton
				variant="primary"
				data-testid="admin-action-matrix-save"
				:disabled="loading || saving"
				@click="save">
				{{
					saving
						? t('integriq', 'Saving…')
						: t('integriq', 'Save action matrix')
				}}
			</NcButton>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'

/**
 * Admin editor for the ADR-023 action-authorization matrix.
 *
 * Renders one row per declared action and one column per Nextcloud group.
 * Each cell is a checkbox: ticking it adds the group to the action's allowed
 * list. The synthetic `admin` column is always-on and disabled because
 * Nextcloud admins always pass ActionAuthService::requireAction().
 *
 * @spec openspec/specs/action-authorization/spec.md#requirement-the-matrix-is-editable-by-an-administrator-and-only-by-one
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
		/** @spec openspec/specs/action-authorization/spec.md#requirement-the-matrix-is-editable-by-an-administrator-and-only-by-one */
		displayGroups() {
			const rest = this.groups.filter((g) => g !== 'admin')
			return ['admin', ...rest]
		},
	},

	/** @spec openspec/specs/action-authorization/spec.md#requirement-the-matrix-is-editable-by-an-administrator-and-only-by-one */
	async mounted() {
		await this.load()
	},

	methods: {
		/** @spec openspec/specs/action-authorization/spec.md#requirement-the-matrix-is-editable-by-an-administrator-and-only-by-one */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(
					generateUrl('/apps/integriq/api/admin/action-matrix'),
				)
				this.actions = Array.isArray(data.actions) ? data.actions : []
				this.groups = Array.isArray(data.groups) ? data.groups : []
				// Clone the matrix into a plain editable map keyed by action.
				const next = {}
				const source =
					data.matrix && typeof data.matrix === 'object' ? data.matrix : {}
				for (const action of this.actions) {
					const allowed = Array.isArray(source[action])
						? source[action]
						: []
					next[action] = [...allowed]
				}
				this.matrix = next
			} catch (e) {
				console.error('Failed to load action matrix', e)
				this.error = this.t(
					'integriq',
					'Failed to load the action matrix.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Decide whether one cell of the matrix renders as ticked. `admin` is
		 * always allowed, whatever the stored list says.
		 *
		 * @param {string} action Action name identifying the matrix row.
		 * @param {string} group Nextcloud group id identifying the column.
		 *
		 * @spec openspec/specs/action-authorization/spec.md#requirement-the-matrix-is-editable-by-an-administrator-and-only-by-one
		 */
		isChecked(action, group) {
			// Admins always pass regardless of the stored list.
			if (group === 'admin') {
				return true
			}
			const allowed = this.matrix[action] || []
			return allowed.includes(group)
		},

		/**
		 * Add or remove one group from an action's allow-list in the local
		 * matrix (persisted later by `save`). The `admin` column is fixed and
		 * ignored here.
		 *
		 * @param {string} action Action name identifying the matrix row.
		 * @param {string} group Nextcloud group id identifying the column.
		 * @param {boolean} checked New checkbox state — true grants the group
		 *   the action, false revokes it.
		 *
		 * @spec openspec/specs/action-authorization/spec.md#requirement-the-matrix-is-editable-by-an-administrator-and-only-by-one
		 */
		toggle(action, group, checked) {
			// The admin column is fixed and never persisted as a toggle.
			if (group === 'admin') {
				return
			}
			const allowed = Array.isArray(this.matrix[action])
				? [...this.matrix[action]]
				: []
			const index = allowed.indexOf(group)
			if (checked === true && index === -1) {
				allowed.push(group)
			} else if (checked === false && index !== -1) {
				allowed.splice(index, 1)
			}
			this.matrix = { ...this.matrix, [action]: allowed }
		},

		/** @spec openspec/specs/action-authorization/spec.md#requirement-the-matrix-is-editable-by-an-administrator-and-only-by-one */
		async save() {
			this.saving = true
			try {
				// Persist `admin` plus any explicitly ticked groups so the
				// stored posture stays admin-inclusive and human-readable.
				const payload = {}
				for (const action of this.actions) {
					const extra = (this.matrix[action] || []).filter(
						(g) => g !== 'admin',
					)
					payload[action] = ['admin', ...extra]
				}
				const { data } = await axios.put(
					generateUrl('/apps/integriq/api/admin/action-matrix'),
					{ matrix: payload },
				)
				const saved =
					data && data.matrix && typeof data.matrix === 'object'
						? data.matrix
						: {}
				const next = {}
				for (const action of this.actions) {
					const allowed = Array.isArray(saved[action]) ? saved[action] : []
					next[action] = [...allowed]
				}
				this.matrix = next
				showSuccess(this.t('integriq', 'Action matrix saved.'))
			} catch (e) {
				console.error('Failed to save action matrix', e)
				showError(
					this.t('integriq', 'Failed to save the action matrix.'),
				)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.integriq-admin__hint {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.integriq-admin__action-error {
	background: var(--color-error);
	color: var(--color-primary-element-text);
	padding: 8px 12px;
	border-radius: var(--border-radius);
	margin-bottom: 16px;
}

.integriq-admin__matrix-wrapper {
	overflow-x: auto;
	margin-bottom: 16px;
}

.integriq-admin__matrix {
	border-collapse: collapse;
	width: 100%;
}

.integriq-admin__matrix th,
.integriq-admin__matrix td {
	border: 1px solid var(--color-border);
	padding: 6px 10px;
	text-align: left;
}

.integriq-admin__matrix-group {
	text-align: center;
	white-space: nowrap;
}

.integriq-admin__matrix-action {
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.85em;
	white-space: nowrap;
}

.integriq-admin__matrix-cell {
	text-align: center;
}

.integriq-admin__matrix-actions {
	display: flex;
	justify-content: flex-end;
}
</style>
