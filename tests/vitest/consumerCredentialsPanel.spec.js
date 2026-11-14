// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A consumer's credentials on its page (access-consumer-credentials, tasks
 * 2 to 4): the list, a key shown once, a pinned certificate, a revoke.
 *
 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-new-key-is-shown-exactly-once-req-cred-002
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ConsumerCredentialsPanel from '@/components/ConsumerCredentialsPanel.vue'
import AddConsumerCredentialDialog from '@/dialogs/AddConsumerCredentialDialog.vue'
import RevokeConsumerCredentialDialog from '@/dialogs/RevokeConsumerCredentialDialog.vue'
import { createBody, credentialsPath, identifies, isExpired, sortedCredentials } from '@/views/Consumer/consumerCredentials.js'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), delete: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const box = (name) => defineComponent({
		name,
		props: ['name', 'open', 'type', 'size'],
		render() {
			return this.open === false ? null : h('div', { class: name }, [this.$slots.default?.(), this.$slots.actions?.()])
		},
	})
	const field = (name, tag) => defineComponent({
		name,
		props: ['modelValue', 'label', 'type'],
		emits: ['update:modelValue'],
		render() {
			return h(tag, { class: name, value: this.modelValue, onInput: (e) => this.$emit('update:modelValue', e.target.value) })
		},
	})
	return {
		NcButton: defineComponent({
			name: 'NcButton',
			props: ['variant', 'disabled'],
			emits: ['click'],
			render() {
				return h('button', { class: 'NcButton', disabled: this.disabled || undefined, onClick: () => this.$emit('click') }, this.$slots.default?.())
			},
		}),
		NcTextField: field('NcTextField', 'input'),
		NcTextArea: field('NcTextArea', 'textarea'),
		NcDialog: box('NcDialog'),
		NcNoteCard: box('NcNoteCard'),
		NcLoadingIcon: box('NcLoadingIcon'),
	}
})

const LIST = '/index.php/apps/integriq/api/consumers/c1/credentials'
const OLD = { id: 'k1', label: 'Old key', type: 'apiKey', keyPrefix: 'iq_abc', createdAt: '2026-01-01T10:00:00+00:00', lastUsedAt: '2026-11-01T10:00:00+00:00' }
const NEW = { id: 'k2', label: 'New key', type: 'apiKey', keyPrefix: 'iq_def', createdAt: '2026-11-10T10:00:00+00:00', lastUsedAt: null, expiresAt: '2030-01-01T00:00:00+00:00' }
const CERT = { id: 'c9', label: 'Partner cert', type: 'clientCertificate', subject: 'CN=Partner, serialNumber=00000001234567890000', createdAt: '2026-06-01T10:00:00+00:00' }

/**
 * Mount the panel on consumer c1 after its credentials load.
 *
 * @return {Promise<object>} The wrapper
 */
