<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  OpenApiImportDialog: "Import an OpenAPI description" on the Sources page.
  Mounted once in ModalHost.

  An administrator gives a vendor's OpenAPI or Swagger document, by URL or by
  pasting it, and a gateway prefix. The preview lists every operation and
  writes nothing; an operation whose route an endpoint already serves is
  marked and cannot be picked. Import creates one source and one endpoint per
  picked operation, then says which credential the new source asks for.

  @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
-->
<template>
	<NcDialog
		:open="open"
		:name="t('integriq', 'Import an OpenAPI description')"
		size="large"
		data-testid="openapi-import-dialog"
		@update:open="onOpenChanged">
		<div class="openapi-import">
			<p class="openapi-import__muted">
				{{ t('integriq', 'Give the vendor\'s OpenAPI or Swagger document by its address or paste it. The preview changes nothing.') }}
			</p>

			<NcTextField
				v-model="url"
				data-testid="openapi-import-url"
				:label="t('integriq', 'Document address')"
				:disabled="busy" />
			<label class="openapi-import__label" for="openapi-import-document">
				{{ t('integriq', 'Or paste the document') }}
			</label>
			<textarea
				id="openapi-import-document"
				v-model="text"
				class="openapi-import__document"
				data-testid="openapi-import-document"
				rows="6"
				:disabled="busy" />
			<NcTextField
				v-model="prefix"
				data-testid="openapi-import-prefix"
				:label="t('integriq', 'Gateway prefix')"
				:helperText="t('integriq', 'Every imported endpoint is served under this path.')"
				:disabled="busy" />
			<NcButton
				data-testid="openapi-import-preview"
				:disabled="!canPreview || busy"
				@click="runPreview">
				{{ t('integriq', 'Preview') }}
			</NcButton>

			<NcNoteCard
				v-if="error"
				type="error"
				data-testid="openapi-import-error">
				{{ error }}
			</NcNoteCard>

			<template v-if="preview && !result">
				<NcTextField
					v-model="name"
					data-testid="openapi-import-name"
					:label="t('integriq', 'Source name')"
					:disabled="busy" />
				<NcSelect
					v-if="serverOptions.length > 1"
					v-model="server"
					data-testid="openapi-import-server"
					:inputLabel="t('integriq', 'Server')"
					:options="serverOptions"
					:clearable="false" />
				<p v-else class="openapi-import__muted" data-testid="openapi-import-location">
					{{ t('integriq', 'Source address: {location}', { location: preview.source.location || t('integriq', 'none given') }, undefined, { escape: false }) }}
				</p>

				<table class="openapi-import__table" data-testid="openapi-import-operations">
					<thead>
						<tr>
							<th scope="col">
								<span class="hidden-visually">{{ t('integriq', 'Import') }}</span>
							</th>
							<th scope="col">
								{{ t('integriq', 'Operation') }}
							</th>
							<th scope="col">
								{{ t('integriq', 'Gateway route') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="operation in preview.operations"
							:key="operation.key"
							:data-operation="operation.key"
							:class="{ 'openapi-import__row--collides': operation.collides }">
							<td>
								<input
									:id="'openapi-op-' + operation.key"
									v-model="chosen"
									type="checkbox"
									:value="operation.key"
									:disabled="operation.collides || busy"
									:aria-label="t('integriq', 'Import {operation}', { operation: operation.key }, undefined, { escape: false })">
							</td>
							<td>
								<label :for="'openapi-op-' + operation.key">{{ operation.key }}</label>
								<span v-if="operation.summary" class="openapi-import__muted">{{ operation.summary }}</span>
							</td>
							<td>
								{{ operation.method }} /{{ operation.gatewayPath }}
								<span v-if="operation.collides" class="openapi-import__collides">
									{{ t('integriq', 'Already served by {endpoint}', { endpoint: operation.collidesWith || t('integriq', 'another endpoint') }, undefined, { escape: false }) }}
								</span>
							</td>
						</tr>
					</tbody>
				</table>

				<NcButton
					variant="primary"
					data-testid="openapi-import-confirm"
					:disabled="chosen.length === 0 || busy"
					@click="runImport">
					{{ n('integriq', 'Import {count} operation', 'Import {count} operations', chosen.length, { count: chosen.length }) }}
				</NcButton>
			</template>

			<NcNoteCard
				v-if="result"
				:type="result.failed > 0 ? 'warning' : 'success'"
				data-testid="openapi-import-result">
				<p>{{ resultText }}</p>
				<p v-if="credentialText">
					{{ credentialText }}
				</p>
			</NcNoteCard>
		</div>
	</NcDialog>
</template>

<script>
import { dispatchObjectsChanged } from '@conduction/nextcloud-vue/src/utils/objectSignals.js'
import axios from '@nextcloud/axios'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import closableDialog from '../mixins/closableDialog.js'
import { credentialSummary, importRequest, importSummary } from './openApiImport.js'

export default {
	name: 'OpenApiImportDialog',

	components: { NcButton, NcDialog, NcNoteCard, NcSelect, NcTextField },

	mixins: [closableDialog],

	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close'],

	data() {
		return {
			url: '',
			text: '',
			prefix: '',
			name: '',
			server: null,
			preview: null,
			chosen: [],
			result: null,
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * Whether a document and a prefix are given.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
		 */
		canPreview() {
			return (this.url.trim() !== '' || this.text.trim() !== '') && this.prefix.trim() !== ''
		},

		/**
		 * The document's servers as select options.
		 *
		 * @return {Array<{id: number, label: string}>}
		 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
		 */
		serverOptions() {
			return (this.preview?.servers ?? []).map((server) => ({
				id: server.index,
				label: server.description ? `${server.url} (${server.description})` : server.url,
			}))
		},

		/**
		 * What the import did.
		 *
		 * @return {string}
		 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#scenario-an-administrator-connects-a-vendor-api-from-its-document
		 */
		resultText() {
			return importSummary(this.result, t, n)
		},

		/**
		 * Which credential the new source asks for, or ''.
		 *
		 * @return {string}
		 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#scenario-an-administrator-connects-a-vendor-api-from-its-document
		 */
		credentialText() {
			return credentialSummary(this.result, t)
		},
	},

	watch: {
		/**
		 * Start empty each time the dialog opens.
		 *
		 * @param {boolean} isOpen Whether the dialog is open.
		 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
		 */
		open(isOpen) {
			if (isOpen) {
				Object.assign(this.$data, this.$options.data.call(this))
			}
		},
	},

	methods: {
		t,
		n,

		/**
		 * The request body shared by preview and import.
		 *
		 * @return {object}
		 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
		 */
		body() {
			return importRequest({
				url: this.url,
				text: this.text,
				prefix: this.prefix,
				serverIndex: this.server?.id ?? null,
			})
		},

		/**
		 * The server's message, or the fallback.
		 *
		 * @param {Error} error The failed request.
		 * @param {string} fallback The message when the server gave none.
		 * @return {string}
		 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
		 */
		messageOf(error, fallback) {
			const message = error?.response?.data?.message
			return typeof message === 'string' && message !== '' ? message : fallback
		},

		/**
		 * Read what an import would create; nothing is written.
		 *
		 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#scenario-a-colliding-path-is-flagged-before-anything-is-written
		 */
		async runPreview() {
			this.busy = true
			this.error = ''
			this.result = null
			try {
				const res = await axios.post(generateUrl('/apps/integriq/api/openapi/import/preview'), this.body())
				this.preview = res.data
				this.name = res.data?.source?.name ?? ''
				const servers = this.serverOptions
				this.server = servers.find((option) => option.id === res.data?.serverIndex) ?? servers[0] ?? null
				this.chosen = (res.data?.operations ?? []).filter((operation) => !operation.collides).map((operation) => operation.key)
			} catch (error) {
				this.preview = null
				this.chosen = []
				this.error = this.messageOf(error, t('integriq', 'The document could not be read.'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * Create the source and the picked endpoints.
		 *
		 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#scenario-an-administrator-connects-a-vendor-api-from-its-document
		 */
		async runImport() {
			this.busy = true
			this.error = ''
			try {
				const res = await axios.post(generateUrl('/apps/integriq/api/openapi/import'), {
					...this.body(),
					operations: this.chosen,
					name: this.name,
				})
				this.result = res.data
				dispatchObjectsChanged({ register: 'integriq', schema: 'source' })
				dispatchObjectsChanged({ register: 'integriq', schema: 'endpoint' })
			} catch (error) {
				this.error = this.messageOf(error, t('integriq', 'The import failed. Nothing was created.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.openapi-import {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0 12px;
}

.openapi-import__muted {
	color: var(--color-text-maxcontrast);
	display: block;
}

.openapi-import__label {
	font-weight: bold;
}

.openapi-import__document {
	width: 100%;
	font-family: var(--font-face-monospace, monospace);
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.openapi-import__table {
	width: 100%;
	border-collapse: collapse;
}

.openapi-import__table th,
.openapi-import__table td {
	text-align: start;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	overflow-wrap: anywhere;
}

.openapi-import__row--collides td {
	color: var(--color-text-maxcontrast);
}

.openapi-import__collides {
	display: block;
	color: var(--color-warning-text, var(--color-main-text));
}
</style>
