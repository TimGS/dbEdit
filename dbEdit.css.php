<?php
header("Content-type: text/css");
if (isset($_GET['prefix'])) {
    echo str_replace('dbEditPrefix', $_GET['prefix'], file_get_contents(dirname(__FILE__).'/dbEdit.css'));
}
