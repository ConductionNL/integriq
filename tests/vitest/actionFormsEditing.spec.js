// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rule action forms as an editor uses them: each field shows the stored
 * configuration, an edit emits the whole configuration with that one key
 * changed, number fields drop the key when emptied and ignore text, switches
 * store booleans, and pickers load their options and emit ids.
 */
import { translate } from '@nextcloud/l10n'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const collections = vi.hoisted(() => ({ calls: [], answers: {} }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const field = (name) => defineComponent({
		name,
		props: ['modelValue', 'label', 'type', 'placeholder', 'options', 'loading', 'ariaLabelCombobox', 'clearable'],
		emits: ['update:modelValue'],
		render() {
			return h('div', { class: name, 'data-label': this.label ?? this.ariaLabelCombobox, 'data-type': this.type }, [this.$slots.default?.()])
		},
	})
	return { NcCheckboxRadioSwitch: field('NcCheckboxRadioSwitch'), NcSelect: field('NcSelect'), NcTextField: field('NcTextField') }
})

vi.mock('../../src/views/Rule/actionForms/shared.js', async (importOriginal) => ({
	...(await importOriginal()),
	fetchOpenRegisterCollection: async (schema, register = 'integriq') => {
		collections.calls.push([schema, register])
		return collections.answers[schema] ?? []
	},
}))

const forms = {}
for (const name of ['DownloadForm', 'ErrorForm', 'LockingForm', 'MappingForm', 'SaveObjectForm', 'UploadForm', 'WebhookSignatureForm', 'WriteFileForm']) {
	forms[name] = (await import(`../../src/views/Rule/actionForms/${name}.vue`)).default
}

/**
 * Mount a form with a stored configuration.
 *
 * @param {string} name The form.
 * @param {object} props Its props.
 * @return {Promise<object>} The wrapper.
 */
async function mountForm(name, props) {
	const wrapper = mount(forms[name], { props, global: { mocks: { t: translate } } })
	await flushPromises()
	return wrapper
}

/**
 * The field component with the given label.
 *
 * @param {object} wrapper The form.
 * @param {string} label The field label.
 * @return {object} The field.
 */
function field(wrapper, label) {
	const found = wrapper.findAllComponents({ name: 'NcTextField' })
		.concat(wrapper.findAllComponents({ name: 'NcSelect' }), wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }))
		.find((c) => c.props('label') === label || c.props('ariaLabelCombobox') === label || c.text() === label)
	if (!found) {
		throw new Error(`no field ${label}`)
	}
	return found
}

/**
 * Edit a field and return what the form emitted for it.
 *
 * @param {object} wrapper The form.
 * @param {string} label The field label.
 * @param {*} value The new value.
 * @param {string} event The form event.
 * @return {Array|undefined} The last emitted payloads.
 */
async function edit(wrapper, label, value, event = 'update:value') {
	await field(wrapper, label).vm.$emit('update:modelValue', value)
	return wrapper.emitted(event)?.at(-1)
}

beforeEach(() => {
	collections.calls = []
	collections.answers = {}
})

describe('number fields', () => {
	const cases = [
		['DownloadForm', 'File ID position in URL path (legacy)', 'fileIdPosition'],
		['ErrorForm', 'HTTP status code', 'code'],
		['UploadForm', 'Max file size (MB)', 'maxSize'],
		['LockingForm', 'Duration (seconds, default 3600)', 'duration'],
	]

	for (const [name, label, key] of cases) {
		it(`${name} stores ${key} as a number, drops it when emptied and ignores text`, async () => {
			const wrapper = await mountForm(name, { value: { keep: true, [key]: 7 } })

			expect(field(wrapper, label).props('modelValue')).toBe('7')
			expect(await edit(wrapper, label, '42')).toEqual([{ keep: true, [key]: 42 }])
			expect(await edit(wrapper, label, '')).toEqual([{ keep: true }])
			const before = wrapper.emitted('update:value').length
			await edit(wrapper, label, 'abc')
			expect(wrapper.emitted('update:value')).toHaveLength(before)
		})
	}

	it('shows an unset number as empty', async () => {
		expect(field(await mountForm('ErrorForm', { value: {} }), 'HTTP status code').props('modelValue')).toBe('')
	})
})

