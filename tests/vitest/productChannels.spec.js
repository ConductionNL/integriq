// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
/**
 * The channel list of an API product: a channel needs a slug in the pattern, a
 * title and a type, and a slug is unique within the version.
 *
 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-an-api-product-can-declare-event-channels-req-aapi-001
 */
import { describe, expect, it } from 'vitest'
import { channelsOf, draftOf, parseTypes, withChannel, withoutChannel } from '../../src/views/ApiProducts/productChannels.js'

const publications = { slug: 'publications', title: 'Publications', types: ['nl.woo.publication.created'] }

describe('product channels', () => {
	it('reads the channels of a product', () => {
		expect(channelsOf({ channels: [publications] })).toEqual([publications])
		expect(channelsOf({ channels: 'x' })).toEqual([])
		expect(channelsOf(null)).toEqual([])
	})

	it('splits the typed types on lines and commas', () => {
		expect(parseTypes(' a\nb, c\n\na ')).toEqual(['a', 'b', 'c'])
		expect(parseTypes(undefined)).toEqual([])
	})

	it('adds a channel with its types', () => {
		const result = withChannel([], { slug: 'publications', title: 'Publications', types: 'nl.woo.publication.created', description: '' })
		expect(result).toEqual({ channels: [publications] })
	})

	it('refuses a bad slug, no title, no type or a repeated slug', () => {
		const draft = { slug: 'publications', title: 'P', types: 't' }
		expect(withChannel([], { ...draft, slug: 'Bad Slug' }).error).toBe('slug')
		expect(withChannel([], { ...draft, title: ' ' }).error).toBe('title')
		expect(withChannel([], { ...draft, types: ' , ' }).error).toBe('types')
		expect(withChannel([publications], draft).error).toBe('duplicate')
	})

	it('edits a channel in place, and may keep its own slug', () => {
		const decisions = { slug: 'decisions', title: 'D', types: ['d'] }
		const result = withChannel([publications, decisions], { ...draftOf(publications), title: 'Woo publications', description: 'All of them' }, 'publications')
		expect(result.channels).toEqual([{ ...publications, title: 'Woo publications', description: 'All of them' }, decisions])
		expect(withChannel([publications, decisions], { ...draftOf(publications), slug: 'decisions' }, 'publications').error).toBe('duplicate')
	})

	it('removes a channel and fills the form from one', () => {
		expect(withoutChannel([publications], 'publications')).toEqual([])
		expect(draftOf(publications)).toEqual({ slug: 'publications', title: 'Publications', types: 'nl.woo.publication.created', description: '' })
	})
})
