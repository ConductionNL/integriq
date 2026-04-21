import { createCrudStore } from '@conduction/nextcloud-vue'
import { Endpoint } from '../../entities/index.js'
import { importExportStore } from '../store.js'
import { useLogStore } from './log'

export const useEndpointStore = createCrudStore('endpoint', {
	endpoint: 'endpoints',
	baseUrl: '/index.php/apps/openconnector/api',
	entity: Endpoint,
	features: { loading: true, viewMode: true },
	extend: {
		state: () => ({
			endpointLogs: [] as object[],
		}),
		actions: {
			setEndpointLogs(logs: object[]) {
				this.endpointLogs = logs
			},

			async refreshEndpointLogs(filters: Record<string, unknown> = {}) {
				const logStore = useLogStore()
				logStore.setLogsLoading(true)

				try {
					const queryParams = new URLSearchParams()

					if (!('endpoint_id' in filters) && this.item?.id) {
						queryParams.append('endpoint_id', this.item.id.toString())
					}

					Object.entries(filters).forEach(([key, value]) => {
						if (value !== null && value !== undefined && value !== '') {
							queryParams.append(key, value.toString())
						}
					})

					const url = `/index.php/apps/openconnector/api/endpoints/logs${queryParams.toString() ? '?' + queryParams.toString() : ''}`
					const response = await fetch(url, { method: 'GET' })

					const data = await response.json()
					this.setEndpointLogs(data)
					return { response, data }
				} catch (error) {
					console.error('Error refreshing endpoint logs:', error)
					throw error
				} finally {
					logStore.setLogsLoading(false)
				}
			},

			async exportEndpoint(id: string) {
				if (!id) {
					throw new Error('No endpoint to export')
				}
				importExportStore.exportFile(id, 'endpoint')
					.then(({ download }: { download: () => void }) => {
						download()
					})
					.catch((err: Error) => {
						console.error('Error exporting endpoint:', err)
						throw err
					})
			},
		},
	},
})
