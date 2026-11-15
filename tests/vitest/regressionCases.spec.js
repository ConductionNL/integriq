// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression test cases in the UI: the pure helpers, "Run test cases" on the
 * detail pages and "Save as test case" on the trace detail page.
 *
 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-test-case-can-be-recorded-from-a-traced-execution-req-irt-002
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RegressionSuitePanel from '@/components/regression/RegressionSuitePanel.vue'
import SaveTestCaseModal from '@/modals/SaveTestCaseModal.vue'
import {
	formatValue,
	parseIgnoredPaths,
	recordableSteps,
	redactedPaths,
	subjectIdOf,
	withValueAt,
} from '@/components/regression/regressionCases.js'

const { post, showSuccess } = vi.hoisted(() => ({
	post: vi.fn(),
	showSuccess: vi.fn(),
}))
vi.mock('@nextcloud/axios', () => ({ default: { post } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props) =>
		defineComponent({
			name,
			props,
			emits: ['click', 'close', 'update:modelValue'],
			render() {
				return h('div', { class: name }, [this.$slots.icon?.(), this.$slots.default?.()])
			},
		})
	return {
		NcModal: stub('NcModal', ['labelId', 'size']),
		NcButton: stub('NcButton', ['variant', 'disabled']),
		NcSelect: stub('NcSelect', ['modelValue', 'options', 'inputLabel', 'clearable', 'label']),
		NcTextField: stub('NcTextField', ['modelValue', 'label', 'helperText']),
		NcLoadingIcon: stub('NcLoadingIcon', ['size']),
		NcNoteCard: stub('NcNoteCard', ['type']),
	}
})

const STEPS = [
	{ order: 1, type: 'rule', name: 'Check', input: {}, output: {} },
	{
		order: 2,
		type: 'synchronization',
		name: 'Contacts',
		input: { Name: 'Ada', headers: { authorization: '***REDACTED***' } },
		output: { name: 'Ada' },
	},
	{ order: 3, type: 'mapping', name: 'lowercase-keys', input: { A: 1 }, output: { a: 1 } },
]

describe('regressionCases helpers', () => {
	it('keeps only mapping and synchronization steps', () => {
		expect(recordableSteps(STEPS).map((step) => step.order)).toEqual([2, 3])
		expect(recordableSteps(null)).toEqual([])
	})

	it('finds redacted values at every depth', () => {
		expect(redactedPaths({ a: '***REDACTED***', b: { c: '***REDACTED***', d: 'x' }, e: [{ f: '***REDACTED***' }] }, 'input'))
			.toEqual(['input.a', 'input.b.c', 'input.e.0.f'])
		expect(redactedPaths('text')).toEqual([])
	})

	it('replaces a value in a copy and leaves the original alone', () => {
		const original = { headers: { authorization: '***REDACTED***' } }
		expect(withValueAt(original, 'headers.authorization', 'Bearer test')).toEqual({ headers: { authorization: 'Bearer test' } })
		expect(original.headers.authorization).toBe('***REDACTED***')
		expect(withValueAt(original, 'missing.path', 'x')).toEqual(original)
	})

	it('splits ignored paths on commas and newlines', () => {
		expect(parseIgnoredPaths(' dateModified, meta.id\n\n,x ')).toEqual(['dateModified', 'meta.id', 'x'])
	})

	it('names a subject by its slug before its id', () => {
		expect(subjectIdOf({ slug: 'lowercase-keys' }, 'uuid-1')).toBe('lowercase-keys')
		expect(subjectIdOf({ '@self': { slug: 'from-self' } }, 'uuid-1')).toBe('from-self')
		expect(subjectIdOf(null, 'uuid-1')).toBe('uuid-1')
	})

	it('shows a missing value as a dash', () => {
		expect(formatValue(undefined)).toBe('—')
		expect(formatValue({ a: 1 })).toBe('{"a":1}')
	})
})

