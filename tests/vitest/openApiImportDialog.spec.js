// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Import an OpenAPI description" on the Sources page
 * (gateway-openapi-import-and-publish, task 3). These tests mount the real
 * dialog: the preview posts the address or the pasted document with the
 * prefix and writes nothing, a colliding operation is marked and cannot be
 * picked, import posts the picked operations and reports what was created
 * and which credential the source asks for, and a refused document shows the
 * server's reason.
 *
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import OpenApiImportDialog from '@/dialogs/OpenApiImportDialog.vue'
import { credentialSummary, importRequest, importSummary } from '@/dialogs/openApiImport.js'

const { post, changed } = vi.hoisted(() => ({ post: vi.fn(), changed: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { post } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@conduction/nextcloud-vue/src/utils/objectSignals.js', () => ({ dispatchObjectsChanged: changed }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props = []) =>
		defineComponent({
			name,
			props,
			emits: ['click', 'update:modelValue', 'update:open'],
			render() {
				const attrs = { class: name }
				if (name === 'NcButton') {
					attrs.onClick = () => this.$emit('click')
					attrs.disabled = this.$props.disabled
				}
				return h(name === 'NcButton' ? 'button' : 'div', attrs, [this.$slots.default?.()])
			},
		})
	const field = defineComponent({
		name: 'NcTextField',
		props: ['modelValue', 'label', 'disabled', 'helperText'],
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
		NcButton: stub('NcButton', ['disabled', 'type']),
		NcDialog: stub('NcDialog', ['open', 'name', 'size']),
		NcNoteCard: stub('NcNoteCard', ['type']),
		NcSelect: stub('NcSelect', ['modelValue', 'options', 'inputLabel', 'clearable']),
		NcTextField: field,
	}
})

const PREVIEW = {
	openapi: '3.1.0',
	title: 'Zaken',
	servers: [{ index: 0, url: 'https://vendor.example/v1', description: '' }],
	serverIndex: 0,
	source: { name: 'Zaken', location: 'https://vendor.example/v1', type: 'api' },
	total: 3,
	operations: [
		{ key: 'listZaken', method: 'GET', gatewayPath: 'vendor/zaken', summary: 'List', collides: true, collidesWith: 'Existing zaken' },
		{ key: 'getZaak', method: 'GET', gatewayPath: 'vendor/zaken/{{id}}', summary: '', collides: false, collidesWith: null },
		{ key: 'createZaak', method: 'POST', gatewayPath: 'vendor/zaken', summary: '', collides: false, collidesWith: null },
	],
	credentials: [{ scheme: 'apiKeyAuth', type: 'apiKey' }],
}

const RESULT = {
	source: { uuid: 's-1', name: 'Zaken', location: 'https://vendor.example/v1', credentials: [{ scheme: 'apiKeyAuth' }] },
	endpoints: [],
	created: 2,
	skipped: 0,
	failed: 0,
	unknown: 0,
}

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_, key) => vars[key] ?? `{${key}}`)
const n = (app, one, many, count, vars = {}) => t(app, count === 1 ? one : many, { count, ...vars })

async function opened() {
	const wrapper = mount(OpenApiImportDialog, { props: { open: false } })
	await wrapper.setProps({ open: true })
	return wrapper
}

async function previewed(wrapper) {
	const fields = wrapper.findAll('input.NcTextField')
	await fields[0].setValue('https://vendor.example/openapi.json')
	await fields[1].setValue('vendor')
	await wrapper.find('[data-testid="openapi-import-preview"]').trigger('click')
	await flushPromises()
}

