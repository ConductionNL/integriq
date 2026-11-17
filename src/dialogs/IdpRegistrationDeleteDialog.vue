<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  IdpRegistrationDeleteDialog: confirms deleting one identity provider
  registration from the Authenticatie section on the admin settings page.
  It names the organisation whose logins stop, so nobody removes the wrong
  organisation's login by accident.

  @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
-->
<template>
	<NcDialog
		:open="open"
		:name="t('integriq', 'Delete registration')"
		size="small"
		data-testid="idp-registration-delete-dialog"
		@update:open="onOpenChanged">
		<p data-testid="idp-registration-delete-text">
			{{
				t(
					'integriq',
					'Residents and businesses of {organisation} can no longer log in through {provider} once this registration is deleted.',
					{ organisation, provider },
				)
			}}
		</p>

		<template #actions>
			<NcButton :disabled="busy" @click="$emit('close')">
				{{ t('integriq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="error"
				:disabled="busy"
				data-testid="idp-registration-delete-confirm"
				@click="$emit('confirm')">
				{{ busy ? t('integriq', 'Deleting…') : t('integriq', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog } from '@nextcloud/vue'

/**
 * The confirmation before a registration is deleted.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
 */
export default {
	name: 'IdpRegistrationDeleteDialog',

	components: {
		NcButton,
		NcDialog,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		organisation: {
			type: String,
			default: '',
		},

		provider: {
			type: String,
			default: '',
		},

		busy: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'confirm'],

	methods: {
		t,
		/**
		 * Close when the dialog asks to close.
		 *
		 * @param {boolean} open Whether the dialog stays open.
		 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
		 */
		onOpenChanged(open) {
			if (!open) {
				this.$emit('close')
			}
		},
	},
}
</script>