describe('RegressionSuitePanel', () => {
	beforeEach(() => {
		post.mockReset()
	})

	it('runs the suite and shows the counts and the diff of each failure', async () => {
		post.mockResolvedValue({
			data: {
				total: 2,
				passed: 1,
				failed: 1,
				failures: [{ caseId: 'c-2', caseName: 'Status maps', differences: [{ path: 'status', expected: 'open', actual: 'closed' }] }],
			},
		})
		const wrapper = mount(RegressionSuitePanel, { props: { subjectType: 'mapping', subjectId: 'lowercase-keys' } })

		await wrapper.findComponent({ name: 'NcButton' }).vm.$emit('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith('/index.php/apps/integriq/api/regression-suites/mapping/lowercase-keys/run')
		const result = wrapper.find('[data-testid="regression-suite-result"]').text()
		expect(result).toContain('Passed: 1')
		expect(result).toContain('Failed: 1')
		const failure = wrapper.find('[data-testid="regression-suite-failure"]').text()
		expect(failure).toContain('Status maps')
		expect(failure).toContain('status')
		expect(failure).toContain('"open"')
		expect(failure).toContain('"closed"')
	})

	it('shows the refusal the server gives', async () => {
		post.mockRejectedValue(Object.assign(new Error('403'), { response: { data: { error: 'You are not allowed to run test cases.' } } }))
		const wrapper = mount(RegressionSuitePanel, { props: { subjectType: 'synchronization', subjectId: 's-1' } })

		await wrapper.findComponent({ name: 'NcButton' }).vm.$emit('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('You are not allowed to run test cases.')
		expect(wrapper.find('[data-testid="regression-suite-result"]').exists()).toBe(false)
	})
})

describe('SaveTestCaseModal', () => {
	beforeEach(() => {
		post.mockReset()
		showSuccess.mockReset()
	})

	/**
	 * Mount the modal closed, then open it so the watcher picks the first step.
	 *
	 * @return {Promise<object>} the wrapper
	 */
	async function openModal() {
		const wrapper = mount(SaveTestCaseModal, { props: { open: false, traceId: 'trace-1', steps: STEPS } })
		await wrapper.setProps({ open: true })
		return wrapper
	}

	it('offers the recordable steps and names the redacted field', async () => {
		const wrapper = await openModal()

		expect(wrapper.vm.stepOptions.map((option) => option.id)).toEqual([2, 3])
		expect(wrapper.vm.selectedStep.id).toBe(2)
		expect(wrapper.vm.name).toBe('Contacts')
		expect(wrapper.find('[data-testid="save-test-case-redacted"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="save-test-case-redacted-input.headers.authorization"]').exists()).toBe(true)
	})

	it('saves the snapshot with the replaced field and the ignored paths', async () => {
		post.mockResolvedValue({ data: { id: 'case-1', redactedPaths: [] } })
		const wrapper = await openModal()
		wrapper.vm.setReplacement('input.headers.authorization', 'Bearer test')
		wrapper.vm.ignoredPathsText = 'dateModified'

		await wrapper.vm.save()

		expect(post).toHaveBeenCalledWith('/index.php/apps/integriq/api/execution-traces/trace-1/test-case', {
			step: 2,
			name: 'Contacts',
			input: { Name: 'Ada', headers: { authorization: 'Bearer test' } },
			expectedOutput: { name: 'Ada' },
			ignoredPaths: ['dateModified'],
		})
		expect(showSuccess).toHaveBeenCalledWith('Test case saved')
		expect(wrapper.emitted('saved')).toEqual([[{ id: 'case-1', redactedPaths: [] }]])
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('a mapping step is saved as its snapshot unchanged', async () => {
		post.mockResolvedValue({ data: { id: 'case-2' } })
		const wrapper = await openModal()
		wrapper.vm.onSelectStep(wrapper.vm.stepOptions[1])

		expect(wrapper.vm.redacted).toEqual([])
		await wrapper.vm.save()

		expect(post.mock.calls[0][1]).toEqual({ step: 3, name: 'lowercase-keys', input: { A: 1 }, expectedOutput: { a: 1 }, ignoredPaths: [] })
	})

	it('keeps the dialog open with the server error when saving fails', async () => {
		post.mockRejectedValue(Object.assign(new Error('400'), { response: { data: { error: 'A test case is saved from a mapping or synchronization step only.' } } }))
		const wrapper = await openModal()

		await wrapper.vm.save()

		expect(wrapper.vm.errorMessage).toBe('A test case is saved from a mapping or synchronization step only.')
		expect(wrapper.emitted('close')).toBeUndefined()
	})
})
