// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// What a mount-mode leaf reports to a nextcloud-vue host that draws it:
// `onHeader` when the host draws the library card around the leaf
// (integration-leaf-card), `onSummary` when a "linked" card lists it among a
// record's other links (linked-integrations-card). The pure builders are kept
// apart from the mixin so the node test runner covers them.

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
export function leafHeader({
	loading,
	error,
	count,
	emptyLabel,
	note = '',
	actions = [],
}) {
	const header = { actions, note }
	if (loading === true || error !== '') {
		return header
	}
	header.count = count
	if (count === 0) {
		header.empty = { name: emptyLabel }
	}
	return header
}

/**
 * The summary for a "linked" card: the count, the plural noun for its
 * "nothing linked" line, a row per item and the actions. Nothing while the
 * read runs or after it failed, so the card never says "nothing linked" when
 * it could not tell.
 *
 * @param {object} state The leaf's state.
 * @param {boolean} state.loading Whether the read is still running.
 * @param {string} state.error The read error, or ''.
 * @param {number} state.count How many items there are.
 * @param {string} state.noun The plural noun, lower case.
 * @param {Array<{label: string, href?: string, icon?: string}>} [state.rows] A row per item.
 * @param {Array<{label: string, run: () => void}>} [state.actions] The actions.
 *
 * @return {{count: number, noun: string, rows: Array, actions: Array}|null} The summary, or null.
 */
export function leafSummary({
	loading,
	error,
	count,
	noun,
	rows = [],
	actions = [],
}) {
	if (loading === true || error !== '') {
		return null
	}
	return { count, noun, rows: count > 0 ? rows : [], actions }
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
