/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The GET the legacy editor modals (EditEndpoint, EditRule) send to OpenRegister's
 * API to list registers, schemas and configurations, with the request options they
 * always sent.
 */

/**
 * Fetch one OpenRegister API collection.
 *
 * @param {string} collection The collection under /index.php/apps/openregister/api/, e.g. `schemas`.
 * @return {Promise<Response>} The fetch response.
 */
export function fetchOpenRegisterCollection(collection) {
	return fetch(
		'/index.php/apps/openregister/api/' + collection,
		{
			headers: {
				accept: '*/*',
				'accept-language': 'en-US,en;q=0.9,nl;q=0.8',
				'cache-control': 'no-cache',
				pragma: 'no-cache',
				'x-requested-with': 'XMLHttpRequest',
			},
			referrerPolicy: 'no-referrer',
			body: null,
			method: 'GET',
			mode: 'cors',
			credentials: 'include',
		},
	)
}