async function panel() {
	const wrapper = mount(ConsumerCredentialsPanel, { global: { provide: { cnSectionContext: { value: { objectId: 'c1', object: {} } } } } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
	http.delete.mockReset()
	http.get.mockResolvedValue({ data: { results: [OLD, CERT, NEW] } })
})

describe('ConsumerCredentialsPanel', () => {
	it('lists the consumer\'s credentials newest first, by prefix or subject, with when each was last used', async () => {
		const wrapper = await panel()

		expect(http.get).toHaveBeenCalledWith(LIST)
		const rows = wrapper.findAll('tbody tr')
		expect(rows.map((row) => row.findAll('td')[0].text())).toEqual(['New key', 'Partner cert', 'Old key'])
		expect(rows[0].text()).toContain('iq_def…')
		expect(rows[0].text()).toContain('Not used yet')
		expect(rows[1].text()).toContain('Client certificate')
		expect(rows[1].text()).toContain('CN=Partner')
		expect(rows[2].text()).toContain('iq_abc…')
		expect(rows[2].text()).not.toContain('Not used yet')
	})

	it('says when a consumer has no credentials, and shows a failed load', async () => {
		http.get.mockResolvedValueOnce({ data: { results: [] } })
		expect((await panel()).find('[data-testid="consumer-credentials-empty"]').exists()).toBe(true)

		http.get.mockRejectedValueOnce({ response: { data: { error: 'Only an administrator can manage consumer credentials.' } } })
		expect((await panel()).find('[data-testid="consumer-credentials-error"]').text()).toBe('Only an administrator can manage consumer credentials.')
	})

	it('adds a generated key at the top and drops a revoked one', async () => {
		const wrapper = await panel()

		await wrapper.find('[data-testid="consumer-credentials-generate"]').trigger('click')
		expect(wrapper.findComponent(AddConsumerCredentialDialog).props()).toMatchObject({ open: true, type: 'apiKey', consumerId: 'c1' })
		wrapper.findComponent(AddConsumerCredentialDialog).vm.$emit('created', { id: 'k3', label: 'Newest', type: 'apiKey', keyPrefix: 'iq_ghi', createdAt: '2026-11-14T10:00:00+00:00' })
		await flushPromises()
		expect(wrapper.findAll('tbody tr')[0].text()).toContain('Newest')

		await wrapper.find('[data-testid="consumer-credential-revoke-k1"]').trigger('click')
		expect(wrapper.findComponent(RevokeConsumerCredentialDialog).props('credential')).toEqual(OLD)
		wrapper.findComponent(RevokeConsumerCredentialDialog).vm.$emit('revoked', 'k1')
		await flushPromises()
		expect(wrapper.find('[data-testid="consumer-credential-k1"]').exists()).toBe(false)
	})
})

describe('AddConsumerCredentialDialog', () => {
	it('shows a generated key once, copies it, and forgets it on close', async () => {
		http.post.mockResolvedValue({ data: { key: 'iq_def_secretvalue', credential: NEW } })
		const writeText = vi.fn().mockResolvedValue(undefined)
		Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
		const wrapper = mount(AddConsumerCredentialDialog, { props: { open: true, consumerId: 'c1' } })

		expect(wrapper.find('[data-testid="add-credential-submit"]').attributes('disabled')).toBeDefined()
		await wrapper.find('.NcTextField').setValue('  New key ')
		await wrapper.find('[data-testid="add-credential-submit"]').trigger('click')
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith(LIST, { label: 'New key' })
		expect(wrapper.emitted('created')[0][0]).toEqual(NEW)
		expect(wrapper.find('[data-testid="add-credential-key"]').text()).toBe('iq_def_secretvalue')

		await wrapper.find('[data-testid="add-credential-copy"]').trigger('click')
		await flushPromises()
		expect(writeText).toHaveBeenCalledWith('iq_def_secretvalue')
		expect(wrapper.find('[data-testid="add-credential-copied"]').exists()).toBe(true)

		await wrapper.find('[data-testid="add-credential-done"]').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(wrapper.find('[data-testid="add-credential-key"]').exists()).toBe(false)
		expect(wrapper.vm.key).toBe('')
	})

	it('pins a certificate and closes, with no key to show', async () => {
		http.post.mockResolvedValue({ data: { credential: CERT } })
		const wrapper = mount(AddConsumerCredentialDialog, { props: { open: true, consumerId: 'c1', type: 'clientCertificate' } })

		await wrapper.find('[data-testid="add-credential-label"]').setValue('Partner cert')
		expect(wrapper.find('[data-testid="add-credential-submit"]').attributes('disabled')).toBeDefined()
		await wrapper.find('[data-testid="add-credential-certificate"]').setValue('-----BEGIN CERTIFICATE-----\nMII\n-----END CERTIFICATE-----')
		await wrapper.find('[data-testid="add-credential-submit"]').trigger('click')
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith(LIST, { label: 'Partner cert', type: 'clientCertificate', certificate: '-----BEGIN CERTIFICATE-----\nMII\n-----END CERTIFICATE-----' })
		expect(wrapper.emitted('created')[0][0]).toEqual(CERT)
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('shows the server\'s refusal and keeps the form', async () => {
		http.post.mockRejectedValue({ response: { data: { error: 'Give a label of at most 64 characters.' } } })
		const wrapper = mount(AddConsumerCredentialDialog, { props: { open: true, consumerId: 'c1' } })

		await wrapper.find('.NcTextField').setValue('x')
		await wrapper.find('[data-testid="add-credential-submit"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="add-credential-error"]').text()).toBe('Give a label of at most 64 characters.')
		expect(wrapper.emitted('created')).toBeUndefined()
	})
})

describe('RevokeConsumerCredentialDialog', () => {
	it('deletes the credential and passes on its id', async () => {
		http.delete.mockResolvedValue({ data: { revoked: 'k1' } })
		const wrapper = mount(RevokeConsumerCredentialDialog, { props: { open: true, consumerId: 'c1', credential: OLD } })

		await wrapper.find('[data-testid="revoke-credential-submit"]').trigger('click')
		await flushPromises()

		expect(http.delete).toHaveBeenCalledWith(`${LIST}/k1`)
		expect(wrapper.emitted('revoked')[0][0]).toBe('k1')
	})

	it('shows a refusal and does not report a revoke', async () => {
		http.delete.mockRejectedValue({})
		const wrapper = mount(RevokeConsumerCredentialDialog, { props: { open: true, consumerId: 'c1', credential: OLD } })

		await wrapper.find('[data-testid="revoke-credential-submit"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="revoke-credential-error"]').text()).toBe('The credential could not be revoked.')
		expect(wrapper.emitted('revoked')).toBeUndefined()
	})
})

describe('consumerCredentials helpers', () => {
	it('builds the credentials paths', () => {
		expect(credentialsPath('c 1')).toBe('/apps/integriq/api/consumers/c%201/credentials')
		expect(credentialsPath('c1', 'k/1')).toBe('/apps/integriq/api/consumers/c1/credentials/k%2F1')
	})

	it('identifies a key by its prefix, a certificate by subject, fingerprint or issuer and pattern, never by a hash', () => {
		expect(identifies({ type: 'apiKey', keyPrefix: 'iq_a', keyHash: 'secret' })).toBe('iq_a…')
		expect(identifies({ type: 'apiKey', keyHash: 'secret' })).toBe('')
		expect(identifies({ type: 'clientCertificate', fingerprint: 'AB:CD' })).toBe('AB:CD')
		expect(identifies({ type: 'clientCertificate', issuer: 'CN=CA', subjectPattern: 'CN=*.example.org' })).toBe('CN=CA · CN=*.example.org')
	})

	it('sorts newest first and leaves out what is not a credential', () => {
		expect(sortedCredentials([OLD, null, { label: 'no id' }, NEW]).map((c) => c.id)).toEqual(['k2', 'k1'])
		expect(sortedCredentials(undefined)).toEqual([])
	})

	it('reads an expiry against a moment', () => {
		const now = new Date('2026-11-14T00:00:00Z')
		expect(isExpired({ expiresAt: '2026-11-13T00:00:00Z' }, now)).toBe(true)
		expect(isExpired({ expiresAt: '2027-01-01T00:00:00Z' }, now)).toBe(false)
		expect(isExpired({}, now)).toBe(false)
	})

	it('sends an issuer and pattern only when no certificate is given, and an expiry as ISO', () => {
		expect(createBody({ type: 'clientCertificate', label: 'x', certificate: ' ', issuerCertificate: ' CA ', subjectPattern: ' CN=* ' }))
			.toEqual({ label: 'x', type: 'clientCertificate', issuerCertificate: 'CA', subjectPattern: 'CN=*' })
		expect(createBody({ type: 'apiKey', label: 'k', expiresAt: '2027-01-01T00:00:00Z' })).toEqual({ label: 'k', expiresAt: '2027-01-01T00:00:00.000Z' })
	})
})
