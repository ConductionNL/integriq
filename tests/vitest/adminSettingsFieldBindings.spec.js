// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin forms as mounted: every field writes into the form and the
 * saved request carries what was typed, for the OpenTelemetry export, the
 * DSO signature configuration and the Open Formulieren connection.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn(), showWarning: vi.fn() }))
vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url) => `/ocs/v2.php/${url}`,
	generateUrl: (url) => url,
}))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const field = (name) => defineComponent({
		name,
		props: { modelValue: { default: null }, label: { type: String, default: '' }, inputLabel: { type: String, default: '' } },
		emits: ['update:modelValue', 'search'],
		setup(props, { slots }) {
			return () => h('div', { class: 'field-stub' }, slots.default?.())
		},
	})
	return {
		NcButton: defineComponent({
			name: 'NcButton',
			props: { disabled: { type: Boolean, default: false } },
			emits: ['click'],
			setup(props, { slots, emit }) {
				return () => h('button', { disabled: props.disabled, onClick: () => emit('click') }, slots.default?.())
			},
		}),
		NcNoteCard: field('NcNoteCard'),
		NcCheckboxRadioSwitch: field('NcCheckboxRadioSwitch'),
		NcTextField: field('NcTextField'),
		NcPasswordField: field('NcPasswordField'),
		NcSelect: field('NcSelect'),
		NcSelectUsers: field('NcSelectUsers'),
	}
})

const t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

/**
 * Mount a component and let its load settle.
 *
 * @param {object} component The component.
 * @return {Promise<object>} The wrapper.
 */
async function mountForm(component) {
	const wrapper = mount(component, { global: { mocks: { t } } })
	await flushPromises()
	return wrapper
}

/**
 * Emit a new value from the field with the given data-testid.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} name    The field component name.
 * @param {string} testId  The data-testid.
 * @param {*}      value   The value.
 * @return {void}
 */
function type(wrapper, name, testId, value) {
	const found = wrapper.findAllComponents({ name }).find((c) => c.attributes('data-testid') === testId)
	expect(found, testId).toBeDefined()
	found.vm.$emit('update:modelValue', value)
}

beforeEach(() => {
	http.get.mockReset()
	http.put.mockReset()
	http.put.mockResolvedValue({ data: {} })
})

describe('OtelSettings fields', () => {
	it('stores every typed field, with the sampling percentage as a ratio', async () => {
		http.get.mockResolvedValue({ data: {} })
		const { default: OtelSettings } = await import('@/views/admin/OtelSettings.vue')
		const wrapper = await mountForm(OtelSettings)

		const [enabled, allowLocal] = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		enabled.vm.$emit('update:modelValue', true)
		allowLocal.vm.$emit('update:modelValue', true)
		const [endpoint, serviceName, sampling, credentialName, headerName] = wrapper.findAllComponents({ name: 'NcTextField' })
		endpoint.vm.$emit('update:modelValue', 'https://otel.example/v1/traces')
		serviceName.vm.$emit('update:modelValue', 'integriq-acc')
		sampling.vm.$emit('update:modelValue', '25')
		credentialName.vm.$emit('update:modelValue', 'otel-key')
		headerName.vm.$emit('update:modelValue', 'X-Api-Key')
		await flushPromises()

		await wrapper.find('button').trigger('click')
		await flushPromises()
		expect(http.put).toHaveBeenCalledWith('/apps/integriq/api/admin/otel', {
			enabled: true,
			endpoint: 'https://otel.example/v1/traces',
			allowLocal: true,
			serviceName: 'integriq-acc',
			samplingRatio: 0.25,
			credentialName: 'otel-key',
			headerName: 'X-Api-Key',
		})
	})

	it('treats a non-numeric sampling percentage as zero', async () => {
		http.get.mockResolvedValue({ data: {} })
		const { default: OtelSettings } = await import('@/views/admin/OtelSettings.vue')
		const wrapper = await mountForm(OtelSettings)

		wrapper.findAllComponents({ name: 'NcTextField' })[2].vm.$emit('update:modelValue', 'veel')
		expect(wrapper.vm.form.samplingRatio).toBe(0)
	})
})

