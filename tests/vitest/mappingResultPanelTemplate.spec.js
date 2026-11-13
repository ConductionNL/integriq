// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the mapping test panel (src/components/mapping/MappingResultPanel.vue)
 * renders in each state: the schema picker, the run spinner and error, the
 * valid/invalid line, the per-field error table, the result or the empty
 * text, and the save-to-register block with its hint and gated button.
 * mappingResultPanel.spec.js pins the logic on a plain context; this file
 * mounts the template.
 *
 * @spec openspec/specs/mapping-editor-ui/spec.md
 */
import { translate } from '@nextcloud/l10n'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props = []) => defineComponent({
		name,
		props,
		emits: ['update:modelValue', 'click'],
		render() {
			return h('div', { class: name, 'data-label': this.ariaLabelCombobox, 'data-disabled': String(!!this.disabled) }, [
				this.$slots.icon?.(),
				this.$slots.default?.(),
				this.$slots.option?.({ label: 'Opt', description: '' }),
			])
		},
	})
	return {
		NcButton: stub('NcButton', ['variant', 'disabled']),
		NcLoadingIcon: stub('NcLoadingIcon', ['size']),
		NcNoteCard: stub('NcNoteCard', ['type']),
		NcSelect: stub('NcSelect', ['modelValue', 'ariaLabelCombobox', 'options', 'loading', 'placeholder', 'clearable', 'inputId']),
	}
})

const panel = (await import('@/components/mapping/MappingResultPanel.vue')).default

/**
 * Mount the panel with nothing loading on mount, then put it in a state.
 *
 * @param {object} props Props to override.
 * @param {object} state Data to set after mount.
 * @return {Promise<object>} The wrapper.
 */
async function mountPanel(props = {}, state = {}) {
	const wrapper = mount(panel, {
		props: { auto: false, showSchemaPicker: false, allowSaveToRegister: false, ...props },
		global: {
			mocks: { t: translate },
			stubs: { CheckCircleIcon: true, CloseCircleIcon: true, ContentSaveOutlineIcon: true, DatabaseOutlineIcon: true },
		},
	})
	await flushPromises()
	await wrapper.setData(state)
	return wrapper
}

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
	http.get.mockResolvedValue({ data: [] })
})

