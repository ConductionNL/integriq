// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * MappingDetailPage against a fake object store: registration and fetch on
 * mount, the live subscription, what the header and body derive from the
 * cached mapping, each child edit persisted as a merged save with the right
 * toast, the test-modal bus event and the preview reset.
 *
 * @spec openspec/specs/mapping-editor-ui/spec.md
 */
import { translate } from '@nextcloud/l10n'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'
import MappingDetailPage from '@/views/wrappers/MappingDetailPage.vue'
import { EVENT_OPEN_TEST_MAPPING, modalBus } from '@/handlers/modalBus.js'

const store = vi.hoisted(() => ({ current: null }))

vi.mock('@/store/objectStore.js', () => ({
	useObjectStore: () => store.current,
}))
vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		NcButton: defineComponent({
			name: 'NcButton',
			props: ['variant', 'disabled'],
			emits: ['click'],
			render() {
				return h(
					'button',
					{
						class: 'NcButton',
						disabled: this.disabled || undefined,
						onClick: () => this.$emit('click'),
					},
					this.$slots.default?.(),
				)
			},
		}),
		NcCheckboxRadioSwitch: defineComponent({
			name: 'NcCheckboxRadioSwitch',
			props: ['modelValue', 'disabled'],
			emits: ['update:modelValue'],
			render() {
				return h('label', { class: 'NcCheckboxRadioSwitch' }, this.$slots.default?.())
			},
		}),
	}
})
vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		CnDetailPage: defineComponent({
			name: 'CnDetailPage',
			props: [
				'title',
				'description',
				'loading',
				'error',
				'errorMessage',
				'onRetry',
				'objectType',
				'objectId',
				'sidebar',
				'subscribe',
			],
			render() {
				return h('div', { class: 'CnDetailPage' }, [
					this.$slots.actions?.(),
					this.$slots.default?.(),
				])
			},
		}),
	}
})
vi.mock('@/components/AutomationDeprecationNotice.vue', () => ({
	default: { name: 'AutomationDeprecationNotice', render: () => null },
}))
vi.mock('@/components/mapping/MappingCallableByField.vue', () => ({
	default: {
		name: 'MappingCallableByField',
		props: ['value', 'disabled'],
		emits: ['update'],
		render: () => null,
	},
}))
vi.mock('@/views/wrappers/MappingRulesEditor.vue', () => ({
	default: {
		name: 'MappingRulesEditor',
		props: ['mappingRules', 'castRules', 'unsetRules', 'passThrough', 'saving'],
		emits: ['update-mapping', 'update-cast', 'update-unset'],
		render: () => null,
	},
}))
const resultReset = vi.hoisted(() => vi.fn())
vi.mock('@/components/mapping/MappingResultPanel.vue', () => ({
	default: {
		name: 'MappingResultPanel',
		props: ['mapping', 'inputObject'],
		emits: ['inputError'],
		methods: { reset: resultReset },
		render: () => null,
	},
}))

const { showError, showSuccess } = await import('@nextcloud/dialogs')

const MAPPING = {
	id: '7',
	name: 'Zaak to case',
	description: 'Maps a zaak',
	passThrough: false,
	mapping: '{"title":"{{ omschrijving }}"}',
	cast: { year: 'integer' },
	unset: ['secret'],
	callableBy: ['openregister'],
}

/**
 * A fake object store holding the given cached mappings.
 *
 * @param {object} objects id → cached mapping
 * @return {object} the reactive store
 */
function fakeStore(objects = { 7: { ...MAPPING } }) {
	const fake = reactive({
		objects,
		loading: {},
		errors: {},
		objectTypeRegistry: {},
		getObject: (type, id) => (type === 'mapping' ? fake.objects[id] : undefined),
		registerObjectType: vi.fn(),
		fetchObject: vi.fn(async () => null),
		saveObject: vi.fn(async (_type, obj) => {
			fake.objects[obj.id] = obj
			return obj
		}),
		subscribe: vi.fn(async () => ({ handle: 1 })),
		unsubscribe: vi.fn(),
	})
	return fake
}

/**
 * Mount the page.
 *
 * @param {object} props page props
 * @return {object} the wrapper
 */
function mountPage(props = { id: 7 }) {
	return mount(MappingDetailPage, { props, global: { mocks: { t: translate } } })
}

/**
 * The props CnDetailPage received.
 *
 * @param {object} wrapper the page
 * @return {object} the props
 */
function header(wrapper) {
	return wrapper.findComponent({ name: 'CnDetailPage' }).props()
}

beforeEach(() => {
	store.current = fakeStore()
	vi.mocked(showError).mockClear()
	vi.mocked(showSuccess).mockClear()
	resultReset.mockClear()
})

