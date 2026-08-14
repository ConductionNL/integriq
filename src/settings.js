/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Admin settings entry point (ADR-023 action-authorization matrix).
 * Loaded by templates/settings/admin.php via Util::addScript.
 *
 * Vue 3 (ADR-066): createApp replaces `new Vue({ el })`; global t/n install via
 * app.config.globalProperties rather than Vue.mixin.
 */

import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { createApp } from 'vue'
import AdminSettings from './views/admin/AdminSettings.vue'

const app = createApp(AdminSettings)
app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.mount('#settings')

export default app
