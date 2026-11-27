<?php

/**
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

use OCP\Util;

$appId = OCA\Integriq\AppInfo\Application::APP_ID;
// The library stylesheet, linked so the browser keeps it parsed between loads.
Util::addStyle($appId, 'nextcloud-vue');
Util::addScript($appId, $appId . '-main');
Util::addStyle($appId, 'main');
?>
<div id="integriq"></div>


