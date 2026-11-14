// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Route to publications" from the SharePoint card: what the setup dialog asks
 * Graph (through the lookup route), what it says when Graph refuses, and what
 * it saves.
 *
 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SharePointPublicationSetupDialog from '@/dialogs/SharePointPublicationSetupDialog.vue'
import {
	canSave,
	createdSynchronizationId,
	intakeBody,
	lookupFailureText,
	lookupOptions,
	lookupParams,
	offersPublicationRoute,
} from '@/dialogs/sharePointSetup.js'

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props = []) =>
		defineComponent({
			name,
			props,
			emits: ['click', 'update:modelValue', 'update:open'],
			render() {
				return h('div', { class: name, onClick: () => this.$emit('click') }, [this.$slots.default?.()])
			},
		})
	return {
		NcButton: stub('NcButton', ['variant', 'disabled']),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch', ['modelValue', 'type']),
		NcDialog: stub('NcDialog', ['open', 'name', 'size']),
		NcNoteCard: stub('NcNoteCard', ['type']),
		NcSelect: stub('NcSelect', ['modelValue', 'options', 'inputLabel', 'loading', 'placeholder', 'clearable']),
		NcTextField: stub('NcTextField', ['modelValue', 'label', 'disabled']),
	}
})

globalThis.t = (_app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_match, key) => String(vars[key] ?? ''))

const CREDENTIAL = { id: 'cred-1', label: 'Graph (microsoft)' }

/**
 * Answer GETs by URL and lookup kind.
 *
 * @param {object} lookups Lookup kind => answer or Error.
 */
function answer(lookups = {}) {
	get.mockImplementation(async (url, config = {}) => {
		if (url === '/apps/openregister/api/credentials') {
			return { data: { results: [{ id: 'cred-1', name: 'Graph', provider: 'microsoft' }] } }
		}
		if (url.startsWith('/apps/openregister/api/objects/')) {
			return { data: { results: [{ id: 'cat-woo', title: 'Woo' }] } }
		}
		const result = lookups[config.params?.kind]
		if (result instanceof Error) {
			throw result
		}
		return { data: result ?? { items: [] } }
	})
}

/**
 * The dialog, opened.
 *
 * @return {Promise<object>} The wrapper.
 */
async function openDialog() {
	const wrapper = mount(SharePointPublicationSetupDialog, { props: { open: false }, global: { mocks: { t: globalThis.t } } })
	await wrapper.setProps({ open: true })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	get.mockReset()
	post.mockReset()
})

describe('the request shapes', () => {
	it('offers the route on the SharePoint card only', () => {
		expect(offersPublicationRoute({ slug: 'adapter:sharepoint-online' })).toBe(true)
		expect(offersPublicationRoute({ slug: 'adapter:microsoft-365' })).toBe(false)
		expect(offersPublicationRoute(null)).toBe(false)
	})

	it('sends only the parameters a lookup has', () => {
		expect(lookupParams('c', 'children', { driveId: ' d ', itemId: '', search: undefined })).toEqual({ credential: 'c', kind: 'children', driveId: 'd' })
	})

	it('keeps only folders when asked', () => {
		const data = { items: [{ id: 'a', name: 'Gereed', isFolder: true }, { id: 'b', name: 'x.pdf', isFolder: false }, { name: 'no id' }] }
		expect(lookupOptions(data, true)).toEqual([{ id: 'a', label: 'Gereed', webUrl: '' }])
		expect(lookupOptions(data)).toHaveLength(2)
		expect(lookupOptions(null)).toEqual([])
	})

	it('names the Graph permission a refusal needs', () => {
		const refusal = { response: { data: { error: 'graph_refused', permission: 'Sites.Read.All', graphMessage: 'Access denied' } } }
		expect(lookupFailureText(refusal)).toBe('Microsoft Graph refused this lookup. Grant the app registration the Sites.Read.All permission and try again. (Access denied)')
		expect(lookupFailureText({ response: { data: { error: 'credential_unusable' } } })).toBe('The credential could not be used. Check it in the credential broker.')
		expect(lookupFailureText(new Error('x'))).toBe('The lookup failed. Try again.')
	})

	it('needs every choice but the folder before it saves', () => {
		const state = { credential: CREDENTIAL, site: { id: 's' }, drive: { id: 'd' }, folder: null, catalog: 'cat', register: 'publication', schema: 'publication' }
		expect(canSave(state)).toBe(true)
		expect(canSave({ ...state, catalog: '  ' })).toBe(false)
		expect(intakeBody(state)).toEqual({
			credentialId: 'cred-1',
			siteId: 's',
			driveId: 'd',
			folderId: '',
			folderName: '',
			catalog: 'cat',
			register: 'publication',
			schema: 'publication',
			requiresApproval: false,
		})
		expect(createdSynchronizationId({ created: { synchronizations: [{ id: 'sync-1' }] } })).toBe('sync-1')
		expect(createdSynchronizationId({})).toBeNull()
	})
})

