<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  SynchronizationNodeEditor — the flow editor opens the REAL Synchronization
  dialog for an `openconnector.synchronization-run` step.

  Registered through nc-vue's `registerFlowNodeEditor`, replacing the generic
  key-per-field dialog for exactly this node type. A step whose configuration
  IS a synchronization deserves the synchronization's own editing surface —
  source, target and transform on one screen — not a uuid in a text box.

  ## What edits what

  Two documents are edited here and they are committed separately, each to
  the store that owns it:

  - The SYNCHRONIZATION (source/target/transform) is persisted through
    `objectStore.saveObject('synchronization', …)` — the same store and path
    the Synchronizations index and detail page use, so their lists see the
    write.
  - The STEP options (force, output, maxItems, onError) live in the flow
    document. They ride the `#host-extras` slot inside the dialog and are
    committed to the flow store's node config when the dialog saves — and
    NEVER before, per the registry's draft contract: the node must not change
    while the dialog is open, closing without saving is Cancel.

  A step with no synchronization yet opens the dialog in CREATE mode; saving
  writes the new synchronization and points the step at it in the same
  commit.
-->
<template>
	<SynchronizationEditorModal
		:show="ready"
		:item="synchronization"
		:confirm="persist"
		:close="closeEditor">
		<template #hostExtras>
			<section class="oc-sync-node-step">
				<h3>{{ t('openconnector', 'Step options') }}</h3>
				<p class="oc-sync-node-step__hint">
					{{
						t(
							'openconnector',
							'How this flow step runs the synchronization. Saved with the flow, not with the synchronization.',
						)
					}}
				</p>
				<div class="oc-sync-node-step__grid">
					<NcCheckboxRadioSwitch
						:modelValue="stepDraft.force === true"
						type="switch"
						@update:modelValue="(value) => setStep('force', value)">
						{{ t('openconnector', 'Force a full pass') }}
					</NcCheckboxRadioSwitch>
					<NcTextField
						:modelValue="stepDraft.output || ''"
						:label="t('openconnector', 'Field to store the summary in')"
						:helperText="
							t(
								'openconnector',
								'Empty means the summary replaces the item.',
							)
						"
						@update:modelValue="(value) => setStep('output', value)" />
					<NcTextField
						:modelValue="
							stepDraft.maxItems == null
								? ''
								: String(stepDraft.maxItems)
						"
						type="number"
						:label="t('openconnector', 'Item ceiling')"
						:helperText="
							t(
								'openconnector',
								'The most synchronised objects this step emits as items.',
							)
						"
						@update:modelValue="
							(value) =>
								setStep(
									'maxItems',
									value === '' ? null : Number(value),
								)
						" />
					<div class="oc-sync-node-step__on-error">
						<label :for="onErrorSelectId">
							{{ t('openconnector', 'When the run fails') }}
						</label>
						<NcSelect
							:inputId="onErrorSelectId"
							:modelValue="selectedOnError"
							:options="onErrorOptions"
							:clearable="false"
							label="label"
							@update:modelValue="
								(option) => setStep('onError', option && option.id)
							" />
					</div>
				</div>
			</section>
		</template>
	</SynchronizationEditorModal>
</template>

<script>
import { useFlowStore } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'
import SynchronizationEditorModal from '../../modals/v2/SynchronizationEditorModal.vue'
import { useObjectStore } from '../../store/objectStore.js'

const SCHEMA_SLUG = 'synchronization'
const REGISTER_SLUG = 'openconnector'

