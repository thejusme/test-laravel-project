<?php

use App\Models\Person;

require_once __DIR__ . '/../../../resources/lib/db.class.php';
require_once __DIR__ . '/../../../resources/lib/functions.php';

$db = new db(env('DB_HOST'), env('DB_USERNAME'), env('DB_PASSWORD'), env('DB_DATABASE'));

if (!empty($_REQUEST['people']))
{
    // Initialize for safety
    $errors = [];
    $classes = [];

    foreach (array_keys($_REQUEST['people']) as $id)
    {
        $classes["person[$id][first_name]"] = 'required';
        $classes["person[$id][last_name]"] = 'required';
        $classes["person[$id][email]"] = 'required format-email';
    }

    // Normally this would hit the same logic as when the form is submitted to ensure that the data is validated on both the front-end and back-end but I couldn't get the curl call working with Laravel
    // I'm thinking maybe the simple artisan server can only handle one connection at a time or something so making a synchronous curl call to the same server is causing both connections to hang
    //check_form($classes, $errors);

    if (empty($errors))
    {
        foreach ($_REQUEST['people'] as $id => $personData)
        {
            if (!is_numeric($id))
            {
                try
                {
                    $person = new Person($db, ['id' => $id]);
                    $person->first_name = $personData['first_name'];
                    $person->last_name = $personData['last_name'];
                    $person->phone = $personData['phone'];
                    $person->email = $personData['email'];
                }
                catch (Exception $ex) { }
            }
            else
            {
                $person = new Person($db, NULL, $personData['first_name'], $personData['last_name'], $personData['phone'], $personData['email']);
            }

            try
            {
                $person->save();
            }
            catch (Exception $ex) { }
        }
    }
}

if (!empty($_REQUEST['del']))
{
    $success = $db->query("DELETE FROM people WHERE id = '" . $db->escape_string($_REQUEST['del']) . "'");
    $error = (!$success ? 'Person could not be deleted!' : '');
    header('Content-type: application/json');
    die(json_encode(['success' => $success, 'error' => $error]));
}

$people = $db->query("SELECT * FROM people ORDER BY last_name ASC");