describe('the setup dialog', () => {
	it('connects a Woo coordinator\'s library and saves one setup', async () => {
		answer({
			sites: { items: [{ id: 'site-woo', name: 'Woo verzoeken' }] },
			drives: { items: [{ id: 'drive-dossiers', name: 'Dossiers' }] },
			children: { items: [{ id: 'folder-gereed', name: 'Gereed', isFolder: true }, { id: 'f', name: 'readme.docx', isFolder: false }] },
		})
		post.mockResolvedValue({ data: { created: { synchronizations: [{ slug: 's', id: 'sync-1' }] } } })
		const wrapper = await openDialog()

		expect(wrapper.vm.credentialOptions.map((option) => option.id)).toEqual(['cred-1'])
		expect(wrapper.vm.catalogOptions).toEqual([{ id: 'cat-woo', label: 'Woo' }])

		wrapper.vm.credential = wrapper.vm.credentialOptions[0]
		wrapper.vm.siteSearch = 'Woo'
		await wrapper.vm.searchSites()
		expect(get).toHaveBeenCalledWith('/apps/integriq/api/sharepoint/lookup', { params: { credential: 'cred-1', kind: 'sites', search: 'Woo' } })

		wrapper.vm.site = wrapper.vm.siteOptions[0]
		await wrapper.vm.onSitePicked()
		wrapper.vm.drive = wrapper.vm.driveOptions[0]
		await wrapper.vm.onDrivePicked()
		expect(get).toHaveBeenCalledWith('/apps/integriq/api/sharepoint/lookup', { params: { credential: 'cred-1', kind: 'children', driveId: 'drive-dossiers' } })
		expect(wrapper.vm.folderOptions.map((option) => option.label)).toEqual(['Gereed'])

		wrapper.vm.folder = wrapper.vm.folderOptions[0]
		wrapper.vm.catalog = wrapper.vm.catalogOptions[0]
		wrapper.vm.requiresApproval = true
		await flushPromises()
		expect(wrapper.vm.saveable).toBe(true)

		await wrapper.vm.save()
		expect(post).toHaveBeenCalledWith('/apps/integriq/api/sharepoint/publication-intake', {
			credentialId: 'cred-1',
			siteId: 'site-woo',
			driveId: 'drive-dossiers',
			folderId: 'folder-gereed',
			folderName: 'Gereed',
			catalog: 'cat-woo',
			register: 'publication',
			schema: 'publication',
			requiresApproval: true,
		})
		expect(wrapper.vm.synchronizationId).toBe('sync-1')

		post.mockResolvedValue({ data: {} })
		await wrapper.vm.runNow()
		expect(post).toHaveBeenLastCalledWith('/apps/integriq/api/synchronizations/sync-1/run')
		expect(wrapper.vm.successMessage).toBe('The run started and waits for approval before it writes.')
	})

	it('names the missing Graph permission and creates nothing when the site search is refused', async () => {
		const refusal = Object.assign(new Error('403'), { response: { data: { error: 'graph_refused', permission: 'Sites.Read.All' } } })
		answer({ sites: refusal })
		const wrapper = await openDialog()

		wrapper.vm.credential = CREDENTIAL
		wrapper.vm.siteSearch = 'Woo'
		await wrapper.vm.searchSites()
		await flushPromises()

		expect(wrapper.vm.errorMessage).toContain('Sites.Read.All')
		expect(wrapper.vm.siteOptions).toEqual([])
		expect(wrapper.vm.saveable).toBe(false)
		expect(post).not.toHaveBeenCalled()
	})

	it('says why a save failed', async () => {
		answer()
		post.mockRejectedValue({ response: { data: { message: 'Choose catalog before saving.' } } })
		const wrapper = await openDialog()

		await wrapper.vm.save()

		expect(wrapper.vm.errorMessage).toBe('Saving failed: Choose catalog before saving.')
		expect(wrapper.vm.synchronizationId).toBeNull()
	})
})

describe('the import preview steers away from a deprecated template', () => {
	it('names the replacement, and the reason when there is one', async () => {
		const { default: ImportPreviewDialog } = await import('@/dialogs/ImportPreviewDialog.vue')
		const text = ImportPreviewDialog.methods.deprecationText

		expect(text({ type: 'source', slug: 'sharepoint', replacedBy: 'sharepoint-publications', reason: 'Use the new set.' }))
			.toBe('The source "sharepoint" is deprecated. Use "sharepoint-publications" instead. Use the new set.')
		expect(text({ type: 'rule', slug: 'old', replacedBy: '', reason: '' })).toBe('The rule "old" is deprecated.')
	})
})
