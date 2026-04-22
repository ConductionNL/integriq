import { createCrudStore } from '@conduction/nextcloud-vue'
import { Consumer } from '../../entities/index.js'

export const useConsumerStore = createCrudStore('consumer', {
	endpoint: 'consumers',
	baseUrl: '/index.php/apps/openconnector/api',
	entity: Consumer,
	features: { loading: true, viewMode: true },
})
