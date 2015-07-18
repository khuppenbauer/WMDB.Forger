$(document).ready(function () {
	var $ticketType = $('.js-changetypes button'),
		$ticketField = $('#ticketid'),
		$titleField = $('#title'),
		$generateButton = $('#generate'),
		$modal = $('.modal'),
		$activeChangeType = null,
		$lastActiveTextarea = null,
		$flashmessageContainer = $('.flashmessage-container');

	$('[data-toggle="tooltip"]').tooltip();

	$('textarea').on('focus', function () {
		$lastActiveTextarea = $(this);
	});

	// build text-to-tickettype tree
	var typeTextTree = {},
		$ticketTypeRelatedFields = $('[data-tickettypes]');

	$ticketTypeRelatedFields.each(function () {
		var $textfield = $(this),
			types = $textfield.data('tickettypes').split(' ');

		$.each(types, function (_, type) {
			if (typeof typeTextTree[type] === 'undefined') {
				typeTextTree[type] = [];
			}
			typeTextTree[type].push($textfield);
		});
	});

	$ticketType.on('click', function () {
		var $me = $(this),
			value = $me.val();

		$('#no-type-selected-hint').slideUp();

		$ticketType.removeClass('active').find('i.fa').remove();
		$me.addClass('active').prepend('<i class="fa fa-check"></i>');

		$activeChangeType = $me;
		$ticketTypeRelatedFields.addClass('hidden');

		if (typeof typeTextTree[value] !== 'undefined') {
			$generateButton.removeClass('hidden');
			$.each(typeTextTree[value], function (_, $affectedTicketTypeField) {
				$affectedTicketTypeField.removeClass('hidden');
			});
		} else {
			$generateButton.addClass('hidden');
		}
	});

	$('[data-action=load-ticket]').on('click', function () {
		var $trigger = $(this),
			$icon = $trigger.find('.fa'),
			ticketId = parseInt($ticketField.val().replace(/\D/g, ''));

		if (isNaN(ticketId)) {
			return;
		}

		$.ajax({
			url: 'getissuejson',
			data: {
				issueId: ticketId
			},
			dataType: 'json',
			beforeSend: function() {
				$ticketField.attr('disabled', 'disabled');
				$trigger.attr('disabled', 'disabled');
				$icon.addClass('fa-spin');
			},
			success: function(issue) {
				if (typeof issue.error !== 'undefined') {
					$flashmessageContainer.append(
						$('<div/>', {class: 'alert alert-danger alert-dismissible fade in', role: 'alert'}).append(
							$('<button/>', {type: 'button', class: 'close', 'data-dismiss': 'alert', 'aria-label': 'Close'}).append(
								$('<span/>', {'aria-hidden': 'true'}).html('&times;')
							),
							$('<strong/>').text(issue.error)
						).fadeIn()
					);
				} else {
					$titleField.val(issue.subject);
					$('#description').val(issue.description);
				}
			},
			complete: function() {
				$ticketField.removeAttr('disabled');
				$trigger.removeAttr('disabled');
				$icon.removeClass('fa-spin');
			}
		})
	});

	$('[data-action=paste-code]').on('click', function () {
		if ($lastActiveTextarea === null) {
			alert('Please activate a textarea first');
			return;
		}
		var snippet = $(this).parent().text();
		if (document.selection) {
			$lastActiveTextarea.focus();
			var sel = document.selection.createRange();
			sel.text = snippet;
		} else if ($lastActiveTextarea.prop('selectionStart') || $lastActiveTextarea.prop('selectionStart') === 0) {
			var startPos = $lastActiveTextarea.prop('selectionStart'),
				endPos = $lastActiveTextarea.prop('selectionEnd'),
				fieldValue = $lastActiveTextarea.val();

			$lastActiveTextarea.val(fieldValue.substring(0, startPos) + snippet + fieldValue.substring(endPos, fieldValue.length));
		} else {
			$lastActiveTextarea.val($lastActiveTextarea.val() + snippet);
		}
	});

	// Do the serious stuff here!
	$generateButton.on('click', function () {
		var ticketId = $ticketField.val().replace(/\D/g, ''),
			title = $.trim($titleField.val()),
			ticketType = $activeChangeType.text(),
			headline = sprintf('%s: #%d - %s', ticketType, ticketId, title),
			filename = sprintf('%s-%d-%s', ticketType, ticketId, title.toUpperCamelCase().replace(/[^0-9a-z-_]/gi, '')) + '.rst';

		var restContent = sprintf('%s\n%s\n%s', '='.repeat(headline.length), headline, '='.repeat(headline.length)),
			restBody = '';

		$('textarea:visible').each(function () {
			var $me = $(this),
				$label = $me.parents('.form-group').find('label'),
				value = $.trim($me.val());

			if (value.length > 0) {
				var labelText = $.trim($label.text());
				restBody += sprintf('\n\n\n%s\n%s\n\n%s', labelText, '='.repeat(labelText.length), value);
			}
		});
		restContent += restBody.replace('\n', '');

		$modal.find('.modal-title').text(filename);
		$modal.find('.modal-body textarea').val(restContent);

		$('.modal').modal({
			backdrop: 'static'
		});
	});

	$modal.on('shown.bs.modal', function() {
		$modal.find('textarea').focus().select();
	});
});

String.prototype.toUpperCamelCase = function () {
	return this.replace(/\w\S*/g, function (txt) {
		return txt.charAt(0).toUpperCase() + txt.substr(1);
	}).replace(/\s/g, '');
};

String.prototype.repeat = function (num) {
	return new Array(num + 1).join(this);
};