describe('text fields and switches', () => {
	it('DownloadForm edits the filename position', async () => {
		const wrapper = await mountForm('DownloadForm', { value: { filenamePosition: 'a.b' } })

		expect(field(wrapper, 'Filename position (dotted path on object)').props('modelValue')).toBe('a.b')
		expect(await edit(wrapper, 'Filename position (dotted path on object)', 'c.d')).toEqual([{ filenamePosition: 'c.d' }])
	})

	it('ErrorForm edits its title, message and JSON Logic switch', async () => {
		const wrapper = await mountForm('ErrorForm', { value: { name: 'Oops', includeJsonLogicResult: 1 } })

		expect(field(wrapper, 'Error title').props('modelValue')).toBe('Oops')
		expect(field(wrapper, 'Include JSON Logic results in errors array').props('modelValue')).toBe(true)
		expect(await edit(wrapper, 'Error title', 'Fout')).toEqual([{ name: 'Fout', includeJsonLogicResult: 1 }])
		expect(await edit(wrapper, 'Include JSON Logic results in errors array', 0)).toEqual([{ name: 'Oops', includeJsonLogicResult: false }])

		const textarea = wrapper.find('textarea')
		expect(wrapper.find('label').attributes('for')).toBe(textarea.attributes('id'))
		await textarea.setValue('Probeer het later opnieuw')
		expect(wrapper.emitted('update:value').at(-1)).toEqual([{ name: 'Oops', includeJsonLogicResult: 1, message: 'Probeer het later opnieuw' }])
	})

	it('UploadForm edits its path and allowed types', async () => {
		const wrapper = await mountForm('UploadForm', { value: {} })

		expect(await edit(wrapper, 'Upload path', '/in')).toEqual([{ path: '/in' }])
		expect(await edit(wrapper, 'Allowed file types (comma-separated)', 'pdf,png')).toEqual([{ allowedTypes: 'pdf,png' }])
	})

	it('WriteFileForm shows tags as text and stores them as a trimmed list', async () => {
		const listed = await mountForm('WriteFileForm', { value: { tags: ['invoice', 'outbox'], autoShare: false } })
		const text = await mountForm('WriteFileForm', { value: { tags: 'legacy' } })

		expect(field(listed, 'Tags (comma-separated)').props('modelValue')).toBe('invoice,outbox')
		expect(field(text, 'Tags (comma-separated)').props('modelValue')).toBe('legacy')
		expect(await edit(listed, 'Tags (comma-separated)', ' a , ,b ')).toEqual([{ tags: ['a', 'b'], autoShare: false }])
		expect(await edit(listed, 'Tags (comma-separated)', '')).toEqual([{ tags: [], autoShare: false }])
		expect(await edit(listed, 'Auto-share written files', 'yes')).toEqual([{ tags: ['invoice', 'outbox'], autoShare: true }])
		expect(await edit(listed, 'File path (dot path on data, required)', 'body.file')).toEqual([{ tags: ['invoice', 'outbox'], autoShare: false, filePath: 'body.file' }])
		expect(await edit(listed, 'Filename path (dot path on data, required)', 'body.name')).toEqual([{ tags: ['invoice', 'outbox'], autoShare: false, fileNamePath: 'body.name' }])
	})
})

