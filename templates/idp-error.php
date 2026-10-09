<?php

/**
 * The page a person sees when a government sign-in cannot start or finish.
 *
 * It names no reason: the reason is logged, and a page that says which check
 * failed tells a prober which check to try next.
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
	<p><?php p($_['hint']); ?></p>
</div>
