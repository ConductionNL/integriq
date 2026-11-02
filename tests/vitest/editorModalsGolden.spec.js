// @vitest-environment jsdom

import { translate } from '@nextcloud/l10n'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation of the consumer, rule and mapping editor dialogs: their
 * markup on create and edit, every template listener and the draft change
 * it causes, name validation, reopening, cancel, and save through the slot
 * confirm binding with its toasts and errors. The consumer editor also pins
 * its authorization helper text and its JSON check of the credential config.
 *
 * @spec openspec/specs/consumer-management/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import ConsumerEditorModal from '@/modals/v2/ConsumerEditorModal.vue'
import MappingEditorModal from '@/modals/v2/MappingEditorModal.vue'
import RuleEditorModal from '@/modals/v2/RuleEditorModal.vue'
import SynchronizationEditorModal from '@/modals/v2/SynchronizationEditorModal.vue'

const { toasts, post } = vi.hoisted(() => ({ toasts: [], post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn().mockResolvedValue({ data: { results: [] } }),
		post,
	},
}))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (text) => toasts.push(['error', text]),
	showSuccess: (text) => toasts.push(['success', text]),
}))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name) =>
		defineComponent({
			name,
			inheritAttrs: false,
			props: [
				'modelValue',
				'label',
				'disabled',
				'error',
				'helperText',
				'name',
				'variant',
			],
			render() {
				return h(
					'div',
					{
						class: name,
						'data-label': this.label,
						'data-disabled': this.disabled ? 'yes' : undefined,
						'data-error': this.error ? 'yes' : undefined,
						'data-helper': this.helperText || undefined,
						'data-name': this.name,
					},
					[
						this.$slots.icon?.(),
						this.$slots.default?.(),
						this.$slots.actions?.(),
					],
				)
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
					this.$slots.title?.(),
					this.$slots.default?.(),
				])
			},
		})
	return {
		CnJsonViewer: stub('CnJsonViewer'),
		CnTab: stub('CnTab'),
		CnTabs: stub('CnTabs'),
		createObjectStore: () => () => ({}),
	}
})

globalThis.t = translate
post.mockResolvedValue({ data: {} })

const STUBS = {
	SyncConfigWidget: true,
	SyncMappingPicker: true,
	SyncReferenceList: true,
	SyncWriteBackFields: true,
	FlowForm: true,
	RuleConditionGroup: true,
	MappingResultPanel: true,
	MappingRulesEditor: true,
}

// A picked value that reads as a string to text inputs and carries an id for
// the select handlers that store `option.id`.
const PICKED = Object.assign(new String('picked'), { id: 'picked-id' })

const MODALS = [
	{
		name: 'ConsumerEditorModal',
		component: ConsumerEditorModal,
		item: {
			id: 'c1',
			name: 'Partner',
			description: 'd',
			domains: ['a.example'],
			ips: ['10.0.0.1'],
			authorizationType: 'jwt',
			rateLimitRequestsPerWindow: 10,
			rateLimitWindowSeconds: 60,
		},
	},
	{
		name: 'RuleEditorModal',
		component: RuleEditorModal,
		item: {
			id: 'r1',
			name: 'Rule',
			action: 'mapping',
			timing: 'before',
			conditions: [],
		},
		ready: { action: 'mapping' },
	},
	{
		name: 'MappingEditorModal',
		component: MappingEditorModal,
		item: { id: 'm1', name: 'Mapping', mapping: { a: '{{ b }}' }, unset: ['x'] },
	},
	{
		name: 'SynchronizationEditorModal',
		component: SynchronizationEditorModal,
		item: {
			id: 's1',
			name: 'Sync',
			sourceType: 'api',
			targetType: 'register/schema',
			sourceConfig: { disappearancePolicy: 'delete' },
		},
	},
]

/**
 * The rendered markup without the scoped-style ids, which follow the file,
 * and without HTML comments (template comments and v-if placeholders).
 *
 * @param {object} wrapper the mounted dialog
 * @return {string} the markup
 */
function markup(wrapper) {
	return wrapper
		.html()
		.replace(/ data-v-[0-9a-f]+=""/g, '')
		.replace(/\n?\s*<!--[\s\S]*?-->/g, '')
}

/**
 * Mount a dialog open on an item (or on create).
 *
 * @param {object} modal the modal case
 * @param {object} props extra props
 * @return {object} the wrapper
 */
function mountModal(modal, props = {}) {
	return mount(modal.component, {
		props: {
			show: true,
			item: null,
			confirm: vi.fn(),
			close: vi.fn(),
			...props,
		},
		global: { mocks: { t: translate, n: (app, one) => one }, stubs: STUBS },
	})
}

