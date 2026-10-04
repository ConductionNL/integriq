// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The DSO activities section on integriq's admin settings page (change
 * dso-activity-mapping-table, REQ-DSO-012). These tests mount the real
 * section and the real dialog and assert what they send: a row with two case
 * types is POSTed in the schema's shape, an edit is a PUT of the stored row,
 * deactivating keeps the row and flips isActive, an unmapped activity opens
 * the dialog prefilled, and a refusal shows the server's reason.
 *
 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#requirement-administrators-maintain-the-activity-table-on-the-admin-settings-page-req-dso-012
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DsoActivityMappingSettings from '@/views/admin/DsoActivityMappingSettings.vue'
import {
	draftFromRow,
	draftProblems,
	payloadFromDraft,
} from '@/views/admin/dsoActivityMapping.js'

const { get, post, put } = vi.hoisted(() => ({
	get: vi.fn(),
	post: vi.fn(),
	put: vi.fn(),
}))
vi.mock('@nextcloud/axios', () => ({ default: { get, post, put } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn() }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props = []) =>
		defineComponent({
			name,
			props,
			emits: ['click', 'update:modelValue', 'update:open'],
			render() {
				return h(
					'div',
					{ class: name, onClick: () => this.$emit('click') },
					this.$slots.default?.(),
				)
			},
		})
	const field = defineComponent({
		name: 'NcTextField',
		props: ['modelValue', 'label', 'helperText'],
		emits: ['update:modelValue'],
		render() {
			return h('input', {
				class: 'NcTextField',
				value: this.modelValue,
				onInput: (event) =>
					this.$emit('update:modelValue', event.target.value),
			})
		},
	})
	return {
		NcButton: stub('NcButton', ['variant', 'disabled']),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch', ['modelValue', 'type']),
		NcDialog: stub('NcDialog', ['open', 'name', 'size']),
		NcNoteCard: stub('NcNoteCard', ['type']),
		NcSelect: stub('NcSelect', [
			'modelValue',
			'options',
			'inputLabel',
			'clearable',
		]),
		NcTextField: field,
	}
})

const MAPPING_URL = '/apps/openregister/api/objects/integriq/dso_activity_mapping'
const STORED = {
	id: 'row-1',
	imowId: 'nl.imow-gm0000.activiteit.DemoBouwen',
	activityName: 'Demo: bouwen',
	caseTypes: [{ reference: 'DEMO-ZAAKTYPE-BOUWEN', title: 'Demo: bouwen' }],
	samenloopStrategy: 'deelzaken',
	isActive: true,
}
const UNMAPPED = {
	activities: [
		{
			imowId: 'nl.imow-gm0000.activiteit.DemoKappen',
			activityId: 'Demo-0000-Kappen',
			activityName: 'Kappen',
			count: 3,
			lastSeen: '2026-10-03T09:00:00+00:00',
		},
	],
	withoutIdentifier: 0,
}

function answer(rows) {
	get.mockImplementation((url) =>
		Promise.resolve({
			data: url === MAPPING_URL ? { results: rows } : UNMAPPED,
		}),
	)
}

async function type(wrapper, testid, value, index = 0) {
	await wrapper.findAll(`[data-testid="${testid}"]`)[index].setValue(value)
}

