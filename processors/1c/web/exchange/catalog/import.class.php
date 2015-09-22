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
    /**
     * классы временных объектов
     */
    protected $tmpCategoriesClass = 'Shopmodx1cTmpCategory';
    protected $tmpProductsClass = 'Shopmodx1cTmpProduct';
    protected $tmpPropertiesClass = 'Shopmodx1cTmpProperty';
    protected $tmpPropertyValuesClass = 'Shopmodx1cTmpPropertiesValue';
    protected $tmpPriceClass = 'Shopmodx1cTmpPrice';
    protected $tmpPriceTypeClass = 'Shopmodx1cTmpPriceType';
    #
    
    /**
     * Импортируемые группы
     */
    protected $groups = array();
    #
    
    /**
     * Импортируемые свойства (твшки)
     */
    protected $_properties = array();
    #
    
    /**
     * Значения импортируемых свойств
     */
    protected $_propertyValues = array();
    #
    
    /**
     * удалять или нет временные файлы
     */
    protected $removeTmpFiles = false;
    #
    
    /**
     * очищать или нет временные таблицы
     */
    protected $clearTmpTables = false;
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
     * статусы обработки временных записей
     */
    const PROCESSED_STATUS = 1;
    const UNPROCESSED_STATUS = 0;
    #
    
    /**
     * режимы импорта
     */
    // режим отладки
    protected $debug = true;
    // данный режим служит для вывода информации глубокой отладки (параметры сессии, файлов и т.д...)
    protected $debugDeep = true;
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
            
            /**
             * events
             */
            "NEW_PROPERTY_EVENT_NAME" => 'shopmodx1c.new_property',
            "NEW_PROPERTIES_VALUES_EVENT_NAME" => 'OnShopmodx1cPropertiesValuesCreate'
        ));
        #
        
        /**
         * подгружаем парсеры
         */
        $ok = $this->loadPriceParser();
        if ($ok === null) 
        {
            return $this->end(false, 'Can\'t load price\'s parser');
        }
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
     * DI
     */
    protected function loadPriceParser() 
    {
        if (!property_exists($this->modx, 'priceparser')) 
        {
            return $this->modx->getService('priceparser', 'shopModx1C.parser.priceParser', $this->getModulePath() , array(
                'linesPerStep' => $this->linesPerStep
            ));
        }
        # else
        return true;
    }
    #
    
    /**
     * custom output with transliteration
     */
    protected function addOutput($string) 
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
         * Авторизация
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
         * Проверка, что пользователь авторизован
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
         * Первичная инициализация
         */
        if ($mode == "init") 
        {
            /**
             * Проверяем основные настройки
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
         * Сохраняем импортируемый файл-выгрузку
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
                         * решаем как обрабатывать файл
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
         * Если переданный файл был zip-архивом, распаковываем его
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
            switch ($NS["STEP"]) 
            {
            case 0:
                /**
                 * Перемещаем картинки в папку изображений товаров
                 */
                $DIR_NAME = $this->getProperty('DIR_NAME');
                $source = $DIR_NAME . 'import_files/';
                $target = $this->modx->getOption('shopmodx1c.images_path', null, MODX_ASSETS_PATH . 'images/') . "import_files/";
                $this->modx->loadClass('modCacheManager', '', true, false);
                $modCacheManager = new modCacheManager($this->modx);
                $r = $modCacheManager->copyTree($source, $target);
                $NS["STEP"] = 1;
                $this->addOutput('Swtich to the 1-th step');
                return $this->end(true, 'success');
            break;
            case 1:
                $this->importCategories($ABS_FILE_NAME);
                $NS["STEP"] = 2;
                $this->addOutput('Swtich to the 2-th step');
            break;
                # Парсим свойства и их значения
                
            case 2:
                $this->importProperties($ABS_FILE_NAME);
                $NS['STEP'] = 3;
                $this->addOutput('Swtich to the 3-th step');
            break;
                // Парсим товары
                
            case 3:
                $this->importGoods($ABS_FILE_NAME);
                $NS["STEP"] = 4;
                $this->addOutput('Swtich to the 4-th step');
            break;
                #
                
                /**
                 *  парсим цены
                 */
            case 4:
                $nextStep = 5;
                #
                
                /**
                 */
                $ok = $this->importPrices($ABS_FILE_NAME);
                if ($ok !== true) 
                {
                    return $this->end(false, 'failure');
                }
                #
                $NS["STEP"] = $nextStep;
                $this->addOutput("Swtich to the {$nextStep}-th step");
            break;
                // Сохраняем категории
                
            case 5:
                /*
                Выполняем необходимые действия над данными,
                пока не будет импорт выполнен до конца.
                */
                $tmpClass = $this->tmpCategoriesClass;
                $article_tv_id = $this->modx->getOption('shopmodx1c.article_tv');
                $catalog_root_id = $this->modx->getOption('shopmodx1c.catalog_root_id');
                $catalog_tmp_root_id = $this->modx->getOption('shopmodx1c.catalog_tmp_root_id');
                $category_template = $this->modx->getOption('shopmodx1c.category_default_template');
                $limit = $this->getProperty('process_items_per_step');
                #
                
                /**
                 * если в настройках указан айдишник для временного каталога, то все категории копируем туда (подготовка для сортировки)
                 */
                if ($catalog_tmp_root_id) 
                {
                    $catalog_root_id = $catalog_tmp_root_id;
                }
                #
                $q = $this->modx->newQuery($tmpClass);
                $q->leftJoin('modTemplateVarResource', 'tv_article', "tv_article.tmplvarid = {$article_tv_id} AND {$tmpClass}.article = tv_article.value");
                $q->where(array(
                    "tv_article.id" => null,
                    "processed" => self::UNPROCESSED_STATUS,
                ));
                $q->select(array(
                    'tv_article.id as article_id',
                    "{$tmpClass}.*"
                ));
                $q->sortby("{$tmpClass}.id");
                $q->limit($limit);
                #
                
                /**
                 * Получаем все группы/категории из 1С
                 */
                if ($groups = $this->modx->getCollection($tmpClass, $q)) 
                {
                    /**
                     * logging
                     */
                    $this->logCount($tmpClass, $q, 'categories');
                    #
                    
                    /**
                     * Проходимся по каждому и создаем категорию
                     */
                    foreach ($groups as $group) 
                    {
                        /*
                                Создаем новую категорию
                        */
                        // Если указан артикул родителя, то пытаемся его получить
                        if ($group->parent) 
                        {
                            if (!$parent = $this->getResourceIdByArticle($group->parent)) 
                            {
                                $error = "Не был получен раздел с артикулом '{$group->parent}'";
                                $this->modx->log(1, $error);
                                $this->addOutput($error);
                                return $this->failure('failure');
                            }
                        }
                        else
                        {
                            $parent = $catalog_root_id;
                        }
                        $data = array(
                            "pagetitle" => $group->title,
                            "parent" => $parent,
                            "template" => $category_template,
                            "isfolder" => 1,
                            "tv{$article_tv_id}" => $group->article,
                        );
                        if (!$response = $this->modx->runProcessor('resource/create', $data)) 
                        {
                            $error = "Ошибка выполнения процессора";
                            $this->modx->log(xPDO::LOG_LEVEL_WARN, $error);
                            $this->addOutput($error);
                            return $this->failure('failure');
                        }
                        //else
                        if ($response->isError()) 
                        {
                            if (!$error = $response->getMessage()) 
                            {
                                $error = "Не удалось создать раздел с артикулом '{$article}'";
                            }
                            $this->modx->log(xPDO::LOG_LEVEL_WARN, $error);
                            $this->addOutput($error);
                            return $this->failure('failure');
                        }
                        // else
                        $o = $response->getObject();
                        $category_id = $o['id'];
                        if ($object = $this->modx->getObject('modResource', $category_id)) 
                        {
                            $object->set('alias', $object->id);
                            $object->save();
                        }
                        $group->set('processed', self::PROCESSED_STATUS);
                        $group->save();
                    }
                    #
                    
                }
                // else
                else 
                {
                    $this->addOutput('Swtich to the 6-th step');
                    $NS["STEP"] = 6;
                }
            break;
            case 6:
                // создаем необходимые тв-параметры
                $tmpClass = $this->tmpPropertiesClass;
                $product_template = $this->modx->getOption('shopmodx1c.product_default_template');
                $limit = $this->getProperty('process_items_per_step');
                $q = $this->modx->newQuery($tmpClass);
                // создаем только несуществующие тв
                $q->leftJoin('modTemplateVar', 'tv', "tv.name = {$tmpClass}.article");
                $q->where(array(
                    "tv.id" => null,
                    "processed" => self::UNPROCESSED_STATUS,
                ));
                $q->sortby("{$tmpClass}.id");
                // получаем опцию, содержащую имя уник. идентификатора, для определения уник. ключа импорта
                $_keyOption = $this->modx->getOption('shopmodx1c.article_field_name');
                # исключаем из списка тв-параметров твшку, которая хранит ключ товара из 1c
                if ($_keyOption) 
                {
                    $q->where(array(
                        'title:!=' => $_keyOption
                    ));
                }
                $q->limit($limit);
                #
                
                /**
                 * Получаем все свойства из 1С
                 */
                if ($tvs = $this->modx->getCollection($tmpClass, $q)) 
                {
                    /**
                     * logging
                     */
                    $this->logCount($tmpClass, $q, 'tvs');
                    #
                    foreach ($tvs as $tv) 
                    {
                        $data = array(
                            "caption" => $tv->title,
                            "name" => $tv->article,
                            "locked" => true,
                            "template" => $product_template,
                            "templates" => array(
                                array(
                                    'access' => true,
                                    'id' => $product_template
                                )
                            )
                        );
                        if (!$response = $this->modx->runProcessor('element/tv/create', $data)) 
                        {
                            $error = "Ошибка выполнения процессора";
                            $this->modx->log(1, $error);
                            $this->addOutput($error);
                            return $this->failure('failure');
                        }
                        //else
                        $name = $tv->title;
                        if ($response->isError()) 
                        {
                            if (!$error = $response->getMessage()) 
                            {
                                $error = "Не удалось создать tv с именем '{$name}'";
                            }
                            $this->modx->log(1, $error);
                            $this->addOutput($error);
                            return $this->failure('failure');
                        }
                        $tv->set('processed', self::PROCESSED_STATUS);
                        $tv->save();
                    }
                    #
                    
                }
                else
                {
                    $this->addOutput('Swtich to the 7-th step');
                    $NS["STEP"] = 7;
                }
            break;
            case 7:
                /*
                Выполняем необходимые действия над данными,
                пока не будет импорт выполнен до конца.
                */
                // Сохраняем/обновляем товары
                $tmpClass = $this->tmpProductsClass;
                $article_tv_id = $this->modx->getOption('shopmodx1c.article_tv');
                $image_tv_id = $this->modx->getOption('shopmodx1c.product_image_tv');
                $currency = $this->modx->getOption('shopmodx.default_currency');
                $products_template = $this->modx->getOption('shopmodx1c.product_default_template');
                $limit = $this->getProperty('process_items_per_step');
                // получаем опцию, содержащую имя уник. идентификатора, для определения уник. ключа импорта
                $_keyOption = $this->modx->getOption('shopmodx1c.article_field_name');
                if (!empty($_keyOption)) 
                {
                    $_keyField = $this->modx->getObject('Shopmodx1cTmpProperty', array(
                        'title' => $_keyOption
                    ));
                }
                // Получаем первичные данные по не обработанным товарам
                $q = $this->modx->newQuery($tmpClass);
                // раньше артикул товара забивался в твшку. сейчас мы переносим эти данные в поле доп. таблицы
                # $q->leftJoin('modTemplateVarResource', 'tv_article', "tv_article.tmplvarid = {$article_tv_id} AND {$tmpClass}.article = tv_article.value");
                /*
                    В импорте, кроме внутреннего айди товара 1с, передается уникальный идентификатор товара (доп. функционал 1с),
                        т.к. 1с, при обновлении данных, может изменить внутренний айдишник товара.
                    В настройках модуля импорта есть спец. пункт, который хранит название свойства в выгрузке, хранящее новый уникальный айди.
                    В нашей системе этот уникальный айдишник сохраняется, как артикул, при этом мы также храним внутренний айдишник 1с
                    
                    Поля находятся в модели продукта. sm_articul — уник. айди
                    sm_externalKey — для доп. поля
                */
                /**
                 * UPD
                 * т.к. мы предусматриваем возможность передачи кастомного айди, то если он у нас указан — пытаемся получать связку временны товар - товар в зивисимости от ключа
                 * если ключ кастомный, то артикул в поле externalKey, нет — основное поле
                 */
                if (empty($_keyOption)) 
                {
                    $q->leftJoin('ShopmodxProduct', 'Product', "Product.sm_article = {$tmpClass}.article");
                }
                else
                {
                    $q->leftJoin('ShopmodxProduct', 'Product', "Product.sm_externalKey = {$tmpClass}.article");
                }
                $q->where(array(
                    "processed" => self::UNPROCESSED_STATUS,
                ));
                $q->select(array(
                    # "tv_article.id as tv_article_id",
                    # "tv_article.contentid as resource_id",
                    "Product.resource_id as resource_id",
                    "{$tmpClass}.*",
                ));
                $q->limit($limit);
                # $q->prepare();
                # print $q->toSQL();
                # die;
                
                /**
                 * Получаем все товары из 1С
                 */
                if ($products = $this->modx->getCollection($tmpClass, $q)) 
                {
                    /**
                     * logging
                     */
                    $this->logCount($tmpClass, $q, 'goods');
                    #
                    // Проходимся по каждому товару
                    foreach ($products as $product) 
                    {
                        $article = $product->article;
                        /**
                         * получаем цену
                         */
                        # $price = $this->getGoodsPrice($product->article);
                        #
                        
                        /**
                         * Если товар уже есть в магазине, то обновляем его
                         */
                        $_rid = $product->resource_id;
                        if ($_rid) 
                        {
                            $good = $this->modx->getObject('ShopmodxResourceProduct', $_rid);
                            // обновляем твшки
                            $this->setTVs($good, $product->extended);
                            # $goodProduct = $good->Product;
                            # $goodProduct->set('sm_price', $price);
                            # $good->save();
                            
                        }
                        // иначе создаем новый
                        else 
                        {
                            // Определяем категорию, куда товар создавать
                            if (!$groups = json_decode($product->groups, 1) OR !$group = current($groups)) 
                            {
                                $error = "Не был получен раздел для товара с артикулом '{$article}'";
                                $this->modx->log(xPDO::LOG_LEVEL_WARN, $error);
                                $this->modx->log(xPDO::LOG_LEVEL_WARN, print_r($product->toArray() , 1));
                                $this->addOutput($error);
                                #
                                
                                /**
                                 * ставим флаг «обработано» для товара
                                 */
                                $this->processTmpEntity($product);
                                return $this->failure('failure');
                            }
                            // else
                            // Проверяем наличие категории в каталоге
                            if (!$parent = $this->getResourceIdByArticle($group)) 
                            {
                                $error = "Не был получен раздел с артикулом '{$group}'";
                                $this->modx->log(xPDO::LOG_LEVEL_WARN, $error);
                                $this->modx->log(xPDO::LOG_LEVEL_WARN, print_r($product->toArray() , 1));
                                $this->addOutput($error);
                                #
                                
                                /**
                                 * ставим флаг «обработано» для товара
                                 */
                                $this->processTmpEntity($product);
                                return $this->failure('failure');
                            }
                            # else
                            
                            /**
                             */
                            $content = preg_replace("/[\n]/", "<br />\n", $product->description);
                            $data = array(
                                "class_key" => "ShopmodxResourceProduct",
                                "pagetitle" => $product->title,
                                "content" => $content,
                                "parent" => $parent,
                                "template" => $products_template,
                                "isfolder" => 0,
                                # "tv{$article_tv_id}"      => $article,
                                "sm_currency" => $currency,
                                # "sm_price" => $price,
                                
                            );
                            # print_r($_keyOption);
                            # print_r($_keyField->toArray());
                            # print_r($product->toArray());
                            # die;
                            // если в наcтройках указана опция, в которой хранится уник. айди, то решаем какой параметр — основной
                            if (!empty($_keyOption)) 
                            {
                                $data["sm_externalKey"] = $product->article;
                                $ext = $product->extended;
                                # $this->modx->log(1,print_r($product->toArray(),1));
                                # $this->modx->log(1,print_r($data,1));
                                # if(is_object($_keyField)){
                                #     $this->modx->log(1,print_r($_keyField->toArray(),1));
                                # }
                                # $this->modx->log(1,print_r($ext,1));
                                # $this->addOutput($product->extended);
                                # $this->addOutput($ext);
                                if ($ext && is_object($_keyField)) 
                                {
                                    $ext = json_decode($ext, 1);
                                    $data["sm_article"] = $ext[$_keyField->article];
                                }
                                #
                                
                                /**
                                 * if there are errors
                                 */
                                if (!$data["sm_article"]) 
                                {
                                    $error = "Не был получен уник. идентификатор товара (артикул) '{$product->article}'";
                                    $this->modx->log(xPDO::LOG_LEVEL_WARN, $error);
                                    $this->modx->log(xPDO::LOG_LEVEL_WARN, print_r($product->toArray() , 1));
                                    /**
                                     * ставим флаг «обработано» для товара
                                     */
                                    $this->processTmpEntity($product);
                                    $this->addOutput($error);
                                    return $this->failure('failure');
                                }
                            }
                            else
                            {
                                $data["sm_article"] = $product->article;
                            }
                            #
                            # print_r($data);
                            # die;
                            
                            /**
                             * check the image
                             */
                            if ($product->image && $image_tv_id) 
                            {
                                $data["tv{$image_tv_id}"] = $product->image;
                            }
                            # else
                            
                            /**
                             * resource creating
                             */
                            if (!$response = $this->modx->runProcessor('resource/create', $data)) 
                            {
                                $error = "Ошибка выполнения процессора";
                                $this->modx->log(xPDO::LOG_LEVEL_WARN, $error);
                                $this->modx->log(xPDO::LOG_LEVEL_WARN, print_r($product->toArray() , 1));
                                $this->addOutput($error);
                                #
                                
                                /**
                                 * ставим флаг «обработано» для товара
                                 */
                                $this->processTmpEntity($product);
                                return $this->failure('failure');
                                # continue;
                                
                            }
                            //else
                            if ($response->isError()) 
                            {
                                if (!$error = $response->getMessage()) 
                                {
                                    $error = "Не удалось создать товар с артикулом '{$article}'";
                                }
                                $this->modx->log(xPDO::LOG_LEVEL_WARN, $error);
                                $this->modx->log(xPDO::LOG_LEVEL_WARN, print_r($product->toArray() , 1));
                                $this->modx->log(xPDO::LOG_LEVEL_WARN, print_r($response->getResponse() , true));
                                $this->addOutput($error);
                                /**
                                 * ставим флаг «обработано» для товара
                                 */
                                $this->processTmpEntity($product);
                                return $this->failure('failure');
                                # continue;
                                
                            }
                            /*
                             * если объект успешно создан — набиваем твшки;
                            */
                            else 
                            {
                                $data = $response->getObject();
                                $resource = $this->modx->getObject('ShopmodxResourceProduct', $data['id']);
                                if (!$resource) 
                                {
                                    $error = "Не был получен объект товара после создания оного. артикул: '{$product->article}'. Значения тв-параметров не будут обновлены";
                                    $this->modx->log(xPDO::LOG_LEVEL_WARN, $error);
                                    $this->modx->log(xPDO::LOG_LEVEL_WARN, print_r($product->toArray() , 1));
                                    $this->addOutput($error);
                                    /**
                                     * ставим флаг «обработано» для товара
                                     */
                                    $this->processTmpEntity($product);
                                    return $this->failure('failure');
                                    # continue;
                                    
                                }
                                else
                                {
                                    $this->setTVs($resource, $product->extended);
                                }
                            }
                        }
                        #
                        
                        /**
                         * ставим флаг «обработано» для товара
                         */
                        $this->processTmpEntity($product);
                    }
                    #
                    
                }
                // else
                else 
                {
                    $this->addOutput('Swtich to the 7.5-th step');
                    $NS["STEP"] = 7.5;
                }
            break;
                # for custom events which
                
            case 7.5:
                $nextStep = 8;
                $this->savePrices();
                $this->addOutput("Swtich to the {$nextStep}-th step");
                $NS["STEP"] = $nextStep;
            break;
            case 8:
                # Здесь мы обрабатываем значения свойств тв-параметров.
                # Мы будем получать коллекцию объектов из бд, созданных ранее и выбрасывать их вместе с событием в систему
                # по завершению мы будем менять флаг элемента
                $tmpClass = $this->tmpPropertyValuesClass;
                $limit = $this->getProperty('process_items_per_step');
                $_keyOption = $this->modx->getOption('shopmodx1c.article_field_name');
                #
                $c = $this->modx->newQuery($tmpClass);
                $c->where(array(
                    "processed" => self::UNPROCESSED_STATUS,
                ));
                #
                
                /**
                 * если в массиве свойств передается уникалный ключ товара, то пропускаем такие свойства, т.к. они нам не нужны для формирования наборов параметров
                 */
                if ($_keyOption) 
                {
                    $c->innerJoin($this->tmpPropertiesClass, 'tmpProperty', "tmpProperty.article != {$tmpClass}.parent and tmpProperty.title = '{$_keyOption}'");
                }
                $c->select(array(
                    "{$tmpClass}.*"
                ));
                $c->limit($limit);
                #
                # $c->prepare();
                # print $c->toSQL();
                # die;
                #
                if ($collection = $this->modx->getCollection($tmpClass, $c)) 
                {
                    /**
                     * logging
                     */
                    $this->logCount($tmpClass, $q, 'tv values');
                    #
                    foreach ($collection as $obj) 
                    {
                        # if we have a property
                        if ($event = $this->getProperty('NEW_PROPERTIES_VALUES_EVENT_NAME')) 
                        {
                            $response = $this->modx->invokeEvent($event, array(
                                'propertyValue' => & $obj
                            ));
                        }
                        /**
                         * ставим флаг «обработано» для свойства
                         */
                        $this->processTmpEntity($obj);
                    }
                }
                # else
                else 
                {
                    return $this->end(true, 'success');
                }
            break;
            }
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
     * парсинг цен
     */
    public function importPrices($ABS_FILE_NAME) 
    {
        $ok = $this->modx->priceparser->parseXML($ABS_FILE_NAME);
        if ($ok !== true) 
        {
            return $ok;
        }
        return true;
    }
    public function savePrices() 
    {
        $ok = $this->modx->priceparser->savePrices();
        if ($ok !== true) 
        {
            return $ok;
        }
        return true;
    }
    #
    # /**
    #  * получение цены товара
    #  */
    # public function getGoodsPrice($goodId)
    # {
    #     $ok = $this->modx->priceparser->getGoodsPrice($goodId);
    #     if ($ok !== true)
    #     {
    #         return $ok;
    #     }
    #     return true;
    # }
    #
    # обработка категорий
    public function importCategories($ABS_FILE_NAME) 
    {
        // Парсинг большого документа посредством XMLReader с Expand - DOM/DOMXpath
        $reader = new XMLReader();
        $reader->open($ABS_FILE_NAME);
        while ($reader->read()) 
        {
            if (($reader->nodeType == XMLReader::ELEMENT) && ($reader->name == 'КоммерческаяИнформация')) 
            {
                while ($reader->read()) 
                {
                    if (($reader->nodeType == XMLReader::ELEMENT) && ($reader->name == 'Классификатор')) 
                    {
                        while ($reader->read()) 
                        {
                            // парсим группы
                            if (($reader->nodeType == XMLReader::ELEMENT) && ($reader->name == 'Группы')) 
                            {
                                while ($reader->read()) 
                                {
                                    // парсим группы
                                    if (($reader->nodeType == XMLReader::ELEMENT && ($reader->name == 'Группа'))) 
                                    {
                                        $this->importCategoriesStuff($reader);
                                        $reader->next();
                                    }
                                    if ($reader->nodeType == XMLReader::END_ELEMENT && $reader->localName == 'Группы') 
                                    {
                                        break;
                                    }
                                }
                            }
                            if ($reader->nodeType == XMLReader::END_ELEMENT && $reader->localName == 'Классификатор') 
                            {
                                break;
                            }
                        }
                    }
                    elseif (($reader->nodeType == XMLReader::ELEMENT) && ($reader->name == 'Каталог')) 
                    {
                        $reader->next();
                    }
                }
            }
        }
    }
    protected function importCategoriesStuff($reader) 
    {
        $node = $reader->readOuterXML();
        $xml = simplexml_load_string($node);
        // Парсим группы-категории
        $this->parseGroup($xml);
        // Сохраняем первичные данные во временную таблицу
        $this->insertGroupsInDataBase();
    }
    #
    
    /**
     * импорт свойств (характеристики) и их значений
     */
    public function importProperties($ABS_FILE_NAME) 
    {
        // Парсинг большого документа посредством XMLReader с Expand - DOM/DOMXpath
        $reader = new XMLReader();
        $reader->open($ABS_FILE_NAME);
        while ($reader->read()) 
        {
            if (($reader->nodeType == XMLReader::ELEMENT) && ($reader->name == 'КоммерческаяИнформация')) 
            {
                while ($reader->read()) 
                {
                    if (($reader->nodeType == XMLReader::ELEMENT) && ($reader->name == 'Классификатор')) 
                    {
                        while ($reader->read()) 
                        {
                            // парсим свойства товара (твшки)
                            if (($reader->nodeType == XMLReader::ELEMENT) && ($reader->name == 'Свойства')) 
                            {
                                while ($reader->read()) 
                                {
                                    // парсим группы
                                    if (($reader->nodeType == XMLReader::ELEMENT && ($reader->name == 'Свойство'))) 
                                    {
                                        $parent = null;
                                        $isGood = false;
                                        #
                                        
                                        /**
                                         * Парсим свойства
                                         */
                                        $xml = $this->importPropertiesStuff($reader, $isGood);
                                        #
                                        
                                        /**
                                         * если у нас текущее свойство является свойством товара (твшка), то процессим его словарь значений
                                         */
                                        if ($isGood !== false and $xml->ВариантыЗначений and $xml->ТипЗначений == 'Справочник') 
                                        {
                                            /*
                                                на данном этапе происходит импорт справочника. мы заносим значения во временную таблицу.
                                            */
                                            # для привязки значений свойств к свойствам
                                            $parent = current((array)$xml->Ид);
                                            while ($reader->read()) 
                                            {
                                                # парсим варианты значений свойств
                                                if (($reader->nodeType == XMLReader::ELEMENT && ($reader->name == 'Справочник'))) 
                                                {
                                                    # some action
                                                    $this->importPropertiesValuesStuff($reader, $parent);
                                                    if ($reader->nodeType == XMLReader::END_ELEMENT && $reader->localName == 'Справочник') 
                                                    {
                                                        break;
                                                    }
                                                }
                                                if ($reader->nodeType == XMLReader::END_ELEMENT && $reader->localName == 'ВариантыЗначений') 
                                                {
                                                    break;
                                                }
                                            }
                                        }
                                    }
                                    if ($reader->nodeType == XMLReader::END_ELEMENT && $reader->localName == 'Свойства') 
                                    {
                                        break;
                                    }
                                }
                            }
                            if ($reader->nodeType == XMLReader::END_ELEMENT && $reader->localName == 'Классификатор') 
                            {
                                break;
                            }
                        }
                    }
                    elseif (($reader->nodeType == XMLReader::ELEMENT) && ($reader->name == 'Каталог')) 
                    {
                        $reader->next();
                    }
                }
            }
        }
    }
    protected function importPropertiesStuff($reader, &$isGood = false) 
    {
        $node = $reader->readOuterXML();
        $xml = simplexml_load_string($node);
        // Парсим свойства
        $isGood = $this->parseProperty($xml);
        // Сохраняем первичные данные во временную таблицу
        $this->insertPropertiesInDataBase();
        return $xml;
    }
    protected function importPropertiesValuesStuff($reader, $parent) 
    {
        $node = $reader->readOuterXML();
        $xml = simplexml_load_string($node);
        $_id = (string)$xml->ИдЗначения;
        #
        
        /**
         * если айди boolean, то пропускаем и пишем в лог
         */
        if ($_id == 'true' or $_id == 'false') 
        {
            $this->modx->log(xPDO::LOG_LEVEL_WARN, "Попытка импорта некорректного значения характеристики товара. Артикул: {$parent}");
            $this->modx->log(xPDO::LOG_LEVEL_WARN, print_r($node, 1));
        }
        #
        
        /**
         * если айди передается, но пустое
         */
        else if (!$_id) 
        {
            $this->modx->log(xPDO::LOG_LEVEL_WARN, "Попытка импорта пустого значения характеристики товара. Артикул: {$parent}");
            $this->modx->log(xPDO::LOG_LEVEL_WARN, print_r($node, 1));
        }
        #
        else 
        {
            $this->parsePropertyValue($xml, $parent);
            $this->insertPropertyValuesInDataBase();
        }
        unset($_id);
    }
    public function importGoods($ABS_FILE_NAME) 
    {
        $table = $this->modx->getTableName($this->tmpProductsClass);
        $columns = array(
            "article",
            "title",
            "description",
            "image",
            "groups",
            "extended"
        );
        $rows = array();
        $linesPerStep = $this->linesPerStep;
        $i = 0;
        // Парсинг большого документа посредством XMLReader с Expand - DOM/DOMXpath
        $reader = new XMLReader();
        $reader->open($ABS_FILE_NAME);
        while ($reader->read()) 
        {
            if (($reader->nodeType == XMLReader::ELEMENT) && ($reader->name == 'КоммерческаяИнформация')) 
            {
                while ($reader->read()) 
                {
                    if (($reader->nodeType == XMLReader::ELEMENT) && ($reader->name == 'Классификатор')) 
                    {
                        $reader->next();
                    }
                    elseif (($reader->nodeType == XMLReader::ELEMENT) && ($reader->name == 'Каталог')) 
                    {
                        while ($reader->read()) 
                        {
                            if (($reader->nodeType == XMLReader::ELEMENT) && ($reader->name == 'Товары')) 
                            {
                                while ($reader->read()) 
                                {
                                    if (($reader->nodeType == XMLReader::ELEMENT && ($reader->name == 'Товар'))) 
                                    {
                                        $this->importGoodsStuff($reader, $rows, $table, $columns, $i);
                                        $reader->next();
                                    }
                                    if ($reader->nodeType == XMLReader::END_ELEMENT && $reader->localName == 'Товары') 
                                    {
                                        // Если еще есть массив с данными, то сохраняем их
                                        if ($rows) 
                                        {
                                            $this->insertInDataBase($table, $rows, $columns);
                                        }
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    protected function importGoodsStuff($reader, &$rows, &$table, &$columns, &$i) 
    {
        $linesPerStep = $this->linesPerStep;
        $i++;
        $node = $reader->readOuterXML();
        $product = simplexml_load_string($node);
        // Сохраняем первичные данные во временную таблицу
        $article = (string)$product->Ид;
        $title = str_replace("'", "\'", (string)$product->Наименование);
        $description = str_replace("'", "\'", (string)$product->Описание);
        $image = (string)$product->Картинка;
        // Необходимо переделать на множество групп
        $groups = array(
            (string)current($product->Группы->Ид)
        );
        $groups = json_encode($groups);
        // сохраняем параметры товара
        $extended = array();
        $properties = $product->ЗначенияСвойств->ЗначенияСвойства;
        if ($properties) 
        {
            foreach ($properties as $prop) 
            {
                $val = (string)$prop->Значение;
                $id = (string)$prop->Ид;
                # если пустые значения
                if (!$id or !$val) 
                {
                    # $this->modx->log(xPDO::LOG_LEVEL_ERROR,'Попытка импорта пустого значения характеристики');
                    # $this->modx->log(xPDO::LOG_LEVEL_WARN,print_r($prop,1));
                    # если передается ключ, то пишем в лог (происходит в случае типа поля - булеан)
                    
                }
                else if (preg_match("/^(.+-.+){4}$/", $val)) 
                {
                    $this->modx->log(xPDO::LOG_LEVEL_WARN, 'Попытка импорта некорректного значения характеристики');
                    $this->modx->log(xPDO::LOG_LEVEL_WARN, print_r($prop, 1));
                    # если значение корректное, то добавляем данные во временный массив, а так же пишем в таблицу свойств
                    
                }
                else if ($val) 
                {
                    # сохраняем во временный массив, причем пропускаем обработку значения, хранящего уник. айди, если он передается
                    // получаем опцию, содержащую имя уник. идентификатора, для определения уник. ключа импорта
                    $_keyOption = $this->modx->getOption('shopmodx1c.article_field_name');
                    if (!empty($_keyOption)) 
                    {
                        $_keyField = $this->modx->getObject('Shopmodx1cTmpProperty', array(
                            'title' => $_keyOption
                        ));
                        # если в массив свойств товара передается уник. айди товара, то не обрабатываем значение
                        if ($_keyField and $_keyField->article != $id) 
                        {
                            # $extended[ $id ] = urlencode(htmlspecialchars($val));
                            $extended[$id] = urlencode(($val));
                        }
                        else
                        {
                            $extended[$id] = $val;
                        }
                    }
                    else
                    {
                        # $extended[ $id ] = urlencode(htmlspecialchars($val));
                        $extended[$id] = urlencode(($val));
                    }
                    # пишем данные в бд
                    $this->parsePropertyValue($prop, $id, $article);
                    $this->insertPropertyValuesInDataBase();
                }
            }
        }
        $extended = json_encode($extended);
        $rows[] = "('{$article}', '{$title}', '{$description}', '{$image}', '{$groups}', '{$extended}')";
        if ($i % $linesPerStep == 0) 
        {
            if (!$this->insertInDataBase($table, $rows, $columns)) 
            {
                return $this->failure("Не удалось выполнить запрос");
            }
            $rows = array();
        }
    }
    /*
        Очистка временной директории
    */
    protected function clearImportDir() 
    {
        if (!$this->removeTmpFiles) 
        {
            return;
        }
        #
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
    #
    
    /**
     * log items left
     */
    protected function logCount($class, xPDOQuery & $q, $name = 'items') 
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
    
    /**
     * Очистка таблиц
     */
    protected function clearTmpTables() 
    {
        if (!$this->clearTmpTables) 
        {
            return;
        }
        #
        $classes = array(
            $this->tmpCategoriesClass,
            $this->tmpProductsClass,
            $this->tmpPropertiesClass,
            $this->tmpPropertyValuesClass,
            $this->tmpPriceClass,
            $this->tmpPriceTypeClass,
        );
        foreach ($classes as $class) 
        {
            if ($table = $this->modx->getTableName($class)) 
            {
                $this->modx->exec("TRUNCATE TABLE {$table}");
            }
        }
    }
    // парсим свойства
    protected function parseProperty(SimpleXMLElement & $property, $parent = null) 
    {
        # $this->modx->log(1,print_r($properties,1));
        if (!(bool)$property->ДляТоваров) return false;
        $this->_properties[] = array(
            "article" => (string)$property->Ид,
            "title" => (string)$property->Наименование,
            "parent" => (string)$parent
        );
        return;
    }
    // парсим значения свойств
    protected function parsePropertyValue(SimpleXMLElement & $property, $parent = null, $articul = null) 
    {
        $key = 'ИдЗначения';
        $id = (string)$property->$key;
        $value = (string)$property->Значение;
        $parent = (string)$parent;
        if (!$id) 
        {
            $id = md5($value . $parent);
        }
        if (!$this->modx->getCount($this->tmpPropertyValuesClass, array(
            'article' => $id
        ))) 
        {
            $this->_propertyValues[] = array(
                "article" => $id,
                "title" => $value,
                "parent" => $parent,
                'good' => (string)$articul
            );
        }
        else
        {
            # $this->modx->log(xPDO::LOG_LEVEL_INFO,'Попытка вставки дубля значения характеристики:' . $value);
            
        }
        return;
    }
    // обновляем твшки
    protected function setTVs(modResource & $resource, $extended) 
    {
        if (!$resource || !$extended) 
        {
            return;
        }
        $extended = json_decode($extended, 1);
        if (!is_array($extended)) return;
        foreach ($extended as $k => $v) 
        {
            # $resource->setTVValue( (string)$k, urldecode(htmlspecialchars_decode($v)));
            $resource->setTVValue((string)$k, urldecode(($v)));
        }
        return;
        # $resource->save();
        
    }
    // Парсим группу
    protected function parseGroup(SimpleXMLElement & $group, $parent = null) 
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
            $this->modx->log(xPDO::LOG_LEVEL_WARN, 'SQL ERROR Import');
            $this->modx->log(xPDO::LOG_LEVEL_WARN, print_r($s->errorInfo() , 1));
            $this->modx->log(xPDO::LOG_LEVEL_WARN, $sql);
        }
        return $result;
    }
    protected function insertGroupsInDataBase() 
    {
        $table = $this->modx->getTableName($this->tmpCategoriesClass);
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
        // Сбрасываем массив групп
        $this->groups = array();
        return;
    }
    protected function insertPropertiesInDataBase() 
    {
        $table = $this->modx->getTableName($this->tmpPropertiesClass);
        $columns = array(
            "article",
            "title",
            "parent",
        );
        $rows = array();
        $linesPerStep = 500;
        $i = 0;
        foreach ($this->_properties as $property) 
        {
            $i++;
            $article = $property['article'];
            $title = str_replace("'", "\'", $property['title']);
            $parent = ($property['parent'] ? "'{$property['parent']}'" : "NULL");
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
        // Сбрасываем массив групп
        $this->_properties = array();
        return;
    }
    # добавляем значения свойств во временную таблицу
    protected function insertPropertyValuesInDataBase() 
    {
        $table = $this->modx->getTableName($this->tmpPropertyValuesClass);
        $columns = array(
            "article",
            "title",
            "parent",
            "good"
        );
        $rows = array();
        $linesPerStep = 500;
        $i = 0;
        foreach ($this->_propertyValues as $property) 
        {
            $i++;
            $article = $property['article'];
            $title = str_replace("'", "\'", $property['title']);
            $parent = ($property['parent'] ? "'{$property['parent']}'" : "NULL");
            $good = ($property['good'] ? "'{$property['good']}'" : "NULL");
            $rows[] = "('{$article}', '{$title}', {$parent}, {$good})";
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
        // Сбрасываем массив групп
        $this->_propertyValues = array();
        return;
    }
    // Находим ID документа по артикулу
    protected function getResourceIdByArticle($article) 
    {
        $result = null;
        $article_tv_id = $this->modx->getOption('shopmodx1c.article_tv');
        if ($article) 
        {
            $q = $this->modx->newQuery('modTemplateVarResource', array(
                "tmplvarid" => $article_tv_id,
                "value" => $article,
            ));
            $q->select(array(
                'contentid',
            ));
            $q->limit(1);
            $result = $this->modx->getValue($q->prepare());
        }
        return $result;
    }
    # метод завершения обработки временной сущности
    protected function processTmpEntity(xPDOObject & $object, $status = self::PROCESSED_STATUS) 
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
return 'mod1cWebExchangeCatalogImportProcessor';
