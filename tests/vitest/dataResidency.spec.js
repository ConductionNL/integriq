// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Data residency on the screen (connections-declare-data-residency, tasks 2
 * to 4). The residency dialog stores a country with its evidence through the
 * admin route and says what the source carries now; the outbound destinations
 * page lists the endpoint's rows with their zone, gives the verdict, says
 * which calls it does not cover, and keeps EU only mode off when the backend
 * refuses, naming the destinations that block it. The country list mirrors
 * the PHP list the backend validates against.
 *
 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-one-page-lists-every-outbound-destination-with-its-location-req-res-002
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import SourceResidencyDialog from '@/dialogs/SourceResidencyDialog.vue'
import EgressDestinationsPage from '@/views/EgressDestinations/EgressDestinationsPage.vue'
import {
	EVENT_OPEN_SOURCE_RESIDENCY,
	EVENT_SOURCE_RESIDENCY_SAVED,
	modalBus,
} from '@/handlers/modalBus.js'
import {
	blockingText,
	COUNTRY_CODES,
	countryOptions,
	verdictText,
	zoneLabel,
} from '@/views/EgressDestinations/residency.js'

const { get, put } = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, put } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))

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
					{
						class: name,
						onClick: () => this.$emit('click'),
					},
					[this.$slots.default?.(), this.$slots.actions?.()],
				)
			},
		})
	return {
		NcButton: stub('NcButton', ['variant', 'disabled']),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch', ['modelValue', 'type', 'disabled']),
		NcDialog: stub('NcDialog', ['open', 'name', 'size']),
		NcNoteCard: stub('NcNoteCard', ['type']),
		NcSelect: stub('NcSelect', ['modelValue', 'options', 'inputLabel', 'clearable']),
		NcTextField: stub('NcTextField', ['modelValue', 'label', 'helperText']),
	}
})

vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		CnDataTable: defineComponent({
			name: 'CnDataTable',
			props: ['columns', 'rows', 'loading', 'rowKey', 'emptyText'],
			render() {
				return h(
					'table',
					{},
					(this.rows || []).map((row) =>
						h('tr', { 'data-host': row.host }, [
							...this.columns.map((col) =>
								h(
									'td',
									{ 'data-col': col.key },
									this.$slots['column-' + col.key]?.({ row, value: row[col.key] }) ?? String(row[col.key] ?? ''),
								),
							),
							h('td', {}, this.$slots['row-actions']?.({ row })),
						]),
					),
				)
			},
		}),
	}
})

enableAutoUnmount(afterEach)

const LIST = {
	destinations: [
		{ host: 'zaken.example.nl', name: 'Zaken', sourceId: 'src-1', country: 'NL', basis: 'set-by-admin', evidence: 'DPA 2025', zone: 'eu' },
		{ host: 'api.example.no', name: 'Kart', sourceId: 'src-2', country: 'NO', basis: 'declared-by-app', evidence: '', zone: 'eea' },
		{ host: 'stray.example.com', name: '', sourceId: null, country: null, basis: '', evidence: '', zone: 'unknown' },
	],
	verdict: 'not-proven',
	counts: { eu: 1, eea: 1, outside: 0, unknown: 1 },
	euOnly: false,
}

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? vars[k] : m))

describe('residency text', () => {
	it('offers exactly the codes the backend accepts', () => {
		const php = readFileSync(resolve(__dirname, '../../lib/Service/Residency/ResidencyZones.php'), 'utf8')
		const block = php.match(/ISO_3166_1 = \[([\s\S]*?)\];/)[1]
		expect(COUNTRY_CODES).toEqual(block.match(/[A-Z]{2}/g))
		const options = countryOptions('en')
		expect(options).toHaveLength(COUNTRY_CODES.length)
		expect(options.find((option) => option.id === 'NL').label).toBe('Netherlands (NL)')
	})

	it('names each zone and both verdicts', () => {
		expect(zoneLabel('eu', t)).toBe('EU')
		expect(zoneLabel('eea', t)).toBe('EEA, outside the EU')
		expect(zoneLabel('outside', t)).toBe('Outside the EEA')
		expect(zoneLabel('anything', t)).toBe('Unknown')
		expect(verdictText('eu-only', t)).toContain('EU only')
		expect(verdictText('not-proven', t)).toContain('Not proven')
	})

	it('names the blocking destinations, preferring the backend sentence', () => {
		expect(blockingText({ error: 'Stays off: Kart' }, t)).toBe('Stays off: Kart')
		expect(blockingText({ blocking: [{ name: 'Kart', host: 'api.example.no' }, { name: '', host: 'stray.example.com' }] }, t)).toBe(
			'EU only mode stays off. These destinations are not shown to be in the EU: Kart, stray.example.com',
		)
	})
})