describe('pickers', () => {
	it('LockingForm offers lock and unlock and stores the picked action', async () => {
		const wrapper = await mountForm('LockingForm', { value: { action: 'unlock' } })
		const picker = field(wrapper, 'Lock action')

		expect(picker.props('options')).toEqual([{ id: 'lock', label: 'Lock resource' }, { id: 'unlock', label: 'Unlock resource' }])
		expect(picker.props('modelValue')).toEqual({ id: 'unlock', label: 'Unlock resource' })
		expect(await edit(wrapper, 'Lock action', { id: 'lock' })).toEqual([{ action: 'lock' }])
		expect(await edit(wrapper, 'Lock action', null)).toEqual([{ action: '' }])
		expect(field(await mountForm('LockingForm', { value: {} }), 'Lock action').props('modelValue')).toBeNull()
	})

	it('WebhookSignatureForm defaults to the OpenConnector scheme and hides the tolerance for GitHub', async () => {
		const wrapper = await mountForm('WebhookSignatureForm', { value: {} })

		expect(field(wrapper, 'Signature scheme').props('modelValue')).toEqual({ id: 'openconnector', label: 'OpenConnector (t=,v1=)' })
		expect(field(wrapper, 'Shared secret').props('type')).toBe('password')
		expect(field(wrapper, 'Timestamp tolerance (seconds)').props('modelValue')).toBe('300')
		expect(await edit(wrapper, 'Timestamp tolerance (seconds)', '60')).toEqual([{ toleranceSeconds: 60 }])
		expect(await edit(wrapper, 'Timestamp tolerance (seconds)', 'soon')).toEqual([{ toleranceSeconds: 300 }])
		expect(await edit(wrapper, 'Signature scheme', null)).toEqual([{ scheme: 'openconnector' }])
		expect(await edit(wrapper, 'Signature header', 'X-Sig')).toEqual([{ header: 'X-Sig' }])
		expect(await edit(wrapper, 'Shared secret', 's3')).toEqual([{ secret: 's3' }])

		const github = await mountForm('WebhookSignatureForm', { value: { scheme: 'github' } })
		expect(github.findAllComponents({ name: 'NcTextField' }).map((c) => c.props('label'))).toEqual(['Signature header', 'Shared secret'])
		expect(await edit(github, 'Signature scheme', { id: 'stripe' })).toEqual([{ scheme: 'stripe' }])
	})

	it('MappingForm loads the mappings and emits the picked id', async () => {
		collections.answers.mapping = [{ id: 'm-1', label: 'Zaak naar ZGW' }]
		const known = await mountForm('MappingForm', { id: 'm-1' })
		const unknown = await mountForm('MappingForm', { id: 7 })

		expect(collections.calls).toEqual([['mapping', 'integriq'], ['mapping', 'integriq']])
		expect(field(known, 'Mapping').props('modelValue')).toEqual({ id: 'm-1', label: 'Zaak naar ZGW' })
		expect(field(known, 'Mapping').props('loading')).toBe(false)
		expect(field(unknown, 'Mapping').props('modelValue')).toEqual({ id: '7', label: '7' })
		expect(field(await mountForm('MappingForm', {}), 'Mapping').props('modelValue')).toBeNull()

		expect(await edit(known, 'Mapping', { id: 9 }, 'update:id')).toEqual(['9'])
		expect(await edit(known, 'Mapping', null, 'update:id')).toEqual([''])
	})

	it('SaveObjectForm loads registers, schemas and mappings and stores picked ids', async () => {
		collections.answers = { register: [{ id: 'r-1', label: 'Zaken' }], schema: [], mapping: [{ id: 'm-1', label: 'Map' }] }
		const wrapper = await mountForm('SaveObjectForm', { value: { register: 'r-1', schema: 's-9' } })

		expect(collections.calls).toEqual([['register', 'openregister'], ['schema', 'openregister'], ['mapping', 'integriq']])
		expect(field(wrapper, 'Register (required)').props('modelValue')).toEqual({ id: 'r-1', label: 'Zaken' })
		expect(field(wrapper, 'Schema (required)').props('modelValue')).toEqual({ id: 's-9', label: 's-9' })
		expect(field(wrapper, 'Mapping (optional)').props('modelValue')).toBeNull()

		expect(await edit(wrapper, 'Schema (required)', { id: 5 })).toEqual([{ register: 'r-1', schema: '5' }])
		expect(await edit(wrapper, 'Mapping (optional)', { id: 'm-1' })).toEqual([{ register: 'r-1', schema: 's-9', mapping: 'm-1' }])
		expect(await edit(wrapper, 'Register (required)', null)).toEqual([{ register: '', schema: 's-9' }])
	})
})
