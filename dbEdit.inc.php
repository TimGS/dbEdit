<?php
/** 
 * dbEdit database table editor
 * 
 * Config options:
 * 
 * General:
 *      'name'          Column heading and input label
 *      'in_view'       If true (the default) then the column is displayed in the general table view
 *      'type'          Input type: text (default), number, checkbox, date, datetime, time.
 *                          - Other HTML5 'text-like' input types can be used for the purposes of client side form validation, but dbEdit's handling of them is as per 'text'.
 *                          - For <select>s set 'dropdown' (below)
 *                          - For <textarea>s set 'textarea' (below)
 *      'dropdown'      Array of text => value pairs for <select>s. Affects both output and forms. Do not set 'type'; a <select> is inferred.
 *      'textarea'      Array with keys 'rows' and 'cols'. Do not set 'type'; a <textarea> is inferred.
 *      'constraint'    Only rows having this column's field set to this value will be used by dbEdit.
 *                          - This field is not available for editing.
 *                          - New rows will take the constraint value.
 *      'extra'         If set to true, this field will not be displayed in either add or edit forms. Defaults can be set in the database.
 * Output:
 *      'sql'           SQL expression giving alternative value for display purposes only (view and delete-confirm)
 *      'date'          Date format string for PHP's date.
 *                          - Not obligatory for dates or datetimes, but if used the field must be a MySQL timestamp or PHP and MySQL must be using identical timezones.
 *      'checkbox_value_html' Array of two values, with keys 0 and 1, for displaying the setting of checkbox/boolean fields.
 *      'php'           PHP callback to process a field value for display. Replace dbEdit's processing.
 *                          - Callback must take ($primary_key_value, $row_array, $temp_field_suffix, $column_name, $column_config, $charset)
 *                          - Field value is normally $row_array[$column_name] unless 'sql' or 'date' is used.
 *      'php_after'     PHP callback to process a field value after dbEdit has done so, but before HTML escaping (see 'no_esc'), trimming (see 'trim') and converting newlines (see 'nl2br')
 *                          - Callback must take ($db_edits_output, $primary_key_value, $row_array, $temp_field_suffix, $column_name, $column_config, $charset)
 *      'no_esc'        Default to false. If true, this field's contents will not be escaped for HTML display. Intended for HTML richtext fields.
 *      'trim'          If true, trims the output of whitespace.
 *      'nl2br'         If true, converts newlines to HTML line breaks.
 * Adding/editing rows:
 *      'allow_edit'    SQL that determines if this column can be edited. As this is an SQL expression, the result can be different on different rows.
 *      'input_classes' Input element classes. Used for <input> and <select>
 *      'input_date'    Format string for the input values of date and datetime fields. Not obligatory - if not used such field's inputs must 
 *                      produce a date string in the usual MySQL format of YYYY-MM-DD
 *      'step'          Used for 'type'="number"
 * 
 */
class dbEdit {

    // Set in constructor
    private $dbapi;   // DB API. Only mysqli supported at the moment. Child classes could extend this.
    private $conn;    // existing mysqli connection
    private $table;   // db table
    private $primary; // primary key
    private $cols;    // column details
    private $other_cols = []; // Columns that are set external to the editor. Array of arrays with keys 'type' (as per config plus 'timestamp') and 'val' (string, number or special case 'NOW()')
    private $where;   // where clause (optional)
    private $uniqid;  // identifier of this editor; used to preserve the editor over successive requests made by the editor itself.
    private $atime;   // When the editor was last used
    private $sql_order; // SQL for ordering in table (default to primary key ascending)
    
    public  $id_param     = 'id';// request parameter used to denote the record id
    public  $action_param = 'a'; // request parameter used to denote action
    
    private $allow_add = false;  // Can we add rows?
    private $allow_del = false;  // Can we delete rows?
    private $allow_edit = false; // Can we edit rows?
    
    private $allow_del_sql_condition; // If not null, a row must satisfy this condition to be able to be deleted.
    private $allow_edit_sql_condition; // If not null, a row must satisfy this condition to be able to be edited.
    
    public  $outer_classes      = array('v'=>null, 'a'=>null, 'dc'=>null, 'e'=>null);
    
    public  $delete_header_html = 'Delete'; // For view list <th>
    public  $delete_html        = 'Delete'; // For view list <td>
    public  $add_html           = '<a href="[+add_url+]">Add</a>'; // After view 
    
    public  $cancel_html        = '<button type="submit" name="[+name+]" value="v" onclick="this.type=\'button\'; window.location.href=\'[+url+]\';">Cancel</button>'; // For all cancel buttons/links
    public  $reset_html         = '<button type="reset">Reset</button>';

    public  $debug = false;
    private $sql_log = array();

