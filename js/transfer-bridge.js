/**
 * Bridge for the shared transfer page (apps/_shared/config-transfer):
 * exposes the window.TourCheckApi / TourCheckMessaging contract the synced
 * js/transfer.js expects, in TourCheck's own envelope
 * ({success,data} / {success:false,error,message,context}).
 *
 * Messaging delegates to the app's canonical announcer: TCK.toast (dedup on
 * kind+text with timer reset — toast-hygiene rule) and mirrors every line
 * into the #tck-transfer-result-live status region the part renders.
 *
 * App-specific — not synced. Loaded before transfer.js, on the Transfer
 * settings section only.
 */
(function () {
	'use strict';

	function requestToken() {
		return (window.OC && OC.requestToken) || '';
	}

	function parse(resp) {
		return resp.json().catch(function () { return null; }).then(function (j) {
			if (!resp.ok || (j && (j.ok === false || j.success === false))) {
				var errObj = j && j.error;
				var ctx = (j && (j.context !== undefined ? j.context : j.data)) || {};
				throw {
					code: (errObj && (errObj.code || errObj)) || 'SERVER_ERROR',
					context: (ctx && typeof ctx === 'object') ? ctx : {},
				};
			}
			return j && Object.prototype.hasOwnProperty.call(j, 'data') ? j.data : j;
		});
	}

	function url(path) {
		return (window.OC && OC.generateUrl) ? OC.generateUrl('/apps/tourcheck' + path) : path;
	}

	window.TourCheckApi = {
		url: url,
		get: function (path, params) {
			var u = url(path);
			if (params) {
				var q = Object.keys(params)
					.filter(function (k) { return params[k] !== undefined && params[k] !== null; })
					.map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); })
					.join('&');
				if (q) { u += '?' + q; }
			}
			return fetch(u, { credentials: 'same-origin' }).then(parse);
		},
		post: function (path, body) {
			return fetch(url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', requesttoken: requestToken() },
				body: JSON.stringify(body || {}),
			}).then(parse);
		},
		upload: function (path, file, extra) {
			var fd = new FormData();
			fd.append('file', file);
			if (extra) {
				Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); });
			}
			return fetch(url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { requesttoken: requestToken() },
				body: fd,
			}).then(parse);
		},
	};

	function live(text) {
		var el = document.getElementById('tck-transfer-result-live');
		if (el) { el.textContent = text; }
	}

	window.TourCheckMessaging = {
		alert: function (text) {
			if (window.TCK && TCK.toast) { TCK.toast('error', text); } else { live(text); }
			live(text);
		},
		polite: function (text) {
			if (window.TCK && TCK.toast) { TCK.toast('ok', text); } else { live(text); }
			live(text);
		},
	};
})();
