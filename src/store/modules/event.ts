import { createCrudStore, logsPlugin } from '@conduction/nextcloud-vue'
import { Event } from '../../entities/index.js'
import { MissingParameterError } from '../../services/errors/index.js'

export const useEventStore = createCrudStore('event', {
	endpoint: 'events',
	baseUrl: '/index.php/apps/openconnector/api',
	entity: Event,
	features: { loading: true, viewMode: true },
	plugins: [
		// No autoRefreshOnItemChange: setting the active event from the index
		// or detail view shouldn't trigger a log fetch. Log views call
		// refreshLogs() explicitly.
		logsPlugin({ parentIdParam: 'event_id' }),
	],
	extend: {
		state: () => ({
			testResult: null as object | null,
			runResult: null as object | null,
			argumentKey: null as string | null,
		}),
		actions: {
			setTestResult(value: object | false) {
				this.testResult = value || null
			},

			setRunResult(value: object | false) {
				this.runResult = value || null
			},

			setArgumentKey(value: string | null) {
				this.argumentKey = value
			},

			// /api/events/logs has no backend route yet (EventsController has no
			// logs() action), so Nextcloud serves the SPA index HTML and a naive
			// JSON.parse explodes. Treat non-JSON / non-OK responses as empty
			// until the backend is wired up.
			async refreshLogs(filters: Record<string, unknown> = {}) {
				this.logsLoading = true
				this.logsError = null
				try {
					const params = new URLSearchParams()
					if (!('event_id' in filters) && this.item?.id) {
						params.set('event_id', String(this.item.id))
					}
					for (const [k, v] of Object.entries(filters)) {
						if (v != null && v !== '') {
							params.set(k, String(v))
						}
					}
					const qs = params.toString()
					const url = `/index.php/apps/openconnector/api/events/logs${qs ? '?' + qs : ''}`
					const response = await fetch(url)
					const ct = response.headers.get('content-type') || ''
					if (!response.ok || !ct.includes('json')) {
						console.warn(`Event logs endpoint returned ${response.status} (${ct || 'no content-type'}); treating as empty.`)
						this.logs = []
						return { response, data: [] }
					}
					const data = await response.json()
					this.logs = data
					return { response, data }
				} catch (error) {
					this.logsError = (error as Error)?.message || 'Failed to load event logs'
					this.logs = []
					return { response: null, data: [] }
				} finally {
					this.logsLoading = false
				}
			},

			async testEvent(id: string) {
				if (!id) {
					throw new MissingParameterError('id')
				}
				const response = await fetch(
					`/index.php/apps/openconnector/api/events-test/${id}`,
					{
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify([]),
					},
				)
				const data = await response.json()
				this.setTestResult(data)
				this.refreshLogs({ event_id: id })
				return { response, data }
			},

			async runEvent(id: string) {
				if (!id) {
					throw new MissingParameterError('id')
				}
				const response = await fetch(
					`/index.php/apps/openconnector/api/events-run/${id}`,
					{
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify([]),
					},
				)
				const data = await response.json()
				this.setRunResult(data)
				this.refreshLogs({ event_id: id })
				return { response, data }
			},
		},
	},
})
