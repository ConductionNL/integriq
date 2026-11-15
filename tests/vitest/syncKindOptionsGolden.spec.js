// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation of the source, target and sync-mode selectors that the
 * synchronisation detail page and the synchronisation editor modal both
 * offer: which kinds each selector lists and which option is selected, for
 * every combination of enabled apps and stored kinds.
 *
 * The computed getters are resolved through each component's mixins and its
 * own options and run on a plain context, so the components are not mounted.
 *
 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-table-picker-for-the-nextcloud-table-sourcetarget-kind-req-syncui-006
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))
vi.mock('@nextcloud/vue', () => anyExport())
vi.mock('@conduction/nextcloud-vue', () => anyExport())

/**
 * A module mock whose every export, property and call result is itself.
 *
 * @return {object} the module
 */
function anyExport() {
	const any = new Proxy(function () {}, {
		get: (_target, name) => (name === 'then' ? undefined : any),
		has: () => true,
		apply: () => any,
	})
	return new Proxy(
		{},
		{
			get: (_target, name) => (name === 'then' ? undefined : any),
			has: () => true,
		},
	)
}

const COMPONENTS = {
	SynchronizationDetailPage: (
		await import('@/views/Synchronization/SynchronizationDetailPage.vue')
	).default,
	SynchronizationEditorModal: (
		await import('@/modals/v2/SynchronizationEditorModal.vue')
	).default,
}

const NAMES = [
	'typeOptions',
	'sourceTypeOptions',
	'selectedSourceType',
	'selectedTargetType',
	'syncModeOptions',
	'selectedSyncMode',
]

/**
 * A plain context whose computed getters read the given state.
 *
 * @param {object} component the component options
 * @param {object} state draft, tablesEnabled and formsEnabled
 * @return {object} the context
 */
function context(component, state) {
	const ctx = { ...state }
	for (const layer of [...(component.mixins || []), component]) {
		for (const [name, def] of Object.entries(layer.computed || {})) {
			const getter = typeof def === 'function' ? def : def.get
			Object.defineProperty(ctx, name, {
				configurable: true,
				get: () => getter.call(ctx),
			})
		}
	}
	return ctx
}

const KINDS = [
	undefined,
	'api',
	'register',
	'nextcloud-table',
	'nextcloud-form',
	'nope',
]
const CASES = []
for (const tablesEnabled of [false, true]) {
	for (const formsEnabled of [false, true]) {
		for (const sourceType of KINDS) {
			for (const targetType of KINDS) {
				CASES.push({ tablesEnabled, formsEnabled, sourceType, targetType })
			}
		}
	}
}

describe.each(Object.entries(COMPONENTS))('%s', (_name, component) => {
	it('lists and selects the kinds as recorded', () => {
		const trace = CASES.map(({ tablesEnabled, formsEnabled, ...draft }) => {
			const ctx = context(component, { tablesEnabled, formsEnabled, draft })
			return {
				in: { tablesEnabled, formsEnabled, ...draft },
				type: ctx.typeOptions.map((o) => o.id),
				source: ctx.sourceTypeOptions.map((o) => o.id),
				selectedSource: ctx.selectedSourceType.id,
				selectedTarget: ctx.selectedTargetType.id,
			}
		})
		expect(trace).toMatchSnapshot()
	})

	it.each([undefined, null, 'full', 'incremental', 'nope'])(
		'sync mode %s',
		(syncMode) => {
			const ctx = context(component, { draft: { syncMode } })
			expect([ctx.syncModeOptions, ctx.selectedSyncMode]).toMatchSnapshot()
		},
	)

	it('reads no draft at all', () => {
		const ctx = context(component, { draft: null })
		expect(NAMES.map((n) => ctx[n])).toMatchSnapshot()
	})
})
