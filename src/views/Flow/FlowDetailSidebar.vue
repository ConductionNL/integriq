<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  The flow controls, in Nextcloud's app sidebar. Shares `useFlowStore` with the
  canvas, which is why neither needs props from the other. Save/Run live on
  CnFlowDetail's toolbar; the host page (FlowDetailPage) handles them.

  @spec openspec/specs/flow-orchestration/spec.md#REQ-017
-->
<template>
	<CnFlowSidebar />
</template>

<script>
import { CnFlowSidebar } from '@conduction/nextcloud-vue'

export default {
	name: 'FlowDetailSidebar',

	components: { CnFlowSidebar },
}
</script>
