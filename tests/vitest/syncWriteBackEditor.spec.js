// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The synchronisation editor declares what a push writes back onto the
 * object that started it (connectors-case-system-document-delivery Task 1,
 * REQ-CSD-001).
 *
 * The engine reads `synchronization.writeBack.onSuccess` / `onFailure` and
 * the schema carries both since 1.2.0, but no screen set them, so a
 * write-back could only be written by hand in JSON. These tests mount the
 * real modal and the real write-back fields and assert what they write.
 *
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-push-writes-its-outcome-back-onto-the-object-that-started-it-req-csd-001
 */
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SynchronizationEditorModal from '@/modals/v2/SynchronizationEditorModal.vue'
import SyncWriteBackFields from '@/views/Synchronization/SyncWriteBackFields.vue'
import {
	WRITE_BACK_PLACEHOLDERS,
	writeBackFromRows,
	writeBackRows,
} from '@/views/Synchronization/writeBack.js'

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name) =>
		defineComponent({
			name,
			render() {
				return h('div', this.$slots.default?.())
			},
		})
	return { CnTab: stub('CnTab'), CnTabs: stub('CnTabs') }
})
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props = []) =>
		defineComponent({
			name,
			props,
			emits: ['click', 'update:modelValue'],
			render() {
				return h('div', { class: name }, [
					this.$slots.icon?.(),
					this.$slots.default?.(),
				])
			},
		})
	const named = {
		NcButton: stub('NcButton', ['variant', 'disabled', 'title', 'ariaLabel']),
		NcDialog: stub('NcDialog', ['name', 'size', 'open']),
		NcNoteCard: stub('NcNoteCard', ['type']),
		NcSelect: stub('NcSelect', [
			'inputId',
			'inputLabel',
			'ariaLabelCombobox',
			'modelValue',
			'options',
			'clearable',
			'disabled',
		]),
		NcTextArea: stub('NcTextArea', ['label', 'modelValue']),
		NcTextField: stub('NcTextField', ['label', 'modelValue', 'disabled']),
	}
	return new Proxy(named, {
		get: (target, key) =>
			key in target
				? target[key]
				: typeof key === 'string' && /^Nc/.test(key)
					? stub(key)
					: undefined,
		has: (target, key) =>
			key in target || (typeof key === 'string' && /^Nc/.test(key)),
	})
})

const root = join(__dirname, '..', '..')
const mocks = { t: (app, text, vars) => text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m)), n: (app, one) => one }

/**
 * Mount the editor over one synchronisation.
 *
 * @param {object} item the synchronisation being edited
 * @param {Function} confirm the save binding
 * @return {object} the wrapper
 */
function mountEditor(item, confirm = vi.fn().mockResolvedValue(undefined)) {
	return mount(SynchronizationEditorModal, {
		props: { show: true, item, confirm, close: vi.fn() },
		global: {
			mocks,
			stubs: {
				SyncConfigWidget: true,
				SyncMappingPicker: true,
				SyncReferenceList: true,
				RuleConditionGroup: true,
			},
		},
	})
}

/**
 * The text fields of one side of the write-back fields.
 *
 * @param {object} wrapper the mounted fields
 * @param {string} side onSuccess or onFailure
 * @return {Array<object>} the NcTextFields of that side, field then value per row
 */
function fieldsOf(wrapper, side) {
	return wrapper
		.find(`[data-testid="sync-write-back-${side}"]`)
		.findAllComponents({ name: 'NcTextField' })
}

/**
 * A button of the write-back fields by its test id.
 *
 * @param {object} wrapper the mounted fields
 * @param {string} testId the button's data-testid
 * @return {object} the NcButton
 */
function button(wrapper, testId) {
	return wrapper
		.findAllComponents({ name: 'NcButton' })
		.find((candidate) => candidate.attributes('data-testid') === testId)
}

const pushSync = {
	id: 's1',
	name: 'Levering naar zaaksysteem',
	sourceType: 'register/schema',
	targetType: 'api',
	sourceConfig: {},
	writeBack: {
		onSuccess: { documentUrl: '{{ response.url }}', deliveryStatus: 'delivered' },
		onFailure: { deliveryError: '{{ error.message }}' },
	},
}