    /**
     * Make a brand new instance. dbEdit::init() should normally be used instead.
     */
    function __construct($dbapi, $conn, $table, $primary, $cols, $where = null) {
        if ($dbapi != 'mysqli') {
            exit('This version of the dbEdit class only supports mysqli.'); // For other DB APIs, child classes could override:
                                                                            //      dbEdit::__construct()
                                                                            //      dbEdit::db_query()
                                                                            //      dbEdit::db_fetch()
                                                                            //      dbEdit::escape()
        }
        $this->dbapi = $dbapi;
        $this->conn = $conn;
        $this->table = $table;
        $this->primary = $primary;
        $this->cols = $cols;
        $this->uniqid = uniqid(null, true);
        $this->where = $where;
        $this->atime = time();
    }
    
    function __destruct() {
        if ($this->debug) {
            echo "<div style='text-transform: none'>\n\n".implode("\n\n", $this->sql_log)."\n\n</div>";
        }
    }
    
    private function db_query($sql) {
        $this->sql_log[] = $sql;
        $rs = mysqli_query($this->conn, $sql);
        if ($rs) {
            return $rs;
        } elseif (preg_match('/localhost$/', $_SERVER['SERVER_NAME'])) {
            exit($sql."\n".mysqli_error($this->conn));
        } else {
            exit();
        }
    }
    
    private function db_fetch($rs) {
        if ($rs) {
            return mysqli_fetch_assoc($rs);
        }
    }
    
    private function db_escape($str) {
        return mysqli_real_escape_string($this->conn, $str);
    }

    /**
     * Can the field's value be returned as a timestamp by MySQL?
     */
    private function can_be_timestamp($col) {
        return isset($col['type']) && ($col['type'] == 'date' || $col['type'] == 'datetime');
    }

    /**
     * Generates SQL used for displaying rows (i.e. any view, including such as delete-confirm)
     */
    private function sql_fields($temp_field_suffix, $include_joins) {
        $sql_fields = '';
        foreach($this->cols as $field => $col) {
            if (!isset($col['constraint']) && (!isset($col['tables']) || $include_joins)) {
                
                if (strpos($field, '.') === false) {
                    $origin_field = $this->table.'.'.$field;
                    $return_field = $field;
                } else {
                    $origin_field = $field;
                    $return_field = substr(strrchr($field, '.'), 1);
                }
                
                if (isset($col['sql'])) {
                    $sql_fields .= ", ({$col['sql']}) AS {$return_field}_sql{$temp_field_suffix}";
                } elseif ($this->can_be_timestamp($col) && isset($col['date'])) {
                    // If $col['date'] is set, ask MySQL for a timestamp so we can then use PHP's date function
                    // for output. $col['date'] will be used as the format string for date().
                    // Either 
                    //      - the MySQL field is a timestamp, or 
                    //      - MySQL should be using the same timezone as PHP and the column in MySQL should be a date or datetime.
                    //
                    // NOTE (1): It is not obligatory to use $col['date'] just because $col['type'] is set to "date" or "datetime". It is 
                    //           purely output formatting functionality.
                    //
                    // NOTE (2): If the column in MySQL is a date or datetime, and it is not known that MySQL is using the same timezone as
                    //           PHP, this functionality should not be used. Instead use $col['sql'] and use MySQL's DATE_FORMAT() function.
                    $sql_fields .= ", UNIX_TIMESTAMP({$origin_field}) AS {$return_field}_unixtime{$temp_field_suffix}";
                } else {
                    $sql_fields .= ", {$origin_field} AS {$return_field}";
                }
            }
        }
        return $sql_fields;
    }

    /**
     * Returns SQL for finding if a row can be edited or deleted
     */
    private function sql_condition($sql_condition) {
        return $sql_condition ? " AND ({$sql_condition})" : '';
    }

    /**
     * Get fields, tables and WHERE for viewing record(s)
     */
    private function get_sql_fields_tables_and_where(string $temp_field_suffix): object {
        $join_tables = array();
        $join_conditions = array();
        foreach($this->cols as $field => $col) {
            if (isset($col['tables'])) {
                foreach($col['tables'] as $join_table) { // table, join condition, optional table alias, optional join type (INNER, LEFT, RIGHT - default INNER)
                    $join_type_specified = !empty($join_table[3]);
                    $join = ($join_type_specified ? $join_table[3] : 'INNER').' JOIN '.$join_table[0].(!empty($join_table[2]) ? ' AS '.$join_table[2] : '');
                    if ($join_type_specified) {
                        $join_tables[] = $join.' ON '.$join_table[1]; // ON (LEFT/RIGHT JOIN)
                    } else {
                        $join_tables[] = $join;
                        $join_conditions[] = $join_table[1]; // WHERE/AND (JOIN)
                    }
                    // <<<< todo - avoid duplicate joins
                }
            }
        }

        $sql_fields = $this->sql_fields($temp_field_suffix, true);

        // Table joins?
        if (sizeof($join_tables)) {
            $tables = $this->table;
            foreach($join_tables as $join_table) {
                $tables .= ' '.$join_table;
            }
            $where = ($this->where ? $this->where.' AND ' : '').implode(' AND ', $join_conditions);
        } else {
            $tables = $this->table;
            $where = $this->where;
        }

        return (object)['fields' => $sql_fields, 'tables' => $tables, 'where' => $where];
    }


