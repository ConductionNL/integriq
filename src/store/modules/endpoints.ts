import { createCrudStore, logsPlugin } from '@conduction/nextcloud-vue'
import { Endpoint } from '../../entities/index.js'
import { importExportStore } from '../store.js'

export const useEndpointStore = createCrudStore('endpoint', {
	endpoint: 'endpoints',
	baseUrl: '/index.php/apps/openconnector/api',
	entity: Endpoint,
	features: { loading: true, viewMode: true },
	plugins: [
		logsPlugin({ parentIdParam: 'endpoint_id', autoRefreshOnItemChange: true }),
	],
	extend: {
		actions: {
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