export default {
	name: 'SynchronizationNodeEditor',

	components: {
		NcCheckboxRadioSwitch,
		NcSelect,
		NcTextField,
		SynchronizationEditorModal,
	},

	setup() {
		const objectStore = useObjectStore()
		// Same registration the detail page performs: fetchObject/saveObject
		// resolve the schema → register pair for URL building through it.
		if (typeof objectStore.registerObjectType === 'function') {
			objectStore.registerObjectType(SCHEMA_SLUG, SCHEMA_SLUG, REGISTER_SLUG)
		}
		return { flowStore: useFlowStore(), objectStore }
	},

	data() {
		const node = useFlowStore().editingNode
		const config = (node && node.config) || {}
		return {
			/** The loaded synchronization row, or null while loading / in create mode. */
			synchronization: null,
			/** The dialog only opens once the referenced synchronization is loaded. */
			ready: false,
			/**
			 * DRAFT of the step-level options, snapshotted when the editor
			 * opens. Committed on save, discarded on close — the node config
			 * must not change while the dialog is open.
			 */
			stepDraft: {
				force: config.force === true,
				output: typeof config.output === 'string' ? config.output : '',
				maxItems:
					typeof config.maxItems === 'number' ? config.maxItems : null,

				onError:
					typeof config.onError === 'string' && config.onError !== ''
						? config.onError
						: 'stop',
			},
		}
	},

	computed: {
		onErrorSelectId() {
			return `oc-sync-node-on-error-${this.flowStore.editingNodeId}`
		},

		onErrorOptions() {
			return [
				{ id: 'stop', label: t('openconnector', 'Stop the flow') },
				{
					id: 'continue',
					label: t('openconnector', 'Record the error and continue'),
				},
				{
					id: 'dead_letter',
					label: t('openconnector', 'Route to dead letter'),
				},
			]
		},

		selectedOnError() {
			return (
				this.onErrorOptions.find(
					(option) => option.id === this.stepDraft.onError,
				) || this.onErrorOptions[0]
			)
		},
	},

	async created() {
		const config = this.nodeConfig()
		const reference =
			typeof config.synchronization === 'string'
				? config.synchronization.trim()
				: ''
		if (reference !== '') {
			// A reference that fails to load still opens the dialog — in
			// create mode the author can re-create or re-point the step; a
			// dialog that never opens would leave the step uneditable.
			this.synchronization = await this.objectStore
				.fetchObject(SCHEMA_SLUG, reference)
				.catch(() => null)
		}
		this.ready = true
	},

	methods: {
		t,

		nodeConfig() {
			const node = this.flowStore.editingNode
			return (node && node.config) || {}
		},

		setStep(key, value) {
			this.stepDraft = { ...this.stepDraft, [key]: value }
		},

		/**
		 * The dialog's save path. Persists the synchronization through the
		 * app's object store, then commits BOTH halves onto the step in one
		 * config write: the (possibly new) synchronization reference and the
		 * step options draft.
		 *
		 * Thrown errors propagate — the dialog shows them inline and stays
		 * open, so a failed save never half-commits the step.
		 *
		 * @param {object} payload The synchronization to persist.
		 * @return {Promise<void>} Resolves once both commits landed.
		 */
		async persist(payload) {
			const saved = await this.objectStore.saveObject(SCHEMA_SLUG, payload)
			if (!saved) {
				throw new Error(
					this.objectStore.errors?.[SCHEMA_SLUG]
						|| t('openconnector', 'Save failed'),
				)
			}
			this.synchronization = saved

			const nodeId = this.flowStore.editingNodeId
			const reference = String(
				saved.id ?? saved['@self']?.uuid ?? saved.uuid ?? '',
			)
			const config = {
				...this.nodeConfig(),
				synchronization: reference,
				force: this.stepDraft.force === true ? true : undefined,
				output: this.stepDraft.output || undefined,
				maxItems: this.stepDraft.maxItems ?? undefined,
				onError:
					this.stepDraft.onError !== 'stop'
						? this.stepDraft.onError
						: undefined,
			}
			// `undefined` values are dropped on serialisation, so an option
			// left at its default never bloats the flow document.
			Object.keys(config).forEach((key) => {
				if (config[key] === undefined) delete config[key]
			})
			this.flowStore.setNodeConfigById(nodeId, config)
		},

		/** Close = Cancel for anything not yet saved, per the registry contract. */
		closeEditor() {
			this.flowStore.editingNodeId = null
		},
	},
}
</script>

<style scoped>
.oc-sync-node-step {
	margin-top: 16px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.oc-sync-node-step h3 {
	margin: 0 0 4px;
}

.oc-sync-node-step__hint {
	margin: 0 0 12px;
	color: var(--color-text-maxcontrast);
}

.oc-sync-node-step__grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
	gap: 12px;
	align-items: end;
}

.oc-sync-node-step__on-error label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}
</style>
