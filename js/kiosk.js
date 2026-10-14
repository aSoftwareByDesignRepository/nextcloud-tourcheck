/* TourCheck kiosk — grid → PIN → confirm → full reset (UX-4). */
(function () {
	'use strict';
	function t(key) {
		if (window.OC && OC.L10N && typeof OC.L10N.translate === 'function') {
			try {
				var tr = OC.L10N.translate('tourcheck', key);
				if (tr) { return tr; }
			} catch (e) { /* fall through */ }
		}
		return key;
	}
	var appId = 'tourcheck';
	function route(path) { return OC.generateUrl('/apps/' + appId + path); }
	function headers() {
		return { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken || '' };
	}
	/* The station token must not sit in the address bar (history, shoulder
	   surf, copied links) or ride every API URL (access logs record the
	   full path). Take it from the URL once, park it in sessionStorage so
	   a reload still works, strip it from the visible URL, and send it as
	   a Bearer header — the server accepts both. If storage is blocked we
	   keep the URL token (degraded but functional). */
	var stationToken = new URLSearchParams(location.search).get('station_token') || '';
	if (stationToken) {
		var persisted = false;
		try { sessionStorage.setItem('tck_station_token', stationToken); persisted = true; } catch (e) { /* storage blocked */ }
		if (persisted && window.history && history.replaceState) {
			var clean = new URL(location.href);
			clean.searchParams.delete('station_token');
			history.replaceState(null, '', clean.toString());
		}
	} else {
		try { stationToken = sessionStorage.getItem('tck_station_token') || ''; } catch (e) { /* storage blocked */ }
	}

	function api(path, body) {
		var h = headers();
		if (stationToken) { h['Authorization'] = 'Bearer ' + stationToken; }
		return fetch(route(path), { method: 'POST', headers: h, credentials: 'same-origin', body: JSON.stringify(body) })
			.then(function (r) { return r.json(); });
	}
	function announce(text) {
		var r = document.getElementById('tck-alert-region');
		if (r) { r.textContent = ''; setTimeout(function () { r.textContent = text; }, 30); }
	}

	var stages = {};
	document.querySelectorAll('[data-tck-stage]').forEach(function (s) {
		stages[s.getAttribute('data-tck-stage')] = s;
	});
	var pinInput = document.querySelector('[data-tck-pin-input]');
	var dots = document.querySelector('[data-tck-dots]');
	var pinName = document.querySelector('[data-tck-pin-name]');
	var pinErr = document.querySelector('[data-tck-pin-error]');
	var confirmText = document.querySelector('[data-tck-confirm-text]');
	var memberId = null;
	var pin = '';
	var submitting = false;
	/* PIN length is site-configured (4–8). The pad renders that many dots
	   and auto-submits at that length — a hardcoded count would dead-end
	   every PIN that isn't exactly 6 digits. */
	var kioskEl = document.querySelector('[data-tck-kiosk]');
	var pinLen = Math.min(8, Math.max(4, parseInt(kioskEl ? kioskEl.getAttribute('data-pin-len') : '6', 10) || 6));

	/* NH-06 signature stage — the pad is a document surface: fixed ink on
	   light paper regardless of theme, HiDPI-scaled, pointer-only (keyboard
	   users take the always-available Skip path). ~1 MB wire cap matches
	   the server; oversized art must be cleared or skipped, never silently
	   truncated. */
	var signCanvas = document.querySelector('[data-tck-signpad]');
	var signClear = document.querySelector('[data-tck-sign-clear]');
	var signConfirm = document.querySelector('[data-tck-sign-confirm]');
	var signSkip = document.querySelector('[data-tck-sign-skip]');
	var signBack = document.querySelector('[data-tck-sign-back]');
	var signName = document.querySelector('[data-tck-sign-name]');
	var signErr = document.querySelector('[data-tck-sign-error]');
	var signHasInk = false;
	var signCtx = null;
	var SIGN_MAX_B64 = 1400000; // ~1 MB binary after base64

	function setupSignpad() {
		if (!signCanvas) { return; }
		var ratio = Math.max(1, window.devicePixelRatio || 1);
		var w = signCanvas.width, h = signCanvas.height;
		signCanvas.width = w * ratio;
		signCanvas.height = h * ratio;
		signCanvas.style.width = w + 'px';
		signCanvas.style.height = h + 'px';
		signCtx = signCanvas.getContext('2d');
		signCtx.scale(ratio, ratio);
		signCtx.fillStyle = '#ffffff';
		signCtx.fillRect(0, 0, w, h);
		signCtx.strokeStyle = '#111111';
		signCtx.lineWidth = 3;
		signCtx.lineCap = 'round';
		signCtx.lineJoin = 'round';
		signHasInk = false;
	}
	function signPos(e) {
		var r = signCanvas.getBoundingClientRect();
		// draw in logical (pre-ratio) coordinates: ctx is scaled
		return { x: (e.clientX - r.left), y: (e.clientY - r.top) };
	}
	var signDrawing = false;
	function signDraw(e) {
		if (!signCtx || !signDrawing) { return; }
		var p = signPos(e);
		signCtx.lineTo(p.x, p.y);
		signCtx.stroke();
	}
	function signBegin(e) {
		if (!signCtx) { return; }
		e.preventDefault();
		if (signCanvas.setPointerCapture) { signCanvas.setPointerCapture(e.pointerId); }
		signDrawing = true;
		var p = signPos(e);
		signCtx.beginPath();
		signCtx.moveTo(p.x, p.y);
		signHasInk = true;
		if (signConfirm) { signConfirm.disabled = false; }
		if (signClear) { signClear.disabled = false; }
	}
	function signEnd() { signDrawing = false; }
	function clearSignature() {
		if (signCtx) {
			var w = signCanvas.width, h = signCanvas.height;
			var ratio = Math.max(1, window.devicePixelRatio || 1);
			signCtx.fillStyle = '#ffffff';
			signCtx.fillRect(0, 0, w / ratio, h / ratio);
		}
		signHasInk = false;
		if (signConfirm) { signConfirm.disabled = true; }
		if (signClear) { signClear.disabled = true; }
		if (signErr) { signErr.hidden = true; }
	}
	function signatureB64() {
		if (!signHasInk || !signCanvas) { return ''; }
		var url = signCanvas.toDataURL('image/png');
		var b64 = url.slice(url.indexOf(',') + 1);
		if (b64.length > SIGN_MAX_B64) {
			if (signErr) {
				signErr.textContent = t('Signature too large — clear or skip');
				signErr.hidden = false;
				announce(signErr.textContent);
			}
			return null; // abort submit — do not send a server-rejected payload
		}
		return b64;
	}
	if (signCanvas) {
		setupSignpad();
		signCanvas.addEventListener('pointerdown', signBegin);
		signCanvas.addEventListener('pointermove', signDraw);
		signCanvas.addEventListener('pointerup', signEnd);
		signCanvas.addEventListener('pointercancel', signEnd);
	}
	if (signSkip) { signSkip.addEventListener('click', function () { postConfirm(null); }); }
	if (signConfirm) {
		signConfirm.addEventListener('click', function () {
			var b64 = signatureB64();
			if (b64 === null) { return; }
			postConfirm(b64 || null);
		});
	}
	if (signClear) { signClear.addEventListener('click', clearSignature); }
	if (signBack) { signBack.addEventListener('click', function () { pin = ''; renderDots(); clearSignature(); show('pin'); }); }

	function show(name) {
		Object.keys(stages).forEach(function (k) { stages[k].classList.add('tck-hidden'); });
		stages[name].classList.remove('tck-hidden');
	}
	function reset() {
		memberId = null; pin = ''; submitting = false;
		clearSignature();
		renderDots();
		if (pinInput) { pinInput.value = ''; }
		if (pinErr) { pinErr.hidden = true; }
		show('grid');
	}
	function renderDots() {
		dots.innerHTML = '';
		for (var i = 0; i < pinLen; i++) {
			var d = document.createElement('span');
			if (i < pin.length) { d.className = 'tck-dot--filled'; }
			dots.appendChild(d);
		}
	}
	function submitPin() {
		/* PIN full → optional signature stage (NH-06 parity with the
		   companion station flow). The PIN is verified server-side inside
		   the single confirm call so member + PIN + signature commit
		   atomically — a wrong PIN never stores a signature. */
		show('sign');
		if (signSkip) { signSkip.focus(); }
	}
	function postConfirm(signatureB64) {
		/* Guard: every key press after the pad is full used to re-POST the
		   same PIN — a double event + double lockout count. */
		if (submitting) { return; }
		submitting = true;
		var body = { member_id: memberId, pin: pin };
		if (signatureB64) { body.signature_b64 = signatureB64; }
		api('/api/kiosk/confirm', body).then(function (j) {
			if (j.success) {
				confirmText.textContent = j.data.message || t('Done');
				show('confirm');
				setTimeout(reset, 1800);
			} else {
				submitting = false;
				pin = '';
				renderDots();
				pinErr.textContent = j.message || t('Wrong PIN');
				pinErr.hidden = false;
				show('pin');
				if (pinInput) { pinInput.focus(); }
				announce(pinErr.textContent);
				if (j.data && j.data.locked) {
					setTimeout(reset, 2500);
				}
			}
		}).catch(function () {
			submitting = false;
			pin = '';
			renderDots();
			pinErr.textContent = t('Network error');
			pinErr.hidden = false;
			show('pin');
			if (pinInput) { pinInput.focus(); }
			announce(pinErr.textContent);
		});
	}

	document.querySelectorAll('[data-tck-member]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			memberId = parseInt(btn.getAttribute('data-tck-member'), 10);
			pinName.textContent = btn.getAttribute('data-name');
			if (signName) { signName.textContent = btn.getAttribute('data-name'); }
			pin = '';
			renderDots();
			show('pin');
			if (pinInput) { pinInput.focus(); }
		});
	});
	document.querySelectorAll('[data-tck-key]').forEach(function (key) {
		key.addEventListener('click', function () {
			var k = key.getAttribute('data-tck-key');
			if (k === '⌫') { pin = pin.slice(0, -1); }
			else if (pin.length < pinLen) { pin += k; }
			renderDots();
			if (pin.length === pinLen) { submitPin(); }
		});
	});
	if (pinInput) {
		pinInput.addEventListener('input', function () {
			pin = pinInput.value.replace(/\D/g, '').slice(0, pinLen);
			pinInput.value = pin;
			renderDots();
			if (pin.length === pinLen) { submitPin(); }
		});
		pinInput.addEventListener('keydown', function (e) {
			/* Keyboard users can still hit Enter — submits when full. */
			if (e.key === 'Enter' && pin.length === pinLen) { e.preventDefault(); submitPin(); }
		});
	}
	var back = document.querySelector('[data-tck-back]');
	if (back) { back.addEventListener('click', reset); }
	renderDots();
})();
