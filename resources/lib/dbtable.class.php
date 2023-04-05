<?php

// !!! Every database field must be defined in the child class; new properties can be added to classes before database fields are added

require_once __DIR__ . '/../../resources/lib/db.class.php';

abstract class dbtable
{
    // Properties without a default value won't get reset whenever $this->reset() is called

    public    $db_name = 'test';
    protected $db;
    protected $dont_log = array();      // Child classes can add property names to this array to avoid logging changes for those properties
    protected $log_simple = array();    // Child classes can add property names to this array to log only that a change has been made in the property, but not the old and new values (e.g. for passwords)
    protected $initial_data = array();
    protected $new_initial_data = array();
    protected $primary_table;
    protected $primary_key;
    protected $unique_key;
    private   $sanitized = false;
    private   $tables_columns;

    public function __construct(\db $db)
    {
        $this->db = $db;
    }

    public function __clone()
    {
        $this->unique_key = NULL;
    }

    // This makes non-public properties read-only-accessible from outside the class for convenience
    public function __get($property_name)
    {
        return $this->$property_name;
    }

    // This makes isset() and empty() work for non-public properties
    public function __isset($property_name)
    {
        return isset($this->$property_name);
    }

    public function get_log_object_id()
    {
        if (defined('static::LOG_PARENT_OBJECT'))
        {
            $parent_obj = static::LOG_PARENT_OBJECT;
            return $this->$parent_obj->get_log_object_id();
        }
        elseif (defined('static::LOG_KEY'))
        {
            $log_keys = (array)static::LOG_KEY;
            // Initialize for safety
            $object_id = array();
            foreach ($log_keys as $log_key)
                $object_id[$log_key] = $this->$log_key;
            return $object_id;
        }
        else
        {
            trigger_error(get_class($this) . '::' . __FUNCTION__ . '() failed because neither ' . get_class($this) . '::_get_log_data() nor ' . get_class($this) . '::LOG_KEY exist!', E_USER_WARNING);
            return NULL;
        }
    }

    public function get_log_child_object_id()
    {
        if (defined('static::LOG_CHILD_KEY'))
        {
            $log_keys = (array)static::LOG_CHILD_KEY;
            // Initialize for safety
            $child_object_id = array();
            foreach ($log_keys as $log_key)
                $child_object_id[$log_key] = $this->$log_key;
            return $child_object_id;
        }
        else
            return NULL;
    }

    // $unique_key = array('unique_key_1' => 'value_1', ...)
    public function load_by_unique_key($unique_key, $row = NULL)
    {
        $this->unique_key = $unique_key;
        if (empty($row))
            $row = $this->_load_by_unique_key();
        $this->initial_data = $row;
        $this->_set_properties($row);

        if (method_exists($this, '_post_load'))
            $this->_post_load();
    }

    public function reload()
    {
        if (!empty($this->unique_key))
            $this->load_by_unique_key($this->unique_key);
        else
            $this->reset();
    }

    public function reset()
    {
        $arr = get_class_vars(get_class($this));

        // Don't reset properties without a default value by rule; this is useful for properties like DB connections
        foreach ($arr as $property_name => $property_value)
            if (is_null($property_value))
                unset($arr[$property_name]);

        $this->_set_properties($arr);
    }

