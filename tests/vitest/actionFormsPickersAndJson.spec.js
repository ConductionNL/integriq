// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rule action forms not covered by actionFormsEditing.spec.js: the
 * fetch-file, approval, synchronization, file-parts, file-part upload and
 * authentication forms. Each picker loads its options and emits an id (or
 * drops it when cleared), each text field writes its own key, number fields
 * drop the key when emptied and ignore text, lists are split on commas, and
 * the fetch-file source configuration is parsed as JSON with the error shown.
 */
import { translate } from '@nextcloud/l10n'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const collections = vi.hoisted(() => ({ calls: [], answers: {} }))
const http = vi.hoisted(() => ({ get: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateOcsUrl: (path) => '/ocs/v2.php/' + path, generateUrl: (path) => path }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const field = (name) => defineComponent({
		name,
		props: ['modelValue', 'label', 'inputLabel', 'type', 'placeholder', 'options', 'loading', 'ariaLabelCombobox', 'clearable', 'inputId'],
		emits: ['update:modelValue'],
		render() {
			return h('div', { class: name }, [this.$slots.default?.()])
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
for (const name of ['FetchFileForm', 'ApprovalForm', 'SynchronizationForm', 'FilepartsCreateForm', 'FilepartUploadForm', 'AuthenticationForm']) {
	forms[name] = (await import(`../../src/views/Rule/actionForms/${name}.vue`)).default
}

async function mountForm(name, value) {
	const wrapper = mount(forms[name], { props: { value }, global: { mocks: { t: translate } } })
	await flushPromises()
	return wrapper
}

function field(wrapper, label) {
	const found = wrapper.findAllComponents({ name: 'NcTextField' })
		.concat(wrapper.findAllComponents({ name: 'NcSelect' }), wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }))
		.find((c) => [c.props('label'), c.props('inputLabel'), c.props('ariaLabelCombobox'), c.text()].includes(label))
	if (!found) {
		throw new Error(`no field ${label}`)
	}
	return found
}

async function edit(wrapper, label, value) {
	await field(wrapper, label).vm.$emit('update:modelValue', value)
	return wrapper.emitted('update:value')?.at(-1)?.[0]
}

beforeEach(() => {
	collections.calls = []
	collections.answers = {}
	http.get.mockReset()
})

describe('FetchFileForm', () => {
	it('loads the sources and shows the stored one, by its label when known', async () => {
		collections.answers.source = [{ id: 's1', label: 'Zaaksysteem' }]
		const known = await mountForm('FetchFileForm', { source: 's1' })
		const unknown = await mountForm('FetchFileForm', { source: 's9' })

		expect(collections.calls[0]).toEqual(['source', 'integriq'])
		expect(field(known, 'Source').props('modelValue')).toEqual({ id: 's1', label: 'Zaaksysteem' })
		expect(field(unknown, 'Source').props('modelValue')).toEqual({ id: 's9', label: 's9' })
		expect(field(await mountForm('FetchFileForm', {}), 'Source').props('modelValue')).toBeNull()
	})

	it('writes the source id, the paths, the tags and auto-share', async () => {
		const wrapper = await mountForm('FetchFileForm', { filePath: 'a', tags: ['x', 'y'] })

		expect(field(wrapper, 'Tags (comma-separated)').props('modelValue')).toBe('x,y')
		expect(await edit(wrapper, 'Source', { id: 7, label: 'Seven' })).toEqual({ filePath: 'a', tags: ['x', 'y'], source: '7' })
		expect((await edit(wrapper, 'Source', null)).source).toBe('')
		for (const [label, key] of [
			['File path (dot path)', 'filePath'],
			['Endpoint (optional)', 'endpoint'],
			['Object ID path (optional)', 'objectIdPath'],
			['Origin ID path (optional)', 'originIdPath'],
			['Content path (optional)', 'contentPath'],
			['Filename path (optional)', 'filenamePath'],
			['File extension (optional)', 'fileExtension'],
			['Sub-object filepath (optional)', 'subObjectFilepath'],
		]) {
			expect((await edit(wrapper, label, 'v-' + key))[key]).toBe('v-' + key)
		}
		expect((await edit(wrapper, 'Tags (comma-separated)', ' invoice, ,inbox ')).tags).toEqual(['invoice', 'inbox'])
		expect((await edit(wrapper, 'Auto-share fetched files', 1)).autoShare).toBe(true)
	})

	it('parses the source configuration, shows a JSON error, and drops it when emptied', async () => {
		const wrapper = await mountForm('FetchFileForm', { sourceConfiguration: { headers: { a: 1 } }, filePath: 'p' })
		const textarea = wrapper.find('textarea')
		expect(textarea.element.value).toBe(JSON.stringify({ headers: { a: 1 } }, null, 2))

		await textarea.setValue('{"timeout": 5}')
		expect(wrapper.emitted('update:value').at(-1)[0]).toEqual({ sourceConfiguration: { timeout: 5 }, filePath: 'p' })

		const before = wrapper.emitted('update:value').length
		await textarea.setValue('{oops')
		expect(wrapper.emitted('update:value').length).toBe(before)
		expect(wrapper.find('.action-form__helper--error').text()).toContain('Invalid JSON')

		await textarea.setValue('  ')
		expect(wrapper.emitted('update:value').at(-1)[0]).toEqual({ filePath: 'p' })
		expect(wrapper.find('.action-form__helper--error').exists()).toBe(false)
	})

	it('follows a configuration the parent replaces, and shows a stored string as it is', async () => {
		const wrapper = await mountForm('FetchFileForm', { sourceConfiguration: 'raw text' })
		expect(wrapper.find('textarea').element.value).toBe('raw text')

		await wrapper.setProps({ value: { sourceConfiguration: [1, 2] } })
		expect(wrapper.find('textarea').element.value).toBe(JSON.stringify([1, 2], null, 2))
	})
})

describe('ApprovalForm', () => {
	it('loads the groups and writes the approver group', async () => {
		http.get.mockResolvedValue({ data: { ocs: { data: { groups: ['admin', 'finance'] } } } })
		const wrapper = await mountForm('ApprovalForm', { approverGroup: 'finance' })

		expect(http.get).toHaveBeenCalledWith('/ocs/v2.php/cloud/groups', { params: { format: 'json' } })
		expect(field(wrapper, 'Approver group').props('options')).toEqual([{ id: 'admin', label: 'admin' }, { id: 'finance', label: 'finance' }])
		expect(field(wrapper, 'Approver group').props('modelValue')).toEqual({ id: 'finance', label: 'finance' })
		expect((await edit(wrapper, 'Approver group', { id: 'admin' })).approverGroup).toBe('admin')
		expect((await edit(wrapper, 'Approver group', null)).approverGroup).toBe('')
	})

	it('keeps working with no groups when they cannot be read, showing a stored group by id', async () => {
		http.get.mockRejectedValue(new Error('403'))
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const wrapper = await mountForm('ApprovalForm', { approverGroup: 'gone' })
		warn.mockRestore()

		expect(field(wrapper, 'Approver group').props('options')).toEqual([])
		expect(field(wrapper, 'Approver group').props('modelValue')).toEqual({ id: 'gone', label: 'gone' })
		expect(field(wrapper, 'Approver group').props('loading')).toBe(false)
	})

	it('writes the time to live as a number, drops it when emptied and ignores text', async () => {
		http.get.mockResolvedValue({ data: {} })
		const wrapper = await mountForm('ApprovalForm', { ttlSeconds: 60, approverGroup: 'a' })

		expect(field(wrapper, 'Time to live (seconds, default 86400)').props('modelValue')).toBe('60')
		expect((await edit(wrapper, 'Time to live (seconds, default 86400)', '120')).ttlSeconds).toBe(120)
		expect(await edit(wrapper, 'Time to live (seconds, default 86400)', '')).toEqual({ approverGroup: 'a' })
		const count = wrapper.emitted('update:value').length
		await edit(wrapper, 'Time to live (seconds, default 86400)', 'soon')
		expect(wrapper.emitted('update:value').length).toBe(count)
	})

	it('defaults both outcomes to an error and writes the chosen one', async () => {
		http.get.mockResolvedValue({ data: {} })
		const wrapper = await mountForm('ApprovalForm', { onTimeout: 'dead_letter' })
		const [onReject, onTimeout] = wrapper.findAllComponents({ name: 'NcSelect' }).slice(1)

		expect(onReject.props('modelValue').id).toBe('error')
		expect(onTimeout.props('modelValue').id).toBe('dead_letter')
		expect(onReject.props('options').map((o) => o.id)).toEqual(['error', 'skip', 'dead_letter'])
		onReject.vm.$emit('update:modelValue', { id: 'skip' })
		expect(wrapper.emitted('update:value').at(-1)[0].onReject).toBe('skip')
		onTimeout.vm.$emit('update:modelValue', null)
		expect(wrapper.emitted('update:value').at(-1)[0].onTimeout).toBe('error')
	})
})

describe('SynchronizationForm', () => {
	it('loads the synchronizations and sets or clears the chosen one', async () => {
		collections.answers.synchronization = [{ id: 'sync-1', label: 'Nightly' }]
		const wrapper = await mountForm('SynchronizationForm', { synchronization: 'sync-1', objectIdPath: 'x' })

		expect(collections.calls).toEqual([['synchronization', 'integriq']])
		expect(field(wrapper, 'Synchronization').props('modelValue')).toEqual({ id: 'sync-1', label: 'Nightly' })
		expect(await edit(wrapper, 'Synchronization', { id: 42 })).toEqual({ synchronization: '42', objectIdPath: 'x' })
		expect(await edit(wrapper, 'Synchronization', null)).toEqual({ objectIdPath: 'x' })
		expect(field(await mountForm('SynchronizationForm', { synchronization: 'old' }), 'Synchronization').props('modelValue')).toEqual({ id: 'old', label: 'old' })
	})

	it('writes retain-response, the object id path and the delays as numbers', async () => {
		const wrapper = await mountForm('SynchronizationForm', { preDelay: 2 })

		expect(field(wrapper, 'Pre-delay (seconds)').props('modelValue')).toBe('2')
		expect((await edit(wrapper, 'Retain original response', 'yes')).retainResponse).toBe(true)
		expect((await edit(wrapper, 'Object ID path (optional)', 'body.id')).objectIdPath).toBe('body.id')
		expect((await edit(wrapper, 'Post-delay (seconds)', '5')).postDelay).toBe(5)
		expect(await edit(wrapper, 'Pre-delay (seconds)', '')).toEqual({})
		const count = wrapper.emitted('update:value').length
		await edit(wrapper, 'Post-delay (seconds)', 'later')
		expect(wrapper.emitted('update:value').length).toBe(count)
	})
})

describe('FilepartsCreateForm', () => {
	it('loads schemas from OpenRegister and mappings from integriq, and writes their ids and the locations', async () => {
		collections.answers.schema = [{ id: '5', label: 'Document' }]
		collections.answers.mapping = [{ id: 'm1', label: 'In' }]
		const wrapper = await mountForm('FilepartsCreateForm', { schemaId: 5, mappingId: 'gone' })

		expect(collections.calls).toEqual([['schema', 'openregister'], ['mapping', 'integriq']])
		expect(field(wrapper, 'Schema').props('modelValue')).toEqual({ id: '5', label: 'Document' })
		expect(field(wrapper, 'Mapping (optional)').props('modelValue')).toEqual({ id: 'gone', label: 'gone' })
		expect((await edit(wrapper, 'Schema', { id: 9 })).schemaId).toBe('9')
		expect((await edit(wrapper, 'Mapping (optional)', null)).mappingId).toBe('')
		expect((await edit(wrapper, 'Size location (dot path, required)', 'body.size')).sizeLocation).toBe('body.size')
		expect((await edit(wrapper, 'Filename location (default: filename)', 'name')).filenameLocation).toBe('name')
		expect((await edit(wrapper, 'Filepart location (default: fileParts)', 'parts')).filePartLocation).toBe('parts')
		expect(field(await mountForm('FilepartsCreateForm', {}), 'Schema').props('modelValue')).toBeNull()
	})
})

describe('FilepartUploadForm', () => {
	it('shows and writes the inbound and outbound mappings', async () => {
		collections.answers.mapping = [{ id: 'm1', label: 'Inbound' }]
		const wrapper = await mountForm('FilepartUploadForm', { mappingId: 'm1', mappingOutId: 'm2' })

		expect(field(wrapper, 'Inbound mapping (required)').props('modelValue')).toEqual({ id: 'm1', label: 'Inbound' })
		expect(field(wrapper, 'Outbound mapping (optional)').props('modelValue')).toEqual({ id: 'm2', label: 'm2' })
		expect((await edit(wrapper, 'Inbound mapping (required)', { id: 3 })).mappingId).toBe('3')
		expect((await edit(wrapper, 'Outbound mapping (optional)', null)).mappingOutId).toBe('')
		expect(field(await mountForm('FilepartUploadForm', {}), 'Outbound mapping (optional)').props('modelValue')).toBeNull()
	})
})

describe('AuthenticationForm', () => {
	it('offers the six types and shows the stored one', async () => {
		const wrapper = await mountForm('AuthenticationForm', { type: 'jwt' })

		expect(field(wrapper, 'Authentication type').props('options').map((o) => o.id)).toEqual(['apikey', 'jwt', 'jwt-zgw', 'basic', 'oauth', 'nc-session'])
		expect(field(wrapper, 'Authentication type').props('modelValue').id).toBe('jwt')
		expect(field(await mountForm('AuthenticationForm', {}), 'Authentication type').props('modelValue')).toBeNull()
	})

	it('writes the type, the header, and API keys only for an API key', async () => {
		const wrapper = await mountForm('AuthenticationForm', { type: 'apikey', keys: ['a', 'b'] })

		expect(field(wrapper, 'API keys (comma-separated)').props('modelValue')).toBe('a,b')
		expect((await edit(wrapper, 'API keys (comma-separated)', 'k1, k2,')).keys).toEqual(['k1', 'k2'])
		expect((await edit(wrapper, 'Header (default: Authorization)', 'X-Key')).header).toBe('X-Key')
		expect((await edit(wrapper, 'Authentication type', null)).type).toBe('')
		expect((await edit(wrapper, 'Authentication type', { id: 'basic' })).type).toBe('basic')

		const jwt = await mountForm('AuthenticationForm', { type: 'jwt' })
		expect(() => field(jwt, 'API keys (comma-separated)')).toThrow()
		const session = await mountForm('AuthenticationForm', { type: 'nc-session' })
		expect(() => field(session, 'Header (default: Authorization)')).toThrow()
	})
})
