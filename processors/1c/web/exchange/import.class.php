<?php
require_once __DIR__ . '/exchange.class.php';
class mod1cWebExchangeImportProcessor extends mod1cWebExchangeExchangeProcessor
{
    /**
     * удалять или нет временные файлы
     */
    protected $removeTmpFiles = true;
    #
    
    /**
     * очищать или нет временные таблицы
     */
    protected $clearTmpTables = true;
    #
    
    /**
     * переводить ли в транслит инф. сообщения
     */
    protected $convertToTranslit = false;
    #
    
    /**
     * сколько строк парсим за раз
     */
    protected $linesPerStep = 50;
    #
    
    /**
     * сколько записей в БД парсим за шаг (create|update)
     */
    protected $tableRowsPerStep = 15;
    #
    
    /**
     * tmp records statuses
     */
    const PROCESSED_STATUS = 1;
    const UNPROCESSED_STATUS = 0;
    #
    
    /**
     * import modes
     */
    // debug mode
    protected $debug = true;
    // this is 4 deep debugging (session, file props, etc…)
    protected $debugDeep = false;
    #
    
    /**
     * Метод формарования успешного/ошибочного ответа на сторону 1С (хотя обертки — плохо, но приходится много раз вызывать эти оба метода. поэтому вводим метод-обертку)
     */
    public function end($flag, $msg = '') 
    {
        if ($flag) 
        {
            return $this->success($msg);
        }
        # else
        $this->addOutput($msg);
        return $this->failure('failure');
    }
    #
    
    /**
     */
    public function initialize() 
    {
        if (!$this->getProperty('mode')) 
        {
            return $this->end(false, 'Mode does not exists');
        }
        #
        
        /**
         * debug
         */
        if ($this->debug) 
        {
            $this->modx->setLogLevel(xPDO::LOG_LEVEL_DEBUG);
        }
        #
        
        /**
         * props
         */
        $this->setProperties(array(
            "FILE_SIZE_LIMIT" => (int)$this->modx->getOption('shopmodx1c.postfile_size_limit', null, 200) * 1024,
            "USE_ZIP" => $this->modx->getOption('shopmodx1c.use_zip', null, true) ,
            "DIR_NAME" => $this->modx->getOption('shopmodx1c.import_dir', null, MODX_CORE_PATH . 'components/shopmodx1c/import_files/') ,
            #
            
            /**
             * processing
             */
            "process_items_per_step" => $this->tableRowsPerStep,
            #
            
        ));
        #
        
        /**
         */
        $this->loadParsers();
        #
        
        /**
         */
        if ($this->hasErrors()) 
        {
            return $this->end(false);
        }
        #
        return parent::initialize();
    }
    #
    
    /**
     * here we load different parsers
     */
    protected function loadParsers() 
    {
    }
    #
    
    /**
     * custom output with transliteration
     */
    public function addOutput($string) 
    {
        $this->modx->log($this->log_success_level, $string);
        #
        if ($this->convertToTranslit) 
        {
            $this->modx->getService('translit', $this->modx->getOption('friendly_alias_translit_class') , $this->modx->getOption('friendly_alias_translit_class_path'));
            if ($this->modx->translit) 
            {
                $string = $this->modx->translit->translate($string, 'russian-gosdep');
            }
        }
        else
        {
            $string = mb_convert_encoding($string, $this->getProperty('outputCharset', 'CP1251') , "UTF-8");
        }
        $this->outputData[] = $string;
    }
    #
    
