<?php

use OCP\Util;

$appId = OCA\Integriq\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-main');
Util::addStyle($appId, 'main');
?>
<div id="integriq"></div>


