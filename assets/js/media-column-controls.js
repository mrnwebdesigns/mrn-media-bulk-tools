(function () {
	'use strict';

	var config = window.MRNMediaColumnControls || {};
	var table = document.querySelector('.wp-list-table.media');

	if (!table) {
		return;
	}

	var widths = config.widths && typeof config.widths === 'object' ? Object.assign({}, config.widths) : {};
	var minimumWidth = Number(config.minWidth) || 60;
	var maximumWidth = Number(config.maxWidth) || 800;
	var saveTimer = null;
	var visibleColumnKeys = [];
	var fixedColumnWidth = 0;
	var measurementTable = null;
	var tableScrollContainer = null;
	var topScrollBar = null;
	var topScrollSpacer = null;

	function getColumnKey(header) {
		var columnClass = Array.prototype.find.call(header.classList, function (className) {
			return className.indexOf('column-') === 0;
		});

		return columnClass ? columnClass.substring(7) : '';
	}

	function clampWidth(width) {
		return Math.min(maximumWidth, Math.max(minimumWidth, Math.round(width)));
	}

	function getCurrentWidth(element) {
		var computedWidth = parseFloat(window.getComputedStyle(element).width);

		return Number.isFinite(computedWidth) ? computedWidth : element.getBoundingClientRect().width;
	}

	function updateTableWidth() {
		var tableWidth = visibleColumnKeys.reduce(function (total, columnKey) {
			return total + (Number(widths[columnKey]) || 0);
		}, fixedColumnWidth);

		table.style.width = Math.ceil(tableWidth) + 'px';
		table.style.minWidth = '0';

		if (topScrollSpacer) {
			topScrollSpacer.style.width = Math.ceil(table.getBoundingClientRect().width) + 'px';
		}
	}

	function applyWidth(columnKey, width, updateTable) {
		var normalizedWidth = clampWidth(width);
		var cells = table.querySelectorAll('.column-' + columnKey);

		Array.prototype.forEach.call(cells, function (cell) {
			cell.style.width = normalizedWidth + 'px';
		});

		widths[columnKey] = normalizedWidth;

		if (false !== updateTable) {
			updateTableWidth();
		}

		return normalizedWidth;
	}

	function updateHandleValue(columnKey, width) {
		var handle = table.querySelector('.mrn-media-column-resizer[data-mrn-resize-column="' + columnKey + '"]');

		if (handle) {
			handle.setAttribute('aria-valuenow', String(width));
		}
	}

	function getNextVisibleColumnKey(columnKey) {
		var columnIndex = visibleColumnKeys.indexOf(columnKey);

		return columnIndex >= 0 && columnIndex < visibleColumnKeys.length - 1 ? visibleColumnKeys[columnIndex + 1] : '';
	}

	function applyBoundaryWidth(columnKey, targetWidth) {
		var currentWidth = Number(widths[columnKey]) || minimumWidth;
		var normalizedTarget = clampWidth(targetWidth);
		var nextColumnKey = getNextVisibleColumnKey(columnKey);

		if (!nextColumnKey) {
			var lastColumnWidth = applyWidth(columnKey, normalizedTarget);
			updateHandleValue(columnKey, lastColumnWidth);
			return lastColumnWidth;
		}

		var nextWidth = Number(widths[nextColumnKey]) || minimumWidth;
		var requestedDelta = normalizedTarget - currentWidth;
		var minimumDelta = Math.max(minimumWidth - currentWidth, nextWidth - maximumWidth);
		var maximumDelta = Math.min(maximumWidth - currentWidth, nextWidth - minimumWidth);
		var appliedDelta = Math.min(maximumDelta, Math.max(minimumDelta, requestedDelta));
		var updatedWidth = applyWidth(columnKey, currentWidth + appliedDelta, false);
		var updatedNextWidth = applyWidth(nextColumnKey, nextWidth - appliedDelta, false);

		updateTableWidth();
		updateHandleValue(columnKey, updatedWidth);
		updateHandleValue(nextColumnKey, updatedNextWidth);

		return updatedWidth;
	}

	function addScrollContainer() {
		if (table.parentNode && table.parentNode.classList.contains('mrn-media-table-scroll')) {
			tableScrollContainer = table.parentNode;
			return;
		}

		tableScrollContainer = document.createElement('div');
		tableScrollContainer.className = 'mrn-media-table-scroll';
		topScrollBar = document.createElement('div');
		topScrollBar.className = 'mrn-media-table-scrollbar';
		topScrollBar.tabIndex = 0;
		topScrollBar.setAttribute('role', 'region');
		topScrollBar.setAttribute('aria-label', config.i18n && config.i18n.horizontalScroll ? config.i18n.horizontalScroll : 'Media table horizontal scroll');
		topScrollSpacer = document.createElement('div');
		topScrollSpacer.className = 'mrn-media-table-scrollbar__spacer';
		topScrollBar.appendChild(topScrollSpacer);

		table.parentNode.insertBefore(topScrollBar, table);
		table.parentNode.insertBefore(tableScrollContainer, table);
		tableScrollContainer.appendChild(table);

		topScrollBar.addEventListener('scroll', function () {
			if (tableScrollContainer.scrollLeft !== topScrollBar.scrollLeft) {
				tableScrollContainer.scrollLeft = topScrollBar.scrollLeft;
			}
		});

		tableScrollContainer.addEventListener('scroll', function () {
			if (topScrollBar.scrollLeft !== tableScrollContainer.scrollLeft) {
				topScrollBar.scrollLeft = tableScrollContainer.scrollLeft;
			}
		});
	}

	function initializeColumnWidths() {
		var headers = table.querySelectorAll('thead tr:first-child > *');
		var headerMeasurements = Array.prototype.map.call(headers, function (header) {
			return {
				header: header,
				columnKey: getColumnKey(header),
				display: window.getComputedStyle(header).display,
				width: header.getBoundingClientRect().width,
			};
		});

		Array.prototype.forEach.call(headerMeasurements, function (measurement) {
			var header = measurement.header;
			var renderedWidth = measurement.width;

			if ('none' === measurement.display) {
				return;
			}

			if (header.classList.contains('check-column')) {
				var checkColumnWidth = renderedWidth > 0 ? renderedWidth : minimumWidth;
				fixedColumnWidth += checkColumnWidth;

				Array.prototype.forEach.call(table.querySelectorAll('.check-column'), function (cell) {
					cell.style.width = Math.round(checkColumnWidth) + 'px';
				});
				return;
			}

			var columnKey = measurement.columnKey;

			if (!columnKey) {
				fixedColumnWidth += renderedWidth > 0 ? renderedWidth : minimumWidth;
				return;
			}

			var savedWidth = Number(widths[columnKey]);
			var initialWidth = Number.isFinite(savedWidth) && savedWidth > 0 ? savedWidth : Math.max(renderedWidth, minimumWidth);

			visibleColumnKeys.push(columnKey);
			applyWidth(columnKey, initialWidth, false);
		});

		updateTableWidth();
	}

	function getMeasurementTable() {
		if (measurementTable) {
			return measurementTable;
		}

		measurementTable = document.createElement('table');
		measurementTable.className = table.className + ' mrn-media-column-autofit-measure';
		measurementTable.setAttribute('aria-hidden', 'true');
		measurementTable.innerHTML = '<tbody><tr></tr></tbody>';
		document.body.appendChild(measurementTable);

		return measurementTable;
	}

	function measureCellNaturalWidth(cell) {
		var measurementRow = getMeasurementTable().querySelector('tr');
		var clonedCell = cell.cloneNode(true);

		clonedCell.removeAttribute('id');
		clonedCell.removeAttribute('style');

		Array.prototype.forEach.call(clonedCell.querySelectorAll('[id]'), function (element) {
			element.removeAttribute('id');
		});

		Array.prototype.forEach.call(clonedCell.querySelectorAll('.mrn-media-column-resizer'), function (resizer) {
			resizer.remove();
		});

		measurementRow.textContent = '';
		measurementRow.appendChild(clonedCell);

		return clonedCell.getBoundingClientRect().width;
	}

	function getLargestComponentWidth(columnKey) {
		var largestWidth = minimumWidth;
		var cells = table.querySelectorAll('.column-' + columnKey);

		Array.prototype.forEach.call(cells, function (cell) {
			if (cell.getBoundingClientRect().width <= 0) {
				return;
			}

			largestWidth = Math.max(largestWidth, measureCellNaturalWidth(cell));
		});

		return clampWidth(largestWidth);
	}

	function fitColumnToContent(columnKey, handle) {
		var fittedWidth = applyBoundaryWidth(columnKey, getLargestComponentWidth(columnKey));
		handle.setAttribute('aria-valuenow', String(fittedWidth));
		scheduleSave();
	}

	function saveWidths() {
		if (!config.ajaxUrl || !config.nonce) {
			return;
		}

		var body = new URLSearchParams();
		body.set('action', 'mrn_media_save_column_widths');
		body.set('nonce', config.nonce);
		body.set('widths', JSON.stringify(widths));

		window.fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
			body: body.toString()
		}).catch(function () {
			// A failed preference save must not interrupt Media Library work.
		});
	}

	function scheduleSave() {
		window.clearTimeout(saveTimer);
		saveTimer = window.setTimeout(saveWidths, 250);
	}

	function addResizeHandle(header, options) {
		options = options || {};

		if (header.classList.contains('check-column') || (!options.tableEdge && header.querySelector('.mrn-media-column-resizer:not(.is-table-edge)'))) {
			return;
		}

		var headerColumnKey = getColumnKey(header);
		var headerColumnIndex = visibleColumnKeys.indexOf(headerColumnKey);
		var columnKey = options.columnKey || '';
		var resizedHeader = null;

		if (!headerColumnKey || (!options.tableEdge && headerColumnIndex <= 0)) {
			return;
		}

		if (!columnKey) {
			columnKey = visibleColumnKeys[headerColumnIndex - 1];
		}

		resizedHeader = table.querySelector('thead .column-' + columnKey);

		if (!resizedHeader) {
			return;
		}

		var handle = document.createElement('span');
		handle.className = 'mrn-media-column-resizer' + (options.tableEdge ? ' is-table-edge' : '');
		handle.setAttribute('data-mrn-resize-column', columnKey);
		handle.tabIndex = 0;
		handle.setAttribute('role', 'separator');
		handle.setAttribute('aria-orientation', 'vertical');
		handle.setAttribute('aria-label', (config.i18n && config.i18n.resizeColumn ? config.i18n.resizeColumn : 'Resize column') + ': ' + columnKey.replace(/[-_]+/g, ' '));
		handle.setAttribute('aria-valuemin', String(minimumWidth));
		handle.setAttribute('aria-valuemax', String(maximumWidth));
		handle.setAttribute('aria-valuenow', String(Math.round(getCurrentWidth(resizedHeader))));
		handle.title = options.tableEdge && config.i18n && config.i18n.resizeTableEdgeHelp
			? config.i18n.resizeTableEdgeHelp
			: (config.i18n && config.i18n.resizeHelp ? config.i18n.resizeHelp : 'Drag to resize. Double-click to fit content.');

		handle.addEventListener('pointerdown', function (event) {
			if (0 !== event.button || false === event.isPrimary) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();

			var startX = event.clientX;
			var startWidth = getCurrentWidth(resizedHeader);
			var pointerId = event.pointerId;
			var pendingX = startX;
			var animationFrame = null;
			var dragging = true;

			function renderWidth(clientX) {
				var newWidth = applyBoundaryWidth(columnKey, startWidth + clientX - startX);
				handle.setAttribute('aria-valuenow', String(newWidth));
			}

			function resize(pointerEvent) {
				if (!dragging || pointerId !== pointerEvent.pointerId) {
					return;
				}

				pendingX = pointerEvent.clientX;

				if (null !== animationFrame) {
					return;
				}

				animationFrame = window.requestAnimationFrame(function () {
					animationFrame = null;
					renderWidth(pendingX);
				});
			}

			function finish(pointerEvent) {
				if (!dragging || pointerId !== pointerEvent.pointerId) {
					return;
				}

				dragging = false;

				if (null !== animationFrame) {
					window.cancelAnimationFrame(animationFrame);
					animationFrame = null;
				}

				if ('pointerup' === pointerEvent.type) {
					pendingX = pointerEvent.clientX;
				}

				renderWidth(pendingX);
				document.removeEventListener('pointermove', resize);
				document.removeEventListener('pointerup', finish);
				document.removeEventListener('pointercancel', finish);
				handle.removeEventListener('lostpointercapture', finish);
				handle.classList.remove('is-resizing');
				document.body.classList.remove('mrn-media-column-resizing');

				if (handle.hasPointerCapture && handle.hasPointerCapture(pointerId)) {
					handle.releasePointerCapture(pointerId);
				}

				scheduleSave();
			}

			handle.classList.add('is-resizing');
			document.body.classList.add('mrn-media-column-resizing');
			document.addEventListener('pointermove', resize);
			document.addEventListener('pointerup', finish);
			document.addEventListener('pointercancel', finish);
			handle.addEventListener('lostpointercapture', finish);

			if (handle.setPointerCapture) {
				handle.setPointerCapture(pointerId);
			}
		});

		handle.addEventListener('click', function (event) {
			event.preventDefault();
			event.stopPropagation();
		});

		handle.addEventListener('dblclick', function (event) {
			event.preventDefault();
			event.stopPropagation();
			fitColumnToContent(columnKey, handle);
		});

		handle.addEventListener('keydown', function (event) {
			if ('Enter' === event.key) {
				event.preventDefault();
				event.stopPropagation();
				fitColumnToContent(columnKey, handle);
				return;
			}

			if ('ArrowLeft' !== event.key && 'ArrowRight' !== event.key) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();

			var direction = 'ArrowRight' === event.key ? 1 : -1;
			var increment = event.shiftKey ? 40 : 10;
			var newWidth = applyBoundaryWidth(columnKey, getCurrentWidth(resizedHeader) + direction * increment);
			handle.setAttribute('aria-valuenow', String(newWidth));
			scheduleSave();
		});

		header.appendChild(handle);
	}

	function addLastColumnEdgeHandle() {
		var lastColumnKey = visibleColumnKeys.length ? visibleColumnKeys[visibleColumnKeys.length - 1] : '';
		var lastHeader = lastColumnKey ? table.querySelector('thead .column-' + lastColumnKey) : null;

		if (lastHeader) {
			addResizeHandle(lastHeader, {
				columnKey: lastColumnKey,
				tableEdge: true,
			});
		}
	}

	function addColumnsButton() {
		var nativeButton = document.getElementById('show-settings-link');
		var target = document.querySelector('.tablenav.top .bulkactions');

		if (!nativeButton || !target || document.getElementById('mrn-media-columns-button')) {
			return;
		}

		var button = document.createElement('button');
		button.type = 'button';
		button.id = 'mrn-media-columns-button';
		button.className = 'button mrn-media-columns-button';
		button.textContent = config.i18n && config.i18n.columns ? config.i18n.columns : 'Columns';
		button.setAttribute('aria-controls', 'screen-options-wrap');
		button.setAttribute('aria-expanded', nativeButton.getAttribute('aria-expanded') || 'false');

		button.addEventListener('click', function () {
			nativeButton.click();
			window.requestAnimationFrame(function () {
				button.setAttribute('aria-expanded', nativeButton.getAttribute('aria-expanded') || 'false');
			});
		});

		target.appendChild(button);
	}

	addScrollContainer();
	initializeColumnWidths();
	Array.prototype.forEach.call(table.querySelectorAll('thead th'), function (header) {
		addResizeHandle(header);
	});
	addLastColumnEdgeHandle();
	addColumnsButton();
}());
