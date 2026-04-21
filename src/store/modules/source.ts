import { createCrudStore, logsPlugin } from '@conduction/nextcloud-vue'
import { Source } from '../../entities/index.js'
import { importExportStore } from '../store.js'

export const useSourceStore = createCrudStore('source', {
	endpoint: 'sources',
	baseUrl: '/index.php/apps/openconnector/api',
	entity: Source,
	features: { loading: true, viewMode: true },
	plugins: [
		logsPlugin({ parentIdParam: 'source_id', autoRefreshOnItemChange: true }),
	],
	extend: {
		state: () => ({
			sourceTest: null as object | null,
			sourceLog: null as object | null,
			sourceConfigurationKey: null as string | null,
		}),
		actions: {
			setSourceTest(item: object | false) {
				this.sourceTest = item || null
			},

			setSourceLog(item: object) {
				this.sourceLog = item
			},

			setSourceConfigurationKey(item: string | null) {
				this.sourceConfigurationKey = item
			},

			async testSource(testSourceItem: object) {
				if (!this.item) {
					throw new Error('No source item to test')
				}
				if (!testSourceItem) {
					throw new Error('No testobject to test')
				}

				const endpoint = `/index.php/apps/openconnector/api/sources/test/${this.item.id}`

				const response = await fetch(endpoint, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify(testSourceItem),
				})

				const data = await response.json()

				this.setSourceTest(data)
				this.refreshLogs()

				return { response, data }
			},

			async exportSource(id: string) {
				if (!id) {
					throw new Error('No source item to export')
				}
				importExportStore.exportFile(id, 'source')
					.then(({ download }: { download: () => void }) => {
						download()
					})
					.catch((err: Error) => {
						console.error('Error exporting source:', err)
						throw err
					})
			},
		},
	},
})
