<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div class="openconnector-admin">
		<h2>{{ t('openconnector', 'OpenConnector settings') }}</h2>
		<ActionAuthMatrix />
	</div>
</template>

<script>
import ActionAuthMatrix from './ActionAuthMatrix.vue'

/**
 * Root admin settings panel for OpenConnector.
 * Renders the ADR-023 action-authorization matrix editor.
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 */
export default {
	name: 'AdminSettings',

	components: {
		ActionAuthMatrix,
	},
}
</script>
