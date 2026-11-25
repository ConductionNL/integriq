<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<!--
  DeveloperPortalSettings: the group whose members may use the developer
  portal (app setting developer_portal_group, `integriq-developers` until an
  administrator names another), and how many accounts are in it, so a group
  left empty that hides the portal from everyone is visible here.

  @spec openspec/changes/archive/2026-11-25-access-developer-portal-and-subscriptions/design.md#d1-who-a-developer-is
-->
<template>
	<section
		class="integriq-admin__section"
		data-testid="admin-developer-portal-section">
		<h3>{{ t('integriq', 'Who may use the developer portal') }}</h3>
		<p class="integriq-admin__hint">
			{{
				t(
					'integriq',
					'Members of this group see the public API products in the developer portal, create applications and request access. Guest accounts can be members. Leave it empty to use integriq-developers.',
				)
			}}
		</p>

		<div v-if="error" class="integriq-admin__action-error" role="alert">
			{{ error }}
		</div>

		<p v-if="loading" class="integriq-admin__hint">
			{{ t('integriq', 'Loading the setting…') }}
		</p>
		<template v-else>
			<div class="developer-portal-group__row">
				<NcTextField
					v-model="group"
					data-testid="admin-developer-portal-group"
					:label="t('integriq', 'Group id')"
					@keyup.enter="save" />
				<NcButton
					variant="primary"
					data-testid="admin-developer-portal-group-save"
					:disabled="busy"
					@click="save">
					{{ t('integriq', 'Save') }}
				</NcButton>
			</div>
			<p class="integriq-admin__hint" data-testid="admin-developer-portal-members">
				{{ membersText }}
			</p>
		</template>
	</section>
</template>

<script>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { NcButton, NcTextField } from '@nextcloud/vue'
import adminSetting from '../../mixins/adminSetting.js'

const URL = '/apps/integriq/api/admin/developer-portal'

export default {
	name: 'DeveloperPortalSettings',

	components: { NcButton, NcTextField },

	mixins: [adminSetting],

	data() {
		return {
			group: '',
			saved: '',
			members: null,
		}
	},

	computed: {
		/**
		 * @return {string} The setting endpoint.
		 * @spec openspec/changes/archive/2026-11-25-access-developer-portal-and-subscriptions/design.md#d1-who-a-developer-is
		 */
		settingUrl() {
			return URL
		},

		/**
		 * @return {string} How many accounts the group holds, or that it does not exist.
		 * @spec openspec/changes/archive/2026-11-25-access-developer-portal-and-subscriptions/design.md#risks
		 */
		membersText() {
			if (this.members === null) {
				return t('integriq', 'The group {group} does not exist yet, so nobody can use the developer portal.', { group: this.saved })
			}
			return n('integriq', '{count} account is in this group.', '{count} accounts are in this group.', this.members, { count: this.members })
		},
	},

	methods: {
		t,

		/**
		 * Show what the server holds.
		 *
		 * @param {object|null} data The stored setting.
		 * @return {void}
		 * @spec openspec/changes/archive/2026-11-25-access-developer-portal-and-subscriptions/design.md#d1-who-a-developer-is
		 */
		applyStored(data) {
			this.saved = data?.group || ''
			this.group = this.saved
			this.members = (typeof data?.members === 'number') ? data.members : null
		},

		/**
		 * What to store.
		 *
		 * @return {object} The request body.
		 * @spec openspec/changes/archive/2026-11-25-access-developer-portal-and-subscriptions/design.md#d1-who-a-developer-is
		 */
		settingBody() {
			return { group: this.group.trim() }
		},
	},
}
</script>

<style scoped>
.developer-portal-group__row {
	display: flex;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
	max-width: 480px;
}
</style>
