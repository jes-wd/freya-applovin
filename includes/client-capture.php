<?php
/**
 * Cache-safe client-side AppLovin identifier capture.
 *
 * Inline head script reads aleid / alart from the page URL (and localStorage)
 * and sets first-party cookies even when Breeze / Varnish serves a cached HTML
 * response without running PHP.
 *
 * @package FreyaAppLovin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Output an early inline script that captures AppLovin click IDs in the browser.
 *
 * Mirrors the freya-first-landing pattern: the script is baked into cached HTML
 * so it runs on every page load, not only on PHP cache misses.
 *
 * @return void
 */
function freya_applovin_output_head_capture_script() {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}

	$max_age = (int) FREYA_APPLOVIN_COOKIE_LIFETIME;
	?>
	<script id="freya-applovin-capture">
	(function () {
		var COOKIE_ALEID = <?php echo wp_json_encode( FREYA_APPLOVIN_COOKIE_ALEID ); ?>;
		var COOKIE_ALART = <?php echo wp_json_encode( FREYA_APPLOVIN_COOKIE_ALART ); ?>;
		var COOKIE_CID   = <?php echo wp_json_encode( FREYA_APPLOVIN_COOKIE_CLIENT_ID ); ?>;
		var MAX_AGE      = <?php echo $max_age; ?>;
		var LS_KEY       = 'freya_applovin_ids';

		function sanitize(value, maxLen) {
			if (typeof value !== 'string') {
				return '';
			}
			value = value.trim().slice(0, maxLen || 256);
			return value.replace(/[^A-Za-z0-9_-]/g, '');
		}

		function getCookie(name) {
			var parts = document.cookie ? document.cookie.split(';') : [];
			for (var i = 0; i < parts.length; i++) {
				var part = parts[i].trim();
				if (part.indexOf(name + '=') === 0) {
					return decodeURIComponent(part.slice(name.length + 1));
				}
			}
			return '';
		}

		function setCookie(name, value) {
			if (!value) {
				return;
			}
			var secure = window.location.protocol === 'https:' ? '; Secure' : '';
			document.cookie = name + '=' + encodeURIComponent(value)
				+ '; path=/; max-age=' + MAX_AGE + '; SameSite=Lax' + secure;
		}

		function readStorage() {
			try {
				var raw = window.localStorage.getItem(LS_KEY);
				if (!raw) {
					return null;
				}
				var parsed = JSON.parse(raw);
				return parsed && typeof parsed === 'object' ? parsed : null;
			} catch (e) {
				return null;
			}
		}

		function writeStorage(payload) {
			try {
				window.localStorage.setItem(LS_KEY, JSON.stringify(payload));
			} catch (e) {
				// Private mode / blocked storage — cookies still work when allowed.
			}
		}

		function uuid4() {
			if (window.crypto && window.crypto.randomUUID) {
				return window.crypto.randomUUID();
			}
			return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
				var r = Math.random() * 16 | 0;
				var v = c === 'x' ? r : (r & 0x3 | 0x8);
				return v.toString(16);
			});
		}

		var params;
		try {
			params = new URLSearchParams(window.location.search || '');
		} catch (e) {
			params = { get: function () { return ''; } };
		}

		var aleid = sanitize(params.get('aleid') || '', 128);
		var alart = sanitize(params.get('alart') || '', 128);
		var stored = readStorage() || {};
		var clientId = getCookie(COOKIE_CID) || stored.client_id || '';

		if (!clientId) {
			clientId = uuid4();
		}

		if (aleid) {
			setCookie(COOKIE_ALEID, aleid);
			stored.aleid = aleid;
		} else if (!getCookie(COOKIE_ALEID) && stored.aleid) {
			setCookie(COOKIE_ALEID, stored.aleid);
		}

		if (alart) {
			setCookie(COOKIE_ALART, alart);
			stored.alart = alart;
		} else if (!getCookie(COOKIE_ALART) && stored.alart) {
			setCookie(COOKIE_ALART, stored.alart);
		}

		setCookie(COOKIE_CID, clientId);
		stored.client_id = clientId;
		stored.updated_at = Date.now();
		writeStorage(stored);
	})();
	</script>
	<?php
}
