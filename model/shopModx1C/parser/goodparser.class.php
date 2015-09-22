<?php
require_once __DIR__ . '/importparser.class.php';
class goodParser extends importParser
{
    /**
     * stuff
     */
    protected $tmpGoodClass = 'Shopmodx1cTmpProduct';
    #
    private $debug = false;
    #
    
    /**
     * статусы обработки временных записей
     */
    const PROCESSED_STATUS = 1;
    const UNPROCESSED_STATUS = 0;
    #
    
    /**
     */
    public function parseXML($ABS_FILE_NAME) 
    {
        $reader = $this->getReader($ABS_FILE_NAME);
        #
        return true;
    }
    #
    
    /** */
    protected $events = array(
        'save' => 'OnShopmodx1cGoodSave'
    );
    #
    
    /**
     */
    public function saveTMPGoods($processor) 
    {
        $tmpClass = $this->tmpGoodClass;
        $article_tv_id = $this->modx->getOption('shopmodx1c.article_tv');
        $image_tv_id = $this->modx->getOption('shopmodx1c.product_image_tv');
        $currency = $this->modx->getOption('shopmodx.default_currency');
        $products_template = $this->modx->getOption('shopmodx1c.product_default_template');
        $limit = $this->getProperty('process_items_per_step');
        $parent = $this->modx->getOption('shopmodx1c.catalog_root_id');
        #
        
        /**
         * получаем опцию, содержащую имя уник. идентификатора, для определения уник. ключа импорта
         */
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
            $processor->logCount($tmpClass, $q, 'goods');
            #
            // Проходимся по каждому товару
            foreach ($products as $product) 
            {
                $article = $product->article;
                #
                
                /**
                 * if good exists
                 */
                if ($_rid = $product->resource_id) 
                {
                    $good = $this->modx->getObject('ShopmodxResourceProduct', $_rid);
                    $this->setTVs($good, $product->extended);
                }
                # if not we try to create new product
                else 
                {
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
                        "sm_currency" => $currency,
                    );
                    # print_r($_keyOption);
                    # # print_r($_keyField->toArray());
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
                            $processor->processTmpEntity($product);
                            $processor->addOutput($error);
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
                        $processor->addOutput($error);
                        #
                        
                        /**
                         * ставим флаг «обработано» для товара
                         */
                        $processor->processTmpEntity($product);
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
                        $processor->addOutput($error);
                        /**
                         * ставим флаг «обработано» для товара
                         */
                        $processor->processTmpEntity($product);
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
                            $processor->addOutput($error);
                            /**
                             * ставим флаг «обработано» для товара
                             */
                            $processor->processTmpEntity($product);
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
                var_dump($_rid);
                /** */
                if (true !== $ok = $this->_onGoodSave($product)) 
                {
                    return false;
                }
                /**
                 * ставим флаг «обработано» для товара
                 */
                $processor->processTmpEntity($product);
            }
            #
            
        }
        #
        if ($this->hasErrors()) 
        {
            return false;
        }
        return true;
    }
    #
    
    /**
     * update resource tv's
     */
    protected function setTVs(modResource & $resource, $extended) 
    {
        if (!$resource || !$extended) 
        {
            return;
        }
        #
        
        /** */
        $extended = json_decode($extended, 1);
        if (!is_array($extended)) 
        {
            return;
        }
        #
        
        /** */
        foreach ($extended as $k => $v) 
        {
            $resource->setTVValue((string)$k, urldecode(($v)));
        }
    }
    #
    
    /** */
    protected function _onGoodSave(xPDOObject $product) 
    {
        /**
         * invoke resource update event
         */
        if ($event = $this->events['save']) 
        {
            $response = $this->modx->invokeEvent($event, array(
                'product' => & $product
            ));
            $this->modx->log(1, print_r($response, 1));
        }
        return true;
    }
    #
    
}
