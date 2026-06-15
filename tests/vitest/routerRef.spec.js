/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenConnector Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the module-scoped router holder (src/handlers/routerRef.js).
 * main.js calls setRouter() at boot; the registry action handlers read it via
 * getRouter() (they run without Vue component context, so no this.$router).
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { setRouter, getRouter } from '../../src/handlers/routerRef.js'

describe('routerRef', () => {
	beforeEach(() => {
		setRouter(null)
	})

	it('getRouter returns null before setRouter is called', () => {
		expect(getRouter()).toBeNull()
	})

	it('setRouter stores the instance and getRouter returns it', () => {
		const router = { push: () => {} }
		setRouter(router)
		expect(getRouter()).toBe(router)
	})

	it('setRouter overwrites a previously stored instance', () => {
		const a = { id: 'a' }
		const b = { id: 'b' }
		setRouter(a)
		setRouter(b)
		expect(getRouter()).toBe(b)
	})
})
