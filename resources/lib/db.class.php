<?php

class db extends mysqli
{
    private $db_host;
    private $db_user;
    private $db_pass;
    private $db_name;
    private $persistent;

    public function __construct($db_host, $db_user, $db_pass, $db_name, $persistent = false)
    {
        $this->db_host = $db_host;
        $this->db_user = $db_user;
        $this->db_pass = $db_pass;
        $this->db_name = $db_name;
        $this->persistent = $persistent;

        parent::__construct();
        parent::options(MYSQLI_OPT_CONNECT_TIMEOUT, 1);
        // Initialize for safety
        $i = 1;
        do
        {
            $connected = @parent::real_connect(($this->persistent ? 'p:' : '') . $this->db_host, $this->db_user, $this->db_pass, $this->db_name);
            $i++;
            if (!$connected)
                usleep(100000);
        } while (!$connected && $i <= 3);
        parent::set_charset('utf8mb4');
    }

    // Only works for queries of the form: INSERT INTO `table` (...) VALUES (...)[, (...), ...]
    public function chunk_and_query($prefix, $values)
    {
        if (empty($values))
            return;

        $result = $this->query("SHOW VARIABLES LIKE 'max_allowed_packet'");

        if (!$result || $result->num_rows == 0)
            return;

        $row = $result->fetch_assoc();
        $max_length = ((int)$row['Value'] * 0.9);

        // Initialize for safety
        $query = $prefix;
        $prefix_len = mb_strlen($prefix);
        $len = $prefix_len;

        foreach ($values as $value)
        {
            $value_len = mb_strlen($value);

            if ($len + $value_len >= $max_length)
            {
                $this->query(substr($query, 0, -2));
                $this->ping();
                // Re-initialize
                $query = $prefix;
                $len = $prefix_len;
            }

            $query .= "$value, ";
            $len += $value_len + 2;
        }

        if ($query != $prefix)
        {
            $this->query(substr($query, 0, -2));
            $this->ping();
        }
    }

    public function ping(): bool
    {
        @parent::query('SELECT LAST_INSERT_ID()');

        if (in_array($this->errno, array(2002, 2006, 2014)))
            $this->__construct($this->db_host, $this->db_user, $this->db_pass, $this->db_name, $this->persistent);
        return true;
    }

    public function pquery($query, $resultmode = MYSQLI_STORE_RESULT)
    {
        $i = 1;
        do
        {
            try
            {
                $result = parent::query($query, $resultmode);
            }
            catch (mysqli_sql_exception $e)
            {
                $result = false;
            }

            // Table is locked; wait some random amount of time so that other pending queries don't all rush to get the table lock at the same time and continue the cycle
            if ($this->errno == 1213)
                usleep(mt_rand(100000, 500000));
            // Database connection has gone away; reconnect
            elseif (in_array($this->errno, array(2002, 2006, 2014)))
                $this->__construct($this->db_host, $this->db_user, $this->db_pass, $this->db_name, $this->persistent);

            $i++;
        } while (in_array($this->errno, array(1213, 2002, 2006, 2014)) && $i <= 3);

        return $result;
    }

    public function query($query, $die_on_fail = false, $resultmode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        $result = $this->pquery($query, $resultmode);

        // Don't send an email for duplicate key errors because they should be mitigated in the calling code
        if (!$result && $this->errno != 1062)
        {
            if (!defined('NO_EMAIL_ERRORS'))
            {
                $to = 'accphperror@localhost';

                $body = "Backtrace:\n\n";
                $bt = debug_string_backtrace();
                scrub($bt);
                $body .= "$bt\n";
                $body .= "SQL:\n\n";
                $body .= "\t$query\n\n";
                $body .= "MySQL Error:\n\n";
                $body .= "\t$this->error\n\n";
                if (!empty($_SERVER))
                {
                    $body .= "\$_SERVER:\n\n";
                    foreach ($_SERVER as $key => $value)
                        $body .= "\t$key: " . (is_array($value) ? print_r($value, true) : $value) . "\n";
                    $body .= "\n";
                }
                if (!empty($_GET))
                {
                    $get = $_GET;
                    scrub($get);
                    $body .= "\$_GET:\n\n";
                    foreach ($get as $key => $value)
                        $body .= "\t$key: " . (is_array($value) ? print_r($value, true) : $value) . "\n";
                    $body .= "\n";
                }
                if (!empty($_POST))
                {
                    $post = $_POST;
                    scrub($post);
                	$body .= "\$_POST:\n\n";
                	foreach ($post as $key => $value)
                        $body .= "\t$key: " . (is_array($value) ? print_r($value, true) : $value) . "\n";
                }

                mail($to, '***' . (defined('RUNNING_ON_TEST_SERVER') ? ' Dev' : '') . ' SQL Error ***', $body);
            }

            if (defined('RUNNING_ON_TEST_SERVER'))
                error_log($this->error);

            if ($die_on_fail)
                die();
        }

        return $result;
    }

    public function like_escape_string($str)
    {
        return addcslashes(parent::real_escape_string($str), '%_');
    }

    private function _get_query_type($query)
    {
        $pos = strpos($query, " ");
        return ($pos !== false ? strtoupper(substr($query, 0, $pos)) : "UNKNOWN");
    }
}

?>