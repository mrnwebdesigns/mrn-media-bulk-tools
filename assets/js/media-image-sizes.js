(function () {
	'use strict';

	var config = window.MRNMediaImageSizes || {};
	var activeElement = null;
	var modal = null;
	var modalTitle = null;
	var modalBody = null;

	function request(attachmentId) {
		var body = new URLSearchParams();
		body.set('action', 'mrn_media_image_sizes_get');
		body.set('nonce', config.nonce || '');
		body.set('attachment_id', attachmentId);

		return window.fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
			body: body.toString()
		}).then(function (response) {
			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.success) {
				throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Request failed');
			}

			return payload.data;
		});
	}

	function createModal() {
		if (modal) {
			return;
		}

		modal = document.createElement('div');
		modal.className = 'mrn-media-image-sizes-modal';
		modal.hidden = true;
		modal.innerHTML = '<div class="mrn-media-image-sizes-modal__backdrop" data-mrn-media-image-sizes-close></div>' +
			'<div class="mrn-media-image-sizes-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="mrn-media-image-sizes-modal-title">' +
			'<div class="mrn-media-image-sizes-modal__header"><h2 id="mrn-media-image-sizes-modal-title"></h2>' +
			'<button type="button" class="button-link mrn-media-image-sizes-modal__close" data-mrn-media-image-sizes-close></button></div>' +
			'<div class="mrn-media-image-sizes-modal__body"></div></div>';

		document.body.appendChild(modal);
		modalTitle = modal.querySelector('#mrn-media-image-sizes-modal-title');
		modalBody = modal.querySelector('.mrn-media-image-sizes-modal__body');
		modal.querySelector('.mrn-media-image-sizes-modal__close').textContent = config.i18n.close;

		modal.addEventListener('click', function (event) {
			if (event.target.closest('[data-mrn-media-image-sizes-close]')) {
				closeModal();
			}
		});

		modal.addEventListener('keydown', function (event) {
			if ('Escape' === event.key) {
				event.preventDefault();
				closeModal();
				return;
			}

			if ('Tab' !== event.key) {
				return;
			}

			var focusable = modal.querySelectorAll('a[href], button:not([disabled])');

			if (!focusable.length) {
				return;
			}

			var first = focusable[0];
			var last = focusable[focusable.length - 1];

			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		});
	}

	function openModal(trigger) {
		createModal();
		activeElement = trigger;
		modal.hidden = false;
		document.body.classList.add('mrn-media-image-sizes-modal-open');
		modalTitle.textContent = config.i18n.generatedSizes + ': ' + (trigger.dataset.attachmentTitle || '');
		modalBody.textContent = config.i18n.loading;
		modal.querySelector('.mrn-media-image-sizes-modal__close').focus();

		request(trigger.dataset.attachmentId).then(function (data) {
			renderRecords(data.records || []);
		}).catch(function (error) {
			modalBody.textContent = error.message;
		});
	}

	function closeModal() {
		if (!modal) {
			return;
		}

		modal.hidden = true;
		document.body.classList.remove('mrn-media-image-sizes-modal-open');

		if (activeElement) {
			activeElement.focus();
		}
	}

	function renderRecords(records) {
		modalBody.textContent = '';

		if (!records.length) {
			var empty = document.createElement('p');
			empty.textContent = config.i18n.noSizes;
			modalBody.appendChild(empty);
			return;
		}

		var table = document.createElement('table');
		table.className = 'widefat striped mrn-media-image-sizes-table';
		table.innerHTML = '<thead><tr><th scope="col">Size</th><th scope="col">Dimensions</th><th scope="col">File Size</th><th scope="col">File</th></tr></thead><tbody></tbody>';
		var tbody = table.querySelector('tbody');

		records.forEach(function (record) {
			var row = document.createElement('tr');
			var name = document.createElement('th');
			var dimensions = document.createElement('td');
			var fileSize = document.createElement('td');
			var file = document.createElement('td');
			var link = document.createElement('a');

			name.scope = 'row';
			name.textContent = record.label;
			dimensions.textContent = record.width + ' × ' + record.height + ' px';
			fileSize.textContent = record.fileSize || '—';
			link.href = record.url;
			link.target = '_blank';
			link.rel = 'noopener noreferrer';
			link.textContent = config.i18n.view;
			link.setAttribute('aria-label', record.label + ': ' + config.i18n.view + ' (' + config.i18n.opensNewTab + ')');
			file.appendChild(link);
			row.appendChild(name);
			row.appendChild(dimensions);
			row.appendChild(fileSize);
			row.appendChild(file);
			tbody.appendChild(row);
		});

		modalBody.appendChild(table);
	}

	document.addEventListener('click', function (event) {
		var trigger = event.target.closest('.mrn-media-image-sizes-link');

		if (trigger) {
			openModal(trigger);
		}
	});
}());
