// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A case-system source answers decidiq's five operations through ZGW, and it
 * reads everything from its configuration (design D2). Until now the source
 * editor could not pick the type at all, so an administrator had to write the
 * configuration as raw JSON. These tests mount the real SourceFormFields and
 * the real CaseSystemSourceFields and check that every key the backend reads
 * (ZgwCaseSystem, CaseSystemOperations) is written under the name it reads.
 *
 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-seeded-zgw-zaken-template-links-a-connection-at-once-req-cso-003
 */
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CaseSystemSourceFields from '@/modals/v2/CaseSystemSourceFields.vue'
import SourceFormFields from '@/modals/v2/SourceFormFields.vue'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get } }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const field = (name) =>
		defineComponent({
			name,
			props: ['modelValue', 'label', 'inputLabel', 'options', 'id', 'inputId'],
			emits: ['update:modelValue'],
			render() {
				return h('div', {
					class: name,
					'data-label': this.label || this.inputLabel,
					'data-id': this.id || this.inputId,
				})
			},
		})
	return {
		NcTextField: field('NcTextField'),
		NcSelect: field('NcSelect'),
		NcCheckboxRadioSwitch: field('NcCheckboxRadioSwitch'),
		NcButton: defineComponent({
			name: 'NcButton',
			emits: ['click'],
			render() {
				return h(
					'button',
					{ onClick: () => this.$emit('click') },
					this.$slots.default?.(),
				)
			},
		}),
	}
})

vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		CnFieldHelper: defineComponent({
			name: 'CnFieldHelper',
			render: () => h('span'),
		}),
	}
})

const root = join(__dirname, '..', '..')

const SOURCES = {
	data: {
		results: [
			{ id: 'zaken-uuid', name: 'ZGW Zaken API' },
			{ id: 'documenten-uuid', name: 'ZGW Documenten API' },
		],
	},
}

/**
 * Mount the case-system fields over a configuration.
 *
 * @param {object} configuration the source configuration
 * @return {object} the wrapper
 */
function mountFields(configuration = {}) {
	return mount(CaseSystemSourceFields, { props: { configuration } })
}

/**
 * The last configuration the component emitted.
 *
 * @param {object} wrapper the wrapper
 * @return {object} the configuration
 */
function lastEmitted(wrapper) {
	const events = wrapper.emitted('update:configuration')
	return events[events.length - 1][0]
}

