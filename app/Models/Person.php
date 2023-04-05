<?php

namespace App\Models;

require_once __DIR__ . '/../../resources/lib/db.class.php';
require_once __DIR__ . '/../../resources/lib/dbtable.class.php';
require_once __DIR__ . '/../../resources/lib/functions.php';


class Person extends \dbtable
{
    const TABLE_NAME = 'people';
    const LOG_OBJECT_NAME = 'Person';
    const LOG_KEY = 'id';

    // Properties without a default value won't get reset whenever $this->reset() is called

    // !!! ALL `people` FIELDS SHOULD BE LISTED HERE WITH AN APPROPRIATE DEFAULT VALUE !!!
    protected   $id = '';
    public      $first_name = '';
    public      $last_name = '';
    public      $phone = '';
    public      $email = '';

    public function __construct(\db $db, $primary_key = NULL, $first_name = NULL, $last_name = NULL, $phone = NULL, $email = NULL)
    {
        parent::__construct($db);

        // Loading an existing record
        if (!empty($primary_key))
            $this->load_by_unique_key($primary_key);
        else
        {
            if (($first_name = strip_tags_and_trim($first_name)) != '' && ($last_name = strip_tags_and_trim($last_name)) != '' && ($phone = strip_tags_and_trim($phone)) != '' && ($email = strip_tags_and_trim($email)) != '')
            {
                $this->first_name = $first_name;
                $this->last_name = $last_name;
                $this->phone = $phone;
                $this->email = $email;
            }
            else
                throw new Exception(get_class($this) . '::' . __FUNCTION__ . '(): Programmer Error! Either unique key or (first_name, last_name, phone and email) must be passed!');
        }
    }

    protected function _post_load($initial_load = true)
    {
        // This is where any post-load action should be performed
    }

    protected function _post_save($log_changes)
    {
        if (empty($this->initial_data['id']))
        {
            $this->id = $this->new_initial_data['id'] = $this->db->insert_id;
            //if ($log_changes)
              //  log_event($this->db, $this->client_id, 0, "New Person ($this->id) was created", client::LOG_OBJECT_NAME, NULL, NULL, NULL, (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : NULL), (isset($_SESSION['real_user_id']) ? $_SESSION['real_user_id'] : NULL));
        }

        $this->_post_load(false);
    }

    protected function _pre_save($table)
    {
        if (empty($this->initial_data['id']))
            $this->id = generate_random_str(8);
        return true;
    }

    protected function _sanitize()
    {
        $this->first_name = strip_tags_and_trim($this->first_name);
        $this->last_name = strip_tags_and_trim($this->last_name);
        $this->phone = strip_tags_and_trim($this->phone);
        $this->email = strip_tags_and_trim($this->email);
    }
}

?>