describe('MappingResultPanel template', () => {
	it('shows only the empty text before a run, worded after the run mode', async () => {
		const explicit = await mountPanel()
		expect(explicit.find('.cn-mapping-result__empty').text()).toBe('Run the test to see the result here.')
		expect(explicit.find('.cn-mapping-result__pre').exists()).toBe(false)
		expect(explicit.find('.NcSelect').exists()).toBe(false)
		expect(explicit.find('.NcLoadingIcon').exists()).toBe(false)
		expect(explicit.find('.cn-mapping-result__save').exists()).toBe(false)
	})

	it('renders the schema picker with its label tied to the input', async () => {
		const wrapper = await mountPanel({ showSchemaPicker: true })
		const label = wrapper.find('label.cn-mapping-result__label')
		expect(label.text()).toBe('Validation schema (optional)')
		expect(label.attributes('for')).toMatch(/^cn-mapping-result-schema-\d+$/)
		expect(wrapper.find('.NcSelect').attributes('data-label')).toBe('Validation schema (optional)')
	})

	it('shows the spinner while running and the error instead of the empty text', async () => {
		const wrapper = await mountPanel({}, { running: true, runError: 'Mapping test failed (500)' })
		expect(wrapper.find('.cn-mapping-result__status .NcLoadingIcon').exists()).toBe(true)
		expect(wrapper.find('.NcNoteCard').text()).toBe('Mapping test failed (500)')
		expect(wrapper.find('.cn-mapping-result__empty').exists()).toBe(false)
	})

	it('pretty-prints the result and names no validity without a schema', async () => {
		const wrapper = await mountPanel({}, { result: { a: 1 } })
		expect(wrapper.find('.cn-mapping-result__pre').text()).toBe('{\n  "a": 1\n}')
		expect(wrapper.find('.cn-mapping-result__valid').exists()).toBe(false)
		expect(wrapper.find('.cn-mapping-result__invalid').exists()).toBe(false)
	})

	it('says the result is valid against the picked schema', async () => {
		const wrapper = await mountPanel({}, { result: { a: 1 }, selectedSchema: { id: 3 }, isValid: true })
		expect(wrapper.find('.cn-mapping-result__valid').text()).toBe('Result is valid against the selected schema.')
		expect(wrapper.find('.cn-mapping-result__invalid').exists()).toBe(false)
	})

	it('says the result is invalid and lists the errors per field', async () => {
		const wrapper = await mountPanel({}, {
			result: { a: 1 },
			selectedSchema: { id: 3 },
			isValid: false,
			validationErrors: { title: ['is required', { message: 'too short' }], age: 'must be a number' },
		})
		expect(wrapper.find('.cn-mapping-result__invalid').text()).toBe('Result is not valid against the selected schema.')
		const rows = wrapper.findAll('.cn-mapping-result__errors tbody tr')
		expect(rows).toHaveLength(2)
		expect(rows[0].find('td').text()).toBe('title')
		expect(rows[0].findAll('li').map((li) => li.text())).toEqual(['is required', 'too short'])
		expect(rows[1].findAll('li').map((li) => li.text())).toEqual(['must be a number'])
	})

	it('offers the save block once OpenRegister is there and a result exists', async () => {
		const wrapper = await mountPanel({ allowSaveToRegister: true }, { openRegisterAvailable: true, result: { a: 1 } })
		const block = wrapper.find('.cn-mapping-result__save')
		expect(block.find('h4').text()).toBe('Save result as object')
		expect(block.find('.NcSelect').attributes('data-label')).toBe('Register')
		expect(block.find('.cn-mapping-result__register-option small').text()).toBe('No description')
		expect(block.find('.cn-mapping-result__hint').exists()).toBe(false)
		expect(block.find('.NcButton').attributes('data-disabled')).toBe('true')
		expect(block.find('.NcButton').text()).toContain('Save to register')
	})

	it('hints at the missing schema once a register is picked', async () => {
		const wrapper = await mountPanel(
			{ allowSaveToRegister: true },
			{ openRegisterAvailable: true, result: { a: 1 }, selectedRegister: { id: 9 } },
		)
		expect(wrapper.find('.cn-mapping-result__hint').text()).toBe(
			'Pick a validation schema above — the result is stored against that schema.',
		)
	})

	it('enables saving for a valid result validated against the picked schema and posts on click', async () => {
		http.post.mockResolvedValue({ data: {} })
		const wrapper = await mountPanel(
			{ allowSaveToRegister: true },
			{
				openRegisterAvailable: true,
				result: { a: 1 },
				isValid: true,
				selectedRegister: { id: 9 },
				selectedSchema: { id: 3 },
				validatedSchemaId: 3,
			},
		)
		const button = wrapper.find('.cn-mapping-result__save .NcButton')
		expect(button.attributes('data-disabled')).toBe('false')
		expect(wrapper.find('.cn-mapping-result__hint').exists()).toBe(false)

		await wrapper.findComponent({ name: 'NcButton' }).vm.$emit('click')
		await flushPromises()
		expect(http.post).toHaveBeenCalledTimes(1)
		const [url, body] = http.post.mock.calls[0]
		expect(url).toContain('/apps/integriq/api/mappings/objects')
		expect(body).toEqual({ object: { a: 1 }, register: 9, schema: 3 })
	})

	it('shows the spinner in the save button while saving', async () => {
		const wrapper = await mountPanel(
			{ allowSaveToRegister: true },
			{ openRegisterAvailable: true, result: { a: 1 }, savingResult: true },
		)
		expect(wrapper.find('.cn-mapping-result__save .NcButton .NcLoadingIcon').exists()).toBe(true)
	})
})
