/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Admin settings entry point (ADR-023 action-authorization matrix).
 * Loaded by templates/settings/admin.php via Util::addScript.
 */

import Vue from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

import AdminSettings from './views/admin/AdminSettings.vue'

Vue.mixin({
	methods: { t, n },
})

const app = new Vue({
	el: '#settings',
	render: h => h(AdminSettings),
})

export default app
