/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The blank endpoint the legacy EditEndpoint modal starts from and resets to on close.
 */

/**
 * A fresh blank endpoint item.
 *
 * @return {object} The endpoint item with every field empty.
 */
export function emptyEndpointItem() {
	return {
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
	}
}
