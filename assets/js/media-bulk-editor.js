(function () {
	'use strict';

	function initializeEditor() {
		var form = document.getElementById('mrn-media-bulk-editor-form');
		var config = window.MRNMediaBulkEditor || {};
		var i18n = config.i18n || {};

		if (!form) {
			return;
		}

		var rows = Array.prototype.slice.call(form.querySelectorAll('.mrn-media-bulk-editor-row'));
		var selectAll = document.getElementById('mrn-media-bulk-select-all');
		var isSubmitting = false;

		function rowIsDirty(row) {
			return Array.prototype.some.call(row.querySelectorAll('.mrn-media-edit-field'), function (field) {
				return field.value !== field.getAttribute('data-initial-value');
			});
		}

		function updateSummary() {
			var editableCheckboxes = rows.map(function (row) {
				return row.querySelector('.mrn-media-row-select');
			}).filter(function (checkbox) {
				return checkbox && !checkbox.disabled;
			});
			var selectedCount = editableCheckboxes.filter(function (checkbox) {
				return checkbox.checked;
			}).length;
			var summary = selectedCount === 1
				? (i18n.oneSelected || '1 row selected')
				: (i18n.manySelected || '%d rows selected').replace('%d', selectedCount);

			form.querySelectorAll('.mrn-media-bulk-editor-selection').forEach(function (element) {
				element.textContent = selectedCount ? summary : (i18n.noneSelected || 'No rows selected');
			});

			form.querySelectorAll('.mrn-media-bulk-editor-save').forEach(function (button) {
				button.disabled = selectedCount === 0;
			});

			if (selectAll) {
				selectAll.checked = editableCheckboxes.length > 0 && selectedCount === editableCheckboxes.length;
				selectAll.indeterminate = selectedCount > 0 && selectedCount < editableCheckboxes.length;
			}
		}

		function updateRow(row) {
			var checkbox = row.querySelector('.mrn-media-row-select');
			var status = row.querySelector('.mrn-media-row-status');
			var dirty = rowIsDirty(row);

			row.classList.toggle('is-dirty', dirty);

			if (dirty && checkbox && !checkbox.disabled) {
				if (!checkbox.checked) {
					checkbox.checked = true;
					checkbox.setAttribute('data-auto-selected', 'true');
				}
			} else if (!dirty && checkbox && checkbox.getAttribute('data-auto-selected') === 'true') {
				checkbox.checked = false;
				checkbox.removeAttribute('data-auto-selected');
			}

			if (status && checkbox && !checkbox.disabled) {
				status.textContent = dirty ? (i18n.unsaved || 'Unsaved changes') : (i18n.saved || 'Saved');
			}

			updateSummary();
		}

		rows.forEach(function (row) {
			row.querySelectorAll('.mrn-media-edit-field').forEach(function (field) {
				field.setAttribute('data-initial-value', field.value);
				field.addEventListener('input', function () {
					updateRow(row);
				});
				field.addEventListener('change', function () {
					updateRow(row);
				});
			});

			var checkbox = row.querySelector('.mrn-media-row-select');
			if (checkbox) {
				checkbox.addEventListener('change', function () {
					checkbox.removeAttribute('data-auto-selected');
					updateSummary();
				});
			}
		});

		if (selectAll) {
			selectAll.addEventListener('change', function () {
				rows.forEach(function (row) {
					var checkbox = row.querySelector('.mrn-media-row-select');
					if (checkbox && !checkbox.disabled) {
						checkbox.checked = selectAll.checked;
						checkbox.removeAttribute('data-auto-selected');
					}
				});
				updateSummary();
			});
		}

		form.addEventListener('submit', function () {
			isSubmitting = true;
		});

		window.addEventListener('beforeunload', function (event) {
			if (isSubmitting || !rows.some(rowIsDirty)) {
				return;
			}

			event.preventDefault();
			event.returnValue = '';
		});

		updateSummary();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initializeEditor);
	} else {
		initializeEditor();
	}
}());