describe('the DSO activities section', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
		put.mockReset()
		answer([STORED])
	})

	it('lists the rows and the unmapped activities', async () => {
		const wrapper = mount(DsoActivityMappingSettings)
		await flushPromises()

		expect(get).toHaveBeenCalledWith(MAPPING_URL, { params: { _limit: 500 } })
		expect(get).toHaveBeenCalledWith(
			'/apps/integriq/api/admin/dso-activities/unmapped',
		)
		const row = wrapper.find('[data-testid="admin-dso-activities-row"]').text()
		expect(row).toContain('Demo: bouwen')
		expect(row).toContain('nl.imow-gm0000.activiteit.DemoBouwen')
		const unmapped = wrapper
			.find('[data-testid="admin-dso-unmapped-row"]')
			.text()
		expect(unmapped).toContain('Kappen')
		expect(unmapped).toContain('3')
	})

	it('says where the activities come from when the table is empty', async () => {
		answer([])
		const wrapper = mount(DsoActivityMappingSettings)
		await flushPromises()

		expect(
			wrapper.find('[data-testid="admin-dso-activities-empty"]').text(),
		).toContain('unmapped DSO activities')
	})

	it('adds a row with two case types', async () => {
		post.mockResolvedValue({ data: {} })
		const wrapper = mount(DsoActivityMappingSettings)
		await flushPromises()

		await wrapper
			.find('[data-testid="admin-dso-activities-add"]')
			.trigger('click')
		await type(wrapper, 'dso-activity-name', 'Demo: milieu')
		await type(
			wrapper,
			'dso-activity-imow-id',
			'nl.imow-gm0000.activiteit.DemoMilieu',
		)
		await type(
			wrapper,
			'dso-activity-case-type-reference',
			'DEMO-ZAAKTYPE-MILIEU',
		)
		await type(wrapper, 'dso-activity-case-type-department', 'Demo: team milieu')
		await wrapper
			.find('[data-testid="dso-activity-add-case-type"]')
			.trigger('click')
		await type(
			wrapper,
			'dso-activity-case-type-reference',
			'DEMO-ZAAKTYPE-BOUWEN',
			1,
		)
		await wrapper.find('[data-testid="dso-activity-save"]').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(MAPPING_URL, {
			activityName: 'Demo: milieu',
			imowId: 'nl.imow-gm0000.activiteit.DemoMilieu',
			caseTypes: [
				{
					reference: 'DEMO-ZAAKTYPE-MILIEU',
					department: 'Demo: team milieu',
				},
				{ reference: 'DEMO-ZAAKTYPE-BOUWEN' },
			],
			samenloopStrategy: 'deelzaken',
			samenloopRules: [],
			isActive: true,
		})
		expect(get.mock.calls.length).toBe(4)
	})

	it('edits a row with a PUT and deactivates it without deleting it', async () => {
		put.mockResolvedValue({ data: {} })
		const wrapper = mount(DsoActivityMappingSettings)
		await flushPromises()

		await wrapper
			.find('[data-testid="admin-dso-activities-edit"]')
			.trigger('click')
		await type(
			wrapper,
			'dso-activity-case-type-reference',
			'DEMO-ZAAKTYPE-BOUWEN-2',
		)
		await wrapper.find('[data-testid="dso-activity-save"]').trigger('click')
		await flushPromises()
		expect(put).toHaveBeenCalledWith(
			`${MAPPING_URL}/row-1`,
			expect.objectContaining({
				caseTypes: [
					{ reference: 'DEMO-ZAAKTYPE-BOUWEN-2', title: 'Demo: bouwen' },
				],
			}),
		)

		await wrapper
			.find('[data-testid="admin-dso-activities-toggle"]')
			.trigger('click')
		await flushPromises()
		expect(put).toHaveBeenLastCalledWith(
			`${MAPPING_URL}/row-1`,
			expect.objectContaining({ isActive: false, imowId: STORED.imowId }),
		)
	})

	it('opens the dialog prefilled from an unmapped activity', async () => {
		const wrapper = mount(DsoActivityMappingSettings)
		await flushPromises()

		await wrapper.find('[data-testid="admin-dso-unmapped-map"]').trigger('click')

		expect(
			wrapper.find('[data-testid="dso-activity-imow-id"]').element.value,
		).toBe('nl.imow-gm0000.activiteit.DemoKappen')
		expect(
			wrapper.find('[data-testid="dso-activity-activity-id"]').element.value,
		).toBe('Demo-0000-Kappen')
		expect(wrapper.find('[data-testid="dso-activity-name"]').element.value).toBe(
			'Kappen',
		)
	})

	it('shows the reason a save was refused and keeps the dialog open', async () => {
		post.mockRejectedValue({
			response: {
				data: { message: 'Another active row already maps imow-id x.' },
			},
		})
		const wrapper = mount(DsoActivityMappingSettings)
		await flushPromises()

		await wrapper.find('[data-testid="admin-dso-unmapped-map"]').trigger('click')
		await type(wrapper, 'dso-activity-case-type-reference', 'DEMO')
		await wrapper.find('[data-testid="dso-activity-save"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="dso-activity-error"]').text()).toContain(
			'Another active row already maps',
		)
	})

	it('sends nothing for a draft the schema would refuse', async () => {
		const wrapper = mount(DsoActivityMappingSettings)
		await flushPromises()

		await wrapper
			.find('[data-testid="admin-dso-activities-add"]')
			.trigger('click')
		await type(wrapper, 'dso-activity-name', 'Zonder id')
		await wrapper.find('[data-testid="dso-activity-save"]').trigger('click')
		await flushPromises()

		expect(post).not.toHaveBeenCalled()
	})
})

describe('the draft helpers', () => {
	it('refuse what the schema and the guard refuse', () => {
		const draft = draftFromRow({})
		expect(draftProblems(draft)).toHaveLength(3)
		draft.imowId = 'Bouwen'
		expect(draftProblems(draft).join(' ')).toContain('STAM form')
	})

	it('round-trip a stored row', () => {
		const stored = {
			imowId: 'nl.imow-gm0000.activiteit.DemoKappen',
			activityName: 'Demo: kappen',
			caseTypes: [{ reference: 'X', title: 'Y', department: 'Z' }],
			samenloopStrategy: 'deelzaken',
			samenloopRules: [
				{
					withImowId: 'nl.imow-gm0000.activiteit.DemoBouwen',
					strategy: 'gecombineerd',
				},
			],
			isActive: true,
			note: 'Demo',
		}
		expect(payloadFromDraft(draftFromRow(stored))).toEqual(stored)
	})
})
