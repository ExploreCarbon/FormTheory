(function () {
	'use strict';
	document.addEventListener('click', function (e) {
		var el = e.target.closest ? e.target.closest('[data-ft-confirm]') : null;
		if (el) {
			var only = el.getAttribute('data-ft-confirm-if');
			var needs = true;
			if (only) {
				var sel = el.form && el.form.elements.ft_bulk;
				needs = !!sel && sel.value === only;
			}
			if (needs && !window.confirm(el.getAttribute('data-ft-confirm'))) {
				e.preventDefault();
			}
		}
		if (e.target && e.target.id === 'ft-select-all') {
			var boxes = document.querySelectorAll('.ft-row-check');
			for (var i = 0; i < boxes.length; i++) {
				boxes[i].checked = e.target.checked;
			}
		}
	});
}());
