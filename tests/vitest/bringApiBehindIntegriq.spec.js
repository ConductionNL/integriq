/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Bring behind integriq" on the API inventory (gateway-federated-api-discovery
 * REQ-FEDG-003): the row action opens the OpenAPI import with the description
 * the API's gateway exported, as text, and a prefix from the API's name.
 *
 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-a-discovered-api-can-be-brought-behind-integriq-req-fedg-003
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const { bringApiBehindIntegriqHandler } = await import('../../src/handlers/actionHandlers.js')
const { EVENT_OPEN_OPENAPI_IMPORT, modalBus } = await import('../../src/handlers/modalBus.js')
const { documentText, prefixFor } = await import('../../src/dialogs/openApiImport.js')

const DOCUMENT = { openapi: '3.0.1', info: { title: 'Orders', version: '2' }, paths: { '/orders': { get: {} } } }

describe('bringApiBehindIntegriqHandler', () => {
	it('opens the OpenAPI import with the stored description and a prefix from the name', () => {
		const spy = vi.fn()
		modalBus.on(EVENT_OPEN_OPENAPI_IMPORT, spy)
		bringApiBehindIntegriqHandler({ actionId: 'bring-behind-integriq', item: { name: 'Orders API (Azure)', openApiDocument: DOCUMENT } })
		modalBus.off(EVENT_OPEN_OPENAPI_IMPORT, spy)

		expect(spy).toHaveBeenCalledTimes(1)
		expect(spy).toHaveBeenCalledWith({ initialDocument: JSON.stringify(DOCUMENT, null, 2), initialPrefix: 'orders-api-azure' })
	})
})

describe('prefixFor', () => {
	it('slugs the name, falls back to the gateway id and then to a fixed prefix', () => {
		expect(prefixFor({ name: '  Zaken-API v1 ' })).toBe('zaken-api-v1')
		expect(prefixFor({ name: 'Bezoekersregistratie é' })).toBe('bezoekersregistratie-e')
		expect(prefixFor({ name: '', gatewayApiId: 'kong-svc-42' })).toBe('kong-svc-42')
		expect(prefixFor({ name: '***' })).toBe('imported-api')
		expect(prefixFor(null)).toBe('imported-api')
	})
})

describe('documentText', () => {
	it('keeps a string, indents an object and gives nothing for an empty one', () => {
		expect(documentText('openapi: 3.0.0')).toBe('openapi: 3.0.0')
		expect(documentText(DOCUMENT)).toBe(JSON.stringify(DOCUMENT, null, 2))
		expect(documentText({})).toBe('')
		expect(documentText(null)).toBe('')
		expect(documentText(undefined)).toBe('')
	})
})
