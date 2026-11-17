// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Authenticatie section on integriq's admin settings page (change
 * portal-idp-broker-config, REQ-IDPC-008). The helper tests pin which fields
 * and credential roles each kind stores; the section tests mount the real
 * section, modal and dialog and assert what they send and show: the table
 * with readiness, the empty state, the Keepiq and flag notes, a SAML
 * registration POSTed with references only, Test reporting each check, and a
 * delete confirmed by a dialog that names the organisation.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import IdpRegistrationModal from '@/modals/IdpRegistrationModal.vue'
import IdpRegistrationSettings from '@/views/admin/IdpRegistrationSettings.vue'
import {
	checkLabel,
	credentialOptions,
	draftFromObject,
	draftProblems,
	emptyDraft,
	offeredRoles,
	organisationOptions,
	payloadFromDraft,
	requiredRoles,
	statusText,
} from '@/views/admin/idpRegistration.js'

const { get, post, put, del } = vi.hoisted(() => ({
	get: vi.fn(),
	post: vi.fn(),
	put: vi.fn(),
	del: vi.fn(),
}))
vi.mock('@nextcloud/axios', () => ({ default: { get, post, put, delete: del } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props = []) =>
		defineComponent({
			name,
			props,
			emits: ['click', 'update:modelValue', 'update:open', 'close'],
			render() {
				const slots = Object.values(this.$slots).flatMap((slot) => slot?.() || [])
				return h('div', { class: name, onClick: () => this.$emit('click') }, slots)
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
				onInput: (event) => this.$emit('update:modelValue', event.target.value),
			})
		},
	})
	return {
		NcButton: stub('NcButton', ['variant', 'disabled', 'href', 'target', 'type']),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch', ['modelValue', 'type']),
		NcDialog: stub('NcDialog', ['open', 'name', 'size']),
		NcEmptyContent: stub('NcEmptyContent', ['name', 'description']),
		NcModal: stub('NcModal', ['labelId', 'size']),
		NcNoteCard: stub('NcNoteCard', ['type']),
		NcSelect: stub('NcSelect', ['modelValue', 'options', 'inputLabel', 'clearable', 'loading']),
		NcTextField: field,
	}
})

const OVERVIEW_URL = '/apps/integriq/api/idp/registrations'
const OBJECTS_URL = '/apps/openregister/api/objects/integriq/idpRegistration'
const ORG = '4a7c1f0e-0000-4000-8000-000000000001'
const ROW = {
	id: 'reg-1',
	label: '',
	organisation: ORG,
	provider: 'digid',
	kind: 'saml-sp',
	enabled: true,
	status: 'secret-missing',
	reasons: ['salt'],
	metadataUrl: 'https://cloud.example/apps/integriq/api/idp/metadata/x/digid',
}

function answer({ registrations = [ROW], brokerEnabled = true, keepiqAvailable = true } = {}) {
	get.mockImplementation((url) => {
		if (url === OVERVIEW_URL) {
			return Promise.resolve({ data: { brokerEnabled, keepiqAvailable, registrations } })
		}
		if (url === '/apps/openregister/api/organisations') {
			return Promise.resolve({ data: { results: [{ uuid: ORG, name: 'Gemeente X' }] } })
		}
		if (url === '/apps/openregister/api/credentials') {
			return Promise.resolve({
				data: {
					results: [
						{ id: 7, name: 'sp-signing', provider: 'keepiq', allowedApps: ['integriq'] },
						{ id: 8, name: 'hmac-salt', provider: 'keepiq', allowedApps: [] },
						{ id: 9, name: 'other-app', provider: 'keepiq', allowedApps: ['pipelinq'] },
					],
				},
			})
		}
		return Promise.reject(new Error('unexpected ' + url))
	})
}

