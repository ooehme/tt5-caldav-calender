(function () {
	'use strict';

	document.addEventListener('submit', function (event) {
		var form = event.target.closest('[data-tt5-confirm]');
		if (form && !window.confirm(form.getAttribute('data-tt5-confirm'))) {
			event.preventDefault();
		}
	});
})();
