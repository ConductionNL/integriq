import { createCrudStore, logsPlugin } from '@conduction/nextcloud-vue'
import { Job } from '../../entities/index.js'
import { importExportStore } from '../store.js'
import { MissingParameterError } from '../../services/errors/index.js'

export const useJobStore = createCrudStore('job', {
	endpoint: 'jobs',
	baseUrl: '/index.php/apps/openconnector/api',
	entity: Job,
	features: { loading: true, viewMode: true },
	plugins: [
		// No autoRefreshOnItemChange: setting the active job from JobsIndex / modals
		// shouldn't trigger a log fetch. Log views call refreshLogs() explicitly.
		logsPlugin({ parentIdParam: 'job_id' }),
	],
	extend: {
		state: () => ({
			// Domain extras that don't fit the CRUD pattern.
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

			// Override the plugin's refreshLogs so job_id is optional.
			// /api/jobs/logs supports an "all jobs" mode when no job_id is given.
			async refreshLogs(filters: Record<string, unknown> = {}) {
				this.logsLoading = true
				this.logsError = null
				try {
					const params = new URLSearchParams()
					if (this.item?.id) {
						params.set('job_id', String(this.item.id))
					}
					for (const [k, v] of Object.entries(filters)) {
						if (v != null && v !== '') {
							params.set(k, String(v))
						}
					}
					const qs = params.toString()
					const url = `/index.php/apps/openconnector/api/jobs/logs${qs ? '?' + qs : ''}`
					const response = await fetch(url)
					const data = await response.json()
					this.logs = data
					return { response, data }
				} catch (error) {
					this.logsError = (error as Error)?.message || 'Failed to load job logs'
					throw error
				} finally {
					this.logsLoading = false
				}
			},

			async testJob(id: string) {
				if (!id) {
					throw new MissingParameterError('id')
				}
				const response = await fetch(
					`/index.php/apps/openconnector/api/jobs/run/${id}?test=true`,
					{ method: 'POST' },
				)
				const data = await response.json()
				this.setTestResult(data)
				this.refreshLogs()
				return { response, data }
			},

			async runJob(id: string) {
				if (!id) {
					throw new MissingParameterError('id')
				}
				const response = await fetch(
					`/index.php/apps/openconnector/api/jobs/run/${id}`,
					{ method: 'POST' },
				)
				const data = await response.json()
				this.setRunResult(data)
				this.refreshLogs()
				return { response, data }
			},

			exportJob(id: string) {
				if (!id) {
					throw new MissingParameterError('id')
				}
				importExportStore.exportFile(id, 'job')
					.then(({ download }: { download: () => void }) => {
						download()
					})
					.catch((err: Error) => {
						console.error('Error exporting job:', err)
						throw err
					})
			},
		},
	},
})
