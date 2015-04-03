<?php
/*
    Импорт данных из 1С.
	Следует учитывать, что все это выполняется не за один запрос,
	а за условно бесконечное, пока не будет выполнен импорт до успеха или ошибки.
	За это отвечают ответы success|progress|failure
*/
require_once dirname(dirname(__FILE__)) . '/exchange.class.php';
class mod1cWebExchangeCatalogImportProcessor extends mod1cWebExchangeExchangeProcessor
{
    protected $groups = array(); // Импортируемые группы
    public function initialize() 
    {
        if (!$this->getProperty('mode')) 
        {
            return 'Mode not exists';
        }
        $this->setProperties(array(
            "FILE_SIZE_LIMIT" => (int)$this->modx->getOption('shopmodx1c.postfile_size_limit', null, 200) * 1024,
            "USE_ZIP" => $this->modx->getOption('shopmodx1c.use_zip', null, true) ,
            "DIR_NAME" => $this->modx->getOption('shopmodx1c.import_dir', null, MODX_CORE_PATH . 'components/shopmodx1c/import_files/') ,
        ));
        return parent::initialize();
    }
    public function process() 
    {
        $Params = $this->getProperties();
        $DIR_NAME = $Params['DIR_NAME'];
        $mode = $Params['mode'];
        // Авторизация
        if ($mode == "checkauth") 
        {
            if (!$response = $this->modx->runProcessor('security/login', array(
                "username" => $_SERVER['PHP_AUTH_USER'],
                "password" => $_SERVER['PHP_AUTH_PW'],
            ))) 
            {
                $this->addOutput("Ошибка выполнения запроса.");
                return $this->failure("failure");
            }
            // else
            if ($response->isError()) 
            {
                if (!$msg = $response->getMessage()) 
                {
                    $msg = "Ошибка авторизации.";
                }
                $this->addOutput($msg);
                return $this->failure("failure");
            }
            $this->addOutput(session_name());
            $this->addOutput(session_id());
            return $this->success("success");
        }
        // else
        // Проверка, что пользователь авторизован
        if (!$this->modx->user->isAuthenticated($this->modx->context->key)) 
        {
            $this->addOutput("ERROR_AUTHORIZE");
            return $this->failure('failure');
        }
        // else
        $ABS_FILE_NAME = false;
        $WORK_DIR_NAME = false;
        if ($filename = $this->getProperty('filename')) 
        {
            $ABS_FILE_NAME = $DIR_NAME . $filename;
        }
        // Сохраняем импортируемый файл-выгрузку
        if (($mode == "file") && $ABS_FILE_NAME) 
        {
            if (function_exists("file_get_contents")) $DATA = file_get_contents("php://input");
            elseif (isset($GLOBALS["HTTP_RAW_POST_DATA"])) $DATA = & $GLOBALS["HTTP_RAW_POST_DATA"];
            else $DATA = false;
            $DATA_LEN = mb_strlen($DATA, 'latin1');
            if (isset($DATA) && $DATA !== false) 
            {
                if ($fp = fopen($ABS_FILE_NAME, "ab")) 
                {
                    $result = fwrite($fp, $DATA);
                    if ($result === $DATA_LEN) 
                    {
                        if ($_SESSION["SM_1C_IMPORT"]["zip"]) 
                        {
                            $_SESSION["SM_1C_IMPORT"]["zip"] = $ABS_FILE_NAME;
                        }
                        return $this->success("success");
                    }
                    else
                    {
                        $this->addOutput("ERROR_FILE_WRITE");
                        return $this->failure('failure');
                    }
                }
                else
                {
                    $this->addOutput("ERROR_FILE_OPEN");
                    return $this->failure('failure');
                }
            }
            else
            {
                $this->addOutput("ERROR_HTTP_READ");
                return $this->failure('failure');
            }
        }
        // Если переданный файл был zip-архивом, распаковываем его
        elseif (($mode == "import") && !empty($_SESSION["SM_1C_IMPORT"]["zip"])) 
        {
            $result = false;
            if ($this->modx->loadClass('compression.xPDOZip', XPDO_CORE_PATH, true, true)) 
            {
                $from = $_SESSION["SM_1C_IMPORT"]["zip"];
                $to = $this->getProperty('DIR_NAME');
                $archive = new xPDOZip($this->modx, $from);
                if ($archive) 
                {
                    $result = $archive->unpack($to);
                    $archive->close();
                }
            }
            if (!$result) 
            {
                $this->addOutput("Ошибка распаковки архива.");
                return $this->failure("failure");
            }
            // else
            $_SESSION["SM_1C_IMPORT"]["zip"] = false;
            $this->addOutput("Распаковка архива завершена.");
            return $this->success("progress");
        }
        /*
        Когда все данные переданы со стороны 1С,
        выполняем непосредственно обновление информации на стороне MODX-а.
        */
        elseif (($mode == "import") && $ABS_FILE_NAME) 
        {
            $NS = & $_SESSION["SM_1C_IMPORT"]["NS"];
            $strError = "";
            $strMessage = "";
            // Очищаем временные таблицы
            if ($NS["STEP"] < 1) 
            {
                $classes = array(
                    "Shopmodx1cTmpCategory",
                );
                foreach ($classes as $class) 
                {
                    if ($table = $this->modx->getTableName($class)) 
                    {
                        $this->modx->exec("TRUNCATE TABLE {$table}");
                    }
                }
                $NS["STEP"] = 1;
            }
            elseif ($NS["STEP"] == 1) 
            {
                $xml = simplexml_load_file($ABS_FILE_NAME);
                // Парсим группы-категории
                $this->parseGroups($xml->Классификатор->Группы);
                // Сохраняем первичные данные во временную таблицу
                $this->insertGroupsInDataBase();
                $NS["STEP"] = 2;
            }
            elseif ($NS["STEP"] == 2) 
            {
                /*
                Выполняем необходимые действия над данными,
                пока не будет импорт выполнен до конца.
                */
                $tmpClass = 'Shopmodx1cTmpCategory';
                $q = $this->modx->newQuery($tmpClass);
                $q->sortby('id');
                $q->limit(1);
                if ($o = $this->modx->getObject($tmpClass, $q)) 
                {
                    $o->remove();
                }
                // else
                else return $this->success('success');
            }
            return $this->success('progress');
        }
        elseif ($mode == "init") 
        {
            $this->clearImportDir();
            if (!is_dir($DIR_NAME)) 
            {
                $this->addOutput('ERROR_INIT');
                return $this->failure("failure");
            }
            else
            {
                $_SESSION["SM_1C_IMPORT"] = array(
                    "zip" => $Params["USE_ZIP"] && class_exists("ZipArchive") ,
                    "NS" => array(
                        "STEP" => 0,
                    ) ,
                );
                $this->addOutput("zip=" . ($_SESSION["SM_1C_IMPORT"]["zip"] ? "yes" : "no"));
                $this->addOutput("file_limit=" . $Params["FILE_SIZE_LIMIT"]);
                return $this->success('');
            }
        }
        else
        {
            $this->addOutput('UNKNOWN_COMMAND');
            return $this->failure('failure');
        }
        return $this->success('');
    }
    /*
        Очистка временной директории
    */
    protected function clearImportDir() 
    {
        $DIR_NAME = $this->getProperty('DIR_NAME');
        $this->modx->loadClass('modCacheManager', '', true, false);
        $modCacheManager = new modCacheManager($this->modx);
        $options = array(
            'deleteTop' => false,
            'skipDirs' => false,
            'extensions' => false,
            'delete_exclude_items' => array(
                '.gitignore',
            ) ,
        );
        $r = $modCacheManager->deleteTree($DIR_NAME, $options);
        return;
    }
    // Парсим группы
    protected function parseGroups(SimpleXMLElement & $groups, $parent = null) 
    {
        foreach ($groups->Группа as $group) 
        {
            $this->groups[] = array(
                "article" => (string)$group->Ид,
                "title" => (string)$group->Наименование,
                "parent" => (string)$parent,
            );
            if (!empty($group->Группы)) 
            {
                $this->parseGroups($group->Группы, $group->Ид);
            }
        }
        return;
    }
    /*
        Общая функция для составления запроса на массовую вставку записей
    */
    protected function insertInDataBase($table, array $rows, array $columns) 
    {
        $columns_str = implode(", ", $columns);
        $sql = "INSERT INTO {$table} 
            ({$columns_str}) 
            VALUES \n";
        $sql.= implode(",\n", $rows) . ";";
        $s = $this->modx->prepare($sql);
        $result = $s->execute();
        if (!$result) 
        {
            $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'SQL ERROR Import');
            $this->modx->log(xPDO::LOG_LEVEL_ERROR, print_r($s->errorInfo() , 1));
            $this->modx->log(xPDO::LOG_LEVEL_ERROR, $sql);
        }
        return $result;
    }
    protected function insertGroupsInDataBase() 
    {
        $table = $this->modx->getTableName('Shopmodx1cTmpCategory');
        $columns = array(
            "article",
            "title",
            "parent",
        );
        $rows = array();
        $linesPerStep = 500;
        $i = 0;
        foreach ($this->groups as $group) 
        {
            $i++;
            $article = $group['article'];
            $title = str_replace("'", "\'", $group['title']);
            $parent = ($group['parent'] ? "'{$group['parent']}'" : "NULL");
            $rows[] = "('{$article}', '{$title}', {$parent})";
            if ($i % $linesPerStep == 0) 
            {
                if (!$this->insertInDataBase($table, $rows, $columns)) 
                {
                    return $this->failure("Не удалось выполнить запрос");
                }
                $rows = array();
            }
        }
        if ($rows) 
        {
            $this->insertInDataBase($table, $rows, $columns);
        }
        return;
    }
}
return 'mod1cWebExchangeCatalogImportProcessor';
