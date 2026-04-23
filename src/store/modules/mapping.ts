import { createCrudStore } from '@conduction/nextcloud-vue'
import { Mapping } from '../../entities/index.js'
import { importExportStore } from '../store.js'

export const useMappingStore = createCrudStore('mapping', {
	endpoint: 'mappings',
	baseUrl: '/index.php/apps/openconnector/api',
	entity: Mapping,
	cleanFields: ['id', 'uuid', 'created', 'updated', 'version', 'dateCreated', 'dateModified'],
	features: { loading: true, viewMode: true },
	extend: {
		state: () => ({
			mappingMappingKey: null as string | null,
			mappingCastKey: null as string | null,
			mappingUnsetKey: null as string | null,
			editingMode: null as string | null,
			editingMappingId: null as string | null,
		}),
		actions: {
			setMappingMappingKey(key: string | null) {
				this.mappingMappingKey = key
			},
			setMappingCastKey(key: string | null) {
				this.mappingCastKey = key
			},
			setMappingUnsetKey(key: string | null) {
				this.mappingUnsetKey = key
			},
			setEditingMode(mode: string | null) {
				this.editingMode = mode
			},
			setEditingMappingId(id: string | null) {
				this.editingMappingId = id
			},
			clearEditingContext() {
				this.editingMode = null
				this.editingMappingId = null
				this.mappingMappingKey = null
				this.mappingCastKey = null
				this.mappingUnsetKey = null
			},

			async testMapping(mappingTestObject: { inputObject: object, mapping: object, schema?: object }) {
				if (!mappingTestObject) {
					throw new Error('mappingTestObject is required')
				}
				if (!mappingTestObject?.inputObject) {
					throw new Error('mappingTestObject.inputObject is required')
				}
				if (!mappingTestObject?.mapping) {
					throw new Error('mappingTestObject.mapping is required')
				}

				const payload = {
					inputObject: mappingTestObject.inputObject,
					mapping: mappingTestObject.mapping,
					schema: mappingTestObject?.schema || null,
					validation: !!mappingTestObject?.schema,
				} as { inputObject: object, mapping: object, schema: object | null, validation: boolean }

				if (typeof payload.mapping !== 'object') {
					payload.mapping = JSON.parse(payload.mapping as unknown as string)
				}
				if (typeof payload.inputObject !== 'object') {
					payload.inputObject = JSON.parse(payload.inputObject as unknown as string)
				}
				if (!!payload.schema && typeof payload.schema !== 'object') {
					payload.schema = JSON.parse(payload.schema as unknown as string)
				}

				const response = await fetch(
					'/index.php/apps/openconnector/api/mappings/test',
					{
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify(payload),
					},
				)

				const data = await response.json()
				return { response, data }
			},

			async getMappingObjects() {
				const response = await fetch(
					'/index.php/apps/openconnector/api/mappings/objects',
					{
						method: 'GET',
						headers: { 'Content-Type': 'application/json' },
					},
				)
				const data = await response.json()
				return { response, data }
			},

			async saveMappingObject(mappingObject: object) {
				const response = await fetch(
					'/index.php/apps/openconnector/api/mappings/objects',
					{
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify(mappingObject),
					},
				)
				const data = await response.json()
				return { response, data }
			},

			async exportMapping(id: string) {
				if (!id) {
					throw new Error('No mapping item to export')
				}
				importExportStore.exportFile(id, 'mapping')
					.then(({ download }: { download: () => void }) => {
						download()
					})
					.catch((err: Error) => {
						console.error('Error exporting mapping:', err)
						throw err
					})
			},
		},
	},
})