?>
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>CRUD App</title>
        <style type="text/css">
        body
        {
        	font-family: sans-serif;
        }

        table.withborder
        {
        	border: 0;
        	border-collapse: collapse;
        }

        table.withborder td, table.withborder th
        {
        	border: 1px solid #000;
        	padding: 3px;
        }

        table tr:nth-child(even)
        {
        	background-color: #ddd;
        }

        .has-error
        {
        	border: 1px solid red;
        }
        </style>
        <script src="https://code.jquery.com/jquery-3.6.4.min.js" integrity="sha256-oP6HI9z1XaZNBrJURtCoUT5SUnxFr8s3BzRl+cbzUq8=" crossorigin="anonymous"></script>
        <script>

        var newPeople = 0;

        addPerson = function()
        {
			$('#table').append('<tr>' + getPersonHTML(newPeople++) + '</tr>');
        }

        checkAjaxResponse = function(jqXHR, form)
        {
            // Successful request/response
            if (typeof jqXHR.responseJSON.errors == 'undefined' || $.isEmptyObject(jqXHR.responseJSON.errors))
            {
                if (typeof ajax_success_callback == 'function')
                    ajax_success_callback(jqXHR);

                var ret = true;
            }
            // Unsuccessful request or back-end errors returned
            else
            {
                if (typeof jqXHR.responseJSON.errors !== 'undefined')
                {
                    // Highlight fields with issues
                    $.each(jqXHR.responseJSON.errors, function (key, value)
                    {
                        // Numeric key: array(0 => 'field_name1', 1 => 'field_name2', ...)
                        if (typeof key == 'number' || (typeof key == 'string' && key.match(/^\d+$/)))
                        {
                            var field_name = value;
                            var field_message = '';
                        }
                        // Non-numeric key: array('field_name1' => 'field_message1', 'field_name2' => 'field_message2', ...)
                        else
                        {
                            var field_name = key;
                            var field_message = value;
                        }

                        var field = $(form).find('[name="' + field_name + '"]');
                        if (field.length > 0)
                        {
                            if (field_message != '')
                                field.after('<span class="form-message" style="color: red; font-weight: bold; padding-left: 5px;">' + field_message + '</span>');

        					if (field_name != 'grecaptcha')
                            	field.addClass('has-error');
                        }
                    });
                }

                if (typeof ajax_error_callback == 'function')
                    ajax_error_callback(jqXHR);

                var ret = false;
            }

            return ret;
        }

        checkForm = function(form, submit_form, callback_func)
        {
            checkFormData = undefined;

            $(form).find('.has-error').removeClass('has-error');

            var inputs = $(form).find(':input').not(':disabled');
            // Initialize for safety
            var data = [];
            inputs.each(function (i, elem)
            {
                var value = $(elem).val();

                if ((elem.type == 'checkbox' || elem.type == 'radio') && !$(elem).is(':checked'))
                    value = '';

                // Populate value with tinymce content because it seems to happen after this point which isn't helpful for input validation
                try
                {
                    if ($(elem).attr('id') && $('*[data-id="' + $(elem).attr('id') + '"]', $('iframe').contents()).length > 0)
                        value = $('*[data-id="' + $(elem).attr('id') + '"]', $('iframe').contents()).html();
                }
                catch (err)
                {
                }

                if ($(elem).hasClass('format-amount'))
                {
                    value = value.replace(/[^0-9.-]/g, '');
                    $(elem).val(value);
                }

                if ($(elem).hasClass('windows1252'))
                {
                    value = value.replace(/[\u0000-\u001F\u007F-\u00A0\u00AD\u0100-\uFFFF]/g, '');
                    $(elem).val(value);
                }

                data.push({name: $(elem).attr('name'), value: value, className: $(elem).attr('class')});
            });

            $.ajax({
                url: '/check-form',
                type: 'POST',
                data: {data: JSON.stringify(data)},
                dataType: 'json',
                complete: function(jqXHR, textStatus)
                {
                    checkFormData = jqXHR;

                    var ret = checkAjaxResponse(jqXHR, form);

                    if (typeof window[callback_func] == 'function')
                        window[callback_func](jqXHR, ret);

                    if (typeof check_form_callback == 'function')
                        check_form_callback(jqXHR, ret);

                    if (ret && (typeof submit_form == 'undefined' || submit_form))
                        form.submit();
                }
            });

            return false;
        }

        delPerson = function(id)
        {
			if (confirm('Are you want to delete this person?'))
			{
				$.ajax({
					url: '?del=' + id,
					dataType: 'json',
					success: function (data, textStatus, jqXHR)
					{
						if (data.success)
							$('#' + id).remove();
						else
							alert(data.error);
					},
					error: function (jqXHR, textStatus, errorThrown)
					{
						alert(errorThrown);
					}
				});
			}
        }

        editPerson = function(id)
        {
            var first_name = $('#' + id + ' .first-name').html();
            var last_name = $('#' + id + ' .last-name').html();
            var phone = $('#' + id + ' .phone').html();
            var email = $('#' + id + ' .email').html();

			$('#' + id).html(getPersonHTML(id));
			$('input[name="people\\[' + id + '\\]\\[first_name\\]"]').val(first_name);
			$('input[name="people\\[' + id + '\\]\\[last_name\\]"]').val(last_name);
			$('input[name="people\\[' + id + '\\]\\[phone\\]"]').val(phone);
			$('input[name="people\\[' + id + '\\]\\[email\\]"]').val(email);
        }

        getPersonHTML = function(id)
        {
			return '<td></td><td><input type="text" name="people[' + id + '][first_name]" class="required" placeholder="First Name"></td><td><input type="text" name="people[' + id + '][last_name]" class="required" placeholder="Last Name"></td><td><input type="text" name="people[' + id + '][phone]" placeholder="Phone"></td><td><input type="text" name="people[' + id + '][email]" class="required format-email" placeholder="Email"></td>';
        }

        </script>
    </head>
    <body class="antialiased">
    <form action="?" method="post" onsubmit="return checkForm(this);">
    @csrf
    <table class='withborder'>
   	<thead><tr><th></th><th>First</th><th>Last</th><th>Phone</th><th>Email</th></tr></thead>
   	<tbody id="table">
	@forelse ($people as $person)
		<tr id="{{ $person['id'] }}"><td><a href="javascript:void(0)" onclick="editPerson('{{ $person['id'] }}')">Edit</a>&nbsp;<a href="javascript:void(0)" onclick="delPerson('{{ $person['id'] }}')">Delete</a></td><td class="first-name">{{ $person['first_name'] }}</td><td class="last-name">{{ $person['last_name'] }}</td><td class="phone">{{ $person['phone'] }}</td><td class="email">{{ $person['email'] }}</td></tr>
	@empty
		<tr colspan=5><td>No people exist yet.</td></tr>
	@endforelse
	</tbody>
	</table>
	<div style="height: 20px;"></div>
	<input type="button" value="Add Person" onclick="addPerson()">&nbsp;<input type="submit" value="Save">
	</form>
    </body>
</html>