// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import axios from '@nextcloud/axios'
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'

/**
 * The server's own list of errors, joined, or null when it sent none.
 *
 * @param {Error} e the failed request
 * @return {string|null} the joined errors
 */
function listedErrors(e) {
	const data = e.response && e.response.data
	return data && Array.isArray(data.errors) ? data.errors.join(' ') : null
}

/**
 * The picker value for a stored account id.
 *
 * @param {string} userId the stored account id
 * @param {string} displayName the account's display name
 * @return {object|null} the picker option, or null without an id
 */
function accountOption(userId, displayName) {
	return userId
		? { id: userId, user: userId, displayName: displayName || userId }
		: null
}

/**
 * Shared behaviour of the admin editors of an intake connection (DSO, Open
 * Formulieren): the account it acts as, its handler group, and loading and
 * saving the connection. The component provides `settingsPath` (data), and
 * the methods `applySettings(data)`, `settingsBody()`, `clearSecret()`,
 * `refusalText(name)` and the four status texts `loadFailedText()`,
 * `saveFailedText()`, `savedText()` and `noAccountText()`.
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4
 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-4
 */
export default {
	data() {
		return {
			loading: true,
			saving: false,
			error: '',
			account: null,
			accountInfo: { state: 'none', displayName: '' },
			handlerGroup: { id: '', empty: false },
		}
	},

	computed: {
		/**
		 * The one-line state of the connection's account.
		 *
		 * @return {{refused: boolean, text: string}} whether intake is refused, and why
		 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#scenario-no-account-set-is-shown-plainly
		 */
		accountState() {
			const name = this.accountInfo.displayName
			if (this.accountInfo.state === 'ok') {
				return {
					refused: false,
					text: this.t('integriq', 'Intake acts as {name}', { name }),
				}
			}
			const text =
				this.accountInfo.state === 'none'
					? this.noAccountText()
					: this.refusalText(name)
			return { refused: true, text }
		},
	},

	/** @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4 */
	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Read the stored connection.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(generateUrl(this.settingsPath))
				this.applySettings(data)
				this.applyAccount(data)
			} catch {
				this.error = this.loadFailedText()
			} finally {
				this.loading = false
			}
		},

		/**
		 * Show the stored account and handler group.
		 *
		 * @param {object} data the stored connection
		 * @return {void}
		 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4
		 */
		applyAccount(data) {
			this.handlerGroup = data.handlerGroup || { id: '', empty: false }
			this.accountInfo = data.account || { state: 'none', displayName: '' }
			this.account = accountOption(data.userId, this.accountInfo.displayName)
		},

		/**
		 * Store the connection, then reload it and show the server's warnings.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4
		 */
		async save() {
			this.saving = true
			this.error = ''
			this.accountError = ''
			try {
				await this.saved(await this.store())
			} catch (e) {
				this.saveFailed(e)
			} finally {
				this.saving = false
			}
		},

		/**
		 * PUT the account and the component's settings.
		 *
		 * @return {Promise<object>} the save response
		 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4
		 */
		async store() {
			const { data } = await axios.put(generateUrl(this.settingsPath), {
				userId: this.account ? this.account.id : '',
				...this.settingsBody(),
			})
			return data
		},

		/**
		 * After a save: forget the typed secret, reload, and say so.
		 *
		 * @param {object} data the save response
		 * @return {Promise<void>}
		 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4
		 */
		async saved(data) {
			this.clearSecret()
			await this.load()
			showSuccess(this.savedText())
			for (const warning of data.warnings || []) {
				showWarning(warning)
			}
		},

		/**
		 * Show why a save failed, on the account field and as a toast.
		 *
		 * @param {Error} e the failed request
		 * @return {void}
		 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4
		 */
		saveFailed(e) {
			const fieldErrors =
				(e.response && e.response.data && e.response.data.fieldErrors) || {}
			this.accountError = fieldErrors.userId || ''
			this.error = listedErrors(e) || this.saveFailedText()
			showError(this.error)
		},
	},
}
