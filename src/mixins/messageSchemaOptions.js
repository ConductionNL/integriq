// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * A message schema as a select option, its version in brackets.
 *
 * @param {object} schema One message_schema object.
 * @return {{id: string, label: string}} The option.
 */
function messageSchemaOption(schema) {
	return {
		id: schema['@self']?.id || schema.id,
		label: schema.name + (schema.version ? ' (' + schema.version + ')' : ''),
	}
}

/**
 * The message schemas OpenRegister holds, as select options.
 *
 * @return {Promise<Array<{id: string, label: string}>>} The options.
 */
async function fetchMessageSchemaList() {
	const response = await axios.get(
		generateUrl('/apps/openregister/api/objects/integriq/message_schema'),
	)
	return (response.data?.results || []).map(messageSchemaOption)
}

/**
 * The message schema picker shared by the endpoint and synchronization
 * editors: the option list, its loading flag, and the option for a stored
 * schema uuid.
 *
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
 */
export default {
	data() {
		return {
			/** Message schemas a request, answer, object or body can be checked against. */
			messageSchemaOptions: [],
			messageSchemasLoading: false,
		}
	},

	methods: {
		/**
		 * Load the message schemas from OpenRegister; a failed load leaves the
		 * list empty.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
		 */
		async fetchMessageSchemas() {
			this.messageSchemasLoading = true
			try {
				this.messageSchemaOptions = await fetchMessageSchemaList()
			} catch (err) {
				this.messageSchemaOptions = []
				// eslint-disable-next-line no-console
				console.warn(
					`[${this.$options.name}] message schema fetch failed`,
					err,
				)
			} finally {
				this.messageSchemasLoading = false
			}
		},

		/**
		 * The option for a stored message schema uuid; an unknown uuid shows
		 * as itself.
		 *
		 * @param {string|undefined} uuid The stored uuid.
		 * @return {object|null} The option, or null when none is stored.
		 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
		 */
		messageSchemaOptionFor(uuid) {
			if (!uuid) return null
			return (
				this.messageSchemaOptions.find((option) => option.id === uuid) || {
					id: uuid,
					label: uuid,
				}
			)
		},
	},
}
