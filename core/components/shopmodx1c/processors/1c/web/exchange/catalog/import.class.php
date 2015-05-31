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
    protected $tmpCategoriesClass = 'Shopmodx1cTmpCategory';
    protected $tmpProductsClass = 'Shopmodx1cTmpProduct';
    protected $tmpPropertiesClass = 'Shopmodx1cTmpProperty';
    protected $tmpPropertyValuesClass = 'Shopmodx1cTmpPropertiesValue';
    protected $groups = array(); // Импортируемые группы
    protected $_properties = array(); // Импортируемые свойства (твшки)
    protected $_propertyValues = array(); // Импортируемые значения свойств
    protected $removeTmpFiles = true; // удалять или нет временные файлы
    protected $convertToTranslit = false; // переводить ли в транслит инф. сообщения
    protected $linesPerStep = 300;
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
            "process_items_per_step" => 20, // Сколько элементов обрабатывать за шаг (create|update)
            "NEW_PROPERTY_EVENT_NAME" => 'shopmodx1c.new_property',
            "NEW_PROPERTIES_VALUES_EVENT_NAME" => 'OnShopmodx1cPropertiesValuesCreate',
        ));
        return parent::initialize();
    }
    protected function addOutput($string)
    {
        $this->modx->log(xPDO::LOG_LEVEL_DEBUG, $string);
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
            $string = mb_convert_encoding($string, $this->getProperty('outputCharset', 'CP1251'), "UTF-8");
        }
        $this->outputData[] = $string;
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
            $this->modx->log(1, 'not authed');
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
        /*
            Первичная инициализация
        */
        if ($mode == "init")
        {
            /*
                Проверяем основные настройки
            */
            if (!$this->modx->getOption('shopmodx1c.article_tv'))
            {
                $error = "Не указан ID TV-параметра для артикулов 1С";
                $this->modx->log(1, $error);
                $this->addOutput($error);
                return $this->failure("failure");
            }
            if (!$this->modx->getOption('shopmodx1c.catalog_root_id'))
            {
                $error = "Не указан ID корневого раздела каталога";
                $this->modx->log(1, $error);
                $this->addOutput($error);
                return $this->failure("failure");
            }
            if (!$this->modx->getOption('shopmodx1c.product_default_template'))
            {
                $error = "Не указан ID шаблона для товаров";
                $this->modx->log(1, $error);
                $this->addOutput($error);
                return $this->failure("failure");
            }
            if (!$this->modx->getOption('shopmodx1c.category_default_template'))
            {
                $error = "Не указан ID шаблона для категории";
                $this->modx->log(1, $error);
                $this->addOutput($error);
                return $this->failure("failure");
            }
            /**********************************/
            // Очищаем импорт-директорию
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
        // Сохраняем импортируемый файл-выгрузку
        elseif (($mode == "file") && $ABS_FILE_NAME)
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
            $this->modx->log(xPDO::LOG_LEVEL_INFO, "STEP: " . $NS["STEP"]);
            switch ($NS["STEP"])
            {
                // Перемещаем картинки в папку изображений товаров

            case 0:
                $DIR_NAME = $this->getProperty('DIR_NAME');
                $source = $DIR_NAME . 'import_files/';
                $target = $this->modx->getOption('shopmodx1c.images_path', null, MODX_ASSETS_PATH . 'images/') . "import_files/";
                $this->modx->loadClass('modCacheManager', '', true, false);
                $modCacheManager = new modCacheManager($this->modx);
                $r = $modCacheManager->copyTree($source, $target);
                $NS["STEP"] = 1;
            break;
            case 1:
                $this->importCategories($ABS_FILE_NAME);
                $NS["STEP"] = 2;
            break;
                # Парсим свойства и их значения

            case 2:
                $this->importProperties($ABS_FILE_NAME);
                $NS['STEP'] = 3;
            break;
                // Парсим товары

            case 3:
                $this->importGoods($ABS_FILE_NAME);
                $NS["STEP"] = 4;
            break;
                // Сохраняем категории

            case 4:
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
                // если в настройках указан айдишник для временного каталога, то все категории копируем туда (подготовка для сортировки)
                if ($catalog_tmp_root_id)
                {
                    $catalog_root_id = $catalog_tmp_root_id;
                }
                $q = $this->modx->newQuery($tmpClass);
                $q->leftJoin('modTemplateVarResource', 'tv_article', "tv_article.tmplvarid = {$article_tv_id} AND {$tmpClass}.article = tv_article.value");
                $q->where(array(
                    "tv_article.id" => null,
                    "processed" => 0,
                ));
                $q->sortby("{$tmpClass}.id");
                $q->limit($limit);
                // Получаем все группы/категории из 1С
                if ($groups = $this->modx->getCollection($tmpClass, $q))
                {
                    // Проходимся по каждому и создаем категорию
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
                                return $this->failure($error);
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
                            $this->modx->log(1, $error);
                            $this->addOutput($error);
                            return $this->failure($error);
                        }
                        //else
                        if ($response->isError())
                        {
                            if (!$error = $response->getMessage())
                            {
                                $error = "Не удалось создать раздел с артикулом '{$article}'";
                            }
                            $this->modx->log(1, $error);
                            $this->addOutput($error);
                            return $this->failure($error);
                        }
                        // else
                        $o = $response->getObject();
                        $category_id = $o['id'];
                        if ($object = $this->modx->getObject('modResource', $category_id))
                        {
                            $object->set('alias', $object->id);
                            $object->save();
                        }
                        $group->set('processed', 1);
                        $group->save();
                    }
                }
                // else
                else $NS["STEP"] = 5;
                break;
            case 5:
                // создаем необходимые тв-параметры
                $tmpClass = $this->tmpPropertiesClass;
                $product_template = $this->modx->getOption('shopmodx1c.product_default_template');
                $limit = $this->getProperty('process_items_per_step');
                $q = $this->modx->newQuery($tmpClass);
                // создаем только несуществующие тв
                $q->leftJoin('modTemplateVar', 'tv', "tv.name = {$tmpClass}.article");
                $q->where(array(
                    "tv.id" => null,
                    "processed" => 0,
                ));
                $q->sortby("{$tmpClass}.id");
                $q->limit($limit);
                // получаем опцию, содержащую имя уник. идентификатора, для определения уник. ключа импорта
                $_keyOption = $this->modx->getOption('shopmodx1c.article_field_name');
                # исключаем из списка тв-параметров твшку, которая хранит ключ товара из 1c
                if ($_keyOption)
                {
                    $q->where(array(
                        'title:!=' => $_keyOption
                    ));
                }
                // Получаем все свойства из 1С
                if ($tvs = $this->modx->getCollection($tmpClass, $q))
                {
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
                            return $this->failure($error);
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
                            return $this->failure($error);
                        }
                        $tv->set('processed', 1);
                        $tv->save();
                    }
                }
                else $NS["STEP"] = 6;
                break;
                // Сохраняем/обновляем товары

            case 6:
                /*
                Выполняем необходимые действия над данными,
                пока не будет импорт выполнен до конца.
                */
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
                $q->leftJoin('ShopmodxProduct', 'Product', "Product.sm_article = {$tmpClass}.article");
                $q->where(array(
                    "processed" => 0,
                ));
                $q->select(array(
                    # "tv_article.id as tv_article_id",
                    # "tv_article.contentid as resource_id",
                    "Product.resource_id as resource_id",
                    "{$tmpClass}.*",
                ));
                $q->limit($limit);
                // Получаем все группы/категории из 1С
                if ($products = $this->modx->getCollection($tmpClass, $q))
                {
                    // Проходимся по каждому товару
                    foreach ($products as $product)
                    {
                        $article = $product->article;
                        /*
                                Если товар уже есть в магазине, то обновляем его
                        */
                        $_rid = $product->resource_id;
                        if ($_rid)
                        {
                            $good = $this->modx->getObject('ShopmodxResourceProduct', $_rid);
                            // обновляем твшки
                            $this->setTVs($good, $product->extended);
                        }
                        // иначе создаем новый
                        else
                        {
                            // Определяем категорию, куда товар создавать
                            if (!$groups = json_decode($product->groups, 1) OR !$group = current($groups))
                            {
                                $error = "Не был получен раздел для товара с артикулом '{$article}'";
                                $this->modx->log(xPDO::LOG_LEVEL_ERROR, $error);
                                $this->addOutput($error);
                                return $this->failure($error);
                            }
                            // else
                            // Проверяем наличие категории в каталоге
                            if (!$parent = $this->getResourceIdByArticle($group))
                            {
                                $error = "Не был получен раздел с артикулом '{$group}'";
                                $this->modx->log(xPDO::LOG_LEVEL_ERROR, $error);
                                $this->addOutput($error);
                                return $this->failure($error);
                            }
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
                            );
                            # print_r($_keyOption);
                            # print_r($_keyField);
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
                                else
                                {
                                    $error = "Не был получен уник. идентификатор товара (артикул) '{$product->article}'";
                                    $this->modx->log(1, $error);
                                    $this->addOutput($error);
                                    return $this->failure($error);
                                }
                            }
                            else
                            {
                                $data["sm_article"] = $product->article;
                            }
                            if ($product->image && $image_tv_id)
                            {
                                $data["tv{$image_tv_id}"] = $product->image;
                            }
                            if (!$response = $this->modx->runProcessor('resource/create', $data))
                            {
                                $error = "Ошибка выполнения процессора";
                                $this->modx->log(1, $error);
                                $this->addOutput($error);
                                return $this->failure($error);
                            }
                            //else
                            if ($response->isError())
                            {
                                if (!$error = $response->getMessage())
                                {
                                    $error = "Не удалось создать товар с артикулом '{$article}'";
                                }
                                $this->modx->log(1, $error);
                                $this->modx->log(1, print_r($response->getResponse() , true));
                                // если объект успешно создан — набиваем твшки;

                            }
                            else
                            {
                                $data = $response->getObject();
                                $resource = $this->modx->getObject('ShopmodxResourceProduct', $data['id']);
                                if (!$resource)
                                {
                                    $error = "Не был получен объект товара после создания оного. артикул: '{$product->article}'. Значения тв-параметров не будут обновлены";
                                    $this->modx->log(1, $error);
                                    $this->addOutput($error);
                                    return $this->failure($error);
                                }
                                else
                                {
                                    $this->setTVs($resource, $product->extended);
                                }
                            }
                        }
                        # die;
                        $product->set('processed', 1);
                        $product->save();
                    }
                }
                // else
                else $NS["STEP"] = 7;
                break;
                # for custom events which

            case 7:
                # Здесь мы обрабатываем значения свойств тв-параметров.
                # Мы будем получать коллекцию объектов из бд, созданных ранее и выбрасывать их вместе с событием в систему
                # по завершению мы будем менять флаг элемента
                # return $this->success('success');
                $tmpClass = $this->tmpPropertyValuesClass;
                $limit = $this->getProperty('process_items_per_step');
                $c = $this->modx->newQuery($tmpClass);
                $c->where(array(
                    "processed" => 1,
                ));
                $c->select(array(
                    "{$tmpClass}.*",
                ));
                $c->limit($limit);
                if ($collection = $this->modx->getCollection($tmpClass, $c))
                {
                    // получаем опцию, содержащую имя уник. идентификатора, для определения уник. ключа импорта
                    $_keyOption = $this->modx->getOption('shopmodx1c.article_field_name');
                    if (!empty($_keyOption))
                    {
                        $_keyField = $this->modx->getObject('Shopmodx1cTmpProperty', array(
                            'title' => $_keyOption
                        ));
                    }
                    foreach ($collection as $obj)
                    {
                        # если в массив свойств товара передается уник. айди товара, то не обрабатываем значение
                        if ($_keyField and $_keyField->article == $obj->parent)
                        {
                            continue;
                        }
                        # if we have an event
                        if ($event = $this->getProperty('NEW_PROPERTIES_VALUES_EVENT_NAME'))
                        {
                            $response = $this->modx->invokeEvent($event, array(
                                'propertyValue' => & $obj
                            ));
                        }
                        $obj->set('processed', 2);
                        $obj->save();
                    }
                }
                # else
                else return $this->success('success');
                break;
            }
            return $this->success('progress');
        }
        else if ($mode = 'deactivate')
        {
            # Очищаем временные таблицы
            $this->clearTmpTables();
        }
        else
        {
            $this->modx->log(1, 'UNKNOWN_COMMAND ' . $mode);
            $this->addOutput('UNKNOWN_COMMAND ' . $mode);
            return $this->failure('failure');
        }
        return $this->success('');
    }
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
    # Обработка свойств и их значений
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
                                        // Парсим свойства
                                        $isGood = false;
                                        $xml = $this->importPropertiesStuff($reader, $isGood);
                                        # если у нас текущее свойство является свойством товара (твшка), то процессим его словарь значений
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
        if ($_id == 'true' or $_id == 'false')
        {
            # если айди boolean, то пропускаем и пишем в лог
            $this->modx->log(xPDO::LOG_LEVEL_WARN, "Попытка импорта некорректного значения характеристики {$parent}");
            $this->modx->log(xPDO::LOG_LEVEL_WARN, print_r($node, 1));
        }
        else if (!$_id)
        {
            # если айди передается, но пустое
            $this->modx->log(xPDO::LOG_LEVEL_WARN, "Попытка импорта пустого значения характеристики {$parent}");
            $this->modx->log(xPDO::LOG_LEVEL_WARN, print_r($node, 1));
        }
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
        if (!$this->removeTmpFiles) return;
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
    /*
        Очистка таблиц
    */
    protected function clearTmpTables()
    {
        $classes = array(
            $this->tmpCategoriesClass,
            $this->tmpProductsClass,
            $this->tmpPropertiesClass,
            $this->tmpPropertyValuesClass,
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
}
return 'mod1cWebExchangeCatalogImportProcessor';
