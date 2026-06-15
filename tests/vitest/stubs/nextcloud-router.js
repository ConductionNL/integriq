/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenConnector Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Minimal stub for @nextcloud/router — deterministic, prefix-stable URL
 * builders for the offline Vitest suite.
 */

export function generateUrl(url) {
	return `/index.php${url.startsWith('/') ? url : `/${url}`}`
}

export function generateRemoteUrl(service) {
	return `http://localhost/remote.php/${service}`
}

export function generateOcsUrl(url) {
	return `/ocs/v2.php${url.startsWith('/') ? url : `/${url}`}`
}