describe('SourceResidencyDialog', () => {
	beforeEach(() => {
		put.mockReset()
	})

	async function opened(source) {
		const wrapper = mount(SourceResidencyDialog, { props: { open: false, source } })
		await wrapper.setProps({ open: true })
		return wrapper
	}

	it('starts from the current residency and saves country and evidence', async () => {
		put.mockResolvedValue({ data: { residency: { country: 'DE', basis: 'set-by-admin' } } })
		const wrapper = await opened({ id: 'src-2', name: 'Kart', residency: { country: 'NO', basis: 'declared-by-app', evidence: 'Site' } })
		expect(wrapper.vm.country.id).toBe('NO')
		expect(wrapper.vm.evidence).toBe('Site')
		expect(wrapper.find('[data-testid="source-residency-current"]').text()).toContain('declared by the app')

		wrapper.vm.country = wrapper.vm.options.find((option) => option.id === 'DE')
		wrapper.vm.evidence = '  DPA 2026  '
		await wrapper.vm.save()

		expect(put).toHaveBeenCalledWith('/apps/integriq/api/sources/src-2/residency', { country: 'DE', evidence: 'DPA 2026' })
		expect(wrapper.emitted('saved')[0][0].residency).toEqual({ country: 'DE', basis: 'set-by-admin' })
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('saves nothing without evidence', async () => {
		const wrapper = await opened({ id: 'src-1', name: 'Zaken' })
		wrapper.vm.country = wrapper.vm.options.find((option) => option.id === 'NL')
		expect(wrapper.vm.canSave).toBe(false)
		await wrapper.vm.save()
		expect(put).not.toHaveBeenCalled()
	})

	it('shows the refusal and stays open', async () => {
		put.mockRejectedValue({ response: { status: 403, data: { error: 'Only an administrator can manage data residency.' } } })
		const wrapper = await opened({ id: 'src-1', name: 'Zaken', residency: { country: 'NL', basis: 'set-by-admin', evidence: 'DPA' } })
		await wrapper.vm.save()
		await flushPromises()
		expect(wrapper.find('[data-testid="source-residency-error"]').text()).toBe('Only an administrator can manage data residency.')
		expect(wrapper.emitted('close')).toBeUndefined()
	})
})

describe('EgressDestinationsPage', () => {
	beforeEach(() => {
		get.mockReset()
		put.mockReset()
		get.mockResolvedValue({ data: LIST })
	})

	async function mounted() {
		const wrapper = mount(EgressDestinationsPage)
		await flushPromises()
		return wrapper
	}

	it('lists every destination with its zone, the verdict and the counts', async () => {
		const wrapper = await mounted()
		expect(get).toHaveBeenCalledWith('/apps/integriq/api/egress/destinations')
		expect(wrapper.findAll('tr')).toHaveLength(3)
		const stray = wrapper.find('tr[data-host="stray.example.com"]')
		expect(stray.find('[data-col="name"]').text()).toBe('No source')
		expect(stray.find('[data-col="zone"]').text()).toBe('Unknown')
		expect(stray.find('[data-testid="egress-set-residency"]').exists()).toBe(false)
		expect(wrapper.find('tr[data-host="api.example.no"] [data-col="zone"]').text()).toBe('EEA, outside the EU')
		expect(wrapper.find('tr[data-host="api.example.no"] [data-col="basis"]').text()).toBe('Declared by the app')
		expect(wrapper.find('[data-testid="egress-verdict"]').text()).toContain('Not proven')
		expect(wrapper.find('[data-testid="egress-counts"]').text()).toContain('Unknown: 1')
		expect(wrapper.find('[data-testid="egress-not-covered"]').text()).toContain('other apps make without integriq are not covered')
	})

	it('keeps EU only mode off when the backend refuses, naming the blockers', async () => {
		put.mockRejectedValue({ response: { status: 409, data: { blocking: [LIST.destinations[2]] } } })
		const wrapper = await mounted()
		await wrapper.vm.setEuOnly(true)
		expect(put).toHaveBeenCalledWith('/apps/integriq/api/egress/eu-only', { enabled: true })
		expect(wrapper.vm.euOnly).toBe(false)
		expect(wrapper.find('[data-testid="egress-error"]').text()).toContain('stray.example.com')
	})

	it('switches EU only mode on when the backend allows it', async () => {
		put.mockResolvedValue({ data: { euOnly: true } })
		const wrapper = await mounted()
		await wrapper.vm.setEuOnly(true)
		expect(wrapper.vm.euOnly).toBe(true)
		expect(wrapper.find('[data-testid="egress-error"]').exists()).toBe(false)
	})

	it('opens the residency dialog for a row and reloads after a save', async () => {
		const opened = vi.fn()
		modalBus.on(EVENT_OPEN_SOURCE_RESIDENCY, opened)
		const wrapper = await mounted()
		await wrapper.find('tr[data-host="api.example.no"] [data-testid="egress-set-residency"]').trigger('click')
		expect(opened).toHaveBeenCalledWith({
			source: { id: 'src-2', name: 'Kart', residency: { country: 'NO', basis: 'declared-by-app', evidence: '' } },
		})
		modalBus.off(EVENT_OPEN_SOURCE_RESIDENCY, opened)

		modalBus.emit(EVENT_SOURCE_RESIDENCY_SAVED, {})
		await flushPromises()
		expect(get).toHaveBeenCalledTimes(2)
		wrapper.unmount()
		modalBus.emit(EVENT_SOURCE_RESIDENCY_SAVED, {})
		expect(get).toHaveBeenCalledTimes(2)
	})
})
