<?php

/**
 * The page a learner sees when an LTI launch cannot be completed.
 *
 * It names the check that failed and posts nothing to the tool.
 *
 * @var array $_ The template variables.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

?>
<div class="guest-box">
	<h2><?php p($_['title']); ?></h2>
	<p><?php p($_['message']); ?></p>
	<p><?php p($_['checkLabel']); ?></p>
	<p><?php p($_['hint']); ?></p>
</div>
