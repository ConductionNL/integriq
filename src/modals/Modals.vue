<script setup>
import { navigationStore } from '../store/store.js'
</script>

<template>
	<div>
		<ViewSource />
		<TestSource />
		<DeleteJob />
		<TestJob />
		<RunJob v-if="navigationStore.modal === 'runJob'" />
		<EditJob />
		<DeleteLog />
		<EditLog />
		<EditMapping v-if="navigationStore.modal === 'editMapping'" />
		<TestMapping v-if="navigationStore.modal === 'testMapping'" />
		<EditMappingItem />
		<DeleteMappingItem />
		<EditSynchronization v-if="navigationStore.modal === 'editSynchronization'" />
		<TestSynchronization v-if="navigationStore.modal === 'testSynchronization'" />
		<RunSynchronization v-if="navigationStore.modal === 'runSynchronization'" />
		<EditJobArgument />
		<DeleteJobArgument />
		<EditSourceConfiguration />
		<DeleteSourceConfiguration />
		<EditSourceConfigurationAuthentication v-if="navigationStore.modal === 'editSourceConfigurationAuthentication'" />
		<DeleteSourceConfigurationAuthentication v-if="navigationStore.modal === 'deleteSourceConfigurationAuthentication'" />
		<ViewSourceLog />
		<ViewJobLog />
		<ViewSynchronizationLog />
		<ViewSynchronizationContract />
		<EditSynchronizationSourceConfig v-if="navigationStore.modal === 'editSynchronizationSourceConfig'" />
		<EditSynchronizationTargetConfig v-if="navigationStore.modal === 'editSynchronizationTargetConfig'" />
		<ImportFile v-if="navigationStore.modal === 'importFile'" />
		<EditRule v-if="navigationStore.modal === 'editRule'" />
		<ViewJob v-if="navigationStore.modal === 'viewJob'" />
		<ViewEndpoint v-if="navigationStore.modal === 'viewEndpoint'" />
		<ViewSynchronization v-if="navigationStore.modal === 'viewSynchronization'" />
		<ViewMapping v-if="navigationStore.modal === 'viewMapping'" />
	</div>
</template>

<script>
import ViewSource from './Source/ViewSource.vue'
import TestSource from './TestSource/TestSource.vue'
import TestJob from './Job/TestJob.vue'
import RunJob from './Job/RunJob.vue'
import EditJob from './Job/EditJob.vue'
import DeleteJob from './Job/DeleteJob.vue'
import EditLog from './Log/EditLog.vue'
import DeleteLog from './Log/DeleteLog.vue'
import EditMapping from './Mapping/EditMapping.vue'
import TestMapping from './MappingTest/TestMapping.vue'
import EditSynchronization from './Synchronization/EditSynchronization.vue'
import TestSynchronization from './Synchronization/TestSynchronization.vue'
import RunSynchronization from './Synchronization/RunSynchronization.vue'
import EditJobArgument from './JobArgument/EditJobArgument.vue'
import DeleteJobArgument from './JobArgument/DeleteJobArgument.vue'
import EditSourceConfiguration from './SourceConfiguration/EditSourceConfiguration.vue'
import DeleteSourceConfiguration from './SourceConfiguration/DeleteSourceConfiguration.vue'
import EditSourceConfigurationAuthentication from './SourceConfigurationAuthentication/EditSourceConfigurationAuthentication.vue'
import DeleteSourceConfigurationAuthentication from './SourceConfigurationAuthentication/DeleteSourceConfigurationAuthentication.vue'
import ViewSourceLog from './Log/ViewSourceLog.vue'
import ViewJobLog from './Log/ViewJobLog.vue'
import ViewSynchronizationLog from './Log/ViewSynchronizationLog.vue'
import ViewSynchronizationContract from './Log/ViewSynchronizationContract.vue'
import EditSynchronizationSourceConfig from './SynchronizationSourceConfig/EditSynchronizationSourceConfig.vue'
import EditSynchronizationTargetConfig from './SynchronizationTargetConfig/EditSynchronizationTargetConfig.vue'
import ImportFile from './Import/ImportFile.vue'
import EditRule from './Rule/EditRule.vue'
import ViewJob from './Job/ViewJob.vue'
import ViewEndpoint from './Endpoint/ViewEndpoint.vue'
import ViewSynchronization from './Synchronization/ViewSynchronization.vue'
import ViewMapping from './Mapping/ViewMapping.vue'
import EditMappingItem from './Mapping/mappingItem/EditMappingItem.vue'
import DeleteMappingItem from './Mapping/mappingItem/DeleteMappingItem.vue'

export default {
	name: 'Modals',
	components: {
		ViewSource,
		TestSource,
		DeleteJob,
		EditJob,
		RunJob,
		TestJob,
		DeleteLog,
		EditLog,
		EditMapping,
		TestMapping,
		EditSynchronization,
		TestSynchronization,
		RunSynchronization,
		EditJobArgument,
		DeleteJobArgument,
		EditSourceConfiguration,
		DeleteSourceConfiguration,
		EditSourceConfigurationAuthentication,
		DeleteSourceConfigurationAuthentication,
		ViewSourceLog,
		ViewJobLog,
		ViewSynchronizationLog,
		ViewSynchronizationContract,
		EditSynchronizationSourceConfig,
		EditSynchronizationTargetConfig,
		ImportFile,
		EditRule,
		ViewJob,
		ViewEndpoint,
		ViewSynchronization,
		ViewMapping,
		EditMappingItem,
		DeleteMappingItem,
	},
	setup() {
		return {
			navigationStore,
		}
	},
}
</script>
