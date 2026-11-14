// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * EndpointFormFields as an editor sees it: the path segments as one comma
 * list, method and target type pickers, the register and schema pair that
 * composes `targetId` (or a note when OpenRegister cannot list them), the
 * configurations multiselect, and plain fields. Every control writes through
 * `updateField`. Message validation has its own spec
 * (endpointMessageValidation.spec.js).
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ answers: {}, failing: [] }))

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: async (url) => {
			const key = Object.keys(http.answers).find((path) => url.endsWith(path))
			if (http.failing.some((path) => url.endsWith(path))) {
				throw new Error('unavailable')
			}
			return { data: { results: http.answers[key] ?? [] } }
		},
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const control = (name) => defineComponent({
		name,
		inheritAttrs: false,
		props: ['modelValue', 'label', 'options', 'inputId', 'disabled', 'loading', 'multiple', 'clearable', 'placeholder', 'error'],
		emits: ['update:modelValue'],
		render() {
			return h('div', { class: name, 'data-input-id': this.inputId }, this.$slots.default?.())
		},
	})
	return { NcCheckboxRadioSwitch: control('NcCheckboxRadioSwitch'), NcSelect: control('NcSelect'), NcTextField: control('NcTextField') }
})
vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		CnFieldHelper: defineComponent({
			name: 'CnFieldHelper',
			props: ['text', 'more', 'error'],
			render() {
				return h('p', { class: 'helper' }, this.error || this.text)
			},
		}),
	}
})

const EndpointFormFields = (await import('@/modals/v2/EndpointFormFields.vue')).default

/**
 * Mount the fields and let the OpenRegister lists load.
 *
 * @param {Array} fields The field descriptors.
 * @param {object} formData The endpoint.
 * @return {Promise<{wrapper: object, updateField: Function}>}
 */
async function mountFields(fields, formData = {}) {
	const updateField = vi.fn()
	const wrapper = mount(EndpointFormFields, { props: { fields, formData, updateField }, global: { mocks: { t: (app, text) => text } } })
	await flushPromises()
	return { wrapper, updateField }
}

/**
 * The select with this input id.
 *
 * @param {object} wrapper The form.
 * @param {string} id The input id.
 * @return {object}
 */
function select(wrapper, id) {
	return wrapper.findAllComponents({ name: 'NcSelect' }).find((candidate) => candidate.props('inputId') === id)
}

beforeEach(() => {
	http.answers = {
		'/api/registers': [{ id: 1, title: 'Zaken', schemas: [10, '11'] }, { id: 2, name: 'Personen', schemas: [] }],
		'/api/schemas': [{ id: 10, title: 'Zaak' }, { id: 11, name: 'Document' }, { id: 12, title: 'Persoon' }],
		'/api/configurations': [{ id: 5, title: 'Standaard' }, { id: 6, name: 'Extra' }],
	}
	http.failing = []
})

describe('the path segments', () => {
	it('shows stored segments as a comma list and writes the typed list as segments', async () => {
		const { wrapper, updateField } = await mountFields([{ key: 'endpointArray', label: 'Segments' }], { endpointArray: ['api', 'v1'] })
		const field = wrapper.findComponent({ name: 'NcTextField' })

		expect(field.props('modelValue')).toBe('api, v1')
		expect(field.props('placeholder')).toBe('api, v1, objects, {id}')
		expect(wrapper.find('.helper').text()).toBe('Path segments, split on commas. Left empty the backend derives them from the endpoint path.')

		await field.vm.$emit('update:modelValue', 'api ,v2,  , {id}')
		expect(updateField).toHaveBeenCalledWith('endpointArray', ['api', 'v2', '{id}'])
		expect(field.props('modelValue')).toBe('api ,v2,  , {id}')
	})
})

