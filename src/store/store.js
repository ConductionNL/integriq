// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Central store registry — post chain-C OR-cutover + post chain-E
// orphan-tree sweep + post custom-Import drop.
//
// The 11 per-schema CRUD stores (source/endpoints/consumer/event/job/log/
// mapping/rule/synchronization/contract/webhooks) were deleted in the
// cutover; the UI-only navigation + search stores were deleted alongside
// the legacy modal/sidebar trees they were the only consumers of; the
// importExport store followed when the openconnector-specific Import
// page was dropped in favour of per-index CnMassImportDialog.
// nc-vue's CnIndexPage/CnDetailPage/CnLogsPage manage list/detail/log
// state internally against OR's `/api/objects/openconnector/{schema}/*`
// endpoints, so app-local stores for those resources are redundant.
//
// What's left:
//   - settings — app-level settings cache (small; survives cutover until
//     OR's /api/settings/* is fully adopted)
//
// Connector-specific action stores (useJobRunner, useSourceTester,
// useSyncTrigger, etc. — chain-D2 spec) will be added under
// `src/store/actions/` as they get implemented as v2 widget slots.

import pinia from '../pinia.js'
import { useSettingsStore } from './modules/settings.js'

const settingsStore = useSettingsStore(pinia)

export {
	settingsStore,
}

export { useSettingsStore }