/**
 * Fire every listener of every child component once and record the drafts.
 *
 * @param {object} wrapper the mounted dialog
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
			if (
				!/^on[A-Z]/.test(key)
				|| typeof attrs[key] !== 'function'
				|| key === 'onClick'
			) {
				continue
			}
			for (const value of [PICKED, '42']) {
				const before = JSON.stringify(wrapper.vm.draft)
				attrs[key](value)
				await flushPromises()
				const after = JSON.stringify(wrapper.vm.draft)
				steps.push([
					child.vm.$options.name,
					key,
					before === after ? 'unchanged' : JSON.parse(after),
				])
			}
		}
	}
	return steps
}

describe.each(MODALS)('$name', (modal) => {
	it.each([
		['create', null],
		['edit', 'item'],
	])('renders %s as recorded', async (_label, item) => {
		const wrapper = mountModal(modal, { item: item ? modal.item : null })
		await flushPromises()
		expect(markup(wrapper)).toMatchSnapshot()
		expect([wrapper.vm.isCreate, wrapper.vm.nameLabel]).toMatchSnapshot()
	})

	it('writes each listener into the draft as recorded', async () => {
		const wrapper = mountModal(modal, { item: modal.item })
		await flushPromises()
		expect(await fireAll(wrapper)).toMatchSnapshot()
	})

	it.each(['', '  ', '!!', 'Ok', undefined])(
		'validates the name %j',
		async (name) => {
			const wrapper = mountModal(modal)
			await flushPromises()
			const before = wrapper.vm.nameError
			wrapper.vm.draft.name = name
			const untouched = wrapper.vm.nameError
			wrapper.vm.nameTouched = true
			expect([before, untouched, wrapper.vm.nameError]).toMatchSnapshot()
		},
	)

	it('reseeds the draft when it opens again', async () => {
		const wrapper = mountModal(modal, { item: modal.item, show: false })
		await wrapper.setProps({ show: true })
		wrapper.vm.draft.name = 'changed'
		wrapper.vm.nameTouched = true
		await wrapper.setProps({ show: false })
		await wrapper.setProps({ show: true })
		expect([wrapper.vm.draft.name, wrapper.vm.nameTouched]).toEqual([
			modal.item.name,
			false,
		])
	})

	it('cancels unless a save is running', async () => {
		const close = vi.fn()
		const wrapper = mountModal(modal, { close })
		wrapper.vm.saving = true
		wrapper.vm.onCancel()
		wrapper.vm.saving = false
		wrapper.vm.onCancel()
		expect(close).toHaveBeenCalledTimes(1)
		mountModal(modal, { close: null }).vm.onCancel()
	})

	it.each([
		['create', null, () => Promise.resolve()],
		['edit', 'item', () => Promise.resolve()],
		['a failed save', 'item', () => Promise.reject(new Error('boom'))],
		['a failed save without message', 'item', () => Promise.reject({})],
	])('saves on %s as recorded', async (_label, item, answer) => {
		toasts.length = 0
		const calls = []
		const close = vi.fn()
		const confirm = vi.fn((payload) => {
			calls.push(JSON.parse(JSON.stringify(payload)))
			return answer()
		})
		const wrapper = mountModal(modal, {
			item: item ? modal.item : null,
			confirm,
			close,
		})
		await flushPromises()
		Object.assign(wrapper.vm.draft, { name: 'Named', ...modal.ready })
		wrapper.vm.saveError = 'old'
		const saving = wrapper.vm.onSave()
		const during = wrapper.vm.saving
		await saving
		expect({
			during,
			calls,
			toasts: [...toasts],
			closed: close.mock.calls.length,
			saving: wrapper.vm.saving,
			saveError: wrapper.vm.saveError,
		}).toMatchSnapshot()
	})

	it('does not save while it cannot', async () => {
		const confirm = vi.fn()
		const wrapper = mountModal(modal, { confirm })
		await flushPromises()
		wrapper.vm.draft.name = ''
		await wrapper.vm.onSave()
		const noConfirm = mountModal(modal, { confirm: null })
		noConfirm.vm.draft.name = 'Named'
		await noConfirm.vm.onSave()
		expect(confirm).not.toHaveBeenCalled()
	})
})

describe('SynchronizationEditorModal disappearance policy', () => {
	it.each([
		[
			'a refused policy',
			() =>
				Promise.reject({
					response: { status: 400, data: { error: 'unknown' } },
				}),
		],
		[
			'a refused policy without reason',
			() => Promise.reject({ response: { status: 400 } }),
		],
		['a failed check', () => Promise.reject(new Error('down'))],
	])('stops the save on %s', async (_label, answer) => {
		toasts.length = 0
		post.mockImplementationOnce(answer)
		const confirm = vi.fn()
		const close = vi.fn()
		const wrapper = mountModal(MODALS[3], {
			item: MODALS[3].item,
			confirm,
			close,
		})
		await flushPromises()
		await wrapper.vm.onSave()
		expect({
			confirmed: confirm.mock.calls.length,
			closed: close.mock.calls.length,
			toasts: [...toasts],
			saving: wrapper.vm.saving,
			saveError: wrapper.vm.saveError,
		}).toMatchSnapshot()
	})
})

describe('RuleEditorModal required fields', () => {
	it.each(['type', 'action'])('does not save without a %s', async (field) => {
		const confirm = vi.fn()
		const wrapper = mountModal(MODALS[1], { item: MODALS[1].item, confirm })
		await flushPromises()
		wrapper.vm.draft[field] = ''
		await wrapper.vm.onSave()
		expect([wrapper.vm.canSave, confirm.mock.calls.length]).toEqual([false, 0])
	})
})

describe('ConsumerEditorModal credential', () => {
	const consumer = MODALS[0]

	it.each(['apiKey', 'jwt', 'none', 'oauth', undefined])(
		'explains authorization type %s',
		async (type) => {
			const wrapper = mountModal(consumer)
			wrapper.vm.draft.authorizationType = type
			expect(wrapper.vm.authorizationTypeHelper).toMatchSnapshot()
		},
	)

	it('checks the credential config as recorded', async () => {
		const wrapper = mountModal(consumer, { item: consumer.item })
		await flushPromises()
		const steps = []
		for (const text of [
			'{"a":1}',
			'[1]',
			'null',
			'"x"',
			'{bad',
			'   ',
			'',
			null,
		]) {
			wrapper.vm.authConfigCleared = true
			wrapper.vm.saveError = 'old'
			wrapper.vm.onAuthConfigInput(text)
			steps.push([
				text,
				wrapper.vm.authConfigText,
				wrapper.vm.authConfigCleared,
				wrapper.vm.saveError,
				wrapper.vm.authConfigError,
			])
		}
		expect(steps).toMatchSnapshot()
	})
})