describe('MappingDetailPage mount', () => {
	it('registers the type, fetches the mapping and subscribes to it', async () => {
		mountPage({ id: 7, register: 'reg', schema: 'sch' })
		await flushPromises()
		expect(store.current.registerObjectType).toHaveBeenCalledWith('mapping', 'sch', 'reg', {
			registerSlug: 'reg',
			schemaSlug: 'sch',
		})
		expect(store.current.fetchObject).toHaveBeenCalledWith('mapping', '7')
		expect(store.current.subscribe).toHaveBeenCalledWith('mapping', '7')
	})

	it('does not register a type the store already knows', () => {
		store.current.objectTypeRegistry = { mapping: {} }
		mountPage()
		expect(store.current.registerObjectType).not.toHaveBeenCalled()
	})

	it('tolerates a store without registerObjectType', () => {
		store.current.registerObjectType = undefined
		expect(() => mountPage()).not.toThrow()
		expect(store.current.fetchObject).toHaveBeenCalled()
	})

	it('neither fetches nor subscribes without an id', async () => {
		const wrapper = mountPage({ id: '' })
		await flushPromises()
		expect(store.current.fetchObject).not.toHaveBeenCalled()
		expect(store.current.subscribe).not.toHaveBeenCalled()
		await expect(wrapper.vm.reload()).resolves.toBeNull()
		expect(header(wrapper).title).toBe('Mapping')
		expect(wrapper.find('.cn-mapping-detail').exists()).toBe(false)
	})

	it('releases the live subscription on unmount', async () => {
		const wrapper = mountPage()
		await flushPromises()
		wrapper.unmount()
		expect(store.current.unsubscribe).toHaveBeenCalledWith({ handle: 1 })
	})
})

describe('MappingDetailPage rendering', () => {
	it('passes the cached mapping to the header and sidebar', () => {
		const props = header(mountPage({ id: 7, register: 'reg', schema: 'sch' }))
		expect(props).toMatchObject({
			title: 'Zaak to case',
			description: 'Maps a zaak',
			loading: false,
			error: false,
			errorMessage: '',
			objectType: 'mapping',
			objectId: '7',
			subscribe: true,
			sidebar: { enabled: true, show: true, register: 'reg', schema: 'sch', showMetadata: true },
		})
	})

	it('wires retry to a refetch', async () => {
		const wrapper = mountPage()
		store.current.fetchObject.mockClear()
		await header(wrapper).onRetry()
		expect(store.current.fetchObject).toHaveBeenCalledWith('mapping', '7')
	})

	it('normalises the rule collections for the editor', () => {
		const editor = mountPage().findComponent({ name: 'MappingRulesEditor' })
		expect(editor.props()).toEqual({
			mappingRules: { title: '{{ omschrijving }}' },
			castRules: { year: 'integer' },
			unsetRules: ['secret'],
			passThrough: false,
			saving: false,
		})
	})

	it('shows the general card with dashes for missing fields', () => {
		store.current.objects[7] = { id: '7', passThrough: 1 }
		const wrapper = mountPage()
		expect(wrapper.findAll('dd').map((d) => d.text()).slice(0, 2)).toEqual(['-', '-'])
		const toggle = wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' })
		expect(toggle.props('modelValue')).toBe(true)
		expect(toggle.text()).toBe('Pass through enabled')
		expect(wrapper.findComponent({ name: 'MappingCallableByField' }).props('value')).toEqual([])
		expect(header(wrapper).title).toBe('Mapping')
	})

	it('labels a disabled pass-through', () => {
		const toggle = mountPage().findComponent({ name: 'NcCheckboxRadioSwitch' })
		expect(toggle.text()).toBe('Pass through disabled')
	})

	it('reports loading only while nothing is cached', () => {
		store.current.loading = { mapping: true }
		expect(header(mountPage()).loading).toBe(false)
		store.current.objects = {}
		expect(header(mountPage()).loading).toBe(true)
	})

	it('reports a load error with its message or a fallback', () => {
		store.current.errors = { mapping: { message: 'Gone' } }
		expect(header(mountPage())).toMatchObject({ error: true, errorMessage: 'Gone' })
		store.current.errors = { mapping: {} }
		expect(header(mountPage())).toMatchObject({
			error: true,
			errorMessage: 'Failed to load mapping',
		})
	})
})

