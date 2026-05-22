// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Central store registry — post chain-C OR-cutover.
//
// The 11 per-schema CRUD stores (source/endpoints/consumer/event/job/log/
// mapping/rule/synchronization/contract/webhooks) were deleted in the
// cutover. nc-vue's CnIndexPage/CnDetailPage/CnLogsPage manage list/detail/
// log state internally against OR's `/api/objects/openconnector/{schema}/*`
// endpoints, so app-local stores for those resources are redundant.
//
// What's left here:
//   - generic stores (navigation, search) — UI-only state
//   - importExport — multi-step file-upload UX (the Import page stays
//     custom; see src/registry.js)
//   - settings — app-level settings cache (small; survives cutover until
//     OR's /api/settings/* is fully adopted)
//
// Connector-specific action stores (useJobRunner, useSourceTester,
// useSyncTrigger, etc. — see chain-D2 spec) will be added under
// `src/store/actions/` as they get implemented as v2 widget slots.

import pinia from '../pinia.js'
import { useNavigationStore } from './modules/navigation.js'
import { useSearchStore } from './modules/search.ts'
import { useImportExportStore } from './modules/importExport.js'
import { useSettingsStore } from './modules/settings.js'

const navigationStore = useNavigationStore(pinia)
const searchStore = useSearchStore(pinia)
const importExportStore = useImportExportStore(pinia)
const settingsStore = useSettingsStore(pinia)

export {
	navigationStore,
	searchStore,
	importExportStore,
	settingsStore,
}

export { useSettingsStore }
