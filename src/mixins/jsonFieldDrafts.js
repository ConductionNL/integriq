// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { translate as t } from '@nextcloud/l10n'

/**
 * A stored value as textarea text: strings as they are, anything else
 * pretty-printed, and plain text when it cannot be printed as JSON.
 *
 * @param {unknown} raw the stored value
 * @return {string} the text to show
 */
function jsonText(raw) {
	if (raw === null || raw === undefined) return ''
	if (typeof raw === 'string') return raw
	try {
		return JSON.stringify(raw, null, 2)
	} catch {
		return String(raw)
	}
}

/**
 * Why an edited text is not valid JSON.
 *
 * @param {Error} parseErr the parse failure
 * @return {string} the translated message
 */
function invalidJsonText(parseErr) {
	return t('integriq', 'Invalid JSON: {message}', { message: parseErr.message })
}

/**
 * Shared json textareas of the form-field components. Per-key drafts are kept
 * apart from formData, so a textarea can hold an invalid intermediate string
 * without clobbering the parsed value on every keystroke. The component
 * provides `formData` and `updateField(key, value)`.
 *
 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
 */
export default {
	data() {
		return {
			jsonDrafts: {},
			jsonErrors: {},
		}
	},

	methods: {
		/**
		 * The textarea contents for a json field: the draft in progress when
		 * there is one, else the stored value.
		 *
		 * @param {object} field The field descriptor.
		 * @return {string} The text to show.
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		jsonStringFor(field) {
			if (this.jsonDrafts[field.key] !== undefined) {
				return this.jsonDrafts[field.key]
			}
			return jsonText(this.formData[field.key])
		},

		/**
		 * Parse a json field's edited text, keeping the raw draft so a
		 * half-typed value is not lost. Empty text stores null.
		 *
		 * @param {object} field The field descriptor.
		 * @param {string} raw The textarea contents.
		 * @return {void}
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		onJsonInput(field, raw) {
			this.jsonDrafts[field.key] = raw
			const trimmed = raw.trim()
			if (trimmed.length === 0) {
				this.storeJson(field.key, null)
				return
			}
			try {
				this.storeJson(field.key, JSON.parse(trimmed))
			} catch (parseErr) {
				this.jsonErrors[field.key] = invalidJsonText(parseErr)
			}
		},

		/**
		 * Store a parsed json value and clear the field's error.
		 *
		 * @param {string} key The field key.
		 * @param {unknown} value The parsed value, or null for empty text.
		 * @return {void}
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		storeJson(key, value) {
			this.jsonErrors[key] = ''
			this.updateField(key, value)
		},
	},
}