    /**
     * process
     */
    public function process() 
    {
        $ABS_FILE_NAME = false;
        $WORK_DIR_NAME = false;
        $Params = $this->getProperties();
        $DIR_NAME = $Params['DIR_NAME'];
        $mode = $Params['mode'];
        #
        
        /**
         */
        if ($this->debugDeep) 
        {
            $this->modx->log(xPDO::LOG_LEVEL_DEBUG, print_r($Params, true));
            $this->modx->log(xPDO::LOG_LEVEL_DEBUG, print_r($_SESSION['SM_1C_IMPORT'], true));
        }
        #
        
        /**
         * authorization
         */
        if ($mode == "checkauth") 
        {
            if (!$response = $this->modx->runProcessor('security/login', array(
                "username" => $_SERVER['PHP_AUTH_USER'],
                "password" => $_SERVER['PHP_AUTH_PW'],
            ))) 
            {
                return $this->end(false, "Ошибка выполнения запроса");
            }
            // else
            if ($response->isError()) 
            {
                if (!$msg = $response->getMessage()) 
                {
                    $msg = "Ошибка авторизации.";
                }
                return $this->end(false, $msg);
            }
            $this->addOutput(session_name());
            $this->addOutput(session_id());
            return $this->end(true, 'success');
        }
        // else
        
        /**
         * check if user is authed
         */
        if (!$this->modx->user->isAuthenticated($this->modx->context->key)) 
        {
            $this->addOutput("ERROR_AUTHORIZE");
            return $this->failure('failure');
        }
        # else
        
        /**
         */
        if ($filename = $this->getProperty('filename')) 
        {
            $ABS_FILE_NAME = $DIR_NAME . $filename;
        }
        # else
        
        /**
         * initialization
         */
        if ($mode == "init") 
        {
            /**
             * check the settings
             */
            if (!$this->modx->getOption('shopmodx1c.article_tv')) 
            {
                $error = "Не указан ID TV-параметра для артикулов 1С";
                return $this->end(false, $error);
            }
            if (!$this->modx->getOption('shopmodx1c.catalog_root_id')) 
            {
                $error = "Не указан ID корневого раздела каталога";
                return $this->end(false, $error);
            }
            if (!$this->modx->getOption('shopmodx1c.product_default_template')) 
            {
                $error = "Не указан ID шаблона для товаров";
                return $this->end(false, $error);
            }
            if (!$this->modx->getOption('shopmodx1c.category_default_template')) 
            {
                $error = "Не указан ID шаблона для категории";
                return $this->end(false, $error);
            }
            #
            
            /**
             */
            if (!is_dir($DIR_NAME)) 
            {
                return $this->end(false, 'ERROR_INIT');
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
                return $this->end(true);
            }
        }
        #
        
        /**
         * try to save tmp file
         */
        else if (($mode == "file") && $ABS_FILE_NAME) 
        {
            if (function_exists("file_get_contents")) 
            {
                $DATA = file_get_contents("php://input");
            }
            else if (isset($GLOBALS["HTTP_RAW_POST_DATA"])) 
            {
                $DATA = & $GLOBALS["HTTP_RAW_POST_DATA"];
            }
            else
            {
                $DATA = false;
            }
            #
            $DATA_LEN = mb_strlen($DATA, 'latin1');
            if (isset($DATA) && $DATA !== false) 
            {
                if ($fp = fopen($ABS_FILE_NAME, "ab")) 
                {
                    $result = fwrite($fp, $DATA);
                    if ($result === $DATA_LEN) 
                    {
                        $info = pathinfo($ABS_FILE_NAME);
                        #
                        if ($this->debugDeep) 
                        {
                            $this->modx->log(xPDO::LOG_LEVEL_DEBUG, print_r($info, true));
                        }
                        #
                        
                        /**
                         * try to analyze file type
                         */
                        switch ($info['extension']) 
                        {
                        case 'zip':
                            $_SESSION["SM_1C_IMPORT"]["zip"] = $ABS_FILE_NAME;
                        break;
                        default:
                            $_SESSION["SM_1C_IMPORT"]["zip"] = false;
                        break;
                        }
                        return $this->end(true, 'success');
                    }
                    else
                    {
                        return $this->end(false, "ERROR_FILE_WRITE");
                    }
                }
                else
                {
                    return $this->end(false, "ERROR_FILE_OPEN");
                }
            }
            else
            {
                return $this->end(false, "ERROR_HTTP_READ");
            }
        }
        #
        
        /**
         * if a file haz zip ext
         */
        else if (($mode == "import") && !empty($_SESSION["SM_1C_IMPORT"]["zip"])) 
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
                return $this->end(false, "Ошибка распаковки архива");
            }
            # else
            $_SESSION["SM_1C_IMPORT"]["zip"] = false;
            $this->addOutput("Распаковка архива завершена");
            return $this->success("progress");
        }
        #
        
        /**
         * Когда все данные переданы со стороны 1С, выполняем непосредственно обновление информации на стороне MODX-а.
         */
        elseif (($mode == "import") && $ABS_FILE_NAME) 
        {
            $NS = & $_SESSION["SM_1C_IMPORT"]["NS"];
            $strError = "";
            $strMessage = "";
            $this->modx->log(xPDO::LOG_LEVEL_INFO, "STEP: " . $NS["STEP"]);
            /**
             * define all steps
             */
            $this->setSteps($ABS_FILE_NAME, $NS, $strMessage, $strError);
            #
            return $this->success('progress');
        }
        else if ($mode = 'deactivate') 
        {
            $msg = 'Завершение импорта. Очистка таблиц…';
            $this->modx->log(xPDO::LOG_LEVEL_INFO, $msg);
            #
            
            /**
             * Очищаем временные таблицы
             */
            $this->clearTmpTables();
            #
            
            /**
             * Очищаем импорт-директорию
             */
            $this->clearImportDir();
            #
            return $this->end(true, 'success');
        }
        else
        {
            return $this->end(false, 'UNKNOWN_COMMAND ' . $mode);
        }
        # else
        
        /**
         */
        return $this->end(true);
    }
    #
    
    /**
     */
    protected function setSteps(&$NS, &$strMessage, &$strError) 
    {
        switch ($NS["STEP"]) 
        {
        default:
            return $this->end(true, 'success');
        break;
        }
    }
    #
    
    /**
     * log items left
     */
    public function logCount($class, xPDOQuery & $q, $name = 'items') 
    {
        /**
         * informer
         */
        if (!$q) 
        {
            return;
        }
        $c = clone $q;
        $c->limit(0);
        $count = $this->modx->getCount($class, $c);
        $this->addOutput("{$count} {$name} left…");
    }
    #
    # метод завершения обработки временной сущности
    public function processTmpEntity(xPDOObject & $object, $status = self::PROCESSED_STATUS) 
    {
        if (!$status) 
        {
            $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Не получен статус завершения операции');
            return false;
        }
        $object->set('processed', $status);
        if (!$object->save()) 
        {
            $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Не удалось обновить сущность с артикулом: ' . $object->article ? $object->article : $object->id);
            return false;
        }
        return true;
    }
}
return 'mod1cWebExchangeImportProcessor';
