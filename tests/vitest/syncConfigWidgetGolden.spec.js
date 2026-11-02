// @vitest-environment jsdom

import { translate } from '@nextcloud/l10n'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation of SyncConfigWidget for every source/target type: the
 * option lists it loads (and how it reads each answer shape), its markup,
 * what every child listener emits, the pickers, the validation block, the
 * file picker, and every failed lookup. Requests, emits and state are
 * recorded against a scripted axios.
 *
 * @spec openspec/specs/sync-editor-ui/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SyncConfigWidget from '@/views/Synchronization/SyncConfigWidget.vue'

const { answers, requests, picker } = vi.hoisted(() => ({
	answers: {},
	requests: [],
	picker: { result: () => Promise.resolve('/a/file.csv') },
}))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (url, config) => {
			requests.push([url, config ?? null])
			const answer = answers[url]
			if (answer instanceof Error || answer?.reject) {
				return Promise.reject(answer.reject ?? answer)
			}
			return Promise.resolve({ data: answer ?? { results: [] } })
		},
	},
}))
vi.mock('@nextcloud/dialogs', () => {
	const builder = {}
	for (const name of [
		'setMultiSelect',
		'setMimeTypeFilter',
		'setModal',
		'setType',
		'allowDirectories',
	]) {
		builder[name] = (...args) => {
			requests.push(['picker.' + name, ...args])
			return builder
		}
	}
	builder.build = () => ({ pick: () => picker.result() })
	return {
		FilePickerType: { Choose: 1 },
		getFilePickerBuilder: (title) => {
			requests.push(['picker', title])
			return builder
		},
	}
})
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name) =>
		defineComponent({
			name,
			inheritAttrs: false,
			props: [
				'modelValue',
				'options',
				'loading',
				'label',
				'inputLabel',
				'inputId',
				'disabled',
				'placeholder',
				'variant',
			],
			render() {
				return h(
					'div',
					{
						class: name,
						'data-label': this.label ?? this.inputLabel,
						'data-id': this.inputId,
						'data-value': JSON.stringify(this.modelValue ?? null),
						'data-options': this.options
							? JSON.stringify(this.options)
							: undefined,
						'data-loading': this.loading ? 'yes' : undefined,
						'data-disabled': this.disabled ? 'yes' : undefined,
					},
					[this.$slots.icon?.(), this.$slots.default?.()],
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

globalThis.t = translate

const URL = {
	sources: '/index.php/apps/openregister/api/objects/integriq/source',
	messageSchemas:
		'/index.php/apps/openregister/api/objects/integriq/message_schema',
	registers: '/index.php/apps/openregister/api/registers',
	schemas: '/index.php/apps/openregister/api/schemas',
	tables: '/index.php/apps/integriq/api/synchronizations/tables-bridge/tables',
	forms: '/index.php/apps/integriq/api/synchronizations/forms-bridge/forms',
}

const RICH = {
	[URL.sources]: {
		results: [
			{ id: 's1', name: 'One' },
			{ uuid: 'u2', title: 'Two' },
			{ id: 's3' },
			{ id: 's4', name: 'Named', title: 'Titled' },
		],
	},
	[URL.messageSchemas]: {
		results: [
			{ '@self': { id: 'm1' }, name: 'Msg', version: '2' },
			{ id: 'm2', name: 'Plain' },
		],
	},
	[URL.registers]: [
		{
			id: 1,
			title: 'Reg',
			schemas: [
				10,
				{ id: 11, title: 'Inline' },
				{ slug: 'sl' },
				null,
				{ id: 'u-x', title: 'Own' },
			],
		},
		{ id: 2, slug: 'two' },
		{ id: 3, schemas: 'x', title: 'Three', name: 'Drei' },
	],
	[URL.schemas]: {
		results: [
			{ id: 10, title: 'Ten' },
			{ uuid: 'u-x', name: 'Named' },
			{ slug: 'only' },
			null,
			{ id: null, uuid: null, title: 'Nobody' },
		],
	},
	[URL.tables]: { results: [{ id: 5, title: 'Table five' }] },
	[URL.forms]: { results: [{ id: 7, title: 'Form seven' }] },
}

const CASES = [
	[
		'api',
		's1',
		{ endpoint: '/x', validation: { messageSchema: 'm9', mode: 'refuse' } },
	],
	['register/schema', '1/10', {}],
	['register/schema', '2', {}],
	['nextcloud-table', 's1', { tableId: '5', columnMapping: { a: 'b' } }],
	['nextcloud-form', 's1', { formId: 7 }],
	['file', '/data/x.csv', { format: 'csv' }],
	['', '', null],
]

/**
 * The rendered markup without scoped-style ids and HTML comments.
 *
 * @param {object} wrapper the mounted widget
 * @return {string} the markup
 */
function markup(wrapper) {
	return wrapper
		.html()
		.replace(/ data-v-[0-9a-f]+=""/g, '')
		.replace(/\n?\s*<!--[\s\S]*?-->/g, '')
}

/**
 * Mount the widget after scripting the answers.
 *
 * @param {Array} props type, sourceId and config
 * @param {object} script url to answer
 * @return {Promise<object>} the wrapper
 */
async function mountWidget([type, sourceId, config], script = RICH) {
	for (const key of Object.keys(answers)) delete answers[key]
	Object.assign(answers, script)
	requests.length = 0
	const wrapper = mount(SyncConfigWidget, {
		props: { kind: 'source', type, sourceId, config },
		global: {
			mocks: { t: translate },
			stubs: { TablesColumnMapping: true, FormsFieldMapping: true },
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * The widget state the golden pins.
 *
 * @param {object} vm the widget
 * @return {object} the state
 */
function state(vm) {
	const keys = [
		'sourceOptions',
		'sourcesLoading',
		'registerOptions',
		'registersLoading',
		'selectedRegisterRecord',
		'schemaLabelsById',
		'pickingFile',
		'pickerError',
		'tableOptions',
		'tablesLoading',
		'tablesError',
		'formOptions',
		'formsLoading',
		'formsError',
		'messageSchemaOptions',
		'messageSchemasLoading',
		'schemaOptions',
		'selectedSchema',
		'selectedTable',
		'selectedForm',
		'selectedSource',
		'selectedRegister',
		'validationModeOption',
		'validationSchemaOption',
	]
	return JSON.parse(
		JSON.stringify(
			Object.fromEntries(
				keys.map((k) => [k, vm[k] === undefined ? '<undefined>' : vm[k]]),
			),
		),
	)
}

describe('SyncConfigWidget', () => {
	it.each(CASES)('type %j loads and renders as recorded', async (...props) => {
		const wrapper = await mountWidget(props)
		expect({
			requests: [...requests],
			state: state(wrapper.vm),
		}).toMatchSnapshot()
		expect(markup(wrapper)).toMatchSnapshot()
	})

	it.each(CASES)(
		'type %j emits from every listener as recorded',
		async (...props) => {
			const wrapper = await mountWidget(props)
			const steps = []
			for (const child of wrapper
				.findAllComponents('*')
				.filter((c) => c.vm !== wrapper.vm)) {
				const attrs = child.vm.$attrs || {}
				for (const key of Object.keys(attrs).sort()) {
					if (!/^on[A-Z]/.test(key) || typeof attrs[key] !== 'function')
						continue
					for (const value of [
						{ id: 'picked', label: 'P' },
						null,
						'typed',
						'',
					]) {
						const before =
							(wrapper.emitted()['update:config'] || []).length
							+ (wrapper.emitted()['update:sourceId'] || []).length
						attrs[key](value)
						await flushPromises()
						const emitted = wrapper.emitted()
						steps.push([
							child.vm.$options.name,
							key,
							value,
							[
								...(emitted['update:config'] || []),
								...(emitted['update:sourceId'] || []),
							].length - before,
						])
					}
				}
			}
			expect(
				JSON.parse(
					JSON.stringify({
						steps,
						emitted: {
							config: wrapper.emitted('update:config'),
							sourceId: wrapper.emitted('update:sourceId'),
						},
					}),
				),
			).toMatchSnapshot()
		},
	)

	it('reads every answer shape and failure as recorded', async () => {
		const shapes = [
			{
				[URL.registers]: { results: [{ id: 3 }] },
				[URL.schemas]: [{ id: 1, slug: 's' }],
			},
			{ [URL.registers]: { nothing: true }, [URL.schemas]: { nothing: true } },
			{ [URL.registers]: new Error('down'), [URL.schemas]: new Error('down') },
		]
		const out = []
		for (const script of shapes) {
			const wrapper = await mountWidget(['register/schema', '3/1', {}], script)
			out.push(state(wrapper.vm))
		}
		for (const script of [
			{ [URL.sources]: [{ id: 'a' }], [URL.messageSchemas]: {} },
			{ [URL.sources]: { nothing: 1 }, [URL.messageSchemas]: new Error('x') },
			{ [URL.sources]: new Error('x') },
		]) {
			const wrapper = await mountWidget(['api', '', {}], script)
			out.push(state(wrapper.vm))
		}
		for (const [type, url] of [
			['nextcloud-table', URL.tables],
			['nextcloud-form', URL.forms],
		]) {
			for (const answer of [
				{ reject: { response: { data: { error: 'srv' } } } },
				new Error('x'),
				{ other: 1 },
			]) {
				const wrapper = await mountWidget([type, 's1', {}], {
					...RICH,
					[url]: answer,
				})
				out.push(state(wrapper.vm))
			}
			const noSource = await mountWidget([type, '', {}])
			await noSource.vm[
				type === 'nextcloud-table' ? 'fetchTables' : 'fetchForms'
			]()
			out.push([
				requests.filter(([u]) => u === url).length,
				state(noSource.vm),
			])
		}
		expect(out).toMatchSnapshot()
	})

	it('refetches the bridge lists when the source changes', async () => {
		const out = []
		for (const type of ['nextcloud-table', 'nextcloud-form', 'api']) {
			const wrapper = await mountWidget([type, 's1', {}])
			requests.length = 0
			await wrapper.setProps({ sourceId: 's3' })
			await flushPromises()
			await wrapper.setProps({ sourceId: '' })
			await flushPromises()
			out.push([type, [...requests]])
		}
		expect(out).toMatchSnapshot()
	})

	it('keeps lists when a type returns without a source, and clears stale errors', async () => {
		const out = []
		const tables = await mountWidget(['nextcloud-table', 's1', {}])
		await tables.setProps({ sourceId: '' })
		await tables.setProps({ type: 'api' })
		await tables.setProps({ type: 'nextcloud-table' })
		await flushPromises()
		out.push(state(tables.vm))
		const forms = await mountWidget(['nextcloud-form', 's1', {}], {
			...RICH,
			[URL.forms]: new Error('x'),
		})
		out.push(forms.vm.formsError)
		answers[URL.forms] = RICH[URL.forms]
		await forms.setProps({ sourceId: 's3' })
		await flushPromises()
		out.push(state(forms.vm))
		const schemas = await mountWidget(['api', 's1', {}])
		answers[URL.messageSchemas] = new Error('down')
		await schemas.vm.fetchMessageSchemas()
		out.push(state(schemas.vm))
		expect(out).toMatchSnapshot()
	})

	it('does not reload lists it already has', async () => {
		const wrapper = await mountWidget(['api', 's1', {}])
		await wrapper.setProps({ type: 'register/schema' })
		await flushPromises()
		requests.length = 0
		await wrapper.setProps({ type: 'api' })
		await wrapper.setProps({ type: 'register/schema' })
		await wrapper.setProps({ type: 'nextcloud-table' })
		await wrapper.setProps({ type: 'nextcloud-form' })
		await flushPromises()
		expect(requests).toMatchSnapshot()
	})

	it.each([
		['a path', () => Promise.resolve('/x/y.json')],
		['no path', () => Promise.resolve('')],
		['a cancel', () => Promise.reject(new Error('User cancelled'))],
		['an error', () => Promise.reject(new Error('Disk full'))],
		['a bare rejection', () => Promise.reject(null)],
	])('picks a file with %s as recorded', async (_label, result) => {
		const wrapper = await mountWidget(['file', '', {}])
		picker.result = result
		wrapper.vm.pickerError = 'old'
		const picking = wrapper.vm.openFilePicker()
		const during = wrapper.vm.pickingFile
		await picking
		expect({
			during,
			requests: [...requests],
			emitted: wrapper.emitted('update:sourceId') ?? null,
			pickingFile: wrapper.vm.pickingFile,
			pickerError: wrapper.vm.pickerError,
		}).toMatchSnapshot()
	})

	it('writes config keys, tables, forms and validation as recorded', async () => {
		const out = []
		for (const config of [
			{
				a: 1,
				tableId: 5,
				columnMapping: {},
				formId: 7,
				validation: { mode: 'refuse' },
			},
			null,
			[1],
			'x',
		]) {
			const wrapper = await mountWidget(['api', 's1', config])
			const vm = wrapper.vm
			out.push([
				'configValue',
				['a', 'tableId', 'missing'].map((k) => vm.configValue(k)),
			])
			vm.onConfigUpdate('a', '')
			vm.onConfigUpdate('b', 'v')
			vm.onConfigUpdate('c', null)
			vm.onTablePick({ id: '9' })
			vm.onTablePick(null)
			vm.onFormPick({ id: '8' })
			vm.onFormPick(undefined)
			vm.onValidationSchemaPick({ id: 42 })
			vm.onValidationSchemaPick(null)
			vm.onValidationModePick(null)
			vm.onValidationModePick({ id: 'refuse' })
			vm.onSourcePick({ id: 3 })
			vm.onSourcePick(null)
			vm.onRegisterPick({ id: 1, schemas: [10] })
			vm.onSchemaPick({ id: 10 })
			vm.onSchemaPick(null)
			vm.onRegisterPick(null)
			vm.onSchemaPick({ id: 10 })
			out.push(
				JSON.parse(
					JSON.stringify([
						wrapper.emitted('update:config'),
						wrapper.emitted('update:sourceId'),
					]),
				),
			)
		}
		expect(out).toMatchSnapshot()
	})
})
