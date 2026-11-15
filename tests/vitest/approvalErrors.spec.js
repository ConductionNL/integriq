/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * approvalErrors: the replacing request is read only from a 409 that names
 * one, and a failure toast's suffix comes from the server's error, else the
 * error message, else nothing.
 *
 * @spec openspec/specs/approval-workflow/spec.md
 */
import { describe, expect, it } from 'vitest'
import { failureSuffix, supersededBy } from '@/views/Approvals/approvalErrors.js'

const conflict = (supersededBy) => ({ response: { status: 409, data: { _approval: { supersededBy } } } })

describe('supersededBy', () => {
	it('names the replacing request of a 409', () => {
		expect(supersededBy(conflict('req-2'))).toBe('req-2')
	})

	it('names none for another status, a 409 without one, or no response', () => {
		expect(supersededBy({ response: { status: 422, data: { _approval: { supersededBy: 'req-2' } } } })).toBeNull()
		expect(supersededBy(conflict(''))).toBeNull()
		expect(supersededBy({ response: { status: 409, data: {} } })).toBeNull()
		expect(supersededBy(new Error('network'))).toBeNull()
		expect(supersededBy(undefined)).toBeNull()
	})
})

describe('failureSuffix', () => {
	it('prefers the server error, then the error message', () => {
		const err = new Error('Request failed with status code 403')
		err.response = { data: { error: 'Not your approval' } }

		expect(failureSuffix(err)).toBe(': Not your approval')
		expect(failureSuffix(new Error('timeout'))).toBe(': timeout')
	})

	it('is empty without any detail', () => {
		expect(failureSuffix({ response: { data: { error: '' } }, message: '' })).toBe('')
		expect(failureSuffix({})).toBe('')
		expect(failureSuffix(null)).toBe('')
	})
})