describe('the write-back rows helpers', () => {
	it('turns a stored write-back into rows and back without losing a value', () => {
		const rows = writeBackRows(pushSync.writeBack)
		expect(rows.onSuccess).toEqual([
			{ field: 'documentUrl', value: '{{ response.url }}' },
			{ field: 'deliveryStatus', value: 'delivered' },
		])
		expect(rows.onFailure).toEqual([
			{ field: 'deliveryError', value: '{{ error.message }}' },
		])
		expect(writeBackFromRows(rows)).toEqual(pushSync.writeBack)
	})

	it('drops rows without a field name and a side without rows', () => {
		expect(
			writeBackFromRows({
				onSuccess: [
					{ field: ' zaakUrl ', value: '{{ response.url }}' },
					{ field: '', value: 'orphan' },
				],
				onFailure: [],
			}),
		).toEqual({ onSuccess: { zaakUrl: '{{ response.url }}' } })
		expect(writeBackFromRows({ onSuccess: [], onFailure: [] })).toBeNull()
	})

	it('reads nothing from a missing or malformed write-back', () => {
		expect(writeBackRows(undefined)).toEqual({ onSuccess: [], onFailure: [] })
		expect(writeBackRows({ onSuccess: 'x', onFailure: ['y'] })).toEqual({
			onSuccess: [],
			onFailure: [],
		})
	})

	it('names exactly the placeholders the engine fills', () => {
		const engine = readFileSync(
			join(root, 'lib/Service/Synchronization/OutcomeWriteBack.php'),
			'utf8',
		)
		expect(WRITE_BACK_PLACEHOLDERS).toEqual([
			'{{ response.* }}',
			'{{ status }}',
			'{{ targetId }}',
			'{{ error.message }}',
		])
		for (const placeholder of ['response.*', 'status', 'targetId', 'error.message']) {
			expect(engine).toContain(placeholder)
		}
	})
})

describe('the write-back fields', () => {
	it('shows one field and value input per stored entry, labelled', () => {
		const wrapper = mount(SyncWriteBackFields, {
			props: { value: pushSync.writeBack },
			global: { mocks },
		})
		const success = fieldsOf(wrapper, 'onSuccess')
		expect(success).toHaveLength(4)
		expect(success[0].props('label')).toBe('Field')
		expect(success[0].props('modelValue')).toBe('documentUrl')
		expect(success[1].props('label')).toBe('Value')
		expect(success[1].props('modelValue')).toBe('{{ response.url }}')
		expect(fieldsOf(wrapper, 'onFailure')).toHaveLength(2)
		expect(wrapper.text()).toContain('{{ error.message }}')
	})

	it('adds a row, and emits the write-back once the row has a field name', async () => {
		const wrapper = mount(SyncWriteBackFields, {
			props: { value: null },
			global: { mocks },
		})
		await button(wrapper, 'sync-write-back-add-onFailure').vm.$emit('click')
		const failure = fieldsOf(wrapper, 'onFailure')
		expect(failure).toHaveLength(2)

		await failure[0].vm.$emit('update:modelValue', 'deliveryError')
		await failure[1].vm.$emit('update:modelValue', '{{ error.message }}')

		const emitted = wrapper.emitted('update:value')
		expect(emitted.at(-1)[0]).toEqual({
			onFailure: { deliveryError: '{{ error.message }}' },
		})
	})

	it('removes a row and emits null when nothing is left', async () => {
		const wrapper = mount(SyncWriteBackFields, {
			props: { value: { onFailure: { deliveryError: '{{ error.message }}' } } },
			global: { mocks },
		})
		await button(wrapper, 'sync-write-back-remove-onFailure-0').vm.$emit('click')

		expect(fieldsOf(wrapper, 'onFailure')).toHaveLength(0)
		expect(wrapper.emitted('update:value').at(-1)[0]).toBeNull()
	})
})

describe('the write-back on the synchronisation editor', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
		get.mockResolvedValue({ data: { enabled: false } })
		post.mockResolvedValue({ data: { valid: true } })
	})

	it('offers the write-back for a register/schema source, seeded from the record', async () => {
		const wrapper = mountEditor(pushSync)
		await flushPromises()

		const fields = wrapper.findComponent(SyncWriteBackFields)
		expect(fields.exists()).toBe(true)
		expect(fields.props('value')).toEqual(pushSync.writeBack)
	})

	it('does not offer it for an API source, where the engine never writes back', async () => {
		const wrapper = mountEditor({ ...pushSync, sourceType: 'api' })
		await flushPromises()

		expect(wrapper.findComponent(SyncWriteBackFields).exists()).toBe(false)
	})

	it('saves the edited write-back', async () => {
		const confirm = vi.fn().mockResolvedValue(undefined)
		const wrapper = mountEditor(pushSync, confirm)
		await flushPromises()

		const edited = { onSuccess: { zaakUrl: '{{ response.url }}' } }
		wrapper.findComponent(SyncWriteBackFields).vm.$emit('update:value', edited)
		await wrapper.vm.onSave()

		expect(confirm).toHaveBeenCalledTimes(1)
		expect(confirm.mock.calls[0][0].writeBack).toEqual(edited)
	})

	it('clears a write-back the user emptied instead of keeping the stored one', async () => {
		const confirm = vi.fn().mockResolvedValue(undefined)
		const wrapper = mountEditor(pushSync, confirm)
		await flushPromises()

		wrapper.findComponent(SyncWriteBackFields).vm.$emit('update:value', null)
		await wrapper.vm.onSave()

		expect(confirm.mock.calls[0][0].writeBack).toEqual({})
	})

	it('saves no write-back key for a synchronization that never had one', async () => {
		const confirm = vi.fn().mockResolvedValue(undefined)
		const { writeBack, ...withoutWriteBack } = pushSync
		const wrapper = mountEditor(withoutWriteBack, confirm)
		await flushPromises()

		await wrapper.vm.onSave()

		expect(writeBack).toBeTruthy()
		expect(confirm.mock.calls[0][0]).not.toHaveProperty('writeBack')
	})
})
