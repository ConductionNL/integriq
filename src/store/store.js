// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Central store registry — post chain-C OR-cutover + post chain-E
// orphan-tree sweep.
//
// The 11 per-schema CRUD stores (source/endpoints/consumer/event/job/log/
// mapping/rule/synchronization/contract/webhooks) were deleted in the
// cutover; the UI-only navigation + search stores were deleted alongside
// the legacy modal/sidebar trees they were the only consumers of.
// nc-vue's CnIndexPage/CnDetailPage/CnLogsPage manage list/detail/log
// state internally against OR's `/api/objects/openconnector/{schema}/*`
// endpoints, so app-local stores for those resources are redundant.
//
// What's left:
//   - importExport — multi-step file-upload UX for the live Import page
//     (see src/views/Import/ImportIndex.vue; src/registry.js)
//   - settings — app-level settings cache (small; survives cutover until
//     OR's /api/settings/* is fully adopted)
//
// Connector-specific action stores (useJobRunner, useSourceTester,
// useSyncTrigger, etc. — chain-D2 spec) will be added under
// `src/store/actions/` as they get implemented as v2 widget slots.

import pinia from '../pinia.js'
import { useImportExportStore } from './modules/importExport.js'
import { useSettingsStore } from './modules/settings.js'

const importExportStore = useImportExportStore(pinia)
const settingsStore = useSettingsStore(pinia)

export {
	importExportStore,
	settingsStore,
}

export { useSettingsStore }
