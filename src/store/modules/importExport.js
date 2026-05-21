import { defineStore } from 'pinia'
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
