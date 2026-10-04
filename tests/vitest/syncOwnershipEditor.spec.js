// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The synchronisation editor declares who owns the records and what happens
 * to one the source stops sending, and refuses an unknown policy at save
 * (records-owned-by-an-external-source tasks 2 and 3, REQ-SOR-001/002).
 *
 * The engine read `sourceConfig.ownershipMode` and `disappearancePolicy` and
 * `POST /api/ownership/validate-policy` existed, but no screen set either key
 * or asked the check, so both could only be written by hand in JSON. These
 * tests mount the real modal (its sub-editors stubbed) and assert what it writes and
 * that a refused policy never reaches the save.
 *
 * @spec openspec/specs/source-owned-records/spec.md#requirement-what-happens-when-a-record-disappears-is-declared-not-hardcoded-req-sor-002
 */
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SynchronizationEditorModal from '@/modals/v2/SynchronizationEditorModal.vue'
import {
	disappearancePolicyOptions,
	ownershipModeOptions,
	sourceDestroyedOptions,
} from '@/views/Synchronization/ownershipOptions.js'

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
// The editor's children import more of @nextcloud/vue than it does; any name
// not listed resolves to a plain stub so the import graph loads.
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props = []) =>
		defineComponent({
			name,
			props,
			render() {
				return h('div', { class: name }, this.$slots.default?.())
			},
		})
	const named = {
		NcButton: stub('NcButton', ['variant', 'disabled', 'title']),
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
			mocks: { t: (app, text) => text, n: (app, one) => one },
			stubs: {
				SyncConfigWidget: true,
				SyncMappingPicker: true,
				SyncReferenceList: true,
				RuleConditionGroup: true,
			},
		},
	})
}

describe('the ownership inputs on the synchronisation editor', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
		get.mockResolvedValue({ data: { enabled: false } })
	})

	it('offers exactly the modes and policies the engine knows', () => {
		const state = readFileSync(
			join(root, 'lib/Service/Ownership/OwnershipState.php'),
			'utf8',
		)
		for (const option of ownershipModeOptions()) {
			expect(state).toContain(`'${option.id}'`)
		}
		const policy = readFileSync(
			join(root, 'lib/Service/Ownership/DisappearancePolicy.php'),
			'utf8',
		)
		const accepted = disappearancePolicyOptions().map((option) => option.id)
		expect(accepted).toEqual(['delete', 'markEnded', 'keepAndFlag', 'purge'])
		for (const id of accepted) {
			expect(policy).toContain(`'${id}'`)
		}
	})

	it('renders two labelled pickers, defaulting to local and delete', async () => {
		const wrapper = mountEditor({
			id: 's1',
			name: 'BRP personen',
			sourceConfig: {},
		})
		await flushPromises()

		const selects = wrapper.findAllComponents({ name: 'NcSelect' })
		const mode = selects.find(
			(select) => select.props('inputId') === 'cn-sync-editor-ownership-mode',
		)
		const policy = selects.find(
			(select) =>
				select.props('inputId') === 'cn-sync-editor-disappearance-policy',
		)
		expect(mode.props('inputLabel')).toBe('Who owns these records')
		expect(mode.props('modelValue').id).toBe('local')
		expect(policy.props('modelValue').id).toBe('delete')
	})

	it('writes the picked mode and policy into sourceConfig, keeping its other keys', async () => {
		const wrapper = mountEditor({
			id: 's1',
			name: 'BRP personen',
			sourceConfig: { endpoint: '/personen' },
		})
		await flushPromises()

		const selects = wrapper.findAllComponents({ name: 'NcSelect' })
		selects
			.find(
				(select) =>
					select.props('inputId') === 'cn-sync-editor-ownership-mode',
			)
			.vm.$emit('update:modelValue', { id: 'source' })
		selects
			.find(
				(select) =>
					select.props('inputId')
					=== 'cn-sync-editor-disappearance-policy',
			)
			.vm.$emit('update:modelValue', { id: 'markEnded' })

		expect(wrapper.vm.draft.sourceConfig).toEqual({
			endpoint: '/personen',
			ownershipMode: 'source',
			disappearancePolicy: 'markEnded',
		})
	})

	it('asks the engine about the policy and saves when it is accepted', async () => {
		post.mockResolvedValue({ data: { valid: true, policy: 'keepAndFlag' } })
		const confirm = vi.fn().mockResolvedValue(undefined)
		const wrapper = mountEditor(
			{
				id: 's1',
				name: 'BRP personen',
				sourceConfig: { disappearancePolicy: 'keepAndFlag' },
			},
			confirm,
		)
		await flushPromises()

		await wrapper.vm.onSave()

		expect(post).toHaveBeenCalledWith(
			'/index.php/apps/integriq/api/ownership/validate-policy',
			{
				sourceConfig: { disappearancePolicy: 'keepAndFlag' },
			},
		)
		expect(confirm).toHaveBeenCalledTimes(1)
	})

	it('refuses to save a policy the engine does not know, and says why', async () => {
		post.mockRejectedValue({
			response: {
				status: 400,
				data: {
					valid: false,
					error: 'Unknown disappearancePolicy "markended".',
				},
			},
		})
		const confirm = vi.fn()
		const wrapper = mountEditor(
			{
				id: 's1',
				name: 'BRP personen',
				sourceConfig: { disappearancePolicy: 'markended' },
			},
			confirm,
		)
		await flushPromises()

		expect(wrapper.vm.selectedDisappearancePolicy.id).toBe('markended')
		await wrapper.vm.onSave()

		expect(confirm).not.toHaveBeenCalled()
		expect(wrapper.vm.saveError).toContain('markended')
	})
})

