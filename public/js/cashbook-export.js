(function ($) {
	'use strict';

	function addHiddenField(form, name, value) {
		$('<input>', {
			type: 'hidden',
			name: name,
			value: value
		}).appendTo(form);
	}

	function exportPdf() {
		var options = $(this).data();
		var report = $('#report-print').clone();
		var modal = $('<div>', {
			id: 'cashbook-pdf-modal',
			class: 'modal fade',
			tabindex: '-1',
			role: 'dialog',
			'aria-label': 'Cash Book PDF preview'
		}).append(
			$('<div>', { class: 'modal-dialog', role: 'document' }).css('width', '90%').append(
				$('<div>', { class: 'modal-content' }).append(
					$('<button>', {
						type: 'button',
						class: 'close',
						'data-dismiss': 'modal',
						'aria-label': 'Close'
					}).append($('<span>', { 'aria-hidden': 'true' }).html('&times;')),
					$('<iframe>', {
						name: 'cashbookPdfFrame',
						title: 'Cash Book PDF preview'
					}).css({
						width: '100%',
						height: '80vh',
						border: 0
					})
				)
			)
		);

		report.find('a, input[type="hidden"]').remove();
		report.find('table').addClass('table-condensed');
		$('#cashbook-pdf-modal, #cashbook-pdf-form').remove();
		$('body').append(modal);

		var form = $('<form>', {
			id: 'cashbook-pdf-form',
			method: 'post',
			action: options.pdfUrl,
			target: 'cashbookPdfFrame'
		}).css('display', 'none');
		addHiddenField(form, 'dom', report.html());
		addHiddenField(form, 'title', document.title);
		addHiddenField(form, 'orentation', 'P');
		addHiddenField(form, 'size', 'A4');
		$('body').append(form);

		form[0].submit();
		modal.modal('show');
	}

	function exportExcel() {
		var table = document.querySelector('#table2excel');
		if (!table) {
			console.error('Cash Book table was not found; Excel export was cancelled.');
			return;
		}

		var reportTable = table.cloneNode(true);
		reportTable.querySelectorAll('.noExl').forEach(function (element) {
			element.remove();
		});
		var filename = [
			'CashBook',
			$('#loc').val(),
			'(from_' + $('form input[name="start_date"]').first().val() +
				'_to_' + $('form input[name="end_date"]').first().val() + ')'
		].join('_').replace(/[\\/:*?"<>|]/g, '-');
		var content = '<html><head><meta charset="UTF-8"></head><body>' +
			reportTable.outerHTML + '</body></html>';
		var blob = new Blob(['\ufeff', content], {
			type: 'application/vnd.ms-excel;charset=utf-8'
		});
		var url = window.URL.createObjectURL(blob);
		var link = document.createElement('a');
		link.href = url;
		link.download = filename + '.xls';
		document.body.appendChild(link);
		link.click();
		link.remove();
		window.setTimeout(function () {
			window.URL.revokeObjectURL(url);
		}, 1000);
	}

	$(function () {
		$('#print').on('click', function (event) {
			event.preventDefault();
			exportPdf.call(this);
		});
		$('#excel').on('click', function (event) {
			event.preventDefault();
			exportExcel();
		});
	});
}(jQuery));
