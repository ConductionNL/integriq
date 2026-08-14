/**
 * Runtime webpack publicPath.
 *
 * Nextcloud injects the app's main bundle from the app's ACTUAL web root —
 * `/custom_apps/openconnector/js/` on a dev/clean install, `/apps/openconnector/js/`
 * when installed from the App Store. The base @nextcloud/webpack-vue-config bakes
 * a build-time publicPath that assumes `/apps/`, so lazily-imported chunks 404 into
 * the front controller (served as text/html → "MIME type not executable" → dead SPA)
 * on any install that is not under `/apps/`. Deriving publicPath from `OC.appswebroots`
 * at runtime makes chunk loading correct regardless of install path.
 *
 * MUST be imported before any other module so the assignment runs before the first
 * dynamic import.
 */

if (typeof OC !== 'undefined' && OC.appswebroots && OC.appswebroots.openconnector) {
	// eslint-disable-next-line no-undef
	__webpack_public_path__ = OC.appswebroots.openconnector + '/js/'
}