/**
 * The purge warning on the editor, or undefined when there is none.
 *
 * @param {object} wrapper the mounted editor
 * @return {object|undefined} the warning note card
 */
function purgeWarning(wrapper) {
	return wrapper
		.findAllComponents({ name: 'NcNoteCard' })
		.find((card) => card.attributes('data-testid') === 'sync-editor-purge-warning')
}

/**
 * The picker with the given input id.
 *
 * @param {object} wrapper the mounted editor
 * @param {string} inputId the picker's input id
 * @return {object} the NcSelect
 */
function picker(wrapper, inputId) {
	return wrapper
		.findAllComponents({ name: 'NcSelect' })
		.find((select) => select.props('inputId') === inputId)
}

// synchronisation-source-destruction-purge Task 4 (REQ-SDP-001, REQ-SDP-002):
// purge is a fourth policy, a destruction notice can purge at once, and the
// form says a purge cannot be undone.
describe('the purge choice on the synchronisation editor', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
		get.mockResolvedValue({ data: { enabled: false } })
	})

	it('offers the destruction notice choices the engine reads', () => {
		const ids = sourceDestroyedOptions().map((option) => option.id)
		expect(ids).toEqual(['', 'purge'])
		const engine = readFileSync(
			join(root, 'lib/Service/SynchronizationService.php'),
			'utf8',
		)
		expect(engine).toContain("['onSourceDestroyed'] ?? null) === DisappearancePolicy::PURGE")
	})

	it('shows no warning while nothing is purged', async () => {
		const wrapper = mountEditor({ id: 's1', name: 'Woo publicaties', sourceConfig: {} })
		await flushPromises()

		expect(purgeWarning(wrapper)).toBeUndefined()
		expect(picker(wrapper, 'cn-sync-editor-source-destroyed').props('modelValue').id).toBe('')
	})

	it('warns that purged files cannot be restored when the policy is purge', async () => {
		const wrapper = mountEditor({ id: 's1', name: 'Woo publicaties', sourceConfig: {} })
		await flushPromises()

		picker(wrapper, 'cn-sync-editor-disappearance-policy').vm.$emit('update:modelValue', { id: 'purge' })
		await flushPromises()

		expect(wrapper.vm.draft.sourceConfig.disappearancePolicy).toBe('purge')
		const warning = purgeWarning(wrapper)
		expect(warning).toBeDefined()
		expect(warning.props('type')).toBe('warning')
		expect(warning.text()).toContain('cannot be restored')
	})

	it('writes onSourceDestroyed, warns, and removes the key again', async () => {
		const wrapper = mountEditor({
			id: 's1',
			name: 'Woo publicaties',
			sourceConfig: { endpoint: '/documenten' },
		})
		await flushPromises()

		picker(wrapper, 'cn-sync-editor-source-destroyed').vm.$emit('update:modelValue', { id: 'purge' })
		await flushPromises()
		expect(wrapper.vm.draft.sourceConfig).toEqual({
			endpoint: '/documenten',
			onSourceDestroyed: 'purge',
		})
		expect(purgeWarning(wrapper)).toBeDefined()

		picker(wrapper, 'cn-sync-editor-source-destroyed').vm.$emit('update:modelValue', { id: '' })
		await flushPromises()
		expect(wrapper.vm.draft.sourceConfig).toEqual({ endpoint: '/documenten' })
		expect(purgeWarning(wrapper)).toBeUndefined()
	})
})
