// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The source page of a database source lists its declared statements, runs a
 * read statement once to show the first page and its duration, and lists the
 * tables and columns (sources-database-adapter task 2, REQ-DBSF-001).
 *
 * These tests mount the real panel with the section context CnDetailPage
 * provides and assert the requests it sends and what it shows.
 *
 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
 */
import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DatabaseSourcePanel from '@/components/DatabaseSourcePanel.vue'

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		NcButton: defineComponent({
			name: 'NcButton',
			props: ['variant', 'disabled'],
			emits: ['click'],
			render() {
				return h('button', { onClick: () => this.$emit('click') }, this.$slots.default?.())
			},
		}),
		NcTextField: defineComponent({
			name: 'NcTextField',
			props: ['modelValue', 'label'],
			emits: ['update:modelValue'],
			render() {
				return h('input', {
					'aria-label': this.label,
					value: this.modelValue,
					onInput: (event) => this.$emit('update:modelValue', event.target.value),
				})
			},
		}),
	}
})

/**
 * Mount the panel over one loaded source.
 *
 * @param {object} object the source object
 * @return {object} the wrapper
 */
function mountFor(object) {
	return mount(DatabaseSourcePanel, {
		global: {
			provide: { cnSectionContext: { value: { objectId: 'src-1', object } } },
		},
	})
}

const ZAKEN = {
	type: 'database',
	name: 'Zaken',
	database: { driver: 'pdo_pgsql', host: 'db.example.nl', port: 5432, dbname: 'zaken', user: 'integriq_read' },
	statements: [
		{ name: 'zaken-sinds', kind: 'read', sql: 'SELECT id, zaaknummer FROM zaken WHERE id > :after ORDER BY id LIMIT :limit', pageKey: 'id' },
		{ name: 'zaak-per-status', kind: 'read', sql: 'SELECT id FROM zaken WHERE status = :status', parameters: [{ name: 'status' }] },
	],
}

describe('the database panel on the source page', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
	})

	it('shows where it connects and that a read-only account is enough', () => {
		const wrapper = mountFor(ZAKEN)

		expect(wrapper.find('[data-testid="database-source-connection"]').text()).toBe('Database zaken on db.example.nl:5432 as integriq_read')
		expect(wrapper.find('[data-testid="database-source-account-advice"]').text()).toBe('This source only reads. Connect with a read-only account.')
		expect(wrapper.findAll('[data-statement]').map((item) => item.attributes('data-statement'))).toEqual(['zaken-sinds', 'zaak-per-status'])
	})

	it('warns about the account when a write statement is declared, and offers no test for it', () => {
		const wrapper = mountFor({
			...ZAKEN,
			statements: [...ZAKEN.statements, { name: 'zaak-sluiten', kind: 'write', sql: 'UPDATE zaken SET status = :status' }],
		})

		expect(wrapper.find('[data-testid="database-source-account-advice"]').text()).toBe('This source declares write statements. Its account needs only the rights those statements use.')
		expect(wrapper.find('[data-testid="database-source-test-zaak-sluiten"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="database-source-test-zaken-sinds"]').exists()).toBe(true)
	})

	it('runs a read statement with its values and shows the first page and the duration', async () => {
		post.mockResolvedValue({ data: { rows: [{ id: 3, zaaknummer: 'Z-0003' }, { id: 4, zaaknummer: 'Z-0004' }], rowCount: 2, more: true, durationMs: 18 } })
		const wrapper = mountFor(ZAKEN)

		await wrapper.find('input[aria-label="status"]').setValue('open')
		await wrapper.find('[data-testid="database-source-test-zaak-per-status"]').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(
			'/index.php/apps/integriq/api/sources/src-1/database/statements/zaak-per-status/test',
			{ parameters: { status: 'open' } },
		)
		expect(wrapper.find('[data-testid="database-source-test-summary"]').text()).toBe('Rows on the first page: 2, in 18 ms. More rows follow.')
		const cells = wrapper.findAll('[data-testid="database-source-test-rows"] tbody td').map((cell) => cell.text())
		expect(cells).toEqual(['3', 'Z-0003', '4', 'Z-0004'])
	})

	it('asks for no value for the placeholders a paged read binds itself', () => {
		const wrapper = mountFor(ZAKEN)

		expect(wrapper.find('[data-statement="zaken-sinds"] input').exists()).toBe(false)
	})

	it('shows the refusal when the statement cannot run', async () => {
		post.mockRejectedValue({ response: { data: { error: 'The PHP extension pdo_pgsql is not installed on this server.' } } })
		const wrapper = mountFor(ZAKEN)

		await wrapper.find('[data-testid="database-source-test-zaken-sinds"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-statement="zaken-sinds"] [role="alert"]').text()).toBe('The PHP extension pdo_pgsql is not installed on this server.')
	})

	it('lists the tables and views with their columns', async () => {
		get.mockResolvedValue({
			data: {
				tables: [
					{ name: 'zaken', kind: 'table', columns: [{ name: 'id', type: 'integer' }, { name: 'zaaknummer', type: 'string' }] },
					{ name: 'open_zaken', kind: 'view', columns: [{ name: 'id', type: 'integer' }] },
				],
			},
		})
		const wrapper = mountFor(ZAKEN)

		await wrapper.find('[data-testid="database-source-discover"]').trigger('click')
		await flushPromises()

		expect(get).toHaveBeenCalledWith('/index.php/apps/integriq/api/sources/src-1/database/schema')
		const items = wrapper.findAll('[data-testid="database-source-tables"] li')
		expect(items.map((item) => item.attributes('data-table'))).toEqual(['zaken', 'open_zaken'])
		expect(items[0].text()).toContain('id (integer), zaaknummer (string)')
		expect(items[1].text()).toContain('Database view')
	})

	it('renders nothing for another kind of source', () => {
		const wrapper = mountFor({ type: 'api', name: 'REST' })

		expect(wrapper.find('[data-testid="database-source-panel"]').exists()).toBe(false)
	})

	it('is mounted on the source page', () => {
		const read = (file) => JSON.parse(readFileSync(join(process.cwd(), file), 'utf8'))
		const merged = buildManifest(read('src/manifest.json'), [], read('src/menu-layout.json'))
		const page = merged.pages.find((candidate) => candidate.id === 'SourceDetail')
		const widget = page.config.bodyWidgets.find((entry) => entry.component === 'DatabaseSourcePanel')

		expect(widget).toEqual({ id: 'src-database', component: 'DatabaseSourcePanel', placement: 'after-data', colSpan: 12 })
	})
})
