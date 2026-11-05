/* FormTheory signature pad. Vanilla, no dependencies. The canvas is an optional
   pointer aid; the labelled typed-name input is the keyboard path. */
(function () {
	'use strict';

	var MAX_W = 2000;
	var MAX_H = 1000;

	function setup(field) {
		var canvas = field.querySelector('[data-ft-sig-canvas]');
		var hidden = field.querySelector('[data-ft-sig-data]');
		var clear = field.querySelector('[data-ft-sig-clear]');
		if (!canvas || !hidden || !canvas.getContext) return;
		var ctx = canvas.getContext('2d');
		var drawn = false;
		var drawing = false;
		var last = null;

		// Fixed logical size (from the width/height attributes); CSS scales it. Backing store is
		// HiDPI-scaled but never larger than MAX_W x MAX_H (server limit).
		var baseW = canvas.width;
		var baseH = canvas.height;
		var ratio = Math.max(1, Math.min(window.devicePixelRatio || 1, MAX_W / baseW, MAX_H / baseH));
		canvas.width = Math.min(MAX_W, Math.floor(baseW * ratio));
		canvas.height = Math.min(MAX_H, Math.floor(baseH * ratio));

		function pen() {
			ctx.lineWidth = 2 * ratio;
			ctx.lineCap = 'round';
			ctx.lineJoin = 'round';
			ctx.strokeStyle = '#111';
			ctx.fillStyle = '#111';
		}

		// Pointer position in backing-store pixels, whatever the rendered CSS size.
		function point(e) {
			var rect = canvas.getBoundingClientRect();
			return {
				x: (e.clientX - rect.left) * (canvas.width / (rect.width || canvas.width)),
				y: (e.clientY - rect.top) * (canvas.height / (rect.height || canvas.height))
			};
		}

		function sync() {
			hidden.value = drawn ? canvas.toDataURL('image/png') : '';
		}

		canvas.addEventListener('pointerdown', function (e) {
			if (e.button !== undefined && e.button > 0) return;
			e.preventDefault();
			drawing = true;
			last = point(e);
			if (canvas.setPointerCapture) canvas.setPointerCapture(e.pointerId);
			ctx.beginPath();
			ctx.arc(last.x, last.y, ratio, 0, Math.PI * 2);
			ctx.fill();
			drawn = true;
		});
		canvas.addEventListener('pointermove', function (e) {
			if (!drawing) return;
			e.preventDefault();
			var p = point(e);
			ctx.beginPath();
			ctx.moveTo(last.x, last.y);
			ctx.lineTo(p.x, p.y);
			ctx.stroke();
			last = p;
		});
		function end(e) {
			if (!drawing) return;
			drawing = false;
			if (canvas.releasePointerCapture && e && e.pointerId !== undefined) {
				try { canvas.releasePointerCapture(e.pointerId); } catch (err) { /* already released */ }
			}
			sync();
		}
		canvas.addEventListener('pointerup', end);
		canvas.addEventListener('pointercancel', end);

		if (clear) {
			clear.addEventListener('click', function () {
				ctx.clearRect(0, 0, canvas.width, canvas.height);
				drawn = false;
				sync();
			});
		}

		var form = field.closest('form');
		// Capture phase: runs before front.js validates and builds FormData.
		if (form) form.addEventListener('submit', sync, true);

		pen();
		hidden.value = '';
	}

	function boot() {
		document.querySelectorAll('.ft-signature').forEach(setup);
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
	else boot();
})();
