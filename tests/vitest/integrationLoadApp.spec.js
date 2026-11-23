// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lazily loaded half of the integration leaf: Vue's createApp and the Synced-from app.
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('vue', () => ({ createApp: vi.fn(() => 'app') }))
vi.mock('@/integration/SyncedFromTab.vue', () => ({ default: { name: 'SyncedFromTab' } }))

describe('loadSyncedFromApp', () => {
	it('hands back createApp and the Synced-from component', async () => {
		const { loadSyncedFromApp } = await import('@/integration/loadApp.js')
		const { createApp, SyncedFromTab } = await loadSyncedFromApp()

		expect(SyncedFromTab).toEqual({ name: 'SyncedFromTab' })
		expect(createApp(SyncedFromTab)).toBe('app')
	})
})
