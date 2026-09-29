// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The source page lists a document generation source's vendor templates and
 * activates it (document-generation-vendor-adapter task 5, REQ-DGV-004).
 *
 * documentGeneration#templates and #activate had no caller in src/, so an
 * operator could not see a template id to hand to filinq, nor switch a vendor
 * source on. These tests mount the real panel with the section context
 * CnDetailPage provides and assert the requests it sends and what it shows.
 *
 * @spec openspec/specs/document-generation-vendor-adapter/spec.md#requirement-templates-are-listed-from-the-vendor-not-copied-req-dgv-004
 */
import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DocumentGenerationSourcePanel from '@/components/DocumentGenerationSourcePanel.vue'

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
				return h(
					'button',
					{ onClick: () => this.$emit('click') },
					this.$slots.default?.(),
				)
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
	return mount(DocumentGenerationSourcePanel, {
		global: {
			provide: { cnSectionContext: { value: { objectId: 'src-1', object } } },
		},
	})
}

const XENTIAL = { type: 'documentGeneration', isEnabled: false, name: 'Xential' }

describe('the document generation panel on the source page', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
		get.mockResolvedValue({
			data: {
				providerId: 'xential',
				templates: [
					{ id: 'tpl-besluit', name: 'Besluit' },
					{ id: 'tpl-brief', name: 'Brief' },
					{ id: 'tpl-ontvangst', name: 'Ontvangstbevestiging' },
				],
			},
		})
	})

	it('lists the vendor templates with their ids', async () => {
		const wrapper = mountFor(XENTIAL)
		await flushPromises()

		expect(get).toHaveBeenCalledWith(
			'/index.php/apps/integriq/api/document-generation/sources/src-1/templates',
		)
		const rows = wrapper.findAll(
			'[data-testid="document-generation-templates"] tbody tr',
		)
		expect(rows).toHaveLength(3)
		expect(rows[0].text()).toContain('Besluit')
		expect(rows[0].text()).toContain('tpl-besluit')
		expect(post).not.toHaveBeenCalled()
	})

	it('renders nothing for a source of another kind, and asks the vendor nothing', async () => {
		const wrapper = mountFor({ type: 'api', isEnabled: true })
		await flushPromises()

		expect(
			wrapper
				.find('[data-testid="document-generation-source-panel"]')
				.exists(),
		).toBe(false)
		expect(get).not.toHaveBeenCalled()
	})

	it('activates the source and then says it is active', async () => {
		post.mockResolvedValue({ data: { isEnabled: true, providerId: 'xential' } })
		const wrapper = mountFor(XENTIAL)
		await flushPromises()

		await wrapper
			.find('[data-testid="document-generation-activate"]')
			.trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(
			'/index.php/apps/integriq/api/document-generation/sources/src-1/activate',
		)
		expect(
			wrapper.find('[data-testid="document-generation-active"]').exists(),
		).toBe(true)
	})

	it('shows why a source cannot be activated', async () => {
		post.mockRejectedValue({
			response: {
				data: {
					error: 'Xential needs a credential reference in `configuration.authentication.credentialRef`, and this source carries none.',
				},
			},
		})
		const wrapper = mountFor(XENTIAL)
		await flushPromises()

		await wrapper.vm.activate()
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toContain(
			'credential reference',
		)
		expect(
			wrapper.find('[data-testid="document-generation-active"]').exists(),
		).toBe(false)
	})

	it('says so when the vendor cannot be reached', async () => {
		get.mockRejectedValue({
			response: {
				data: {
					error: 'The vendor could not be reached.',
					unreachable: true,
				},
			},
		})
		const wrapper = mountFor(XENTIAL)
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe(
			'The vendor could not be reached.',
		)
	})

	it('is mounted on SourceDetail and registered under that name', () => {
		const root = join(__dirname, '..', '..')
		const read = (relative) =>
			JSON.parse(readFileSync(join(root, relative), 'utf8'))
		const merged = buildManifest(
			read('src/manifest.json'),
			[],
			read('src/menu-layout.json'),
		)
		const page = merged.pages.find(
			(candidate) => candidate.id === 'SourceDetail',
		)
		const widget = page.config.bodyWidgets.find(
			(entry) => entry.component === 'DocumentGenerationSourcePanel',
		)

		expect(widget).toBeTruthy()
		const registry = readFileSync(join(root, 'src/registry.js'), 'utf8')
		expect(registry).toContain(
			"import DocumentGenerationSourcePanel from './components/DocumentGenerationSourcePanel.vue'",
		)
		expect(registry).toMatch(/^\tDocumentGenerationSourcePanel,$/m)
	})
})
