// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Row actions on the "Intake routing" page: move a rule up or down among the
// rules of its channel, and enable or disable it. Every write goes through
// `saveRule()` so validation runs on it, which is why these are handlers and
// not OpenRegister object patches. After a write the index page is told the
// rules changed, so the list shows the new order or state.
//
// @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001

import { dispatchObjectsChanged } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import {
	fetchChannelRules,
	INTAKE_REGISTER,
	refusalOf,
	RULE_SCHEMA,
	saveRule,
} from '../modals/v2/intakeRuleApi.js'
import { moveWrites, ruleFromRow, ruleId } from '../modals/v2/intakeRuleDraft.js'

/**
 * Move a rule one place up among the rules of its channel.
 *
 * @param {{ actionId: string, item: object }} ctx Row-action context from CnIndexPage.
 * @return {Promise<boolean>} Whether anything was written.
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
 */
export function moveIntakeRuleUpHandler({ item }) {
	return moveIntakeRule(item, 'up')
}

/**
 * Move a rule one place down among the rules of its channel.
 *
 * @param {{ actionId: string, item: object }} ctx Row-action context from CnIndexPage.
 * @return {Promise<boolean>} Whether anything was written.
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
 */
export function moveIntakeRuleDownHandler({ item }) {
	return moveIntakeRule(item, 'down')
}

/**
 * Enable a disabled rule, or disable an enabled one.
 *
 * @param {{ actionId: string, item: object }} ctx Row-action context from CnIndexPage.
 * @return {Promise<boolean>} Whether the rule was saved.
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
 */
export async function toggleIntakeRuleHandler({ item }) {
	const enable = item?.isEnabled === false
	try {
		await saveRule(ruleId(item), { ...ruleFromRow(item), isEnabled: enable })
	} catch (error) {
		showError(refusalOf(error, t('integriq', 'The rule could not be saved.')))
		return false
	}

	rulesChanged()
	showSuccess(enable ? t('integriq', 'Rule enabled') : t('integriq', 'Rule disabled'))
	return true
}

/**
 * Swap a rule with its neighbour on the channel and save both orders.
 *
 * @param {object} item The rule row.
 * @param {string} direction 'up' or 'down'.
 * @return {Promise<boolean>} Whether anything was written.
 */
async function moveIntakeRule(item, direction) {
	let rules
	try {
		rules = await fetchChannelRules(item?.channelId || '')
	} catch (error) {
		showError(refusalOf(error, t('integriq', 'The rules of this channel could not be read.')))
		return false
	}

	const writes = moveWrites(rules, item, direction)
	if (writes.length === 0) {
		showError(direction === 'up'
			? t('integriq', 'This rule is already first on its channel.')
			: t('integriq', 'This rule is already last on its channel.'))
		return false
	}

	try {
		for (const write of writes) {
			await saveRule(ruleId(write.row), { ...ruleFromRow(write.row), order: write.order })
		}
	} catch (error) {
		showError(refusalOf(error, t('integriq', 'The rule could not be saved.')))
		rulesChanged()
		return false
	}

	rulesChanged()
	showSuccess(t('integriq', 'Rule moved'))
	return true
}

/**
 * Tell the routing page its rules changed.
 *
 * @return {void}
 */
function rulesChanged() {
	dispatchObjectsChanged({ register: INTAKE_REGISTER, schema: RULE_SCHEMA })
}