    /**
     * Parse an input date and return a string in suitable format for MySQL
     * 
     * @param string $value
     * @param string $format
     * @param bool $include_time
     * @return string
     */
    private function parse_input_date($value, $format, $include_time = false) {
        $arr = date_parse_from_format($format, $value);
        return $arr['year'].'-'.$arr['month'].'-'.$arr['day'].($include_time ? ' '.$arr['hour'].':'.$arr['minute'].':'.$arr['second'] : '');
    }

    /**
     * HTML output
     */
    private function html($val, $charset, $no_esc = false) {
        return $no_esc ? $val : htmlentities($val, ENT_QUOTES, $charset);
    }
    
    /**
     * Should a column be displayed?
     */
    private function displayable($col) {
        return !isset($col['constraint']) && (!isset($col['in_view']) || $col['in_view']);
    }

    /**
     * Output one cell/value for display.
     * 
     * Returns an empty string for non-displayable columns.
     */
    private function output($row, $temp_field_suffix, $field, $col, $charset) {
        $output = '';
        if ($this->displayable($col)) {

            $is_date = false;

            if (strpos($field, '.') === false) {
                $return_field = $field;
            } else {
                $return_field = substr(strrchr($field, '.'), 1);
            }

            $no_esc = !empty($col['no_esc']);

            if (!empty($col['php'])) {
                // Config-supplied function to generate output
                $output1 = call_user_func($col['php'], $row['dbEdit_primary_key'], $row, $temp_field_suffix, $field, $col, $charset);
            } elseif (!empty($col['sql'])) {
                // Config has custom SQL to generate output
                $output1 = $row[$return_field.'_sql'.$temp_field_suffix];
            } elseif ($this->can_be_timestamp($col) && isset($col['date'])) {
                // If $col['date'] is set, then convert a MySQL-generated timestamp to an output date
                // using PHP's timezone. $col['date'] will be used as the format string for date().
                // Either 
                //      - the MySQL field is a timestamp, or 
                //      - MySQL should be using the same timezone as PHP and the column in MySQL should be a date or datetime.
                //
                // NOTE (1): It is not obligatory to use $col['date'] just because $col['type'] is set to "date" or "datetime". It is 
                //           purely output formatting functionality.
                //
                // NOTE (2): If the column in MySQL is a date or datetime, and it is not known that MySQL is using the same timezone as
                //           PHP, this functionality should not be used. Instead use $col['sql'] and use MySQL's DATE_FORMAT() function.
                $output1 = date($col['date'], $row[$return_field.'_unixtime'.$temp_field_suffix]);
                $is_date = true;
            } else {
                if (isset($col['type']) && $col['type'] == 'checkbox') {
                    // Checkbox; display values should be in the config
                    $output1 = $col['checkbox_value_html'][$row[$return_field]];
                    $no_esc = true;
                } elseif (!empty($col['dropdown'])) {
                    // Select dropdown; display values should be in the config
                    $flip = array_flip($col['dropdown']);
                    $output1 = @$flip[$row[$return_field]];
                } else {
                    // Anything else; the field value is the output
                    $output1 = $row[$return_field];
                }
            }
            
            if (!empty($col['php_after'])) {
                // Config-supplied function to alter the output
                $output1 = call_user_func($col['php_after'], $output1, $row['dbEdit_primary_key'], $row, $temp_field_suffix, $field, $col, $charset);
            }
            
            // Convert to HTML for output
            $output = $this->html($output1, $charset, $no_esc);

            if (!empty($col['trim'])) {
                // Trim
                $output = trim($output);
            }

            if (!empty($col['nl2br'])) {
                // Convert newlines to HTML line breaks
                $output = nl2br($output);
            }
            
            $output = '<td'.($is_date ? ' data-order="'.$this->html($row[$return_field.'_unixtime'.$temp_field_suffix], $charset).'"' : '').'>'.(!empty($col['bold']) ? '<strong>'.$output.'</strong>' : $output).'</td>';
        }
        
        return $output;
    }
    
    /**
     * Update session store.
     * 
     * Must be done during the request when the editor is created. Does nothing on subsequent requests.
     * 
     * @return void
     */
    private function update_session() {
        if ($this->is_new()) {
            // Update editor
            $_SESSION['dbedit']['objects'][$this->uniqid] = clone $this;
            $_SESSION['dbedit']['objects'][$this->uniqid]->conn = null;
        }
    }