describe('the registration helpers', () => {
	it('requires signing and salt for SAML with HMAC, and offers decryption', () => {
		const draft = emptyDraft()
		expect(requiredRoles(draft)).toEqual(['signing', 'salt'])
		expect(offeredRoles(draft)).toEqual(['signing', 'decryption', 'salt'])
	})

	it('requires the client secret for client_secret_basic and the pseudonym keys for polymorphic', () => {
		const draft = { ...emptyDraft(), kind: 'oidc-rp', clientAuth: 'client_secret_basic', pseudonymisation: 'polymorphic' }
		expect(requiredRoles(draft)).toEqual(['clientSecret', 'pseudonymKeys'])
		expect(offeredRoles(draft)).toEqual(['clientSecret', 'pseudonymKeys'])
	})

	it('names every missing piece of a draft', () => {
		const problems = draftProblems({ ...emptyDraft(), idpMetadataUrl: 'http://idp.example/metadata' })
		expect(problems).toHaveLength(4)
		expect(problems[0]).toContain('organisation')
		expect(problems[1]).toContain('https://')
		expect(problems[2]).toContain('signing credential')
		expect(problems[3]).toContain('salt credential')
	})

	it('stores the fields of the chosen kind only, and references only', () => {
		const draft = {
			...emptyDraft(),
			organisation: ORG,
			kind: 'oidc-rp',
			idpMetadataUrl: 'https://left-over.example/metadata',
			issuer: 'https://broker.example',
			clientId: 'client-1',
			credentials: { signing: '7', decryption: '3', clientSecret: '5', pseudonymKeys: '', salt: '8' },
		}
		draft.subjectMapping.bsn = 'urn:bsn'
		draft.assuranceMapping = [{ sent: 'urn:level:3', level: 'substantieel' }, { sent: '', level: 'hoog' }]
		expect(payloadFromDraft(draft)).toEqual({
			organisation: ORG,
			provider: 'digid',
			kind: 'oidc-rp',
			enabled: false,
			pseudonymisation: 'hmac',
			issuer: 'https://broker.example',
			clientId: 'client-1',
			clientAuth: 'private_key_jwt',
			subjectMapping: { bsn: 'urn:bsn' },
			assuranceMapping: { 'urn:level:3': 'substantieel' },
			credentials: { signing: '7', salt: '8' },
		})
	})

	it('reads a stored object back into a draft', () => {
		const draft = draftFromObject({
			'@self': { id: 'reg-2' },
			organisation: ORG,
			provider: 'eherkenning',
			kind: 'saml-sp',
			enabled: true,
			idpMetadataUrl: 'https://idp.example/metadata',
			assuranceMapping: { EH3: 'eh3' },
			credentials: { signing: '7', salt: '8' },
		})
		expect(draft.id).toBe('reg-2')
		expect(draft.provider).toBe('eherkenning')
		expect(draft.enabled).toBe(true)
		expect(draft.assuranceMapping).toEqual([{ sent: 'EH3', level: 'eh3' }])
		expect(draft.credentials).toEqual({ signing: '7', decryption: '', clientSecret: '', pseudonymKeys: '', salt: '8' })
	})

	it('offers only credentials that allow integriq', () => {
		expect(
			credentialOptions({
				results: [
					{ id: 7, name: 'a', provider: 'keepiq', allowedApps: ['integriq'] },
					{ id: 8, name: 'b' },
					{ id: 9, name: 'c', allowedApps: ['pipelinq'] },
					{ name: 'no id' },
				],
			}),
		).toEqual([
			{ id: '7', label: 'a (keepiq)' },
			{ id: '8', label: 'b' },
		])
		expect(organisationOptions({ results: [{ uuid: ORG, name: 'Gemeente X' }, {}] })).toEqual([
			{ id: ORG, label: 'Gemeente X' },
		])
	})

	it('words the status and the checks', () => {
		expect(statusText('secret-missing', ['salt'])).toBe('Credential missing: salt')
		expect(statusText('ready', [])).toBe('Ready')
		expect(checkLabel('credential:salt')).toBe('Salt credential')
		expect(checkLabel('metadata')).toBe('Identity provider metadata')
	})
})

