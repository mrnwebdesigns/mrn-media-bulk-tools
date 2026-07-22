(function () {
	'use strict';

	var config = window.MRNMediaUsage || {};
	var activeElement = null;
	var modal = null;
	var modalTitle = null;
	var modalBody = null;
	var scanButton = null;
	var scanStatus = null;

	function request(action, data) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', config.nonce || '');

		Object.keys(data || {}).forEach(function (key) {
			body.set(key, data[key]);
		});

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
		modal.className = 'mrn-media-usage-modal';
		modal.hidden = true;
		modal.innerHTML = '<div class="mrn-media-usage-modal__backdrop" data-mrn-media-usage-close></div>' +
			'<div class="mrn-media-usage-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="mrn-media-usage-modal-title">' +
			'<div class="mrn-media-usage-modal__header"><h2 id="mrn-media-usage-modal-title"></h2>' +
			'<button type="button" class="button-link mrn-media-usage-modal__close" data-mrn-media-usage-close></button></div>' +
			'<div class="mrn-media-usage-modal__body"></div></div>';

		document.body.appendChild(modal);
		modalTitle = modal.querySelector('#mrn-media-usage-modal-title');
		modalBody = modal.querySelector('.mrn-media-usage-modal__body');
		modal.querySelector('.mrn-media-usage-modal__close').textContent = config.i18n.close;

		modal.addEventListener('click', function (event) {
			if (event.target.closest('[data-mrn-media-usage-close]')) {
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
		document.body.classList.add('mrn-media-usage-modal-open');
		modalTitle.textContent = config.i18n.usedIn + ': ' + (trigger.dataset.attachmentTitle || '');
		modalBody.textContent = config.i18n.loading;
		modal.querySelector('.mrn-media-usage-modal__close').focus();

		request('mrn_media_usage_get', {attachment_id: trigger.dataset.attachmentId}).then(function (data) {
			renderUsageRecords(data.records || []);
		}).catch(function (error) {
			modalBody.textContent = error.message;
		});
	}

	function closeModal() {
		if (!modal) {
			return;
		}

		modal.hidden = true;
		document.body.classList.remove('mrn-media-usage-modal-open');

		if (activeElement) {
			activeElement.focus();
		}
	}

	function renderUsageRecords(records) {
		modalBody.textContent = '';

		var notice = document.createElement('p');
		notice.className = 'description';
		notice.textContent = config.i18n.detectedNotice;
		modalBody.appendChild(notice);

		if (!records.length) {
			var empty = document.createElement('p');
			empty.textContent = config.i18n.noAccessibleUses;
			modalBody.appendChild(empty);
			return;
		}

		var list = document.createElement('ul');
		list.className = 'mrn-media-usage-list';

		records.forEach(function (record) {
			var item = document.createElement('li');
			var heading = document.createElement('h3');
			var link = document.createElement('a');
			link.href = record.editUrl;
			link.textContent = record.title;
			heading.appendChild(link);
			item.appendChild(heading);

			var details = document.createElement('p');
			details.className = 'description';
			details.textContent = record.postType + ' · ' + record.status;
			item.appendChild(details);

			if (record.contexts && record.contexts.length) {
				var contexts = document.createElement('p');
				contexts.textContent = record.contexts.join(', ');
				item.appendChild(contexts);
			}

			list.appendChild(item);
		});

		modalBody.appendChild(list);
	}

	function updateScanStatus(state) {
		if (!scanStatus || !state) {
			return;
		}

		if ('running' === state.status) {
			scanStatus.textContent = config.i18n.scanning + ' ' + state.processed + '/' + state.total;
		} else if ('complete' === state.status) {
			scanStatus.textContent = config.i18n.scanComplete;
		} else {
			scanStatus.textContent = '';
		}
	}

	function runScanBatch() {
		request('mrn_media_usage_scan_batch').then(function (state) {
			updateScanStatus(state);

			if ('running' === state.status) {
				window.setTimeout(runScanBatch, 150);
				return;
			}

			scanButton.disabled = false;
			window.setTimeout(function () {
				window.location.reload();
			}, 400);
		}).catch(function () {
			scanButton.disabled = false;
			scanStatus.textContent = config.i18n.scanFailed;
		});
	}

	function startScan() {
		scanButton.disabled = true;
		scanStatus.textContent = config.i18n.scanning;

		request('mrn_media_usage_start_scan').then(function (state) {
			updateScanStatus(state);
			runScanBatch();
		}).catch(function () {
			scanButton.disabled = false;
			scanStatus.textContent = config.i18n.scanFailed;
		});
	}

	function addScanControls() {
		if (!config.canScan) {
			return;
		}

		var target = document.querySelector('.tablenav.top .bulkactions');

		if (!target || document.getElementById('mrn-media-usage-scan')) {
			return;
		}

		scanButton = document.createElement('button');
		scanButton.type = 'button';
		scanButton.id = 'mrn-media-usage-scan';
		scanButton.className = 'button mrn-media-usage-scan';
		scanButton.textContent = config.i18n.scanUsage;
		scanButton.addEventListener('click', startScan);

		scanStatus = document.createElement('span');
		scanStatus.className = 'mrn-media-usage-scan-status';
		scanStatus.setAttribute('role', 'status');
		scanStatus.setAttribute('aria-live', 'polite');

		target.appendChild(scanButton);
		target.appendChild(scanStatus);
		updateScanStatus(config.state);
	}

	document.addEventListener('click', function (event) {
		var trigger = event.target.closest('.mrn-media-usage-link');

		if (trigger) {
			openModal(trigger);
		}
	});

	addScanControls();
}());
