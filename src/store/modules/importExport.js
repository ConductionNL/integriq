import { defineStore } from 'pinia'
import { sourceStore, endpointStore, jobStore, mappingStore, synchronizationStore, ruleStore } from '../../store/store.js'
import axios from 'axios'

export const useImportExportStore = defineStore(
	'importExport', {
		state: () => ({
			exportSource: '',
			exportSourceResults: '',
			exportSourceError: '',
		}),
		actions: {
			setExportSource(exportSource) {
				this.exportSource = exportSource
				console.info('Active exportSource set to ' + exportSource)
			},
			async exportFile(id, type) {
				const apiEndpoint = `/index.php/apps/openconnector/api/export/${type}/${id}`

				if (!id) {
					throw Error('Passed id is falsy')
				}
				const response = await fetch(
					apiEndpoint,
					{
						method: 'GET',
						headers: {
							Accept: 'application/json',
						},
					},
				)
				const filename = response.headers.get('Content-Disposition').split('filename=')[1].replace(/['"]/g, '')

				const blob = await response.blob()

				const download = () => {
					const url = window.URL.createObjectURL(new Blob([blob]))
					const link = document.createElement('a')
					link.href = url

					link.setAttribute('download', `${filename}`)
					document.body.appendChild(link)
					link.click()
				}

				return { response, blob, download }
			},

			importFile(file, reset) {
				if (!file) {
					throw Error('No file to import')
				}
				if (!reset) {
					throw Error('No reset function to call')
				}

				return axios.post('/index.php/apps/openconnector/api/import', {
					file: file.value ? file.value[0] : '',
				}, {
					headers: {
						'Content-Type': 'multipart/form-data',
					},
				})
					.then((response) => {

						console.info('Importing file:', response.data)

						const setItem = () => {
							switch (response.data.object['@type']) {
							case 'source':
								return (
									sourceStore.refreshList().then(() => {
										const source = sourceStore.list.find(source => source.id === response.data.object.id)
										sourceStore.setItem(source)
									})
								)
							case 'endpoint':
								return (
									endpointStore.refreshList().then(() => {
										const endpoint = endpointStore.list.find(endpoint => endpoint.id === response.data.object.id)
										endpointStore.setItem(endpoint)
									})
								)
							case 'job':
								return (
									jobStore.refreshList().then(() => {
										const job = jobStore.list.find(job => job.id === response.data.object.id)
										jobStore.setItem(job)
									})
								)
							case 'mapping':
								return (
									mappingStore.refreshList().then(() => {
										const mapping = mappingStore.list.find(mapping => mapping.id === response.data.object.id)
										mappingStore.setItem(mapping)
									})
								)
							case 'rule':
								return (
									ruleStore.refreshList().then(() => {
										const rule = ruleStore.list.find(rule => rule.id === response.data.object.id)
										ruleStore.setItem(rule)
									})
								)
							case 'synchronization':
								return (
									synchronizationStore.refreshSynchronizationList().then(() => {
										const synchronization = synchronizationStore.synchronizationList.find(synchronization => synchronization.id === response.data.object.id)
										synchronizationStore.setSynchronizationItem(synchronization)
									})
								)
							}
							reset()
						}
						return setItem()
					// Wait for the user to read the feedback then close the model
					})
					.catch((err) => {
						console.error('Error importing file:', err)
						throw err
					})

			},
			importFiles(files, reset) {
				if (!files) {
					throw Error('No files to import')
				}
				if (!reset) {
					throw Error('No reset function to call')
				}

				return axios.post('/index.php/apps/openconnector/api/import', {
					files: files.value,
				}, {
					headers: {
						'Content-Type': 'multipart/form-data',
					},
				})
					.then((response) => {

						console.info('Importing files:', response.data)

					// Wait for the user to read the feedback then close the model
					})
					.catch((err) => {
						console.error('Error importing files:', err)
						throw err
					})

			},
		},
	},
)
