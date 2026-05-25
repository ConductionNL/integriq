// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Global integration-registration entry (Path 2).
//
// Loaded on EVERY Nextcloud page via `\OCP\Util::addInitScript` (see
// lib/AppInfo/Application.php) — NOT the per-app SPA. That's what lets
// OpenConnector's "Synced from" component render inside OTHER apps'
// detail pages (e.g. an OpenCatalogi publication) where OpenConnector's
// main SPA bundle is never loaded.
//
// Kept deliberately tiny: import the component + register it. The
// registry helper is load-order-safe — if OpenRegister hasn't installed
// its singleton yet, this queues and OR replays on install.

import { registerIntegration } from '@conduction/nextcloud-vue'
import SyncedFromTab from './integration/SyncedFromTab.vue'

registerIntegration({
	id: 'sync-contract',
	label: 'Synced from',
	icon: 'SyncOutline',
	group: 'workflow',
	order: 50,
	requiredApp: 'openconnector',
	// Same component drives the sidebar tab + the detail/dashboard widget
	// + the single-entity surface (AD-19 fallback to `widget`).
	tab: SyncedFromTab,
	widget: SyncedFromTab,
})
