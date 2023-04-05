<?php

define('EMAIL_REGEX', "/^[a-z0-9.!#$%&'*+-\/=?^_`{|}~]+@[a-z0-9-]+(?:\.[a-z0-9-]+)+$/i");

function get_input_value($name, $inputs)
{
    foreach ($inputs as $input)
        if (isset($input->name) && $input->name == $name)
            return $input->value;
            return NULL;
}

// Initialize for safety
$errors = array();
$inputs = (isset($_REQUEST['data']) ? (array)json_decode($_REQUEST['data']) : array());

foreach ($inputs as $input)
{
    if (!isset($input->name) || !isset($input->value) || !isset($input->className) || is_array($input->value))
        continue;

    $classes = explode(' ', $input->className);

    // Initialize for safety
    $is_ok = true;

    foreach ($classes as $class)
    {
        switch ($class)
        {
            case 'required':
                if (trim($input->value) == '')
                    $is_ok = false;
                    break;
            case 'format-date':
                if (trim($input->value) != '' && !is_date($input->value))
                    $is_ok = false;
                    break;
            case 'format-email':
                if (trim($input->value) != '' && !preg_match(EMAIL_REGEX, $input->value))
                    $is_ok = false;
                    break;
            case 'format-amount':
                if (trim($input->value) != '' && !is_numeric($input->value))
                    $is_ok = false;
                    break;
            case 'format-url':
                if (trim($input->value) != '' && !preg_match('/^https:\/\/.+/i', $input->value))
                    $is_ok = false;
                    break;
            case 'format-url-unsecure':
                if (trim($input->value) != '' && !preg_match('/^https?:\/\/.+/i', $input->value))
                    $is_ok = false;
                    break;
            case 'format-wholenum':
                if (trim($input->value) != '' && !preg_match('/^[\d,]+$/', $input->value))
                    $is_ok = false;
                    break;
            case 'format-hex':
                if (trim($input->value) != '' && !preg_match('/^[0-9a-f]+$/i', $input->value))
                    $is_ok = false;
                    break;
            case 'format-html':
                // Work with temporary variable since html_validate() fixes the input
                $temp = $input->value;
                if (!html_validate($temp))
                {
                    $errors[$input->name] = 'Invalid HTML. Please fix your markup to continue.';
                    $is_ok = false;
                }
                break;
            case 'noscript':
                if (preg_match('/<script/i', $input->value))
                    $is_ok = false;
                    break;
        }
    }

    if (!$is_ok)
        $errors[] = $input->name;
}

// Custom input validation goes here

header('Content-type: application/json');
die(json_encode(['errors' => $errors]));

?>