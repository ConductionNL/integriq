/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * gateway-declared-data-feeds task 3: the consumer form edits
 * `consumer.feedScopes`, the scope values a consumer may read per declaring
 * app. A consumer without a line for an app is refused on every feed of that
 * app, so clearing the field must clear the binding, and a line the form
 * cannot read must stop the save rather than drop what the operator typed.
 */

import { describe, expect, it } from 'vitest'
import {
	buildConsumerPayload,
	consumerDraftFromItem,
	feedScopesText,
	parseFeedScopes,
} from '@/modals/v2/consumerDraft.js'

describe('the feed scopes text', () => {
	it('shows one line per app, sorted', () => {
		expect(feedScopesText({ shillinq: ['adm-1', 'adm-2'], pipelinq: ['p-1'] })).toBe('pipelinq: p-1\nshillinq: adm-1, adm-2')
		expect(feedScopesText(null)).toBe('')
		expect(feedScopesText(['shillinq'])).toBe('')
	})

	it('reads back what it shows', () => {
		const scopes = { pipelinq: ['p-1'], shillinq: ['adm-1', 'adm-2'] }
		expect(parseFeedScopes(feedScopesText(scopes))).toEqual({ scopes, error: '' })
	})

	it('skips blank lines and merges an app named twice, each value once', () => {
		expect(parseFeedScopes('\nshillinq: a, b\n\n  shillinq: b , c  \n')).toEqual({ scopes: { shillinq: ['a', 'b', 'c'] }, error: '' })
	})

	it.each([
		['shillinq', '1'],
		['shillinq:', '1'],
		['shillinq: a\n: b', '2'],
		['shillinq: a\nnot an app: b', '2'],
	])('names the line it cannot read in %j', (text, line) => {
		expect(parseFeedScopes(text)).toEqual({ scopes: {}, error: line })
	})
})

describe('the consumer payload', () => {
	const item = { name: 'powerbi', authorizationType: 'apiKey', feedScopes: { shillinq: ['adm-1'] } }

	it('seeds the draft from the stored binding and keeps it on save', () => {
		const draft = consumerDraftFromItem(item)
		expect(draft.feedScopesText).toBe('shillinq: adm-1')
		expect(buildConsumerPayload(item, draft, undefined).feedScopes).toEqual({ shillinq: ['adm-1'] })
	})

	it('sends the edited binding', () => {
		const draft = { ...consumerDraftFromItem(item), feedScopesText: 'shillinq: adm-1, adm-2' }
		expect(buildConsumerPayload(item, draft, undefined).feedScopes).toEqual({ shillinq: ['adm-1', 'adm-2'] })
	})

	it('clears the binding when the field is emptied, so every feed refuses the consumer', () => {
		const draft = { ...consumerDraftFromItem(item), feedScopesText: '' }
		expect(buildConsumerPayload(item, draft, undefined).feedScopes).toEqual({})
	})
})
