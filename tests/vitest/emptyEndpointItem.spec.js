/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the blank endpoint the legacy EditEndpoint modal starts from and resets to.
 */

import { describe, expect, it } from 'vitest'
import { emptyEndpointItem } from '../../src/modals/Endpoint/emptyEndpointItem.js'

describe('emptyEndpointItem', () => {
	it('is the inline blank endpoint of data() and closeModal()', () => {
		expect(emptyEndpointItem()).toStrictEqual({
			name: '',
			description: '',
			endpoint: '',
			endpointArray: '',
			endpointRegex: '',
			method: '',
			targetType: '',
			targetId: '',
			slug: '',
			configurations: [],
		})
	})

	it('is a fresh object, with a fresh configurations list, per call', () => {
		const first = emptyEndpointItem()
		const second = emptyEndpointItem()

		expect(first).not.toBe(second)
		expect(first.configurations).not.toBe(second.configurations)
	})
})