    /**
     * Make a URL for the next dbEdit request
     */
    private function dbEdit_url($qsa, $html, $add_dbedit_handle = true) {
    
        if (is_null($qsa)) {
            $qsa = array();
        }
    
        if (strpos($_SERVER['REQUEST_URI'], '?') !== false) {
            $url = strstr($_SERVER['REQUEST_URI'], '?', true);
            $new_keys = array_keys($qsa);
            $url_parts = explode('?', $_SERVER['REQUEST_URI']);
            $qs_parts = explode('&', $url_parts[1]);
            foreach($qs_parts as $qs_part) {
                $name_value_pair = explode('=', $qs_part);
                $name_value_pair[1] = urldecode($name_value_pair[1]);
                if (!in_array($name_value_pair[0], $new_keys) /* new query string parameters */ && !in_array($name_value_pair[0], array($this->action_param, $this->id_param, 'updated', 'dbedit')) /* reserved query string parameters */ ) {
                    $qsa[$name_value_pair[0]] = $name_value_pair[1];
                }
            }
        } else {
            $url = $_SERVER['REQUEST_URI'];
        }
        
        if ($add_dbedit_handle && !array_key_exists('dbedit', $qsa)) {
            $qsa['dbedit'] = $this->uniqid;
        }

        if (sizeof($qsa)) {
            $sep = '?';
            foreach($qsa as $name => $value) {
                $url .= $sep.$name.'='.rawurlencode($value);
                $sep = $html ? '&amp;' : '&';
            }
        }
        return $url;
    }

    /** 
     * Set/retrieve data for use by the calling script (doesn't affect dbEdit itself)
     * 
     * Calling scripts should use this method to set data for use by the calling script when
     * a new dbEdit object is created and to retrieve it later on subsequent page loads, 
     * e.g.
     *      $editor->Param('sql', 'SELECT * FROM mchAdverts WHERE advertID = '.@$get['id']);
     * ...will set the 'sql' parameter during the script execution when a new dbEdit is created,
     * but will return this original value during later requests involving the same object.
     * 
     * @param string $name
     * @param string $value
     * @return mixed
     */
    function param($name, $value) {
        if (isset($_REQUEST['dbedit'])) {
            return $_SESSION['dbedit']['params'][$this->uniqid][$name];
        } else {
            return $_SESSION['dbedit']['params'][$this->uniqid][$name] = $value;
        }
    }

    /**
     * Has this object being created in this request?
     * 
     * @return bool
     */
    function is_new() {
        return !isset($_REQUEST['dbedit']);
    }

    /**
     * Set whether the user can add rows
     * 
     * If called during the first request when the editor is created, this becomes the default for
     * subsequent requests, otherwise it only lasts for the duration of this request.
     * 
     * @param bool $allow
     */
    function allow_add($allow) {
        $this->allow_add = (bool)$allow;
        $this->update_session();
    }

    /**
     * Set whether the user can edit rows
     * 
     * If called during the first request when the editor is created, this becomes the default for
     * subsequent requests, otherwise it only lasts for the duration of this request.
     * 
     * @param bool $allow
     * @param string $sql_condition
     */
    function allow_edit($allow, $sql_condition = null) {
        $this->allow_edit = (bool)$allow;
        $this->allow_edit_sql_condition = $sql_condition;
        $this->update_session();
    }

    /**
     * Set whether the user can delete rows
     * 
     * If called during the first request when the editor is created, this becomes the default for
     * subsequent requests, otherwise it only lasts for the duration of this request.
     * 
     * @param bool $allow
     * @param string $sql_condition
     */
    function allow_del($allow, $sql_condition = null) {
        $this->allow_del = (bool)$allow;
        $this->allow_del_sql_condition = $sql_condition;
        $this->update_session();
    }

    /**
     * Pass the $cols, if not done via dbEdit::init(). Must be done when the editor is created.
     * 
     * This method does nothing on successive requests of the same editor.
     * 
     * @param array $cols
     * @return void
     */
    function set_cols($cols) {
        if ($this->is_new()) {
            $this->cols = $cols;
            $this->update_session();
        }
    }
    
    /**
     * Pass values that should be set by calling code, but are not included or set by the editor.
     * 
     * Currently only used for row updates
     */
    function set_other_cols(Array $other_cols) {
        $this->other_cols = $other_cols;
    }

    /**
     * Set order column(s)
     * 
     * @param $sql_order
     * @return void
     */
    function set_order($sql_order) {
        $this->sql_order = $sql_order;
    }

