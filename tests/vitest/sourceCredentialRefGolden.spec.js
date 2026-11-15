/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the brokered-credential helpers of the Source form: form
 * data, configurations and credential lists of every shape, compared with
 * tests/vitest/golden/sourceCredentialRef.json.
 *
 * @spec openspec/specs/source-credential-custody/spec.md
 */
import { afterAll, describe, it } from 'vitest'
import { goldenFile } from './golden/goldenFile.js'
import {
	clearCredentialRef,
	isBrokered,
	mapCredentialOptions,
	readCredentialId,
	readCredentialRef,
	writeCredentialRef,
} from '@/modals/v2/sourceCredentialRef.js'

const golden = goldenFile(
	new URL('./golden/sourceCredentialRef.json', import.meta.url),
)
afterAll(() => golden.save())

/**
 * The result of a call, or the kind of error it threw.
 *
 * @param {Function} run The call.
 * @return {object} The outcome.
 */
function outcome(run) {
	try {
		return { result: run() }
	} catch (error) {
		return { threw: error.constructor.name }
	}
}

const ref = { credentialId: 'c-1' }
const FORMS = {
	undefined,
	null: null,
	string: 'source',
	empty: {},
	configNull: { configuration: null },
	configString: { configuration: 'x' },
	configArray: { configuration: [] },
	authMissing: { configuration: {} },
	authString: { configuration: { authentication: 'basic' } },
	authArray: { configuration: { authentication: [ref] } },
	refMissing: { configuration: { authentication: {} } },
	refString: { configuration: { authentication: { credentialRef: 'c-1' } } },
	refNull: { configuration: { authentication: { credentialRef: null } } },
	refArray: { configuration: { authentication: { credentialRef: [] } } },
	refId: { configuration: { authentication: { credentialRef: ref } } },
	refName: {
		configuration: {
			authentication: { credentialRef: { credentialName: 'n' } },
		},
	},
	refEmptyId: {
		configuration: { authentication: { credentialRef: { credentialId: '' } } },
	},
	refNumericId: {
		configuration: { authentication: { credentialRef: { credentialId: 5 } } },
	},
}

const CONFIGS = {
	undefined,
	null: null,
	string: 'x',
	array: [1],
	empty: {},
	other: { url: 'https://example.org', timeout: 3 },
	authString: { url: 'u', authentication: 'basic' },
	authNull: { url: 'u', authentication: null },
	authArray: { url: 'u', authentication: ['a'] },
	authOnlyRef: { url: 'u', authentication: { credentialRef: ref } },
	authRefAndSiblings: {
		url: 'u',
		authentication: { credentialRef: ref, username: 'u', password: 'p' },
	},
	authSiblingsOnly: { authentication: { token: 't' } },
	authEmpty: { authentication: {} },
}

const LISTS = {
	notArray: { results: [] },
	null: null,
	empty: [],
	rows: [
		null,
		'x',
		7,
		{},
		{ id: 'a', name: 'Alpha', provider: 'vault' },
		{ uuid: 'b', name: 'Beta' },
		{ '@self': { id: 'c', title: 'Gamma' }, provider: 'env' },
		{ '@self': { uuid: 'd' } },
		{ '@self': 'not-an-object', id: 'e' },
		{ id: 0, uuid: 'f', name: '' },
		{ id: 12, provider: 'p' },
		{ id: 'g', name: 3, provider: 4 },
		{ '@self': null, name: 'no id' },
		{ id: 'h', uuid: 'i', '@self': { id: 'j', uuid: 'k', title: 'T' } },
		{ uuid: 'l', '@self': { id: 'm', uuid: 'n' } },
		{ '@self': { id: 'o', uuid: 'p' } },
		{ id: 'q', name: 'Named', '@self': { title: 'Titled' } },
		[1, 2],
	],
}

describe('sourceCredentialRef golden', () => {
	it('reads the credential reference', () => {
		for (const [name, form] of Object.entries(FORMS)) {
			golden.check(`read/${name}`, {
				ref: outcome(() => readCredentialRef(form)),
				brokered: outcome(() => isBrokered(form)),
				id: outcome(() => readCredentialId(form)),
			})
		}
	})

	it('writes and clears the credential reference', () => {
		for (const [name, configuration] of Object.entries(CONFIGS)) {
			const before = JSON.stringify(configuration)
			golden.check(
				`write/${name}`,
				outcome(() => writeCredentialRef(configuration, 'c-9')),
			)
			golden.check(
				`clear/${name}`,
				outcome(() => clearCredentialRef(configuration)),
			)
			golden.check(
				`unchanged/${name}`,
				JSON.stringify(configuration) === before,
			)
		}
	})

	it('maps credential options', () => {
		for (const [name, list] of Object.entries(LISTS)) {
			golden.check(
				`options/${name}`,
				outcome(() => mapCredentialOptions(list)),
			)
		}
	})
})
