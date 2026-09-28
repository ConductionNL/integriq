<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  DocumentGenerationSourcePanel: the vendor templates and the activation of
  a document generation source (SmartDocuments, Xential), on the source page.

  Mounted as a body widget on SourceDetail and reads the loaded source off
  `cnSectionContext`, like CircuitBreakerBadge. It renders nothing for any
  other kind of source. The templates are read from the vendor each time the
  page opens and are never stored in integriq: an operator copies a template
  id into filinq's template admin. Activation asks the server, which refuses
  a source that cannot render (no credential reference, no base URL) and says
  which setting is missing.

  @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-templates-are-listed-from-the-vendor-not-copied-req-dgv-004
-->
<template>
	<section
		v-if="isDocumentGeneration"
		class="docGenSource"
		data-testid="document-generation-source-panel">
		<h3>{{ t('integriq', 'Document generation') }}</h3>

		<div class="docGenSource__activation">
			<p v-if="enabled" data-testid="document-generation-active">
				{{ t('integriq', 'This source is active and renders documents.') }}
			</p>
			<template v-else>
				<p>
					{{
						t(
							'integriq',
							'This source is not active yet. Activate it once its credential reference and address are set.',
						)
					}}
				</p>
				<NcButton
					variant="primary"
					:disabled="activating || !objectId"
					data-testid="document-generation-activate"
					@click="activate">
					{{ t('integriq', 'Activate') }}
				</NcButton>
			</template>
			<p v-if="activationError" class="docGenSource__error" role="alert">
				{{ activationError }}
			</p>
		</div>

		<h4>{{ t('integriq', 'Templates at the vendor') }}</h4>
		<p v-if="loading">
			{{ t('integriq', 'Asking the vendor for its templates') }}
		</p>
		<p v-else-if="templatesError" class="docGenSource__error" role="alert">
			{{ templatesError }}
		</p>
		<p
			v-else-if="templates.length === 0"
			data-testid="document-generation-no-templates">
			{{ t('integriq', 'The vendor lists no templates for this source.') }}
		</p>
		<table
			v-else
			class="docGenSource__templates"
			data-testid="document-generation-templates">
			<thead>
				<tr>
					<th scope="col">
						{{ t('integriq', 'Template') }}
					</th>
					<th scope="col">
						{{
							t(
								'integriq',
								'Template id, for the template admin in filinq',
							)
						}}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="template in templates" :key="template.id">
					<td>{{ template.name || template.id }}</td>
					<td>
						<code>{{ template.id }}</code>
					</td>
				</tr>
			</tbody>
		</table>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'

/** The source `type` DocumentGenerationService::SOURCE_TYPE names. */
export const DOCUMENT_GENERATION_TYPE = 'documentGeneration'

export default {
	name: 'DocumentGenerationSourcePanel',

	components: { NcButton },

	inject: {
		cnSectionContext: {
			from: 'cnSectionContext',
			default: () => ({ value: { objectId: null, object: null } }),
		},
	},

	data() {
		return {
			loading: false,
			templates: [],
			templatesError: '',
			activating: false,
			activationError: '',
			activated: false,
		}
	},

	computed: {
		/**
		 * The loaded source object.
		 *
		 * @return {object}
		 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-templates-are-listed-from-the-vendor-not-copied-req-dgv-004
		 */
		source() {
			const ctx = this.cnSectionContext
			const value = ctx && ctx.value !== undefined ? ctx.value : ctx
			return (value && value.object) || {}
		},

		/**
		 * The source's id for the endpoint URLs.
		 *
		 * @return {string}
		 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-templates-are-listed-from-the-vendor-not-copied-req-dgv-004
		 */
		objectId() {
			const ctx = this.cnSectionContext
			const value = ctx && ctx.value !== undefined ? ctx.value : ctx
			return String(
				(value && value.objectId)
					|| this.source['@self']?.id
					|| this.source.uuid
					|| this.source.id
					|| '',
			)
		},

		/**
		 * Whether this source generates documents.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-templates-are-listed-from-the-vendor-not-copied-req-dgv-004
		 */
		isDocumentGeneration() {
			return this.source.type === DOCUMENT_GENERATION_TYPE
		},

		/**
		 * Whether the source is active, after a successful activation too.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-templates-are-listed-from-the-vendor-not-copied-req-dgv-004
		 */
		enabled() {
			return this.activated || this.source.isEnabled === true
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/**
			 * Ask the vendor for its templates once the source is known.
			 *
			 * @return {void}
			 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-templates-are-listed-from-the-vendor-not-copied-req-dgv-004
			 */
			handler() {
				if (this.isDocumentGeneration && this.objectId) {
					this.loadTemplates()
				}
			},
		},
	},

	methods: {
		t,

		/**
		 * List the vendor's templates through integriq, which stores none.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-templates-are-listed-from-the-vendor-not-copied-req-dgv-004
		 */
		async loadTemplates() {
			this.loading = true
			this.templatesError = ''
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/integriq/api/document-generation/sources/${encodeURIComponent(this.objectId)}/templates`,
					),
				)
				this.templates = Array.isArray(data?.templates) ? data.templates : []
			} catch (e) {
				this.templates = []
				this.templatesError =
					e?.response?.data?.error
					|| t('integriq', 'The vendor templates could not be listed.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Activate the source, or show why it cannot render yet.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-credentials-are-resolved-by-reference-never-passed-by-value-req-dgv-003
		 */
		async activate() {
			this.activating = true
			this.activationError = ''
			try {
				await axios.post(
					generateUrl(
						`/apps/integriq/api/document-generation/sources/${encodeURIComponent(this.objectId)}/activate`,
					),
				)
				this.activated = true
			} catch (e) {
				this.activationError =
					e?.response?.data?.error
					|| t('integriq', 'The source could not be activated.')
			} finally {
				this.activating = false
			}
		},
	},
}
</script>

<style scoped>
.docGenSource {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.docGenSource__activation {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.docGenSource__error {
	color: var(--color-error-text);
}

.docGenSource__templates {
	width: 100%;
	border-collapse: collapse;
}

.docGenSource__templates th,
.docGenSource__templates td {
	text-align: start;
	padding: calc(var(--default-grid-baseline) * 1)
		calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
}
</style>
