// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The migration page: pick a source, write a column mapping, save it after
 * the check, and run the read-only test (migration-source-adapters task 2).
 *
 * The four migration-source routes had no caller in src/. These tests mount
 * the real editor dialog (the Migrations index's form-dialog slot) and the
 * real test-run panel (its below-header slot) and assert the requests they
 * send. The stored payload is
 * compared with tests/fixtures/migration/column-mapping-stored-payload.json,
 * which ColumnMappingStoredPayloadTest validates against the register's
 * column_mapping schema, so what the page writes is what the register takes.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002
 */
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import MigrationTestRunPanel from '@/components/MigrationTestRunPanel.vue'
import ColumnMappingEditorModal from '@/modals/Migration/ColumnMappingEditorModal.vue'
import {
	columnsFrom,
	draftFromPreset,
	draftFromStored,
	previewRequest,
	storedPayload,
	validateRequest,
} from '@/views/Migration/columnMappingDraft.js'

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
			emits: ['click', 'update:modelValue'],
			render() {
				return h(
					'div',
					{ class: name, onClick: () => this.$emit('click') },
					this.$slots.default?.(),
				)
			},
		})
	const dialog = defineComponent({
		name: 'NcDialog',
		props: ['name', 'size', 'noClose'],
		render() {
			return h('div', { class: 'NcDialog' }, [
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	})
	return {
		NcDialog: dialog,
		NcButton: stub('NcButton', ['variant', 'disabled']),
		NcNoteCard: stub('NcNoteCard', ['type']),
		NcSelect: stub('NcSelect', ['modelValue', 'options', 'inputLabel']),
		NcTextField: stub('NcTextField', ['modelValue', 'label']),
	}
})

const FIXTURE = JSON.parse(
	readFileSync(
		join(__dirname, '../fixtures/migration/column-mapping-stored-payload.json'),
		'utf8',
	),
)

const CASE_SCHEMA = {
	id: 12,
	slug: 'case',
	title: 'Case',
	properties: { reference: {}, requesterName: {}, city: {} },
	required: ['reference'],
}

const STORED = {
	id: 'cm-1',
	name: 'Zaken uit het oude systeem',
	kind: 'case',
	targetSchema: 'case',
	columns: { zaaknummer: 'reference', naam: 'requesterName', plaats: 'city' },
	identifierColumn: 'zaaknummer',
	version: 2,
}

/**
 * Answer the page's four GETs.
 *
 * @param {string} url the requested url
 * @return {Promise<object>} the response
 */
function answerGet(url) {
	if (url.endsWith('/column-mapping/presets')) {
		return Promise.resolve({ data: { results: [] } })
	}
	if (url.endsWith('/api/migration-sources')) {
		return Promise.resolve({
			data: {
				results: [
					{
						id: 'file',
						label: 'Delivered file',
						kinds: [
							{
								kind: 'row',
								label: 'One row',
								stableIdentifier: false,
							},
						],
					},
					{ id: 'redmine', label: 'Redmine', kinds: [] },
				],
			},
		})
	}
	if (url.endsWith('/objects/integriq/column_mapping')) {
		return Promise.resolve({ data: { results: [STORED] } })
	}
	return Promise.resolve({ data: { results: [CASE_SCHEMA] } })
}

/**
 * Mount the editor dialog on the stored mapping, as the index's Edit opens it.
 *
 * @param {Function} confirm the slot's save binding
 * @return {Promise<object>} the wrapper
 */
async function mountEditor(confirm) {
	const wrapper = mount(ColumnMappingEditorModal, {
		props: { show: true, item: STORED, confirm, close: vi.fn() },
	})
	await flushPromises()
	return wrapper
}

describe('the column mapping draft', () => {
	it('writes the stored object the register schema accepts, one version up', () => {
		expect(storedPayload(draftFromStored(STORED))).toEqual(FIXTURE)
	})

	it('starts a new mapping at version 1 and leaves blank values out', () => {
		const draft = draftFromPreset({
			id: 'parnassys-leerlingen',
			mapping: { name: 'Leerlingen', columns: { leerlingnummer: 'number' } },
		})
		expect(storedPayload(draft)).toEqual({
			name: 'Leerlingen',
			columns: { leerlingnummer: 'number' },
			version: 1,
		})
	})

	it('skips a row with no column or no field, and keeps the first row for a column', () => {
		expect(
			columnsFrom([
				{ column: 'naam', target: 'name' },
				{ column: '', target: 'city' },
				{ column: 'plaats', target: '' },
				{ column: 'naam', target: 'other' },
			]),
		).toEqual({ naam: 'name' })
	})

	it('sends the schema fields and required fields with the check', () => {
		const body = validateRequest(draftFromStored(STORED), CASE_SCHEMA)
		expect(body.schemaFields).toEqual(['reference', 'requesterName', 'city'])
		expect(body.requiredFields).toEqual(['reference'])
		expect(body.mapping.columns).toEqual(STORED.columns)
	})

	it('reads a delivered file through the draft mapping in a test run', () => {
		expect(
			previewRequest('file', draftFromStored(STORED), {
				path: 'Migratie/zaken.csv',
			}),
		).toEqual({
			source: 'file',
			config: {
				path: 'Migratie/zaken.csv',
				mapping: {
					name: STORED.name,
					kind: 'case',
					columns: STORED.columns,
					identifierColumn: 'zaaknummer',
					version: 2,
				},
			},
		})
		expect(previewRequest('redmine', null, { source: 'redmine-prod' })).toEqual({
			source: 'redmine',
			config: { source: 'redmine-prod' },
		})
	})
})

describe('the column mapping editor on the Migrations index', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
		put.mockReset()
		get.mockImplementation(answerGet)
	})

	it('opens a saved mapping with every column filled, for a second delivery', async () => {
		const wrapper = await mountEditor(vi.fn())

		// Picking the saved mapping fills every column: nothing is mapped again.
		expect(wrapper.findAll('[data-testid="migration-column-row"]')).toHaveLength(
			3,
		)
		expect(wrapper.vm.selectedSchema?.id).toBe('case')
	})

	it('refuses a save the check refuses, naming the field, and stores nothing', async () => {
		post.mockRejectedValueOnce({
			response: {
				status: 400,
				data: {
					valid: false,
					errors: [
						"Column 'plaats' maps onto 'town', which the schema does not have.",
					],
				},
			},
		})
		const confirm = vi.fn()
		const wrapper = await mountEditor(confirm)
		wrapper.vm.draft.rows[2].target = 'town'

		await wrapper.find('[data-testid="migration-mapping-save"]').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledTimes(1)
		expect(post.mock.calls[0][0]).toBe(
			'/apps/integriq/api/migration-sources/column-mapping/validate',
		)
		expect(post.mock.calls[0][1].schemaFields).toEqual([
			'reference',
			'requesterName',
			'city',
		])
		expect(confirm).not.toHaveBeenCalled()
		expect(
			wrapper.find('[data-testid="migration-mapping-refused"]').text(),
		).toContain("'town'")
	})

	it('stores a checked mapping as the next version of the saved one', async () => {
		post.mockResolvedValueOnce({ data: { valid: true } })
		const confirm = vi.fn().mockResolvedValue(undefined)
		const wrapper = await mountEditor(confirm)

		await wrapper.find('[data-testid="migration-mapping-save"]').trigger('click')
		await flushPromises()

		expect(confirm).toHaveBeenCalledWith({ ...FIXTURE, id: 'cm-1' })
	})
})

