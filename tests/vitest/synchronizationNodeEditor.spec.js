// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The flow editor's synchronization step, as an author uses it: the dialog
 * opens once the referenced synchronization has loaded (or in create mode when
 * there is none or it cannot be loaded), the step options are drafted locally
 * and never touch the node while the dialog is open, and saving persists the
 * synchronization first and then writes the reference and the non-default
 * options onto the step in one config write.
 *
 * @spec openspec/specs/sync-editor-ui/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'

const stores = vi.hoisted(() => ({ flow: null, object: null }))

vi.mock('@conduction/nextcloud-vue', () => ({ useFlowStore: () => stores.flow }))
vi.mock('../../src/store/objectStore.js', () => ({ useObjectStore: () => stores.object }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const field = (name) => defineComponent({
		name,
		props: ['modelValue', 'label', 'inputLabel', 'options', 'type', 'inputId'],
		emits: ['update:modelValue'],
		render() {
			return h('div', { 'data-field': this.label ?? this.inputLabel ?? name }, this.$slots.default?.())
		},
	})
	return { NcCheckboxRadioSwitch: field('NcCheckboxRadioSwitch'), NcSelect: field('NcSelect'), NcTextField: field('NcTextField') }
})

vi.mock('../../src/modals/v2/SynchronizationEditorModal.vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		default: defineComponent({
			name: 'SynchronizationEditorModal',
			props: ['show', 'item', 'confirm', 'close'],
			render() {
				return h('div', { 'data-show': String(this.show) }, this.$slots.hostExtras?.())
			},
		}),
	}
})

const { default: SynchronizationNodeEditor } = await import('../../src/modals/v2/SynchronizationNodeEditor.vue')

/**
 * Set up the stores around a step with the given config.
 *
 * @param {object} config The node's config.
 */
function stepWith(config) {
	stores.flow = reactive({
		editingNodeId: 'node-1',
		editingNode: { id: 'node-1', config },
		setNodeConfigById: vi.fn(),
	})
	stores.object = {
		registerObjectType: vi.fn(),
		fetchObject: vi.fn(),
		saveObject: vi.fn(),
		errors: {},
	}
}

/**
 * Mount the editor and let the referenced synchronization load.
 *
 * @return {Promise<object>} The wrapper.
 */
async function open() {
	const wrapper = mount(SynchronizationNodeEditor)
	await flushPromises()
	return wrapper
}

/**
 * A step-option field by its label.
 *
 * @param {object} wrapper The mounted editor.
 * @param {string} name The component name.
 * @param {string} label The label.
 * @return {object} The stub's wrapper.
 */
function field(wrapper, name, label) {
	return wrapper.findAllComponents({ name }).find((stub) => (stub.props('inputLabel') ?? stub.props('label') ?? name) === label)
}

const modal = (wrapper) => wrapper.getComponent({ name: 'SynchronizationEditorModal' })

beforeEach(() => {
	stepWith({})
})

