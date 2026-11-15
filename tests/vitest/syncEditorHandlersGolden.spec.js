// @vitest-environment jsdom

import { translate } from '@nextcloud/l10n'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation of the template event handlers of the synchronisation
 * detail page and editor modal: every listener a child component carries is
 * fired with the same picked value, and the draft change it causes is
 * recorded.
 *
 * @spec openspec/specs/sync-editor-ui/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SynchronizationEditorModal from '@/modals/v2/SynchronizationEditorModal.vue'
import SynchronizationDetailPage from '@/views/Synchronization/SynchronizationDetailPage.vue'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn().mockResolvedValue({ data: { enabled: false } }),
		post: vi.fn(),
		put: vi.fn(),
	},
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name) =>
		defineComponent({
			name,
			inheritAttrs: false,
			render() {
				return h('div', { class: name }, [
					this.$slots.default?.(),
					this.$slots.icon?.(),
				])
			},
		})
	return new Proxy(
		{},
		{
			get: (_t, key) =>
				typeof key === 'string' && /^Nc/.test(key) ? stub(key) : undefined,
			has: (_t, key) => typeof key === 'string' && /^Nc/.test(key),
		},
	)
})
vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name) =>
		defineComponent({
			name,
			inheritAttrs: false,
			render() {
				return h('div', { class: name }, [
					this.$slots.actions?.(),
					this.$slots.title?.(),
					this.$slots.default?.(),
				])
			},
		})
	const store = {
		errors: {},
		registerObjectType: () => {},
		fetchObject: () => Promise.resolve(null),
		saveObject: () => Promise.resolve(null),
	}
	return {
		CnDetailPage: stub('CnDetailPage'),
		CnTabs: stub('CnTabs'),
		CnTab: stub('CnTab'),
		createObjectStore: () => () => store,
	}
})

globalThis.t = translate

const CHILD_STUBS = {
	SyncConfigWidget: true,
	SyncMappingPicker: true,
	SyncReferenceList: true,
	RuleConditionGroup: true,
	SyncWriteBackFields: true,
	AutomationDeprecationNotice: true,
}

// A picked value that reads as a string to text inputs and carries an id for
// the select handlers that store `option.id`.
const PICKED = Object.assign(new String('picked'), { id: 'picked-id' })

const RECORD = {
	id: 's1',
	name: 'Sync',
	sourceType: 'register/schema',
	syncMode: 'incremental',
	sourceConfig: { keep: 1 },
}

/**
 * Fire every listener of every child component once and record the drafts.
 *
 * @param {object} wrapper the mounted component
 * @return {Array} one entry per fired listener
 */
async function fireAll(wrapper) {
	const steps = []
	const children = wrapper
		.findAllComponents('*')
		.filter((c) => c.vm !== wrapper.vm && c.vm.$parent)
	for (const child of children) {
		const attrs = child.vm.$attrs || {}
		for (const key of Object.keys(attrs).sort()) {
			if (!/^on[A-Z]/.test(key) || typeof attrs[key] !== 'function') {
				continue
			}
			const before = JSON.stringify(wrapper.vm.draft)
			attrs[key](PICKED)
			await flushPromises()
			const after = JSON.stringify(wrapper.vm.draft)
			steps.push([
				child.vm.$options.name,
				key,
				before === after ? 'unchanged' : JSON.parse(after),
			])
		}
	}
	return steps
}

describe('template handlers', () => {
	it('the editor modal writes each pick as recorded', async () => {
		const wrapper = mount(SynchronizationEditorModal, {
			props: { show: true, item: RECORD, confirm: vi.fn(), close: vi.fn() },
			global: {
				mocks: { t: translate, n: (app, one) => one },
				stubs: CHILD_STUBS,
			},
		})
		await flushPromises()
		expect(await fireAll(wrapper)).toMatchSnapshot()
	})

	it('the detail page writes each pick as recorded', async () => {
		const wrapper = mount(SynchronizationDetailPage, {
			props: { id: '' },
			global: { mocks: { t: translate }, stubs: CHILD_STUBS },
		})
		await flushPromises()
		wrapper.vm.draft = wrapper.vm.normalizeForDiff(RECORD)
		await flushPromises()
		expect(await fireAll(wrapper)).toMatchSnapshot()
	})
})
