import { createCrudStore } from '@conduction/nextcloud-vue'
import { Source, TSource } from '../../entities/index.js'
import { importExportStore } from '../store.js'
import { useLogStore } from './log'

export const useSourceStore = createCrudStore('source', {
	endpoint: 'sources',
	baseUrl: '/index.php/apps/openconnector/api',
	entity: Source,
	features: { loading: true, viewMode: true },
	extend: {
		state: () => ({
			sourceTest: null as object | null,
			sourceLog: null as object | null,
			sourceLogs: [] as object[] | { results: object[] },
			sourceConfigurationKey: null as string | null,
		}),
		actions: {
			async setItem(data: Source | TSource | null) {
				this.item = data ? new Source(data) : null
				if (data?.id) {
					try {
						await this.refreshSourceLogs()
					} catch (error) {
						console.error('Error fetching source logs:', error)
					}
				} else {
					this.sourceLogs = []
				}
			},

			setSourceTest(item: object | false) {
				this.sourceTest = item || null
			},

			setSourceLog(item: object) {
				this.sourceLog = item
			},

			setSourceLogs(item: object[] | { results: object[] }) {
				this.sourceLogs = item
			},

			setSourceConfigurationKey(item: string | null) {
				this.sourceConfigurationKey = item
			},

			async refreshSourceLogs(filters: Record<string, unknown> = {}) {
				const logStore = useLogStore()
				logStore.setLogsLoading(true)

				try {
					const queryParams = new URLSearchParams()

					if (!('source_id' in filters) && this.item?.id) {
						queryParams.append('source_id', this.item.id.toString())
					}

					const sortKey = '_sort[created]'
					if (!(sortKey in filters)) {
						queryParams.append(sortKey, 'desc')
					}

					Object.entries(filters).forEach(([key, value]) => {
						if (value !== null && value !== undefined && value !== '') {
							queryParams.append(key, value.toString())
						}
					})

					const endpoint = `/index.php/apps/openconnector/api/sources/logs${queryParams.toString() ? '?' + queryParams.toString() : ''}`
					const response = await fetch(endpoint, { method: 'GET' })

					const data = await response.json()
					this.setSourceLogs(data)
					return { response, data }
				} catch (error) {
					console.error('Error refreshing source logs:', error)
					throw error
				} finally {
					logStore.setLogsLoading(false)
				}
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
				this.refreshSourceLogs()

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