describe('SynchronizationNodeEditor', () => {
	it('registers the synchronization type and opens on the loaded synchronization', async () => {
		stepWith({ synchronization: ' sync-1 ' })
		stores.object.fetchObject.mockResolvedValue({ id: 'sync-1', name: 'Zaken' })

		const wrapper = await open()

		expect(stores.object.registerObjectType).toHaveBeenCalledWith('synchronization', 'synchronization', 'integriq')
		expect(stores.object.fetchObject).toHaveBeenCalledWith('synchronization', 'sync-1')
		expect(modal(wrapper).props('show')).toBe(true)
		expect(modal(wrapper).props('item')).toEqual({ id: 'sync-1', name: 'Zaken' })
	})

	it('opens in create mode when the step points at nothing or at something unloadable', async () => {
		const blank = await open()
		expect(stores.object.fetchObject).not.toHaveBeenCalled()
		expect(modal(blank).props()).toMatchObject({ show: true, item: null })

		stepWith({ synchronization: 'gone' })
		stores.object.fetchObject.mockRejectedValue(new Error('404'))
		const missing = await open()
		expect(modal(missing).props()).toMatchObject({ show: true, item: null })
	})

	it('drafts the step options from the node, defaulting what is unset', async () => {
		stepWith({ force: true, output: 'summary', maxItems: 50, onError: 'dead_letter' })
		const wrapper = await open()

		expect(field(wrapper, 'NcCheckboxRadioSwitch', 'NcCheckboxRadioSwitch').props('modelValue')).toBe(true)
		expect(field(wrapper, 'NcTextField', 'Field to store the summary in').props('modelValue')).toBe('summary')
		expect(field(wrapper, 'NcTextField', 'Item ceiling').props('modelValue')).toBe('50')
		expect(field(wrapper, 'NcSelect', 'When the run fails').props('modelValue')).toEqual({ id: 'dead_letter', label: 'Route to dead letter' })
		expect(field(wrapper, 'NcSelect', 'When the run fails').props('inputId')).toBe('oc-sync-node-on-error-node-1')

		stepWith({ output: 7, maxItems: '5', onError: '' })
		const defaults = await open()
		expect(field(defaults, 'NcTextField', 'Field to store the summary in').props('modelValue')).toBe('')
		expect(field(defaults, 'NcTextField', 'Item ceiling').props('modelValue')).toBe('')
		expect(field(defaults, 'NcSelect', 'When the run fails').props('modelValue').id).toBe('stop')
	})

	it('edits only the draft until the dialog saves', async () => {
		const wrapper = await open()

		field(wrapper, 'NcCheckboxRadioSwitch', 'NcCheckboxRadioSwitch').vm.$emit('update:modelValue', true)
		field(wrapper, 'NcTextField', 'Field to store the summary in').vm.$emit('update:modelValue', 'result')
		field(wrapper, 'NcTextField', 'Item ceiling').vm.$emit('update:modelValue', '25')
		field(wrapper, 'NcSelect', 'When the run fails').vm.$emit('update:modelValue', { id: 'continue' })
		await flushPromises()

		expect(stores.flow.setNodeConfigById).not.toHaveBeenCalled()
		expect(stores.flow.editingNode.config).toEqual({})
		expect(field(wrapper, 'NcTextField', 'Item ceiling').props('modelValue')).toBe('25')

		field(wrapper, 'NcTextField', 'Item ceiling').vm.$emit('update:modelValue', '')
		await flushPromises()
		expect(field(wrapper, 'NcTextField', 'Item ceiling').props('modelValue')).toBe('')
	})

	it('saves the synchronization, then points the step at it with the changed options', async () => {
		stepWith({ label: 'Sync step' })
		stores.object.saveObject.mockResolvedValue({ '@self': { uuid: 'sync-9' } })
		const wrapper = await open()
		field(wrapper, 'NcCheckboxRadioSwitch', 'NcCheckboxRadioSwitch').vm.$emit('update:modelValue', true)
		field(wrapper, 'NcTextField', 'Item ceiling').vm.$emit('update:modelValue', '25')
		field(wrapper, 'NcSelect', 'When the run fails').vm.$emit('update:modelValue', { id: 'continue' })
		await flushPromises()

		await modal(wrapper).props('confirm')({ name: 'New sync' })

		expect(stores.object.saveObject).toHaveBeenCalledWith('synchronization', { name: 'New sync' })
		expect(stores.flow.setNodeConfigById).toHaveBeenCalledWith('node-1', {
			label: 'Sync step',
			synchronization: 'sync-9',
			force: true,
			maxItems: 25,
			onError: 'continue',
		})
		expect(modal(wrapper).props('item')).toEqual({ '@self': { uuid: 'sync-9' } })
	})

	it('leaves default options out of the step it writes', async () => {
		stepWith({ force: true, output: 'x', maxItems: 3, onError: 'continue' })
		stores.object.saveObject.mockResolvedValue({ id: 'sync-2' })
		const wrapper = await open()
		field(wrapper, 'NcCheckboxRadioSwitch', 'NcCheckboxRadioSwitch').vm.$emit('update:modelValue', false)
		field(wrapper, 'NcTextField', 'Field to store the summary in').vm.$emit('update:modelValue', '')
		field(wrapper, 'NcTextField', 'Item ceiling').vm.$emit('update:modelValue', '')
		field(wrapper, 'NcSelect', 'When the run fails').vm.$emit('update:modelValue', { id: 'stop' })
		await flushPromises()

		await modal(wrapper).props('confirm')({})

		expect(stores.flow.setNodeConfigById).toHaveBeenCalledWith('node-1', { synchronization: 'sync-2' })
	})

	it('refuses to touch the step when the save fails, with the store error or a default', async () => {
		stores.object.saveObject.mockResolvedValue(null)
		stores.object.errors = { synchronization: 'Source is required' }
		const wrapper = await open()

		await expect(modal(wrapper).props('confirm')({})).rejects.toThrow('Source is required')
		stores.object.errors = {}
		await expect(modal(wrapper).props('confirm')({})).rejects.toThrow('Save failed')
		expect(stores.flow.setNodeConfigById).not.toHaveBeenCalled()
	})

	it('closes by clearing the node being edited', async () => {
		const wrapper = await open()

		modal(wrapper).props('close')()

		expect(stores.flow.editingNodeId).toBeNull()
		expect(stores.flow.setNodeConfigById).not.toHaveBeenCalled()
	})
})