describe('CaseSystemSourceFields', () => {
	beforeEach(() => {
		get.mockReset()
		get.mockResolvedValue(SOURCES)
	})

	it("offers the instance's sources for the Zaken and the Documenten API", async () => {
		const wrapper = mountFields()
		await flushPromises()

		expect(get.mock.calls[0][0]).toContain(
			'/apps/openregister/api/objects/integriq/source',
		)
		const selects = wrapper.findAllComponents({ name: 'NcSelect' })
		const zaken = selects.find(
			(s) => s.attributes('data-id') === 'cn-case-system-zaken-source',
		)
		expect(zaken.props('options').map((o) => o.id)).toEqual([
			'zaken-uuid',
			'documenten-uuid',
		])
	})

	it('writes every key under the name the backend reads, keeping the rest', async () => {
		const wrapper = mountFields({ mock: false, unrelated: 'kept' })
		await flushPromises()

		const select = (id) =>
			wrapper
				.findAllComponents({ name: 'NcSelect' })
				.find((s) => s.attributes('data-id') === id)
		const text = (id) =>
			wrapper
				.findAllComponents({ name: 'NcTextField' })
				.find((s) => s.attributes('data-id') === id)

		select('cn-case-system-zaken-source').vm.$emit('update:modelValue', {
			id: 'zaken-uuid',
		})
		select('cn-case-system-documenten-source').vm.$emit('update:modelValue', {
			id: 'documenten-uuid',
		})
		text('cn-case-system-meeting-zaaktype').vm.$emit(
			'update:modelValue',
			'https://zgw.example/zaaktypen/1',
		)
		text('cn-case-system-bronorganisatie').vm.$emit(
			'update:modelValue',
			'002220647',
		)
		text('cn-case-system-auteur').vm.$emit('update:modelValue', 'Griffie')
		select('cn-case-system-confidential-as').vm.$emit('update:modelValue', {
			id: 'geheim',
		})
		select('cn-case-system-public-as').vm.$emit('update:modelValue', {
			id: 'beperkt_openbaar',
		})

		const written = lastEmitted(wrapper)
		expect(written).toMatchObject({
			zakenSource: 'zaken-uuid',
			documentenSource: 'documenten-uuid',
			meetingZaaktype: 'https://zgw.example/zaaktypen/1',
			bronorganisatie: '002220647',
			auteur: 'Griffie',
			confidentialAs: 'geheim',
			publicAs: 'beperkt_openbaar',
			unrelated: 'kept',
		})
	})

	it('offers only the confidentiality levels the ZGW APIs accept', () => {
		const wrapper = mountFields()
		const confidential = wrapper
			.findAllComponents({ name: 'NcSelect' })
			.find(
				(s) => s.attributes('data-id') === 'cn-case-system-confidential-as',
			)
		const zaken = JSON.parse(
			readFileSync(
				join(root, 'tests/fixtures/zgw/zaken-1.5.1.schema.json'),
				'utf8',
			),
		)
		const found = JSON.stringify(zaken).match(/"enum":\[("openbaar"[^\]]*)\]/)
		const allowed = JSON.parse('[' + found[1] + ']')
		expect(
			confidential
				.props('options')
				.map((o) => o.id)
				.sort(),
		).toEqual([...allowed].sort())
	})

	it('writes the document type per kind as an object, and removes a row', async () => {
		const wrapper = mountFields({
			kinds: { besluitenlijst: 'https://zgw.example/iot/1' },
		})
		await flushPromises()

		await wrapper.find('[data-testid="case-system-add-kind"]').trigger('click')
		const texts = () => wrapper.findAllComponents({ name: 'NcTextField' })
		const kind = texts().filter((t) =>
			t.attributes('data-id')?.startsWith('cn-case-system-kind-name-'),
		)[1]
		const url = texts().filter((t) =>
			t.attributes('data-id')?.startsWith('cn-case-system-kind-url-'),
		)[1]
		kind.vm.$emit('update:modelValue', 'agenda')
		url.vm.$emit('update:modelValue', 'https://zgw.example/iot/2')

		expect(lastEmitted(wrapper).kinds).toEqual({
			besluitenlijst: 'https://zgw.example/iot/1',
			agenda: 'https://zgw.example/iot/2',
		})

		await wrapper
			.findAll('[data-testid="case-system-remove-kind"]')[0]
			.trigger('click')
		expect(lastEmitted(wrapper).kinds).toEqual({
			agenda: 'https://zgw.example/iot/2',
		})
	})

	it('switches mock mode as a boolean', async () => {
		const wrapper = mountFields({})
		await flushPromises()
		wrapper
			.findComponent({ name: 'NcCheckboxRadioSwitch' })
			.vm.$emit('update:modelValue', true)
		expect(lastEmitted(wrapper).mock).toBe(true)
	})

	it('still lists the sources when the fetch fails, as an empty list', async () => {
		get.mockRejectedValue(new Error('down'))
		const wrapper = mountFields()
		await flushPromises()
		const zaken = wrapper
			.findAllComponents({ name: 'NcSelect' })
			.find((s) => s.attributes('data-id') === 'cn-case-system-zaken-source')
		expect(zaken.props('options')).toEqual([])
	})
})

describe('SourceFormFields and the case-system type', () => {
	beforeEach(() => {
		get.mockReset()
		get.mockResolvedValue({ data: { results: [], providers: [] } })
	})

	/**
	 * Mount the source editor over one form.
	 *
	 * @param {object} formData the form data
	 * @return {object} the wrapper and its updateField spy
	 */
	function mountEditor(formData) {
		const updateField = vi.fn()
		const wrapper = mount(SourceFormFields, {
			props: {
				fields: [{ key: 'type', label: 'Type' }],
				formData,
				updateField,
			},
		})
		return { wrapper, updateField }
	}

	it('offers case-system as a source type', () => {
		const { wrapper } = mountEditor({ type: 'api' })
		const type = wrapper.findAllComponents({ name: 'NcSelect' })[0]
		expect(type.props('options').map((o) => o.id)).toContain('case-system')
	})

	it('shows the case-system fields only on a case-system source', async () => {
		expect(
			mountEditor({ type: 'api' })
				.wrapper.findComponent(CaseSystemSourceFields)
				.exists(),
		).toBe(false)

		const { wrapper, updateField } = mountEditor({
			type: 'case-system',
			configuration: { mock: true },
		})
		await flushPromises()
		const fields = wrapper.findComponent(CaseSystemSourceFields)
		expect(fields.exists()).toBe(true)
		expect(fields.props('configuration')).toEqual({ mock: true })

		fields.vm.$emit('update:configuration', { mock: false })
		expect(updateField).toHaveBeenCalledWith('configuration', { mock: false })
	})
})
