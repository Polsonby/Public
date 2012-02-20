var contacts = {

	init : function(settings) {
		contacts.hideAllOptions();
	},
	
	hideAllOptions : function () {
		$('.hide').hide();
	},
	
	sendPropertyForm : function(e) {
		e.preventDefault();
		$this = $(this);
		$form = $this.closest('form');
		if ($form.find('input[name="value"]').val() != '') {
			$.post($form.attr('action'), $form.serialize(), function (result) {
				contacts.refreshList($this, result);
			});
		}
	},
	
	updateList : function(e) {
		var contactID = getIDValue($(this).attr('id'));
		var propertyIDs = $(this).sortable('serialize');
		$.post('/contacts/contacts/update-properties/id/' + contactID, propertyIDs, function (result) {
			console.log('sent');
			contacts.refreshList($this, result);
		});

	},
	
	refreshList : function($this, result) {
		$list = $this.closest('ul');
		$list.replaceWith(result);
		contacts.hideAllOptions();
		contacts.sortable();
	}, 
	
	
	showOptions : function () {
		$this = $(this);
		$this.find('.hide').show();
	},
	
	hideOptions : function () {
		$this = $(this);
		$this.find('.hide').hide();
	}
	
}

$(document).ready(contacts.init);