describe('the Authenticatie section', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
		put.mockReset()
		del.mockReset()
		answer()
	})

	it('lists the registrations with organisation, status and metadata URL', async () => {
		const wrapper = mount(IdpRegistrationSettings)
		await flushPromises()

		const row = wrapper.find('[data-testid="idp-registration-row-reg-1"]').text()
		expect(row).toContain('Gemeente X')
		expect(row).toContain('DigiD')
		expect(row).toContain('SAML Service Provider')
		expect(row).toContain('Credential missing: salt')
		expect(row).toContain(ROW.metadataUrl)
		expect(wrapper.find('[data-testid="idp-registrations-keepiq-missing"]').exists()).toBe(false)
	})

	it('shows the empty state and the Keepiq and flag notes', async () => {
		answer({ registrations: [], brokerEnabled: false, keepiqAvailable: false })
		const wrapper = mount(IdpRegistrationSettings)
		await flushPromises()

		expect(wrapper.find('[data-testid="idp-registrations-empty"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="idp-registrations-keepiq-missing"]').text()).toContain('Keepiq')
		expect(wrapper.find('[data-testid="idp-registrations-flag-off"]').exists()).toBe(true)
	})

	it('adds a SAML registration with credential references only', async () => {
		answer({ registrations: [] })
		post.mockResolvedValue({ data: {} })
		const wrapper = mount(IdpRegistrationSettings)
		await flushPromises()

		await wrapper.find('[data-testid="idp-registrations-add-empty"]').trigger('click')
		const modal = wrapper.findComponent(IdpRegistrationModal)
		expect(modal.props('credentials').map((option) => option.id)).toEqual(['7', '8'])
		modal.vm.draft.organisation = ORG
		modal.vm.draft.idpMetadataUrl = 'https://idp.example/metadata'
		modal.vm.draft.credentials.signing = '7'
		modal.vm.draft.credentials.salt = '8'
		await modal.find('form').trigger('submit')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(OBJECTS_URL, {
			organisation: ORG,
			provider: 'digid',
			kind: 'saml-sp',
			enabled: false,
			pseudonymisation: 'hmac',
			idpMetadataUrl: 'https://idp.example/metadata',
			subjectMapping: {},
			assuranceMapping: {},
			credentials: { signing: '7', salt: '8' },
		})
		expect(wrapper.findComponent(IdpRegistrationModal).props('open')).toBe(false)
	})

	it('does not save a draft with problems and shows them', async () => {
		answer({ registrations: [] })
		const wrapper = mount(IdpRegistrationSettings)
		await flushPromises()

		await wrapper.find('[data-testid="idp-registrations-add-empty"]').trigger('click')
		await wrapper.findComponent(IdpRegistrationModal).find('form').trigger('submit')
		await flushPromises()

		expect(post).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="idp-registration-problems"]').text()).toContain('organisation')
	})

	it('shows only the fields of the chosen kind', async () => {
		answer({ registrations: [] })
		const wrapper = mount(IdpRegistrationSettings)
		await flushPromises()
		await wrapper.find('[data-testid="idp-registrations-add-empty"]').trigger('click')
		const modal = wrapper.findComponent(IdpRegistrationModal)

		expect(modal.find('[data-testid="idp-registration-metadata-url"]').exists()).toBe(true)
		expect(modal.find('[data-testid="idp-registration-issuer"]').exists()).toBe(false)
		modal.vm.draft.kind = 'oidc-rp'
		await flushPromises()
		expect(modal.find('[data-testid="idp-registration-metadata-url"]').exists()).toBe(false)
		expect(modal.find('[data-testid="idp-registration-issuer"]').exists()).toBe(true)
		expect(modal.find('[data-testid="idp-registration-credential-decryption"]').exists()).toBe(false)
	})

	it('reports each Test check as passed or failed', async () => {
		post.mockResolvedValue({
			data: {
				id: 'reg-1',
				checks: [
					{ check: 'metadata', passed: true, reason: '' },
					{ check: 'credential:salt', passed: false, reason: 'The salt credential cannot be read (unknown).' },
				],
			},
		})
		const wrapper = mount(IdpRegistrationSettings)
		await flushPromises()

		await wrapper.find('[data-testid="idp-registration-test"]').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(`${OVERVIEW_URL}/reg-1/test`)
		const checks = wrapper.find('[data-testid="idp-registration-checks"]').text()
		expect(checks).toContain('Identity provider metadata: passed')
		expect(checks).toContain('Salt credential: failed')
	})

	it('disables a registration keeping the rest as stored', async () => {
		put.mockResolvedValue({ data: {} })
		get.mockImplementationOnce(() => Promise.resolve({ data: { brokerEnabled: true, keepiqAvailable: true, registrations: [ROW] } }))
		const wrapper = mount(IdpRegistrationSettings)
		await flushPromises()
		answer()
		const inner = get.getMockImplementation()
		get.mockImplementation((url) =>
			url === `${OBJECTS_URL}/reg-1`
				? Promise.resolve({ data: { id: 'reg-1', organisation: ORG, provider: 'digid', kind: 'saml-sp', enabled: true, idpMetadataUrl: 'https://idp.example/metadata', credentials: { signing: '7', salt: '8' } } })
				: inner(url),
		)

		await wrapper.find('[data-testid="idp-registration-toggle"]').trigger('click')
		await flushPromises()

		expect(put).toHaveBeenCalledWith(`${OBJECTS_URL}/reg-1`, expect.objectContaining({
			enabled: false,
			idpMetadataUrl: 'https://idp.example/metadata',
			credentials: { signing: '7', salt: '8' },
		}))
	})

	it('deletes only after a dialog that names the organisation', async () => {
		del.mockResolvedValue({ data: {} })
		const wrapper = mount(IdpRegistrationSettings)
		await flushPromises()

		await wrapper.find('[data-testid="idp-registration-delete"]').trigger('click')
		expect(del).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="idp-registration-delete-text"]').text()).toContain('Gemeente X')

		await wrapper.find('[data-testid="idp-registration-delete-confirm"]').trigger('click')
		await flushPromises()
		expect(del).toHaveBeenCalledWith(`${OBJECTS_URL}/reg-1`)
	})
})
