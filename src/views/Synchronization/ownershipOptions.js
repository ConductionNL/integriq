// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Who owns the records a synchronisation maintains, and what happens to one
// the source stops sending (records-owned-by-an-external-source, REQ-SOR-001
// and REQ-SOR-002). Both are `sourceConfig` keys the engine already reads:
// `ownershipMode` through RecordOwnershipService, `disappearancePolicy`
// through DisappearancePolicy. The ids are those classes' constants.
//
// @spec openspec/specs/source-owned-records/spec.md#requirement-what-happens-when-a-record-disappears-is-declared-not-hardcoded-req-sor-002

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The ownership modes, as OwnershipState::MODES names them.
 *
 * @return {Array<{id: string, label: string}>} the options, `local` first
 */
export function ownershipModeOptions() {
	return [
		{
			id: 'local',
			label: t('integriq', 'Local: people may change the records here'),
		},
		{
			id: 'source',
			label: t('integriq', 'The source: the records are read-only here'),
		},
		{
			id: 'source with local additions',
			label: t('integriq', 'The source, with local additions allowed'),
		},
	]
}

/**
 * The disappearance policies, as DisappearancePolicy::ACCEPTED names them.
 *
 * @return {Array<{id: string, label: string}>} the options, the engine's default first
 */
export function disappearancePolicyOptions() {
	return [
		{ id: 'delete', label: t('integriq', 'Delete the record') },
		{
			id: 'markEnded',
			label: t('integriq', 'Keep the record and give it an end date'),
		},
		{
			id: 'keepAndFlag',
			label: t(
				'integriq',
				'Keep the record and flag that the source dropped it',
			),
		},
	]
}

/**
 * Ask the server whether a sourceConfig's disappearance policy is one the
 * engine knows, before the synchronisation is saved. The engine refuses an
 * unknown policy on every run and deletes nothing, so a typo saved here
 * would silently stop the clean-up; asking at save time says so instead.
 *
 * @param {object} sourceConfig the sourceConfig about to be saved
 * @return {Promise<string>} empty when the policy is accepted, else the reason
 */
export async function disappearancePolicyError(sourceConfig) {
	try {
		await axios.post(
			generateUrl('/apps/integriq/api/ownership/validate-policy'),
			{
				sourceConfig: sourceConfig || {},
			},
		)
		return ''
	} catch (err) {
		if (err?.response?.status === 400) {
			return (
				err.response.data?.error
				|| t(
					'integriq',
					'This disappearance policy is not one the engine knows.',
				)
			)
		}
		throw err
	}
}
