<?php

require_once __DIR__ . '/../../resources/lib/constants.php';

// $arr = array('key' => 'value'), usually $_POST
// $classes = array('key' => 'class1 class2 ...')
function build_check_form_data($arr, $classes, &$data, $prefix = '', $suffix = '')
{
    foreach ($arr as $key => $value)
    {
        if (is_array($value))
            build_check_form_data($value, $classes, $data, $prefix . $key . $suffix . '[', ']');
        else
            $data[] = array('name' => $prefix . $key . $suffix, 'value' => $value, 'className' => (isset($classes[$key]) ? $classes[$key] : ''));
    }
}

function build_cookies()
{
    // Initialize for safety
    $str = '';
    foreach ($_COOKIE as $key => $value)
        $str .= "$key=" . rawurlencode($value) . '; ';
    return $str;
}

function check_form($classes, &$errors)
{
    // Initialize for safety
    if (!is_array($errors))
        $errors = array();
    $data = array();
    build_check_form_data($_POST, $classes, $data);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://192.168.0.1:8000/check-form');
    curl_setopt($ch, CURLOPT_COOKIE, build_cookies());
    curl_setopt($ch, CURLOPT_POSTFIELDS, array('data' => json_encode($data)));
    curl_setopt($ch, CURLOPT_REFERER, 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $ret = curl_exec($ch);

    if ($ret !== false && ($json = json_decode($ret)) !== NULL)
        $errors = array_merge($errors, (array)$json->errors);
    else
        $errors['generic'] = 'Form data could not be validated. Please try submitting the form again.';

    return empty($errors);
}

function generate_random_str($num_chars, $allowed_chars = BASE_53_CHARS)
{
    $bad_words = array('ZnVjaw==', 'c2hpdA==', 'Y3VudA==', 'Y29jaw==', 'cHVzc3k=', 'ZmFn', 'bmlnZ2Vy', 'ZGljaw==', 'Yml0Y2g=');
    foreach ($bad_words as &$bad_word)
        $bad_word = base64_decode($bad_word);
    // Unset for safety
    unset($bad_word);

    do
    {
        // Initialize for safety
        $str = '';
        for ($i = 1; $i <= $num_chars; $i++)
            $str .= $allowed_chars[mt_rand(0, strlen($allowed_chars)-1)];
    } while (in_array_substr($str, $bad_words));

    return $str;
}

function in_array_substr($str, $arr)
{
    if (is_array($arr))
        foreach ($arr as $value)
            if (stripos($str, $value) !== false)
                return true;
    return false;
}

function strip_tags_and_trim($str)
{
    return trim(preg_replace('/\s+/', ' ', strip_tags((string)$str)));
}

?>