describe('method and target type', () => {
	it('offers the HTTP methods and writes the picked one', async () => {
		const { wrapper, updateField } = await mountFields([{ key: 'method', label: 'Method', required: true }], { method: 'PUT' })
		const method = select(wrapper, 'cn-endpoint-form-method')

		expect(method.props('options').map((option) => option.id)).toEqual(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'])
		expect(method.props('modelValue')).toEqual({ id: 'PUT', label: 'PUT' })
		expect(method.props('clearable')).toBe(false)

		await method.vm.$emit('update:modelValue', { id: 'GET' })
		await method.vm.$emit('update:modelValue', null)
		expect(updateField.mock.calls).toEqual([['method', 'GET'], ['method', null]])
	})

	it('shows a stored target type the list does not offer as itself', async () => {
		const { wrapper } = await mountFields([{ key: 'targetType', label: 'Target' }], { targetType: 'api' })

		expect(select(wrapper, 'cn-endpoint-form-targetType').props('modelValue')).toEqual({ id: 'api', label: 'api' })
		expect(select(wrapper, 'cn-endpoint-form-register')).toBeUndefined()
	})
})

describe('the register and schema pair', () => {
	const target = [{ key: 'targetType', label: 'Target' }, { key: 'targetId', label: 'Target id' }]

	it('reads both halves of the stored target and offers only the register\'s schemas', async () => {
		const { wrapper } = await mountFields(target, { targetType: 'register/schema', targetId: '1/11' })
		const register = select(wrapper, 'cn-endpoint-form-register')
		const schema = select(wrapper, 'cn-endpoint-form-schema')

		expect(register.props('modelValue')).toMatchObject({ id: 1, label: 'Zaken' })
		expect(register.props('options').map((option) => option.label)).toEqual(['Zaken', 'Personen'])
		expect(schema.props('options')).toEqual([{ id: 10, label: 'Zaak' }, { id: 11, label: 'Document' }])
		expect(schema.props('modelValue')).toEqual({ id: 11, label: 'Document' })
		expect(schema.props('disabled')).toBe(false)
		expect(wrapper.text()).not.toContain('OpenRegister is not available')
	})

	it('clears the target on a new register and composes it from the picked schema', async () => {
		const { wrapper, updateField } = await mountFields(target, { targetType: 'register/schema', targetId: '' })
		expect(select(wrapper, 'cn-endpoint-form-schema').props('disabled')).toBe(true)

		await select(wrapper, 'cn-endpoint-form-register').vm.$emit('update:modelValue', { id: 2 })
		expect(select(wrapper, 'cn-endpoint-form-schema').props('options').map((option) => option.id)).toEqual([10, 11, 12])

		await select(wrapper, 'cn-endpoint-form-schema').vm.$emit('update:modelValue', { id: 12 })
		await select(wrapper, 'cn-endpoint-form-schema').vm.$emit('update:modelValue', null)
		expect(updateField.mock.calls).toEqual([['targetId', ''], ['targetId', '2/12'], ['targetId', '']])
	})

	it('says why the pair is missing when OpenRegister cannot list registers', async () => {
		http.failing = ['/api/registers']
		const { wrapper } = await mountFields(target, { targetType: 'register/schema' })

		expect(wrapper.text()).toContain('OpenRegister is not available, so registers and schemas cannot be listed.')
		expect(select(wrapper, 'cn-endpoint-form-register')).toBeUndefined()
	})
})

describe('configurations and plain fields', () => {
	it('lists configurations, keeps an unknown stored id and writes the picked ids as strings', async () => {
		const { wrapper, updateField } = await mountFields([{ key: 'configurations', label: 'Configurations' }], { configurations: [5, 'gone'] })
		const picker = select(wrapper, 'cn-endpoint-form-configurations')

		expect(picker.props('multiple')).toBe(true)
		expect(picker.props('options')).toEqual([{ id: 5, label: 'Standaard' }, { id: 6, label: 'Extra' }])
		expect(picker.props('modelValue')).toEqual([{ id: 5, label: 'Standaard' }, { id: 'gone', label: 'gone' }])

		await picker.vm.$emit('update:modelValue', [{ id: 6 }, { id: 5 }])
		await picker.vm.$emit('update:modelValue', null)
		expect(updateField.mock.calls).toEqual([['configurations', ['6', '5']], ['configurations', []]])
	})

	it('lists no configurations when OpenRegister cannot', async () => {
		http.failing = ['/api/configurations', '/api/schemas']
		const { wrapper } = await mountFields([{ key: 'configurations', label: 'Configurations' }], {})

		expect(select(wrapper, 'cn-endpoint-form-configurations').props('options')).toEqual([])
		expect(select(wrapper, 'cn-endpoint-form-configurations').props('loading')).toBe(false)
	})

	it('writes a textarea, a switch and a plain field, and never draws the composed fields', async () => {
		const { wrapper, updateField } = await mountFields(
			[
				{ key: 'description', label: 'Description', widget: 'textarea', required: true },
				{ key: 'isEnabled', label: 'Enabled', widget: 'checkbox' },
				{ key: 'endpoint', label: 'Endpoint' },
				{ key: 'targetId', label: 'Target id' },
				{ key: 'validation', label: 'Validation' },
			],
			{ description: 'Zaken API', isEnabled: true, endpoint: 'api/zaken' },
		)

		expect(wrapper.findAll('.cn-endpoint-form-fields__field')).toHaveLength(3)
		expect(wrapper.find('textarea').element.value).toBe('Zaken API')
		expect(wrapper.find('label[for="cn-endpoint-form-description"]').text()).toBe('Description *')
		expect(wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).props('modelValue')).toBe(true)

		await wrapper.find('textarea').setValue('Zaken')
		await wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', false)
		const plain = wrapper.findAllComponents({ name: 'NcTextField' }).at(-1)
		expect(plain.props('placeholder')).toBe('api/v1/objects/{id}')
		await plain.vm.$emit('update:modelValue', 'api/documenten')

		expect(updateField.mock.calls).toEqual([['description', 'Zaken'], ['isEnabled', false], ['endpoint', 'api/documenten']])
	})
})
