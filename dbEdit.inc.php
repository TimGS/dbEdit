<?php
class dbEdit {

    // Set in constructor
    private $dbapi;   // DB API. Only mysqli supported at the moment.
    private $conn;    // existing mysqli connection
    private $table;   // db table
    private $primary; // primary key
    private $cols;    // column details
    private $where;   // where clause (optional)
    private $uniqid;  // identifier of this editor; used to preserve the editor over successive requests made by the editor itself.
    private $atime;   // When the editor was last used
    private $sql_order; // SQL for ordering in table (default to primary key ascending)
    
    private $allow_add = false;  // Can we add rows?
    private $allow_del = false;  // Can we delete rows?
    private $allow_edit = false; // Can we edit rows?
    
    private $allow_del_sql_condition; // If not null, a row must satisfy this condition to be able to be deleted.
    private $allow_edit_sql_condition; // If not null, a row must satisfy this condition to be able to be edited.
    
    function __construct($dbapi, $conn, $table, $primary, $cols, $where = null) {
        if ($dbapi != 'mysqli') {
            exit('This version of the dbEdit class only supports mysqli.');
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
    
    private function db_query($sql) {
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
    
    private function can_be_timestamp($col) {
        return (@$col['type'] == 'date' || @$col['type'] == 'datetime');
    }

    private function sql_fields($temp_field_suffix) {
        $sql_fields = '';
        foreach($this->cols as $field => $col) {
            if (!isset($col['constraint'])) {
                if (isset($col['sql'])) {
                    $sql_fields .= ", ({$col['sql']}) AS {$field}_sql{$temp_field_suffix}";
                } elseif ($this->can_be_timestamp($col) && isset($col['date'])) {
                    $sql_fields .= ", UNIX_TIMESTAMP({$field}) AS {$field}_unixtime{$temp_field_suffix}";
                }
            }
        }
        return $sql_fields;
    }
    
    private function sql_condition($sql_condition) {
        return $sql_condition ? " AND ({$sql_condition})" : '';
    }

    private function html($val, $charset, $no_esc = false) {
        return $no_esc ? $val : htmlentities($val, ENT_QUOTES, $charset);
    }

    private function output($row, $temp_field_suffix, $field, $col, $charset) {
        $output = '';
        if (!isset($col['constraint'])) {
            $output .= '<td>'.(@$col['bold'] ? '<strong>' : '');
            if (@$col['sql']) {
                $output .= $this->html($row[$field.'_sql'.$temp_field_suffix], $charset, @$col['no_esc']);
            } elseif ($this->can_be_timestamp($col) && isset($col['date'])) {
                $output .= $this->html(date($col['date'], $row[$field.'_unixtime'.$temp_field_suffix]), $charset, @$col['no_esc']);
            } else {
                if (@$col['type'] == 'checkbox') {
                    $output .= $col['checkbox_value_html'][$row[$field]];
                } elseif (@$col['dropdown']) {
                    $flip = array_flip($col['dropdown']);
                    $output .= $this->html($flip[$row[$field]], $charset, @$col['no_esc']);
                } else {
                    $output .= $this->html($row[$field], $charset, @$col['no_esc']);
                }
            $output .= (@$col['bold'] ? '</strong>' : '').'</td>';
            }
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
     * Set/retrieve date for use by the calling script.
     * 
     * Calling scripts should use this method to set data when a new dbEdit object is created.
     * and to retrieve it later. e.g.
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
            $a = @$_REQUEST['a'] ? $_REQUEST['a'] : 'v';
        }
        
        if (!$id && @$_REQUEST['id']) {
            $id =  $_REQUEST['id'];
        }
        if ($id && !ctype_digit($id)) {
            $id = null;
        }
        
        if (!$id && $a == 'e') {
            $a = 'v';
        }
        
        $temp_field_suffix = uniqid();
        
        $output = '';
        
        switch ($a) {
            case 'p':
            case 'post':
                $fields = array();
                foreach($_POST as $name => $value) {
                    if (substr($name, 0, strlen($attr_prefix)) == $attr_prefix) {
                        $post_field = substr($name, strlen($attr_prefix));
                        if (isset($this->cols[$post_field])) {
                            if (@$this->cols[$post_field]['type'] == 'checkbox') {
                                $fields[] = $post_field.'=1';
                            } else {
                                $fields[] = $post_field.'="'.$this->db_escape($value).'"';
                            }
                        }
                    }
                }
                
                foreach($this->cols as $field => $col) {
                    if (@$col['type'] == 'checkbox' && !isset($_POST[$attr_prefix.$field])) {
                        $fields[] = $field.'=0';
                    }
                }
                
                if (sizeof($fields)) {
                    $this->db_query("UPDATE {$this->table} SET ".implode(',', $fields)." WHERE {$this->primary} = {$id}".$this->sql_condition($this->allow_edit_sql_condition));
                }
                
                header('Location: '.$_SERVER['PHP_SELF'].'?updated=1&dbedit='.$this->uniqid);
                exit();
                
            case 'i':
            case 'insert':
                $fields = array();
                $values = array();
                foreach($_POST as $name => $value) {
                    if (substr($name, 0, strlen($attr_prefix)) == $attr_prefix) {
                        $post_field = substr($name, strlen($attr_prefix));
                        if (isset($this->cols[$post_field])) {
                            if (@$this->cols[$post_field]['type'] == 'checkbox') {
                                $fields[] = $post_field;
                                $values[] = '1';
                            } else {
                                $fields[] = $post_field;
                                $values[] = '"'.$this->db_escape($value).'"';
                            }
                        }
                    }
                }
                
                foreach($this->cols as $field => $col) {
                    if (@$col['type'] == 'checkbox' && !isset($_POST[$attr_prefix.$field])) {
                        $fields[] = $field;
                        $values[] = '0';
                    } elseif (@$col['constraint']) {
                        $fields[] = $field;
                        $values[] = $col['constraint'];
                    }
                }

                if (sizeof($fields)) {
                    $this->db_query("INSERT INTO {$this->table} (".implode(',', $fields).') VALUES ('.implode(',', $values).')');
                }
                
                header('Location: '.$_SERVER['PHP_SELF'].'?updated=1&dbedit='.$this->uniqid);
                exit();
                
            case 'v':
            case 'view':
                if (@$_GET['updated']) {
                    $output .= '<p class="'.$attr_prefix.'msg">Row updated</p>';
                }
                $output .= '<table id="'.$attr_prefix.'table"><thead><tr>';
                foreach($this->cols as $field => $col) {
                    if (!isset($col['constraint'])) {
                        $output .= '<th>'.($col['name'] ? $col['name'] : $field).'</th>';
                    }
                }
                $sql_fields = $this->sql_fields($temp_field_suffix);
                
                if ($this->allow_del) {
                    $output .= '<th class="'.$attr_prefix.'del-col">Delete</th>';
                }
                $output .= '</tr></thead><tbody>';
                $rs = $this->db_query('SELECT *'.($this->allow_del_sql_condition ? ", IF({$this->allow_del_sql_condition}, 1, 0) AS dbEdit_allow_del" : '')
                                                .($this->allow_edit_sql_condition ? ", IF({$this->allow_edit_sql_condition}, 1, 0) AS dbEdit_allow_edit" : '')
                                                .$sql_fields
                                            ." FROM {$this->table}".($this->where ? ' WHERE '.$this->where : '').' ORDER BY '.($this->sql_order ? $this->sql_order : $this->primary.' ASC'));
                while($row = $this->db_fetch($rs)) {
                    if ($this->allow_edit && (!$this->allow_edit_sql_condition || $row['dbEdit_allow_edit'])) {
                        $output .= '<tr id="'.$attr_prefix.'row-'.$row[$this->primary].'" class="'.$attr_prefix.'editable" onclick="window.location.href=\''.$_SERVER['PHP_SELF'].'?a=e&amp;dbedit='.$this->uniqid.'&amp;id='.$row[$this->primary].'\'">';
                    } else {
                        $output .= '<tr id="'.$attr_prefix.'row-'.$row[$this->primary].'" class="'.$attr_prefix.'noteditable">';
                    }
                    foreach($this->cols as $field => $col) {
                        $output .= $this->output($row, $temp_field_suffix, $field, $col, $charset);
                    }
                    if ($this->allow_del) {
                        if (!$this->allow_del_sql_condition || $row['dbEdit_allow_del']) {
                            $output .= '<td class="'.$attr_prefix.'del-col"><a href="'.$_SERVER['PHP_SELF'].'?a=dc&amp;dbedit='.$this->uniqid.'&amp;id='.$row[$this->primary].'">Delete</a></td>';
                        } else {
                            $output .= '<td class="'.$attr_prefix.'del-col"></td>';
                        }
                    }
                    $output .= '</tr>';
                }
                $output .= '</tbody></table>';
                
                if ($this->allow_add) {
                    $output .= '<div class="'.$attr_prefix.'add"><a href="'.$_SERVER['PHP_SELF'].'?a=a&amp;dbedit='.$this->uniqid.'">Add</a></div>';
                }

                break;

            case 'dc':
            case 'deleteconfirm':
                    $sql_fields = $this->sql_fields($temp_field_suffix);
                    $row = $this->db_fetch($this->db_query("SELECT *{$sql_fields} FROM {$this->table} WHERE {$this->primary} = {$id}".$this->sql_condition($this->allow_del_sql_condition)));
                    $output .= '<table id="'.$attr_prefix.'table"><tbody>';
                    foreach($this->cols as $field => $col) {
                        if (!@$col['constraint']) {
                            $output .= "<tr><td>{$col['name']}</td><td>{$this->output($row, $temp_field_suffix, $field, $col, $charset)}</td></tr>";
                        }
                    }
                    $output .= '</tbody></table>
                    <form action="'.$_SERVER['PHP_SELF'].'" method="post">
                        <input type="hidden" name="dbedit" value="'.$this->uniqid.'" />
                        <input type="hidden" name="id" value="'.$id.'" />
                        <button type="submit" name="a" value="d">Delete</button>
                        <button type="submit" name="a" value="v" onclick="this.type=\'button\'; window.location.href=\''.$_SERVER['PHP_SELF'].'?dbedit='.$this->uniqid.'\';">Cancel</button>
                    </form>';
                break;
            
            case 'd':
            case 'delete':
                if ($this->allow_del) {
                    $this->db_query("DELETE FROM {$this->table} WHERE {$this->primary} = {$id}".$this->sql_condition($this->allow_del_sql_condition));
                }
                header('Location: '.$_SERVER['PHP_SELF'].'?dbedit='.$this->uniqid);
                exit();

            case 'e':
            case 'edit':
                if ($this->allow_edit) {
                    $allow_edit_fields = '';
                    foreach($this->cols as $field => $col) {
                        if (@$col['allow_edit']) {
                            $allow_edit_fields .= ", ({$col['allow_edit']}) AS allow_edit_of_{$field}{$temp_field_suffix}";
                        }
                    }
                    $row = $this->db_fetch($this->db_query("SELECT *{$allow_edit_fields} FROM {$this->table} WHERE {$this->primary} = {$id}".$this->sql_condition($this->allow_edit_sql_condition)));
                    if ($row) {
                        $output .= '<form id="'.$attr_prefix.'form" method="post" action="'.$_SERVER['PHP_SELF'].'"><fieldset>';
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
                                    $output .= '<input '.$disabled.'type="'.(@$col['type'] ? $col['type'] : 'text').'" id="'.$el_attr.'" name="'.$el_attr.'" '.(sizeof($classes) ? 'class="'.implode(' ', $classes).'" ' : '').'value="'.$this->html($row[$field], $charset).'" />';
                                }
                                $output .= '</div>';
                            }
                        }
                        $output .=
                        '</fieldset>
                        <fieldset class="submit">
                            <button type="submit" id="'.$attr_prefix.'submit" name="a" value="p">Edit</button>
                            <button type="reset">Reset</button>
                            <button type="submit" name="a" value="v" onclick="this.type=\'button\'; window.location.href=\''.$_SERVER['PHP_SELF'].'?dbedit='.$this->uniqid.'\';">Cancel</button>
                            <input type="hidden" name="dbedit" value="'.$this->uniqid.'" />
                            <input type="hidden" name="id" value="'.$id.'" />
                        </fieldset>
                        </form>';
                    }
                }
                break;

            case 'a':
            case 'add':
                $output .= '<form id="'.$attr_prefix.'form" method="post" action="'.$_SERVER['PHP_SELF'].'"><fieldset>';
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
                            $output .= '<input type="'.(@$col['type'] ? $col['type'] : 'text').'" id="'.$el_attr.'" name="'.$el_attr.'" '.(sizeof($classes) ? 'class="'.implode(' ', $classes).'" ' : '').'value="'.$this->html(@$col['default'], $charset).'" />';
                        }
                        $output .= '</div>';
                    }
                }
                $output .=
                '</fieldset>
                <fieldset class="submit">
                    <button type="submit" name="a" value="i">Add</button>
                    <button type="submit" name="a" value="v" onclick="this.type=\'button\'; window.location.href=\''.$_SERVER['PHP_SELF'].'?dbedit='.$this->uniqid.'\';">Cancel</button>
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