    /**
     * Run the editor, returning the HTML for output.
     * 
     * @param string $attr_prefix Prefix for id, classes and name attributes.
     * @param string $charset
     * @param string $a Action - if not set explicitly dbEdit will handle this automatically via $_REQUEST.
     * @param int $id For editing and posting rows, identifies the row - if not set explicitly dbEdit will handle this automatically via $_REQUEST.
     * @return string HTML to output.
     */
    function execute($attr_prefix, $charset, $a = null, $id = null) {
    
        $this->atime = time();
    
        if (!$a) {
            $a = $_REQUEST[$this->action_param] ?? 'v';
        }
        
        if (!$id && !empty($_REQUEST[$this->id_param])) {
            $id =  $_REQUEST[$this->id_param];
        }
        if ($id && !ctype_digit($id)) {
            $id = null;
        }
        
        if (!$id && $a == 'e') {
            $a = 'v';
        }
        
        if (array_key_exists($a, $this->outer_classes)) {
            $this->outer_classes[$a] .= ($this->outer_classes[$a] ? ' ' : '').$attr_prefix.'action_'.$a;
        }
        
        $temp_field_suffix = uniqid();
        
        $output = '';
        
        switch ($a) {

            case 'p':
            case 'post':    // This is the POST that updates/edits an existing row

                $allow_edit_fields = '';
                foreach($this->cols as $field => $col) {
                    if (isset($col['allow_edit'])) {
                        $allow_edit_fields .= ", ({$col['allow_edit']}) AS allow_edit_of_{$field}";
                    }
                }
                if ($allow_edit_fields && !($row_allow_edit = $this->db_fetch($this->db_query('SELECT '.substr($allow_edit_fields, 2)." FROM {$this->table} WHERE {$this->primary} = {$id}".$this->sql_condition($this->allow_edit_sql_condition))))) {
                    header('Location: '.$this->dbEdit_url(array('updated'=>'0'), false));
                    exit();
                }

                $fields = array();
                foreach($_POST as $name => $value) {
                    if (substr($name, 0, strlen($attr_prefix)) == $attr_prefix) {
                        $post_field = substr($name, strlen($attr_prefix));
                        // Check that this POST element is a field in the config, that can be edited.
                        if (isset($this->cols[$post_field]) && (!isset($this->cols[$post_field]['allow_edit']) || $row_allow_edit['allow_edit_of_'.$post_field])) {
                            if (@$this->cols[$post_field]['type'] == 'checkbox') {
                                // Checkbox input are 0 or 1
                                $fields[] = $post_field.'=1';
                            } elseif(isset($this->cols[$post_field]['input_date'])) {
                                // Convert date input format to MySQL format
                                $fields[] = $post_field.'="'.$this->parse_input_date($value, $this->cols[$post_field]['input_date'], (@$col['type'] == 'datetime')).'"';
                            } else {
                                // Anything else; use the POST element's escaped value
                                $fields[] = $post_field.'="'.$this->db_escape($value).'"';
                            }
                        }
                    }
                }
                
                foreach($this->cols as $field => $col) {
                    // Reset any unchecked checkbox inputs - we need to check for their absence in the POST
                    if (@$col['type'] == 'checkbox' && !isset($_POST[$attr_prefix.$field]) && (!isset($col['allow_edit']) || $row_allow_edit['allow_edit_of_'.$field])) {
                        $fields[] = $field.'=0';
                    }
                }
                
                foreach($this->other_cols as $field => $col) {
                    // Column values set by external calling code, not the editor
                    if ($col['val'] == 'NOW()') {
                        // Special case NOW()
                        switch ($col['type']) {
                            case 'date':
                                $fields[] = $field.'=DATE(NOW())';
                                break;
                            case 'timestamp':
                            case 'datetime':
                                $fields[] = $field.'=NOW()';
                                break;
                        }
                    } else {
                        if ($col['type'] == 'number') {
                            if (is_numeric($col['val'])) {
                                $fields[] = $field.'='.$col['val'];
                            } else {
                                $fields[] = $field.'='.$this->db_escape($col['val']);
                            }
                        } else {
                            $fields[] = $field.'='.$this->db_escape($col['val']);
                        }
                    }
                }
                
                if (sizeof($fields)) {
                    $this->db_query("UPDATE {$this->table} SET ".implode(',', $fields)." WHERE {$this->primary} = {$id}".$this->sql_condition($this->allow_edit_sql_condition));
                }
                
                header('Location: '.$this->dbEdit_url(array('updated'=>'1'), false));
                exit();
                
            case 'i':
            case 'insert':  // This is the POST that creates/adds a new row
                $fields = array();
                $values = array();
                foreach($_POST as $name => $value) {
                    if (substr($name, 0, strlen($attr_prefix)) == $attr_prefix) {
                        $post_field = substr($name, strlen($attr_prefix));
                        // Check that this POST element is a field in the config.
                        if (isset($this->cols[$post_field])) {
                            if (@$this->cols[$post_field]['type'] == 'checkbox') {
                                // Checkbox input are 0 or 1
                                $fields[] = $post_field;
                                $values[] = '1';
                            } elseif(isset($this->cols[$post_field]['input_date'])) {
                                // Convert date input format to MySQL format
                                $fields[] = $post_field;
                                $values[] = '"'.$this->parse_input_date($value, $this->cols[$post_field]['input_date'], (@$col['type'] == 'datetime')).'"';
                            } else {
                                // Anything else; use the POST element's escaped value
                                $fields[] = $post_field;
                                $values[] = '"'.$this->db_escape($value).'"';
                            }
                        }
                    }
                }
                
                foreach($this->cols as $field => $col) {
                    
                    if (@$col['type'] == 'checkbox' && !isset($_POST[$attr_prefix.$field])) {
                        // Set any unchecked checkbox inputs - we need to check for their absence in the POST
                        $fields[] = $field;
                        $values[] = '0';
                    } elseif (@$col['constraint']) {
                        // If a column is set to be a constraint, any added rows must use this value.
                        $fields[] = $field;
                        $values[] = $col['constraint'];
                    }
                }

                if (sizeof($fields)) {
                    $this->db_query("INSERT INTO {$this->table} (".implode(',', $fields).') VALUES ('.implode(',', $values).')');
                }
                
                header('Location: '.$this->dbEdit_url(array('updated'=>'1'), false));
                exit();
                
            case 'v':
            case 'view':
                if (!empty($_GET['updated'])) {
                    $output .= '<p class="'.$attr_prefix.'msg">Row updated</p>';
                }
                $output .= '<table id="'.$attr_prefix.'table"'.($this->outer_classes['v'] ? ' class="'.$this->html($this->outer_classes['v'], $charset).'"' : '').'><thead><tr>';

                foreach($this->cols as $field => $col) {
                    if ($this->displayable($col)) {
                        $output .= '<th>'.($col['name'] ? $col['name'] : $field).'</th>';
                    }
                }

                if ($this->allow_del) {
                    $output .= '<th class="'.$attr_prefix.'del-col">'.$this->delete_header_html.'</th>';
                }

                $output .= '</tr></thead><tbody>';

                $wt = $this->get_sql_fields_tables_and_where($temp_field_suffix);

                $rs = $this->db_query('SELECT '.$this->table.'.'.$this->primary.' AS dbEdit_primary_key'.($this->allow_del_sql_condition ? ", IF({$this->allow_del_sql_condition}, 1, 0) AS dbEdit_allow_del" : '')
                                                .($this->allow_edit_sql_condition ? ", IF({$this->allow_edit_sql_condition}, 1, 0) AS dbEdit_allow_edit" : '')
                                                .$wt->fields
                                            ." FROM {$wt->tables}".($wt->where ? ' WHERE '.$wt->where : '').' ORDER BY '.($this->sql_order ? $this->sql_order : $this->table.'.'.$this->primary.' ASC'));
                while($row = $this->db_fetch($rs)) {
                    if ($this->allow_edit && (!$this->allow_edit_sql_condition || $row['dbEdit_allow_edit'])) {
                        $output .= '<tr id="'.$attr_prefix.'row-'.$row['dbEdit_primary_key'].'" class="'.$attr_prefix.'editable" onclick="window.location.href=\''.$this->dbEdit_url(array($this->action_param=>'e', $this->id_param=>$row['dbEdit_primary_key']), true).'\'">';
                    } else {
                        $output .= '<tr id="'.$attr_prefix.'row-'.$row['dbEdit_primary_key'].'" class="'.$attr_prefix.'noteditable">';
                    }
                    foreach($this->cols as $field => $col) {
                        $output .= $this->output($row, $temp_field_suffix, $field, $col, $charset);
                    }
                    if ($this->allow_del) {
                        if (!$this->allow_del_sql_condition || $row['dbEdit_allow_del']) {
                            $output .= '<td class="'.$attr_prefix.'del-col"><a href="'.$this->dbEdit_url(array($this->action_param=>'dc', $this->id_param=>$row['dbEdit_primary_key']), true).'">'.$this->delete_html.'</a></td>';
                        } else {
                            $output .= '<td class="'.$attr_prefix.'del-col"></td>';
                        }
                    }
                    $output .= '</tr>';
                }
                $output .= '</tbody></table>';
                
                if ($this->allow_add) {
                    $output .= '<div class="'.$attr_prefix.'add'.($this->outer_classes['v'] ? ' '.$this->html($this->outer_classes['v'], $charset) : '').'">'.str_replace('[+add_url+]', $this->dbEdit_url(array($this->action_param=>'a'), true), $this->add_html).'</div>';
                }

                break;

            case 'dc':
            case 'deleteconfirm':
                $wt = $this->get_sql_fields_tables_and_where($temp_field_suffix);
            
                $row = $this->db_fetch($this->db_query("SELECT *, {$this->table}.{$this->primary} AS dbEdit_primary_key{$wt->fields}
                                                            FROM {$wt->tables}
                                                            ".($wt->where ? " WHERE {$wt->where} AND " : 'WHERE')."{$this->table}.{$this->primary} = {$id}
                                                            ".$this->sql_condition($this->allow_del_sql_condition)));
                $output .= '<table id="'.$attr_prefix.'table"'.($this->outer_classes['dc'] ? ' class="'.$this->html($this->outer_classes['dc'], $charset).'"' : '').'><tbody>';
                foreach($this->cols as $field => $col) {
                    if ($this->displayable($col)) {
                        $output .= "<tr><td>{$col['name']}</td><td>{$this->output($row, $temp_field_suffix, $field, $col, $charset)}</td></tr>";
                    }
                }
                $output .= '</tbody></table>
                <form action="'.$this->dbEdit_url(null, true, false).'" method="post"'.($this->outer_classes['dc'] ? ' class="'.$this->html($this->outer_classes['dc'], $charset).'"' : '').'>
                    <input type="hidden" name="dbedit" value="'.$this->uniqid.'" />
                    <input type="hidden" name="'.$this->id_param.'" value="'.$id.'" />
                    <button type="submit" name="'.$this->action_param.'" value="d">Delete</button>
                    '.str_replace('[+name+]', $this->action_param, str_replace('[+url+]', $this->dbEdit_url(null, true), $this->cancel_html)).'
                </form>';
                break;
            
            case 'd':
            case 'delete':
                if ($this->allow_del) {
                    $this->db_query("DELETE FROM {$this->table} WHERE {$this->primary} = {$id}".$this->sql_condition($this->allow_del_sql_condition));
                }
                header('Location: '.$this->dbEdit_url(null, false));
                exit();

            case 'e':
            case 'edit':
                if ($this->allow_edit) {
                    $allow_edit_fields = '';
                    foreach($this->cols as $field => $col) {
                        if (isset($col['allow_edit'])) {
                            $allow_edit_fields .= ", ({$col['allow_edit']}) AS allow_edit_of_{$field}{$temp_field_suffix}";
                        }
                    }
                    $row = $this->db_fetch($this->db_query("SELECT *{$allow_edit_fields} FROM {$this->table} WHERE {$this->primary} = {$id}".$this->sql_condition($this->allow_edit_sql_condition)));
                    if ($row) {
                        $output .= '<form id="'.$attr_prefix.'form" method="post" action="'.$this->dbEdit_url(null, true, false).'"'.($this->outer_classes['e'] ? ' class="'.$this->html($this->outer_classes['e'], $charset).'"' : '').'><fieldset>';
                        foreach($this->cols as $field => $col) {
                            if (!isset($col['constraint']) && !@$col['extra']) {
                                $el_attr = $attr_prefix.$field;
                                $disabled = isset($row["allow_edit_of_{$field}{$temp_field_suffix}"]) && !$row["allow_edit_of_{$field}{$temp_field_suffix}"] ? 'disabled="disabled" ' : '';
                                $output .=
                                '<div id="'.$el_attr.'-wrap">
                                <label for="'.$el_attr.'">'.$col['name'].'</label>';
                                if (@$col['textarea']) {
                                    $output .= '<textarea id="'.$el_attr.'" name="'.$el_attr.'" rows="'.$col['textarea']['rows'].'" cols="'.$col['textarea']['cols'].'">'.$this->html($row[$field], $charset).'</textarea>';
                                } elseif (@$col['type'] == 'checkbox') {
                                    $classes = isset($col['input_classes']) ? $col['input_classes'] : array();
                                    $output .= '<input '.$disabled.'type="checkbox" id="'.$el_attr.'" name="'.$el_attr.'" '.(sizeof($classes) ? 'class="'.implode(' ', $classes).'" ' : '').'value="1" '.($row[$field] ? 'checked="checked" ' : '').'/>';
                                } elseif (@$col['dropdown']) {
                                    $classes = isset($col['input_classes']) ? $col['input_classes'] : array();
                                    $output .= '<select '.$disabled.'id="'.$el_attr.'" name="'.$el_attr.'"'.(sizeof($classes) ? ' class="'.implode(' ', $classes).'"' : '').'>';
                                    foreach($col['dropdown'] as $inner => $value) {
                                        $output .= '<option value="'.$this->html($value, $charset).'"'.($value == $row[$field] ? ' selected="selected"' : '').'>'.$this->html($inner, $charset).'</option>';
                                    }
                                    $output .= '</select>';
                                } else {
                                    $classes = isset($col['input_classes']) ? $col['input_classes'] : array();
                                    if (@$col['type'] == 'date' || @$col['type'] == 'time' || @$col['type'] == 'datetime') {
                                        $classes[] = $col['type'];
                                    }
                                    $output .= '<input '.$disabled.'type="'.(@$col['type'] ? $col['type'] : 'text').'" id="'.$el_attr.'" name="'.$el_attr.'" '.(sizeof($classes) ? 'class="'.implode(' ', $classes).'" ' : '').'value="'.$this->html($row[$field], $charset).'" '.(@$col['type'] == 'number' && @$col['step'] ? 'step="'.$this->html($col['step'], $charset).'" ' : '').'/>';
                                }
                                $output .= '</div>';
                            }
                        }
                        $output .=
                        '</fieldset>
                        <fieldset class="submit">
                            <button type="submit" id="'.$attr_prefix.'submit" name="'.$this->action_param.'" value="p">Edit</button>
                            '.$this->reset_html.'
                            '.str_replace('[+name+]', $this->action_param, str_replace('[+url+]', $this->dbEdit_url(null, true), $this->cancel_html)).'
                            <input type="hidden" name="dbedit" value="'.$this->uniqid.'" />
                            <input type="hidden" name="'.$this->id_param.'" value="'.$id.'" />
                        </fieldset>
                        </form>';
                    }
                }
                break;

            case 'a':
            case 'add':
                $output .= '<form id="'.$attr_prefix.'form" method="post" action="'.$this->dbEdit_url(null, true, false).'"'.($this->outer_classes['a'] ? ' class="'.$this->html($this->outer_classes['a'], $charset).'"' : '').'><fieldset>';
                foreach($this->cols as $field => $col) {
                    if (!isset($col['constraint']) && !@$col['extra']) {
                        $el_attr = $attr_prefix.$field;
                        $output .=
                        '<div id="'.$el_attr.'-wrap">
                        <label for="'.$el_attr.'">'.$col['name'].'</label>';
                        if (@$col['textarea']) {
                            $output .= '<textarea id="'.$el_attr.'" name="'.$el_attr.'" rows="'.$col['textarea']['rows'].'" cols="'.$col['textarea']['cols'].'">'.$this->html(@$col['default'], $charset).'</textarea>';
                        } elseif (@$col['type'] == 'checkbox') {
                            $classes = isset($col['input_classes']) ? $col['input_classes'] : array();
                            $output .= '<input type="checkbox" id="'.$el_attr.'" name="'.$el_attr.'" '.(sizeof($classes) ? 'class="'.implode(' ', $classes).'" ' : '').'value="1" '.(@$col['default'] ? 'checked="checked" ' : '').'/>';
                        } elseif (@$col['dropdown']) {
                            $classes = isset($col['input_classes']) ? $col['input_classes'] : array();
                            $output .= '<select id="'.$el_attr.'" name="'.$el_attr.'"'.(sizeof($classes) ? ' class="'.implode(' ', $classes).'"' : '').'>';
                            foreach($col['dropdown'] as $inner => $value) {
                                $output .= '<option value="'.$this->html($value, $charset).'"'.(isset($col['default']) && $value == $col['default'] ? ' selected="selected"' : '').'>'.$this->html($inner, $charset).'</option>';
                            }
                            $output .= '</select>';
                        } else {
                            $classes = isset($col['input_classes']) ? $col['input_classes'] : array();
                            if (@$col['type'] == 'date' || @$col['type'] == 'time' || @$col['type'] == 'datetime') {
                                $classes[] = $col['type'];
                            }
                            $output .= '<input type="'.(@$col['type'] ? $col['type'] : 'text').'" id="'.$el_attr.'" name="'.$el_attr.'" '.(sizeof($classes) ? 'class="'.implode(' ', $classes).'" ' : '').'value="'.$this->html(@$col['default'], $charset).'" '.(@$col['type'] == 'number' && @$col['step'] ? 'step="'.$this->html($col['step'], $charset).'" ' : '').'/>';
                        }
                        $output .= '</div>';
                    }
                }
                $output .=
                '</fieldset>
                <fieldset class="submit">
                    <button type="submit" name="'.$this->action_param.'" value="i">Add</button>
                    '.str_replace('[+name+]', $this->action_param, str_replace('[+url+]', $this->dbEdit_url(null, true), $this->cancel_html)).'
                    <input type="hidden" name="dbedit" value="'.$this->uniqid.'" />
                </fieldset>
                </form>';
                break;

        }
        
        return $output;
    }

    /**
     * Create a dbEdit instance, or retrieve an existing one from $_SESSION
     * 
     * @param string $dbapi Only mysqli currently allowed
     * @param resource|object $conn Database connection handle
     * @param string $table
     * @param array $cols
     * @param string $where Optional
     * @return dbEdit
     */
    static function init($dbapi, $conn, $table, $primary, $cols, $where = null) {

        // Garbage collection
        // <<<< Crude - objects time out after 15 minutes
        if (isset($_SESSION['dbedit']['objects'])) {
            foreach($_SESSION['dbedit']['objects'] as $key => $obj) {
                if (time() - $obj->atime > 900) {
                    unset($_SESSION['dbedit']['objects'][$key]);
                    unset($_SESSION['dbedit']['params'][$key]);
                }
            }
        }
        
        if (!isset($_REQUEST['dbedit'])) {
            // New editor
            $obj = new dbEdit($dbapi, $conn, $table, $primary, $cols, $where);
            $_SESSION['dbedit']['objects'][$obj->uniqid] = clone $obj;
            $_SESSION['dbedit']['objects'][$obj->uniqid]->conn = null;
            $_SESSION['dbedit']['initial_uri'][$obj->uniqid] = $_SERVER['REQUEST_URI'];
            return $obj;
        } elseif (isset($_SESSION['dbedit']['objects'][$_REQUEST['dbedit']])) {
            // Retrieve object from $_SESSION and re-establish db connection
            $obj = clone $_SESSION['dbedit']['objects'][$_REQUEST['dbedit']];
            $obj->conn = $conn; // Re-establish db connection
            return $obj;
        } else {
            // Object missing from $_SESSION - garbage collected?
            // Do nothing and restart
            header('Location: '.$_SESSION['dbedit']['initial_uri'][$_REQUEST['dbedit']]);
        }
    }

}

// <<<< TODO
// serialisable closures for php and php_after ?
// maxlength (automatic for char/varchar ???)
