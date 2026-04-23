<template>
	<div class="endpointTargetIdField">
		<div class="endpointTargetIdField__selectRow">
			<div class="endpointTargetIdField__selectCell">
				<NcSelect :options="registerOptions"
					:value="selectedRegister"
					:input-label="t('openconnector', 'Register')"
					:loading="registersLoading"
					:disabled="disabled || registersLoading || !openRegisterAvailable"
					@input="onRegisterChange" />
			</div>

			<div class="endpointTargetIdField__selectCell">
				<NcSelect :options="schemaOptions"
					:value="selectedSchema"
					:input-label="t('openconnector', 'Schema')"
					:loading="schemasLoading"
					:disabled="disabled || !selectedRegister || schemasLoading || !openRegisterAvailable"
					@input="onSchemaChange" />
			</div>
		</div>

		<template v-if="!openRegisterAvailable">
			<p class="fallbackNote">
				{{ t('openconnector', 'OpenRegister is unavailable. Enter target ID manually below.') }}
			</p>
			<NcTextField :value="modelValue"
				:label="t('openconnector', 'Target ID (registerId/schemaId)')"
				:disabled="disabled"
				@update:value="$emit('update:modelValue', $event)" />
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcSelect, NcTextField } from '@nextcloud/vue'

export default {
	name: 'EndpointTargetIdField',
	components: { NcSelect, NcTextField },
	props: {
		modelValue: {
			type: String,
			default: '',
		},
		disabled: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['update:modelValue'],
	data() {
		return {
			registers: [],
			schemas: [],
			registersLoading: false,
			schemasLoading: false,
			openRegisterAvailable: true,
			selectedRegister: null,
			selectedSchema: null,
		}
	},
	computed: {
		registerOptions() {
			return this.registers.map(r => ({
				id: r.id,
				label: r.title,
				schemas: r.schemas,
			}))
		},
		schemaOptions() {
			if (!this.selectedRegister) return []
			const allowed = this.selectedRegister.schemas || []
			return this.schemas
				.filter(s => allowed.includes(s.id))
				.map(s => ({ id: s.id, label: s.title }))
		},
	},
	watch: {
		modelValue: {
			immediate: false,
			handler() {
				this.syncFromModel()
			},
		},
	},
	async mounted() {
		await Promise.all([this.fetchRegisters(), this.fetchSchemas()])
		this.syncFromModel()
	},
	methods: {
		t,
		async fetchRegisters() {
			this.registersLoading = true
			try {
				const response = await fetch('/index.php/apps/openregister/api/registers', {
					method: 'GET',
					credentials: 'include',
					headers: { 'x-requested-with': 'XMLHttpRequest' },
				})
				if (!response.ok) {
					this.openRegisterAvailable = false
					return
				}
				const body = await response.json()
				this.registers = body.results || []
			} catch (e) {
				this.openRegisterAvailable = false
			} finally {
				this.registersLoading = false
			}
		},
		async fetchSchemas() {
			this.schemasLoading = true
			try {
				const response = await fetch('/index.php/apps/openregister/api/schemas', {
					method: 'GET',
					credentials: 'include',
					headers: { 'x-requested-with': 'XMLHttpRequest' },
				})
				if (!response.ok) {
					this.openRegisterAvailable = false
					return
				}
				const body = await response.json()
				this.schemas = body.results || []
			} catch (e) {
				this.openRegisterAvailable = false
			} finally {
				this.schemasLoading = false
			}
		},
		syncFromModel() {
			const raw = this.modelValue || ''
			const [regId, schemaId] = raw.split('/')

			const reg = this.registerOptions.find(r => String(r.id) === String(regId)) || null
			this.selectedRegister = reg

			if (!reg) {
				this.selectedSchema = null
				return
			}
			const schema = this.schemaOptions.find(s => String(s.id) === String(schemaId)) || null
			this.selectedSchema = schema
		},
		emitComposite() {
			if (!this.selectedRegister || !this.selectedSchema) {
				this.$emit('update:modelValue', '')
				return
			}
			this.$emit('update:modelValue', `${this.selectedRegister.id}/${this.selectedSchema.id}`)
		},
		onRegisterChange(value) {
			this.selectedRegister = value
			this.selectedSchema = null
			this.emitComposite()
		},
		onSchemaChange(value) {
			this.selectedSchema = value
			this.emitComposite()
		},
	},
}
</script>

<style scoped>
.endpointTargetIdField {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
.endpointTargetIdField__selectRow {
	display: flex;
	gap: 12px;
	width: 100%;
}
.endpointTargetIdField__selectCell {
	flex: 1 1 0;
	min-width: 0;
}
.endpointTargetIdField__selectCell :deep(.v-select),
.endpointTargetIdField__selectCell :deep(.vs__dropdown-toggle) {
	width: 100%;
}
.fallbackNote {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	margin: 0;
}
</style>
