<?php

use OCP\Util;

$appId = OCA\OpenConnector\AppInfo\Application::APP_ID;
// webpack.config.js splits Vue / @nextcloud/vue / pinia / @conduction-nextcloud-vue
// off into stable-named shared chunks so each widget entry-point doesn't ship
// its own copy. The chunks must be loaded BEFORE -main, otherwise the runtime's
// `t.O(0, [shared-vendor, shared-nc-vue], ...)` queued entry never fires and
// the SPA mounts to an empty `<div id="openconnector">`.
Util::addScript($appId, $appId . '-shared-vendor');
Util::addScript($appId, $appId . '-shared-nc-vue');
Util::addScript($appId, $appId . '-main');
Util::addStyle($appId, 'main');
?>
<div id="openconnector"></div>