describe('DsoPkiSettings fields', () => {
	it('saves the account, mode and HMAC secret, then the PKIoverheid chain', async () => {
		http.get.mockResolvedValue({ data: { mode: 'hmac', handlerGroup: { id: 'dso', empty: false }, account: { state: 'none', displayName: '' } } })
		const { default: DsoPkiSettings } = await import('@/views/admin/DsoPkiSettings.vue')
		const wrapper = await mountForm(DsoPkiSettings)

		type(wrapper, 'NcSelectUsers', 'admin-dso-account', { id: 'intake', displayName: 'Intake' })
		const mode = wrapper.findAllComponents({ name: 'NcSelect' }).find((c) => c.attributes('data-testid') === 'admin-dso-pki-mode')
		expect(mode.vm.$attrs.reduce({ value: 'pkioverheid' })).toBe('pkioverheid')
		type(wrapper, 'NcPasswordField', 'admin-dso-pki-hmac-secret', 's3cret')
		await flushPromises()
		await wrapper.find('[data-testid="admin-dso-pki-save"]').trigger('click')
		await flushPromises()
		expect(http.put).toHaveBeenLastCalledWith('/apps/integriq/api/admin/dso-pki-config', {
			userId: 'intake',
			mode: 'hmac',
			hmacSecret: 's3cret',
			signingCertificate: '',
			intermediateChain: '',
			rootCa: '',
		})

		type(wrapper, 'NcSelect', 'admin-dso-pki-mode', 'pkioverheid')
		await flushPromises()
		expect(wrapper.vm.mode).toBe('pkioverheid')
		await wrapper.find('[data-testid="admin-dso-pki-signing-cert"]').setValue('CERT')
		await wrapper.find('[data-testid="admin-dso-pki-intermediate-chain"]').setValue('CHAIN')
		await wrapper.find('[data-testid="admin-dso-pki-root-ca"]').setValue('ROOT')
		expect(wrapper.vm.settingsBody()).toEqual({
			mode: 'pkioverheid',
			hmacSecret: '',
			signingCertificate: 'CERT',
			intermediateChain: 'CHAIN',
			rootCa: 'ROOT',
		})
	})
})

describe('OpenFormulierenConnectionSettings fields', () => {
	it('saves the scheme, secret, header and tolerance', async () => {
		http.get.mockResolvedValue({ data: { account: { state: 'none', displayName: '' } } })
		const { default: OpenFormulieren } = await import('@/views/admin/OpenFormulierenConnectionSettings.vue')
		const wrapper = await mountForm(OpenFormulieren)

		const scheme = wrapper.findAllComponents({ name: 'NcSelect' }).find((c) => c.attributes('data-testid') === 'admin-openformulieren-scheme')
		expect(scheme.vm.$attrs.reduce({ value: 'hmac' })).toBe('hmac')
		scheme.vm.$emit('update:modelValue', 'hmac')
		type(wrapper, 'NcPasswordField', 'admin-openformulieren-secret', 'geheim')
		type(wrapper, 'NcTextField', 'admin-openformulieren-header', 'X-Signature')
		type(wrapper, 'NcTextField', 'admin-openformulieren-tolerance', '60')
		type(wrapper, 'NcSelectUsers', 'admin-openformulieren-account', null)
		await flushPromises()

		await wrapper.find('[data-testid="admin-openformulieren-save"]').trigger('click')
		await flushPromises()
		expect(http.put).toHaveBeenLastCalledWith('/apps/integriq/api/admin/open-formulieren-connection', {
			userId: '',
			scheme: 'hmac',
			secret: 'geheim',
			header: 'X-Signature',
			toleranceSeconds: 60,
		})
	})
})
