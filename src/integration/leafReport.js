// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// What a mount-mode leaf reports to a nextcloud-vue host that draws it:
// `onHeader` when the host draws the library card around the leaf
// (integration-leaf-card), `onSummary` when a "linked" card lists it among a
// record's other links (linked-integrations-card). The pure builders are kept
// apart from the mixin so the node test runner covers them.

/**
 * A value, or the fallback when it is undefined.
 *
 * @param {unknown} value The value.
 * @param {unknown} fallback The fallback.
 * @return {unknown} The value or the fallback.
 */
function orDefault(value, fallback) {
	return value === undefined ? fallback : value
}

/**
 * The header for a host that draws the leaf's card: the count beside the
 * title, a note, the title-row actions and the empty state. The count and
 * the empty state wait until the read has settled and stay away after a
 * failed read, so a zero never stands for "could not read".
 *
 * @param {object} state The leaf's state.
 * @param {boolean} state.loading Whether the read is still running.
 * @param {string} state.error The read error, or ''.
 * @param {number} state.count How many items there are.
 * @param {string} state.emptyLabel The empty state.
 * @param {string} [state.note] A short note for the title row.
 * @param {Array<{label: string, run: () => void}>} [state.actions] The title-row actions.
 *
 * @return {{actions: Array, note: string, count?: number, empty?: {name: string}}} The header.
 */
export function leafHeader(state) {
	const header = {
		actions: orDefault(state.actions, []),
		note: orDefault(state.note, ''),
	}
	if (state.loading === true || state.error !== '') {
		return header
	}
	header.count = state.count
	if (state.count === 0) {
		header.empty = { name: state.emptyLabel }
	}
	return header
}

/**
 * The summary for a "linked" card: the count, the plural noun for its
 * "nothing linked" line, a row per item and the actions. Nothing while the
 * read runs. After a failed read it reports `{ unavailable: true }` with no
 * count, noun or rows: the card replaces an earlier read's entry (a re-read
 * after the record changed) and, with no noun, never lists the leaf as
 * "nothing linked" when it could not tell.
 *
 * @param {object} state The leaf's state.
 * @param {boolean} state.loading Whether the read is still running.
 * @param {string} state.error The read error, or ''.
 * @param {number} state.count How many items there are.
 * @param {string} state.noun The plural noun, lower case.
 * @param {Array<{label: string, href?: string, icon?: string}>} [state.rows] A row per item.
 * @param {Array<{label: string, run: () => void}>} [state.actions] The actions.
 *
 * @return {{count: number, noun: string, rows: Array, actions: Array, unavailable?: boolean}|null} The summary, or null while loading.
 */
export function leafSummary(state) {
	if (state.loading === true) {
		return null
	}
	if (state.error !== '') {
		return { count: 0, noun: '', rows: [], actions: [], unavailable: true }
	}
	const { count, noun } = state
	const rows = count > 0 ? orDefault(state.rows, []) : []
	return { count, noun, rows, actions: orDefault(state.actions, []) }
}

/**
 * Options mixin for a leaf component. The component keeps `loading` and
 * `error` in its data and defines a `leafReport` computed:
 * `{ count, emptyLabel, noun, note?, rows?, actions? }`.
 *
 * `carded` tells the template to drop its own count, empty line and title
 * actions; `summarised` tells it to draw nothing at all.
 */
export const leafReportMixin = {
	props: {
		/** Set by a host that draws the library card around this leaf. */
		onHeader: {
			type: Function,
			default: null,
		},

		/** Set by a "linked" card that lists this leaf as a summary. */
		onSummary: {
			type: Function,
			default: null,
		},
	},

	computed: {
		/** @return {boolean} Whether the host draws the card around this leaf. */
		carded() {
			return typeof this.onHeader === 'function'
		},

		/** @return {boolean} Whether a "linked" card shows this leaf as a summary. */
		summarised() {
			return typeof this.onSummary === 'function'
		},

		/** @return {object} The header for a carding host. */
		hostHeader() {
			const report = this.leafReport
			return leafHeader({
				loading: this.loading,
				error: this.error || '',
				count: report.count,
				emptyLabel: report.emptyLabel,
				note: report.note || '',
				actions: report.actions || [],
			})
		},

		/** @return {object|null} The summary for a "linked" card. */
		hostSummary() {
			const report = this.leafReport
			return leafSummary({
				loading: this.loading,
				error: this.error || '',
				count: report.count,
				noun: report.noun,
				rows: report.rows || [],
				actions: report.actions || [],
			})
		},
	},

	watch: {
		hostHeader: {
			handler(header) {
				if (this.carded) {
					this.onHeader(header)
				}
			},

			immediate: true,
		},

		hostSummary: {
			handler(summary) {
				if (this.summarised && summary !== null) {
					this.onSummary(summary)
				}
			},

			immediate: true,
		},
	},
}
