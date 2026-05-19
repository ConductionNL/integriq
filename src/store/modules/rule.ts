import { createCrudStore } from '@conduction/nextcloud-vue'
import { Rule } from '../../entities/index.js'
import { importExportStore } from '../store.js'

export const useRuleStore = createCrudStore('rule', {
	endpoint: 'rules',
	baseUrl: '/index.php/apps/openconnector/api',
	entity: Rule,
	features: { loading: true, viewMode: true },
	extend: {
		actions: {
			async exportRule(id: string) {
				if (!id) {
					throw new Error('No rule item to export')
				}
				importExportStore.exportFile(id, 'rule')
					.then(({ download }: { download: () => void }) => {
						download()
					})
					.catch((err: Error) => {
						console.error('Error exporting rule:', err)
						throw err
					})
			},
		},
	},
})