describe('openApiImport helpers', () => {
	it('sends a pasted document instead of the address, with the trimmed prefix', () => {
		expect(importRequest({ url: 'https://x', text: '{"openapi":"3.1.0"}', prefix: ' vendor ' })).toEqual({ prefix: 'vendor', document: '{"openapi":"3.1.0"}' })
		expect(importRequest({ url: ' https://x ', text: '  ', prefix: 'v', serverIndex: 1 })).toEqual({ prefix: 'v', url: 'https://x', serverIndex: 1 })
		expect(importRequest({ url: 'https://x', prefix: 'v', serverIndex: null })).not.toHaveProperty('serverIndex')
	})

	it('reports what was created, skipped and failed', () => {
		expect(importSummary(RESULT, t, n)).toBe('Source Zaken created. 2 endpoints created.')
		expect(importSummary({ ...RESULT, created: 1, skipped: 1, failed: 1 }, t, n)).toBe('Source Zaken created. 1 endpoint created. 1 operation skipped because its route is already served. 1 endpoint could not be created.')
		expect(importSummary({ source: null, created: 0, skipped: 2 }, t, n)).toBe('Nothing was created: every chosen operation is already served or unknown.')
		expect(importSummary(null, t, n)).toBe('')
	})

	it('names the credential the source asks for, and nothing without one', () => {
		expect(credentialSummary(RESULT, t)).toBe('The source calls nothing until you add its credential (apiKeyAuth) on the source.')
		expect(credentialSummary({ source: { credentials: [] } }, t)).toBe('')
	})
})

describe('OpenApiImportDialog', () => {
	beforeEach(() => {
		post.mockReset()
		changed.mockReset()
	})

	it('previews without importing and marks a colliding operation as not pickable', async () => {
		post.mockResolvedValueOnce({ data: PREVIEW })
		const wrapper = await opened()
		await previewed(wrapper)

		expect(post).toHaveBeenCalledTimes(1)
		expect(post).toHaveBeenCalledWith('/apps/integriq/api/openapi/import/preview', { prefix: 'vendor', url: 'https://vendor.example/openapi.json' })
		const colliding = wrapper.find('[data-operation="listZaken"]')
		expect(colliding.find('input[type="checkbox"]').element.disabled).toBe(true)
		expect(colliding.text()).toContain('Already served by Existing zaken')
		expect(wrapper.find('[data-operation="getZaak"] input[type="checkbox"]').element.checked).toBe(true)
		expect(wrapper.find('[data-operation="listZaken"] input[type="checkbox"]').element.checked).toBe(false)
		expect(changed).not.toHaveBeenCalled()
	})

	it('imports the picked operations and says which credential the source needs', async () => {
		post.mockResolvedValueOnce({ data: PREVIEW }).mockResolvedValueOnce({ data: RESULT })
		const wrapper = await opened()
		await previewed(wrapper)
		await wrapper.find('[data-operation="createZaak"] input[type="checkbox"]').setValue(false)
		await wrapper.find('[data-testid="openapi-import-confirm"]').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenLastCalledWith('/apps/integriq/api/openapi/import', {
			prefix: 'vendor',
			url: 'https://vendor.example/openapi.json',
			serverIndex: 0,
			operations: ['getZaak'],
			name: 'Zaken',
		})
		const result = wrapper.find('[data-testid="openapi-import-result"]').text()
		expect(result).toContain('2 endpoints created.')
		expect(result).toContain('(apiKeyAuth)')
		expect(changed).toHaveBeenCalledWith({ register: 'integriq', schema: 'source' })
		expect(changed).toHaveBeenCalledWith({ register: 'integriq', schema: 'endpoint' })
	})

	it('shows the server\'s reason when the document is refused', async () => {
		post.mockRejectedValueOnce({ response: { data: { error: 'egress_refused', message: 'That address may not be fetched.' } } })
		const wrapper = await opened()
		await previewed(wrapper)

		expect(wrapper.find('[data-testid="openapi-import-error"]').text()).toBe('That address may not be fetched.')
		expect(wrapper.find('[data-testid="openapi-import-operations"]').exists()).toBe(false)
	})

	it('cannot preview without a document or without a prefix', async () => {
		const wrapper = await opened()
		expect(wrapper.find('[data-testid="openapi-import-preview"]').element.disabled).toBe(true)
		await wrapper.findAll('input.NcTextField')[0].setValue('https://vendor.example/openapi.json')
		expect(wrapper.find('[data-testid="openapi-import-preview"]').element.disabled).toBe(true)
	})
})