describe('the migration test run panel', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
		get.mockImplementation(answerGet)
	})

	it('offers the migration sources and runs the read-only test through a saved mapping', async () => {
		post.mockResolvedValueOnce({
			data: {
				source: 'file',
				complete: false,
				wrote: false,
				kinds: [
					{ kind: 'row', label: 'One row', count: 2, complete: false },
				],
			},
		})
		const wrapper = mount(MigrationTestRunPanel)
		await flushPromises()
		expect(wrapper.vm.sourceOptions.map((option) => option.id)).toEqual([
			'file',
			'redmine',
		])
		wrapper.vm.selectedSource = wrapper.vm.sourceOptions[0]
		wrapper.vm.selectedStored = wrapper.vm.storedOptions[0]
		wrapper.vm.filePath = 'Migratie/zaken.csv'
		await flushPromises()

		await wrapper.find('[data-testid="migration-preview"]').trigger('click')
		await flushPromises()

		expect(post.mock.calls[0][0]).toBe(
			'/apps/integriq/api/migration-sources/preview',
		)
		expect(post.mock.calls[0][1].config.path).toBe('Migratie/zaken.csv')
		expect(post.mock.calls[0][1].config.mapping.columns).toEqual(STORED.columns)
		const result = wrapper
			.find('[data-testid="migration-preview-result"]')
			.text()
		expect(result).toContain('2')
		expect(result).toContain('incomplete')
	})
})
