<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Settings;

use OCA\HealthCheck\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

/** Administration settings → Health Check. The form itself is drawn by js/hc-admin.js from /api/admin/settings. */
class Admin implements ISettings {
	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'hc-admin');
		Util::addStyle(Application::APP_ID, 'hc-admin');
		return new TemplateResponse(Application::APP_ID, 'admin', [], '');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
