<?php

use OCP\Util;

$appId = OCA\OpenConnector\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-main');
Util::addStyle($appId, 'main');
?>
<div id="openconnector"></div>


