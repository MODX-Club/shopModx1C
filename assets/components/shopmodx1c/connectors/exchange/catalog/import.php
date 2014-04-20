<?php

/*
    Импорт каталога из 1С
*/

$_GET['_action'] = 'exchange/catalog/import';

require_once dirname(dirname(dirname(__FILE__))) . '/connector.php';