    public function save($log_changes = true, $permission = NULL, $page_perm = NULL, $log_msg = '', $force_save_all_fields = false)
    {
        $tables = (array)static::TABLE_NAME;

        if (is_null($this->primary_table))
            $this->primary_table = $tables[0];

        if (empty($this->tables_columns))
        {
            $result = $this->db->query("SELECT `TABLE_NAME`, `COLUMN_NAME`, `COLUMN_KEY`, `EXTRA` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = '$this->db_name' AND `TABLE_NAME` IN ('" . implode("', '", $tables) . "')");
            if ($result)
            {
                // Initialize for safety
                $this->primary_key = array();
                $this->tables_columns = array();
                while ($row = $result->fetch_assoc())
                {
                    if (!isset($this->tables_columns[$row['TABLE_NAME']]))
                        $this->tables_columns[$row['TABLE_NAME']] = array();
                    $this->tables_columns[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];

                    if (!isset($this->primary_key[$row['TABLE_NAME']]))
                        $this->primary_key[$row['TABLE_NAME']] = array();
                    if ($row['COLUMN_KEY'] == 'PRI')
                        $this->primary_key[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
                }
            }
            else
                return false;
        }

        if (method_exists($this, '_sanitize') && !$this->sanitized)
        {
            $this->_sanitize();
            $this->sanitized = true;
        }

        // Initialize for safety
        $this->new_initial_data = array();

        // Start transaction
        $this->db->autocommit(false);

        foreach ($tables as $table)
        {
            // Initialize for safety
            $i = 0;

            do
            {
                if (method_exists($this, '_pre_save'))
                {
                    if (!$this->_pre_save($table))
                    {
                        $this->db->rollback();
                        $this->db->autocommit(true);
                        return false;
                    }
                }

                if (is_null($this->unique_key))
                {
                    // Initialize for safety
                    $query = '';
                    $query2 = '';
                    foreach ($this->tables_columns[$table] as $property_name)
                    {
                        $query .= "`$property_name`,\n";
                        $query2 .= "'" . $this->db->escape_string($this->$property_name) . "',\n";
                        $this->new_initial_data[$property_name] = $this->$property_name;
                    }
                    $query = substr($query, 0, -2);
                    $query2 = substr($query2, 0, -2);

                    $query = "INSERT INTO `$this->db_name`.`$table` (\n$query)\nVALUES (\n$query2)";
                }
                else
                {
                    // Initialize for safety
                    $query = '';
                    foreach ($this->tables_columns[$table] as $property_name)
                    {
                        if ((isset($this->initial_data[$property_name]) || array_key_exists($property_name, $this->initial_data)) && ($this->$property_name != $this->initial_data[$property_name] || $force_save_all_fields))
                            $query .= "`$property_name` = '" . $this->db->escape_string($this->$property_name) . "', ";
                        $this->new_initial_data[$property_name] = $this->$property_name;
                    }
                    // Break if there are no columns to update
                    if ($query == '')
                    {
                        $result = true;
                        break;
                    }
                    $query = substr($query, 0, -2);
                    // Initialize for safety
                    $query2 = '';
                    foreach ($this->primary_key[$table] as $property_name)
                        $query2 .= "`$property_name` = '" . $this->db->escape_string($this->$property_name) . "' AND ";
                    $query2 = substr($query2, 0, -5);
                    $query = "UPDATE `$this->db_name`.`$table` SET $query WHERE $query2";
                }

                //print "$query\n";

                $result = $this->db->query($query);
                $i++;
            }
            while ($this->db->errno == 1062 && $i <= 10);

            if ($result)
            {
                if (method_exists($this, '_post_save_each'))
                    $this->_post_save_each($table);

                if ($log_changes && !is_null($this->unique_key))
                    $this->_log_changes($table, $permission, $page_perm, $log_msg);
            }
            else
            {
                $this->db->rollback();
                $this->db->autocommit(true);
                return false;
            }
        }

        if (method_exists($this, '_post_save'))
            $this->_post_save($log_changes);

        if (is_null($this->unique_key))
        {
            // Initialize for safety
            $this->unique_key = array();
            foreach ($this->primary_key[$this->primary_table] as $property_name)
                $this->unique_key[$property_name] = $this->$property_name;
        }
        $this->initial_data = $this->new_initial_data;
        $this->sanitized = false;

        $this->db->commit();
        $this->db->autocommit(true);
        return true;
    }

    public function to_array()
    {
        return get_object_vars($this);
    }

    protected function _log_changes($table, $permission, $page_perm, $log_msg)
    {
        // Initialize for safety
        $changes = array();
        foreach ($this->tables_columns[$table] as $property_name)
            if (!in_array($property_name, $this->dont_log) && isset($this->initial_data[$property_name]) && $this->$property_name != $this->initial_data[$property_name])
                $changes[$property_name] = (!in_array($property_name, $this->log_simple) ? array($this->initial_data[$property_name], $this->$property_name) : array());

        $object_id = $this->get_log_object_id();

        if (is_null($object_id))
            return;

        if (!empty($changes))
        {
            global $log_client_id;
            //log_event($this->db, (isset($_SESSION['client_id']) ? $_SESSION['client_id'] : $log_client_id), 0, $log_msg, static::LOG_OBJECT_NAME, $object_id, (defined('static::LOG_CHILD_OBJECT_NAME') ? static::LOG_CHILD_OBJECT_NAME : NULL), $this->get_log_child_object_id(), (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : NULL), (isset($_SESSION['real_user_id']) ? $_SESSION['real_user_id'] : NULL), (isset($_SESSION['payer_id']) ? $_SESSION['payer_id'] : NULL), $permission, $page_perm, $changes, true, $this->db_name);
        }
    }

    protected function _set_properties($arr)
    {
        if (is_array($arr))
            foreach ($arr as $property_name => $property_value)
                $this->$property_name = $property_value;
    }

    private function _format_unique_key_arr()
    {
        // Initialize for safety
        $str = '';
        foreach ($this->unique_key as $key => $value)
            $str .= "'$key' => '$value', ";
        if ($str != '')
            $str = substr($str, 0, -2);
        return $str;
    }

    private function _load_by_unique_key()
    {
        // If this object was never loaded then return an empty data set
        if (is_null($this->unique_key))
            return array();

        $unique_key_str = $this->_format_unique_key_arr();
        $this->primary_table = ((array)static::TABLE_NAME)[0];

        // Initialize for safety
        $columns = array("`$this->primary_table`.*");

        // Initialize for safety
        $inner_joins = '';
        if (property_exists($this, 'table_inner_joins') && !empty($this->table_inner_joins))
        {
            foreach ($this->table_inner_joins as $join_table => $join_str)
            {
                $columns[] = "`$join_table`.*";
                $inner_joins .= "INNER JOIN `$this->db_name`.`$join_table` ON ($join_str)\n";
            }
        }

        // Initialize for safety
        $left_joins = '';
        if (property_exists($this, 'table_left_joins') && !empty($this->table_left_joins))
        {
            foreach ($this->table_left_joins as $join_table => $join_data)
            {
                $join_str = $join_data['join_str'];
                $columns[] = "`$join_table`." . implode(", `$join_table`.", $join_data['columns']);
                $left_joins .= "LEFT JOIN `$this->db_name`.`$join_table` ON ($join_str)\n";
            }
        }

        $columns = implode(', ', $columns);

        // Initialize for safety
        $where_clause = '';
        foreach ($this->unique_key as $key => $value)
        {
            if (empty($key) || empty($value))
                throw new Exception(get_class($this) . '::' . __FUNCTION__ . "($unique_key_str): Programmer Error: Empty primary key/value!");

            $where_clause .= "`$this->primary_table`.`$key` = '" . $this->db->escape_string($value) . "' AND ";
        }
        $where_clause = substr($where_clause, 0, -5);

        $query = "SELECT $columns
                  FROM `$this->db_name`.`$this->primary_table`
                  $inner_joins
                  $left_joins
                  WHERE $where_clause";

        //print "$query\n";

        $result = $this->db->query($query);

        if (!$result || $result->num_rows == 0)
            throw new Exception(get_class($this) . '::' . __FUNCTION__ . "($unique_key_str) failed to load record from `$this->primary_table`!");

        return $result->fetch_assoc();
    }
}

?>