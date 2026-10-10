/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What a file server connection test means for the administrator
 * (sources-sftp-adapter, REQ-SFTP-001), and which sources get that test.
 */
import { describe, expect, it } from 'vitest'
import { fileServerTestState, isFileServerSource } from '../../src/modals/v2/fileServerTestState.js'

const PIN = 'SHA256:AbcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ'

describe('fileServerTestState', () => {
	it('asks to confirm the fingerprint of a new source', () => {
		expect(fileServerTestState({ fingerprint: PIN, pinned: '', matches: false, connected: false })).toBe('confirm')
	})

	it('reports a changed host key', () => {
		expect(fileServerTestState({ fingerprint: 'SHA256:other', pinned: PIN, matches: false, connected: false })).toBe('mismatch')
	})

	it('reports a connection that worked', () => {
		expect(fileServerTestState({ fingerprint: PIN, pinned: PIN, matches: true, connected: true })).toBe('connected')
	})

	it('reports a matching key whose login failed', () => {
		expect(fileServerTestState({ fingerprint: PIN, pinned: PIN, matches: true, connected: false, message: 'refused' })).toBe('failed')
	})

	it('has nothing to say before a test', () => {
		expect(fileServerTestState(null)).toBe('none')
	})
})

describe('isFileServerSource', () => {
	it('is true for sftp and ftps only', () => {
		expect(isFileServerSource({ type: 'sftp' })).toBe(true)
		expect(isFileServerSource({ type: 'ftps' })).toBe(true)
		expect(isFileServerSource({ type: 'api' })).toBe(false)
		expect(isFileServerSource(null)).toBe(false)
	})
})
