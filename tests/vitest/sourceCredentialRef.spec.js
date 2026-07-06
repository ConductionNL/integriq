/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenConnector Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the brokered-credential (credentialRef) helpers that drive the
 * Source editor picker (src/modals/v2/sourceCredentialRef.js). These cover the
 * load-bearing behaviour of SourceFormFields.vue without a DOM mount (the repo's
 * vitest harness is node-env and mounts no .vue — see vitest.config.js):
 *
 *   • detection: readCredentialRef / isBrokered / readCredentialId;
 *   • WRITE: picking a credential sets configuration.authentication.credentialRef
 *     = { credentialId } and drops every sibling secret (mutual-exclusivity);
 *   • CLEAR: turning brokered off removes the ref (and empties authentication);
 *   • the embedded-secret field set the editor hides while brokered;
 *   • the OR list-envelope unwrap + NcSelect option mapping (soft-fail-safe).
 */

import { describe, it, expect } from 'vitest'
import {
	EMBEDDED_SECRET_FIELDS,
	CALLING_APP_ID,
	readCredentialRef,
	isBrokered,
	readCredentialId,
	writeCredentialRef,
	clearCredentialRef,
	extractCredentialResults,
	mapCredentialOptions,
} from '../../src/modals/v2/sourceCredentialRef.js'

const UUID = '00000000-0000-0000-0000-000000000000'

describe('detection', () => {
	it('reads a credentialRef at configuration.authentication.credentialRef', () => {
		const formData = { configuration: { authentication: { credentialRef: { credentialId: UUID } } } }
		expect(readCredentialRef(formData)).toEqual({ credentialId: UUID })
		expect(isBrokered(formData)).toBe(true)
		expect(readCredentialId(formData)).toBe(UUID)
	})

	it('is not brokered for embedded secrets or empty models', () => {
		expect(isBrokered({ configuration: { authentication: { apikey: 'x' } } })).toBe(false)
		expect(isBrokered({ apikey: 'x' })).toBe(false)
		expect(isBrokered({})).toBe(false)
		expect(isBrokered(null)).toBe(false)
		expect(readCredentialId({})).toBe(null)
	})

	it('reads null credentialId when only a credentialName is present', () => {
		const formData = { configuration: { authentication: { credentialRef: { credentialName: 'doffin' } } } }
		expect(isBrokered(formData)).toBe(true)
		expect(readCredentialId(formData)).toBe(null)
	})
})

describe('writeCredentialRef — picking a credential', () => {
	it('writes { credentialId } and preserves other configuration keys', () => {
		const config = { rateLimit: 10, authentication: { legacy: true } }
		const next = writeCredentialRef(config, UUID)
		expect(next.authentication).toEqual({ credentialRef: { credentialId: UUID } })
		expect(next.rateLimit).toBe(10)
	})

	it('drops any sibling secret under authentication (mutual exclusivity)', () => {
		const config = { authentication: { apikey: 'sekret', credentialRef: { credentialId: 'old' } } }
		const next = writeCredentialRef(config, UUID)
		// authentication is collapsed to credentialRef only — no sibling can reach the backend.
		expect(Object.keys(next.authentication)).toEqual(['credentialRef'])
		expect(next.authentication.credentialRef).toEqual({ credentialId: UUID })
	})

	it('does not mutate the input configuration', () => {
		const config = { authentication: { apikey: 'sekret' } }
		writeCredentialRef(config, UUID)
		expect(config.authentication.apikey).toBe('sekret')
	})

	it('handles an undefined configuration', () => {
		expect(writeCredentialRef(undefined, UUID)).toEqual({ authentication: { credentialRef: { credentialId: UUID } } })
	})
})

describe('clearCredentialRef — turning brokered off', () => {
	it('removes the credentialRef and empties authentication', () => {
		const config = { authentication: { credentialRef: { credentialId: UUID } }, rateLimit: 5 }
		const next = clearCredentialRef(config)
		expect(next.authentication).toBeUndefined()
		expect(next.rateLimit).toBe(5)
	})

	it('preserves other siblings under authentication', () => {
		const config = { authentication: { credentialRef: { credentialId: UUID }, note: 'keep' } }
		const next = clearCredentialRef(config)
		expect(next.authentication).toEqual({ note: 'keep' })
	})

	it('is a no-op-safe on configs without a ref', () => {
		expect(clearCredentialRef({ a: 1 })).toEqual({ a: 1 })
		expect(clearCredentialRef(undefined)).toEqual({})
	})
})

describe('embedded-secret fields hidden while brokered', () => {
	it('lists the top-level source auth secrets', () => {
		for (const key of ['auth', 'authenticationConfig', 'apikey', 'secret', 'username', 'password', 'jwt', 'jwtId', 'authorizationHeader']) {
			expect(EMBEDDED_SECRET_FIELDS).toContain(key)
		}
	})

	it('filters exactly the secret fields out of a field list (editor visibleFields logic)', () => {
		const fields = [
			{ key: 'name' }, { key: 'type' }, { key: 'apikey' }, { key: 'secret' }, { key: 'configuration' },
		]
		const visible = fields.filter((f) => !EMBEDDED_SECRET_FIELDS.includes(f.key)).map((f) => f.key)
		expect(visible).toEqual(['name', 'type', 'configuration'])
	})

	it('exposes the calling app id the credential must allow', () => {
		expect(CALLING_APP_ID).toBe('openconnector')
	})
})

describe('OR credentials endpoint mapping', () => {
	it('unwraps the { results } envelope and a bare array; tolerates garbage', () => {
		expect(extractCredentialResults({ results: [{ id: 'a' }] })).toEqual([{ id: 'a' }])
		expect(extractCredentialResults([{ id: 'b' }])).toEqual([{ id: 'b' }])
		expect(extractCredentialResults(null)).toEqual([])
		expect(extractCredentialResults({})).toEqual([])
	})

	it('maps to { id, label, name, provider } with a self-describing label', () => {
		const options = mapCredentialOptions([
			{ id: UUID, name: 'Doffin subscription', provider: 'doffin' },
			{ uuid: 'u2', name: 'GitHub publisher' },
			{ '@self': { id: 'u3' }, name: 'GitLab' },
		])
		expect(options[0]).toEqual({ id: UUID, label: 'Doffin subscription (doffin)', name: 'Doffin subscription', provider: 'doffin' })
		expect(options[1].id).toBe('u2')
		expect(options[1].label).toBe('GitHub publisher')
		expect(options[2].id).toBe('u3')
	})

	it('drops rows with no resolvable id', () => {
		const options = mapCredentialOptions([{ name: 'orphan' }, null, { id: 'x', name: 'ok' }])
		expect(options).toHaveLength(1)
		expect(options[0].id).toBe('x')
	})
})