describe('MappingDetailPage persistence', () => {
	it.each([
		['MappingRulesEditor', 'update-mapping', { a: 'b' }, { mapping: { a: 'b' } }],
		['MappingRulesEditor', 'update-cast', { a: 'float' }, { cast: { a: 'float' } }],
		['MappingRulesEditor', 'update-unset', ['x'], { unset: ['x'] }],
		['MappingCallableByField', 'update', ['app'], { callableBy: ['app'] }],
	])('%s %s saves the merged mapping', async (child, event, payload, patch) => {
		const wrapper = mountPage()
		wrapper.findComponent({ name: child }).vm.$emit(event, payload)
		await flushPromises()
		expect(store.current.saveObject).toHaveBeenCalledWith('mapping', { ...MAPPING, ...patch })
		expect(showSuccess).toHaveBeenCalledWith('Mapping saved')
		expect(showError).not.toHaveBeenCalled()
	})

	it('coerces the pass-through switch to a boolean', async () => {
		const wrapper = mountPage()
		wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', 1)
		await flushPromises()
		expect(store.current.saveObject.mock.calls[0][1].passThrough).toBe(true)
	})

	it('disables the controls while a save is running', async () => {
		let finish
		store.current.saveObject.mockImplementation(
			() => new Promise((resolve) => (finish = resolve)),
		)
		const wrapper = mountPage()
		wrapper.findComponent({ name: 'MappingRulesEditor' }).vm.$emit('update-unset', [])
		await wrapper.vm.$nextTick()
		expect(wrapper.findComponent({ name: 'MappingRulesEditor' }).props('saving')).toBe(true)
		expect(wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).props('disabled')).toBe(true)
		finish({ ...MAPPING })
		await flushPromises()
		expect(wrapper.findComponent({ name: 'MappingRulesEditor' }).props('saving')).toBe(false)
	})

	it('shows the store error when the save returns nothing', async () => {
		store.current.saveObject.mockImplementation(async () => {
			store.current.errors = { mapping: { message: 'Conflict' } }
			return null
		})
		const wrapper = mountPage()
		await wrapper.vm.onUpdateCast({})
		expect(showError).toHaveBeenCalledWith('Conflict')
		expect(showSuccess).not.toHaveBeenCalled()
		expect(wrapper.vm.saving).toBe(false)
	})

	it('falls back to a generic save error', async () => {
		store.current.saveObject.mockResolvedValue(null)
		await mountPage().vm.onUpdateMapping({})
		expect(showError).toHaveBeenCalledWith('Failed to save mapping')
	})

	it('clears the saving flag when the save throws', async () => {
		store.current.saveObject.mockRejectedValue(new Error('network'))
		const wrapper = mountPage()
		await expect(wrapper.vm.onUpdateUnset([])).rejects.toThrow('network')
		expect(wrapper.vm.saving).toBe(false)
	})

	it('saves nothing while no mapping is loaded', async () => {
		store.current.objects = {}
		await mountPage().vm.onTogglePassThrough(true)
		expect(store.current.saveObject).not.toHaveBeenCalled()
	})
})

describe('MappingDetailPage actions', () => {
	it('opens the test modal with the current mapping', async () => {
		const seen = vi.fn()
		modalBus.on(EVENT_OPEN_TEST_MAPPING, seen)
		try {
			const wrapper = mountPage()
			const button = wrapper.findAll('button.NcButton').find((b) => b.text() === 'Test mapping')
			expect(button.attributes('disabled')).toBeUndefined()
			await button.trigger('click')
			expect(seen).toHaveBeenCalledWith({ mapping: MAPPING })
		} finally {
			modalBus.off(EVENT_OPEN_TEST_MAPPING, seen)
		}
	})

	it('disables and guards the test modal without a mapping', () => {
		store.current.objects = {}
		const seen = vi.fn()
		modalBus.on(EVENT_OPEN_TEST_MAPPING, seen)
		try {
			const wrapper = mountPage()
			const button = wrapper.findAll('button.NcButton').find((b) => b.text() === 'Test mapping')
			expect(button.attributes('disabled')).toBeDefined()
			wrapper.vm.openTestModal()
			expect(seen).not.toHaveBeenCalled()
		} finally {
			modalBus.off(EVENT_OPEN_TEST_MAPPING, seen)
		}
	})

	it('feeds the sample input to the preview and shows its parse error', async () => {
		const wrapper = mountPage()
		const panel = wrapper.findComponent({ name: 'MappingResultPanel' })
		expect(panel.props('inputObject')).toBe('{}')
		await wrapper.find('textarea').setValue('{"name": 1')
		expect(panel.props('inputObject')).toBe('{"name": 1')
		panel.vm.$emit('inputError', 'Unexpected end of JSON')
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.cn-mapping-detail__error').text()).toBe('Unexpected end of JSON')
	})

	it('resets the sample, the error and the result panel', async () => {
		const wrapper = mountPage()
		await wrapper.find('textarea').setValue('{"a":1}')
		wrapper.findComponent({ name: 'MappingResultPanel' }).vm.$emit('inputError', 'bad')
		await wrapper.vm.$nextTick()
		const reset = wrapper.findAll('button.NcButton').find((b) => b.text() === 'Reset preview')
		await reset.trigger('click')
		expect(wrapper.find('textarea').element.value).toBe('{}')
		expect(wrapper.find('.cn-mapping-detail__error').exists()).toBe(false)
		expect(resultReset).toHaveBeenCalledTimes(1)
	})
})
