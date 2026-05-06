import { defineStore } from 'pinia'

export const useNavigationStore = defineStore(
	'ui', {
		state: () => ({
			// The currently active menu item, defaults to '' which triggers the dashboard
			selected: 'dashboard',
			// The currently active modal, managed trough the state to ensure that only one modal can be active at the same time
			modal: false,
			// The currently active dialog
			dialog: false,
			// Sidebar state management
			sidebarState: {
				sourceLogs: true,
				endpointLogs: true,
				jobLogs: true,
				eventLogs: true,
				contracts: true,
				logs: true,
			},
			// Any data needed in various models, dialogs, views which cannot be transferred through normal means or without writing crappy/excessive code
			transferData: null,
		}),
		actions: {
			setSelected(selected) {
				this.selected = selected
				console.info('Active menu item set to ' + selected)
			},
			setSelectedCatalogus(selectedCatalogus) {
				this.selectedCatalogus = selectedCatalogus
				console.info('Active catalogus menu set to ' + selectedCatalogus)
			},
			setModal(modal) {
				this.modal = modal
				console.info('Active modal set to ' + modal)
			},
			setDialog(dialog) {
				this.dialog = dialog
				console.info('Active dialog set to ' + dialog)
			},
			setSidebarState(sidebarKey, state) {
				this.sidebarState[sidebarKey] = state
				console.info(`Sidebar ${sidebarKey} set to ${state}`)
			},
			setTransferData(data) {
				this.transferData = data
			},
			getTransferData() {
				const tempData = this.transferData
				this.transferData = null
				return tempData
			},
		},
	},
)
