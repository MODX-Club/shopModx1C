<?php
require_once __DIR__ . '/importparser.class.php';
class goodParser extends importParser
{
    /**
     * stuff
     */
    protected $_goods = array();
    
    protected $goodTMPClass = 'Shopmodx1cTmpProduct';
    #
    
    /**/
    protected $events = array(
        'save' => 'OnShopmodx1cGoodSave'
    );
    #
    
    /**/
    protected $groupGoodsByArticle = true;
    # 
    
    /**/
    public function initialize(){
        
        if($group = $this->getProperty('groupGoodsByArticle', false)){
            $this->groupGoodsByArticle = $good;
        }
        
        return parent::initialize();
    }
    # 
    
    /**
     * XMLReader с Expand - DOM/DOMXpath
     */ 
    public function parseXML($ABS_FILE_NAME) 
    {
        $reader = $this->getReader($ABS_FILE_NAME);
        
        while ($reader->read()) 
        {
            if ($this->isNode('КоммерческаяИнформация', $reader)) 
            {
                while ($reader->read()) 
                {
                    if ($this->isNode('Классификатор', $reader)) 
                    {
                        $reader->next();
                    }
                    # 
                    elseif ($this->isNode('Каталог', $reader)) 
                    {
                        while ($reader->read()) 
                        {
                            if ($this->isNode('Товары', $reader)) 
                            {
                                while ($reader->read()) 
                                {
                                    if ($this->isNode('Товар', $reader)) 
                                    {                                                          
                                        
                                        $this->_goods[] = $this->parseTMPGood($reader);                                                                                    
                                        
                                        $reader->next();
                                        
                                    }
                                    if ($this->isNodeEnd('Товары', $reader)) 
                                    {
                                        $this->insertTMPGoodsToBD();
                                        
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }        

        $this->flushGoods();

        if ($this->hasErrors()) 
        {
            return false;
        }
        #
        return true;
    }
    #
    
    /**/
    protected function parseTMPGood(XMLReader $reader){
        $xml = $this->getXMLNode($reader);
        
        $good = array(
            'article' => (string)$xml->Артикул,
            'externalKey' => (string)$xml->Ид,
            'pagetitle' => (string)$xml->Наименование,
            'image' => (string)$xml->Картинка,
            'description' => (string)$xml->Описание,
        );
        
        foreach($xml->ЗначенияРеквизитов->ЗначениеРеквизита as $prop){
            $value = (string)$prop->Значение;
            switch((string)$prop->Наименование){
                case 'Полное наименование':
                    $good['longtitle'] = $value;    
                break;
                case 'Вес':
                    $good['weight'] = $value;
                break;                
            }
        }
        
        if($groups = $xml->Группы){
            $good['parent'] = json_encode(array((string)$xml->Группы->Ид));
        }
        
        $extended = array();
        if($xml->БазоваяЕдиница){
            $extended['unit'] = (string)$xml->БазоваяЕдиница;            
        }
        
        if(count($extended)){
            $good['extended'] = json_encode($extended);
        }
        
        return $good;
    }
    # 
    
    /**/
    protected function insertTMPGoodsToBD(){
        $i = 0;
        $table = $this->modx->getTableName($this->goodTMPClass);
        $rows = array();
        $columns = array(
            "article",
            "externalKey",
            "title",
            "longtitle",
            "description",
            "image",
            "groups",
            "extended",
            "weight"
        );
        #
        
        /**/
        $total = count($this->_goods);
        $step = $this->getProperty('linesPerStep');
        array_walk($this->_goods, function ($item) use ($table, &$rows, $columns, &$i, $total, $step) 
        {            
            $i++;
            $article = $item['article'] ? $this->modx->quote($item['article']) : "NULL";
            $externalKey = $item['externalKey'] ? $this->modx->quote($item['externalKey']) : "NULL";
            $groups = (isset($item['parent']) ? $this->modx->quote($item['parent']) : "NULL");
            $extended = (isset($item['extended']) ? $this->modx->quote($item['extended']) : "NULL");
            $title = $this->modx->quote($item['pagetitle']);
            $longtitle = $this->modx->quote($item['longtitle']);
            $description = $this->modx->quote($item['description']);
            $image = $this->modx->quote($item['image']);
            $weight = $this->modx->quote($item['weight']);
            #
            
            $rows[] = "({$article}, {$externalKey}, {$title}, {$longtitle}, {$description}, {$image}, {$groups}, {$extended}, {$weight})";
            #

            /**/
            if ($i % $step == 0 || ($total < $step && $i == $total )) 
            {
                if (!$this->insertInDataBase($table, $rows, $columns)) 
                {
                    $this->addFieldError('insertToDB', 'Не удалось выполнить запрос');
                    return false;
                }
                $rows = array();                
            }
        });
        #

        return true;        
    }
    #
    
    /**/
    protected function flushGoods(){
        $this->_goods = array();
    }
    
    /**
     */
    public function saveTMPRecords() 
    {
        $tmpClass = $this->goodTMPClass;
        $article_tv_id = $this->modx->getOption('shopmodx1c.article_tv_id');
        $catalog_root_id = $this->modx->getOption('shopmodx1c.catalog_root_id');
        $catalog_tmp_root_id = $this->modx->getOption('shopmodx1c.tmp_catalog_root_id');
        $limit = $this->getProperty('process_items_per_step');
        $product_template = $this->modx->getOption('shopmodx1c.product_default_template');
        $image_tv_id = $this->modx->getOption('shopmodx1c.product_image_tv');
        $currency = $this->modx->getOption('shopmodx.default_currency');
        #

        // Получаем первичные данные по не обработанным товарам
        $q = $this->modx->newQuery($tmpClass);
        $q->leftJoin('ShopmodxProduct', 'Product', "Product.sm_article = {$tmpClass}.article");
        $q->where(array(
            "processed" => self::UNPROCESSED_STATUS,
            'article is not null'
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
        $shouldGroupProductsByRule = $this->modx->getOption('shopmodx1c.group_products_by_rule');        
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
                
                # Если товар найден, то пробуем обновить сам товар.
                if($rid = $product->resource_id){                    
                    
                    $data = array(
                        'longtitle' => $product->longtitle ? $product->longtitle : $product->title    
                    );
                    
                    if(!$data['longtitle']){
                        $data['longtitle'] = $article;
                    }
                    
                    # Если указано правило группировки, то собираем товары в группы по артикулу
                    if($shouldGroupProductsByRule){
                        
                        if($pos = strpos($article, '.')){                        
                            $prefix = substr($article, 0, $pos);                                            
                        }
                        
                    }
                    # 
                    
                    $existedResource = $this->modx->getObject('modResource', array('id' => $rid));
                    $existedProduct = $this->modx->getObject('ShopmodxProduct', array('sm_article'=>$product->article));
                    
                    if(isset($prefix) && !$existedResource->pagetitle){
                        $data['pagetitle'] = $article;
                    }
                    if(isset($prefix)){
                        
                        if(!$existedProduct->sm_weight){
                            $existedProduct->set('sm_weight', $product->weight);
                        }
                        
                        if(!$existedResource->pagetitle){
                            $data['pagetitle'] = $article;
                        }

                    }
                    $existedResource->fromArray($data);

                    if(!$existedResource->save()){
                        $error = "Не удалось обновить ресурс товара с артикулом '{$article}'";
                        $this->addFieldError('article', $error);
                        
                        $product->set('processed', self::PROCESSED_STATUS);
                        $product->save();                        
                        continue;
                    }                      
                    
                    if(!$existedProduct->save()){
                        $error = "Не удалось обновить товар с артикулом '{$article}'";
                        $this->addFieldError('article', $error);
                        
                        $product->set('processed', self::PROCESSED_STATUS);
                        $product->save();                        
                        continue;
                    }                    

                }
                # Ресурса для товара нет. Следовательно его сделует создать
                else{            
                    
                    if(!$product->groups){
                        $error = "Не был получен раздел для товара с артикулом '{$article}'";
                        $this->addFieldError('article', $error);
                        
                        $this->modx->setLogTarget('FILE');
                        $this->modx->log(xPDO::LOG_LEVEL_DEBUG, $error, 'FILE'); 
                        
                        $product->set('processed', self::PROCESSED_STATUS);
                        $product->save();                        
                        continue;
                    }else{
                        $groups = json_decode($product->groups, 1);
                        
                        if(!$groups){
                            $error = "Не был получен раздел для товара с артикулом '{$article}'";
                            $this->addFieldError('article', $error);
                            
                            $this->modx->setLogTarget('FILE');
                            $this->modx->log(xPDO::LOG_LEVEL_DEBUG, $error, 'FILE'); 
                            
                            $product->set('processed', self::PROCESSED_STATUS);
                            $product->save();                        
                            continue;
                        }
                        
                        $group = current($groups);                        
                        
                    }
                    
                    // else
                    // Проверяем наличие категории в каталоге
                    if (!$parent = $this->getResourceIdByArticle($group)) 
                    {
                        $error = "Не был получен раздел с артикулом '{$group}'";                        
                        $this->addFieldError('article', $error);
                        
                        $this->modx->log(xPDO::LOG_LEVEL_DEBUG, $error, 'FILE');
                        
                        $product->set('processed', self::PROCESSED_STATUS);
                        $product->save(); 
                        continue;
                    }                   
                    
                    
                    # Если указано правило группировки, то собираем товары в группы по артикулу
                    if($shouldGroupProductsByRule){
                        
                        if($pos = strpos($article, '.')){                        
                            $prefix = substr($article, 0, $pos);                                            
                        }
                        
                    }
                    # 

                    $content = preg_replace("/[\n]/", "<br />\n", $product->description);
                    
                    # Если есть префикс, то нужно создать ресурс, в котором будут группироваться товары                    
                    if(isset($prefix)){
                        $data = array(
                            'class_key' => 'ShopmodxResourceProduct',
                            'pagetitle' => $prefix,
                            'longtitle' => $product->longtitle ? $product->longtitle : $product->title,
                            "content" => $content,
                            "parent" => $parent,
                            "template" => $product_template,
                            "isfolder" => 0
                        );                                                                        
                        
                        
                        $existed_resource = $this->modx->getObject('modResource', array('pagetitle' => $prefix));
                    }else{
                        $data = array(
                            "class_key" => "ShopmodxResourceProduct",
                            "pagetitle" => $product->title ? $product->title : $article,
                            'longtitle' => $product->longtitle ? $product->longtitle : $product->title,
                            "content" => $content,
                            "parent" => $parent,
                            "template" => $product_template,
                            "isfolder" => 0,
                            # "tv{$article_tv_id}"      => $article,
                            
                        );                        
                    }  
                                                            
                    $data["sm_article"] = $product->article;
                    $data["sm_weight"] = $product->weight;
                    $data['name'] = $data['longtitle'];
                    $data['alias'] = !isset($prefix) ? ($data['longtitle'] . '-' . $product->article) : ($data['longtitle'] . '-' . $prefix) ;
                    /**
                     * check the image
                     */
                    if ($product->image && $image_tv_id) 
                    {
                        # $data["tv{$image_tv_id}"] = $product->image;
                    }
                    # else

                    
                    # Перед созданием ресурса нам необходимо проверить на существование ресурса с нужным нам заголовком, соответствующим префиксу артикула.
                    # Если таковой есть, то создаем к нему товар в связку. Нет — создаем и ресурс и товар                    
                                        
                    if(isset($prefix) && is_object($existed_resource)){                                            
                        
                        unset($data['class_key']);
                        $data['parent'] = $existed_resource->id;
                        $data['name'] = $data['longtitle'];
                        
                        
                        $newProduct = $this->modx->newObject('ShopmodxProduct');
                        $newProduct->fromArray($data);
                        
                        $newProduct->set('resource_id', $existed_resource->id);                        
                        
                        if(!$newProduct->save()){
                            $error = "Не удалось создать товар с артикулом '{$article}'";                        
                            $this->addFieldError('article', $error);
                            
                            $product->set('processed', self::PROCESSED_STATUS);
                            $product->save(); 
                            continue;
                        }
                        
                    }else{
                        
                        /**
                         * resource creating
                         */
                        if (!$response = $this->modx->runProcessor('resource/create', $data)) 
                        {
                            $error = "Ошибка выполнения процессора";                            
                            $this->addFieldError('processor', $error);
                            
                            
                            $this->modx->log(xPDO::LOG_LEVEL_DEBUG, $error, 'FILE', __CLASS__, "", __LINE__);
                            
                            $product->set('processed', 3);
                            $product->save(); 
                            continue;                            
                        }
                        //else
                        if ($response->isError()) 
                        {                                                        
                            if (!$error = $response->getMessage()) 
                            {
                                $error = "Не удалось создать товар с артикулом '{$article}'";
                            }
                            $resp = $response->getResponse();
                            $this->addFieldError('response', $error);
                            
                            $this->modx->log(xPDO::LOG_LEVEL_DEBUG, $error, 'FILE', __CLASS__, "", __LINE__);                            
                            $this->modx->log(xPDO::LOG_LEVEL_DEBUG, $error . print_r($response->getResponse()['errors'],1), 'FILE', __CLASS__, "", __LINE__);                            
   
                            $product->set('processed', 3);
                            $product->save(); 
                            continue;                             
                        }
                        /*
                         * если объект успешно создан — набиваем твшки;
                        */
                        else 
                        {
                            # $data = $response->getObject();
                            # $resource = $this->modx->getObject('ShopmodxResourceProduct', $data['id']);
                            # if (!$resource) 
                            # {
                            #     $error = "Не был получен объект товара после создания оного. артикул: '{$product->article}'. Значения тв-параметров не будут обновлены";
                            #     $this->modx->log(xPDO::LOG_LEVEL_WARN, $error);
                            #     $this->modx->log(xPDO::LOG_LEVEL_WARN, print_r($product->toArray() , 1));
                            #     $processor->addOutput($error);
                            #     /**
                            #      * ставим флаг «обработано» для товара
                            #      */
                            #     $processor->processTmpEntity($product);
                            #     return $this->failure('failure');
                            #     # continue;
                            #     
                            # }
                            # else
                            # {
                            #     $this->setTVs($resource, $product->extended);
                            # }
                        }                           
                    }
                    
                }
                
                $prefix = null;
                
                /**
                 * ставим флаг «обработано» для товара
                 */
                $product->set('processed', self::PROCESSED_STATUS);
                if(!$product->save()){
                    $error = 'Не удалось обновить временный товар';
                    $this->addFieldError('processed', $error);
                    continue;
                }
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
            
            if($this->getProperty('debug')){
                $this->modx->log(xPDO::LOG_LEVEL_DEBUG, print_r($response, 1), '', __CLASS__);                
            }
            
        }
        
        if(current($response) == true){
            return true;
        }
        
        return false;
    }
    #
    
    public function customProcess(){
        $tmpClass = $this->goodTMPClass;
        $article_tv_id = $this->modx->getOption('shopmodx1c.article_tv_id');
        $catalog_root_id = $this->modx->getOption('shopmodx1c.catalog_root_id');
        $catalog_tmp_root_id = $this->modx->getOption('shopmodx1c.tmp_catalog_root_id');
        $limit = $this->getProperty('process_items_per_step');
        $product_template = $this->modx->getOption('shopmodx1c.product_default_template');
        $image_tv_id = $this->modx->getOption('shopmodx1c.product_image_tv');
        $currency = $this->modx->getOption('shopmodx.default_currency');
        #

        // Получаем первичные данные по не обработанным товарам
        $q = $this->modx->newQuery($tmpClass);
        $q->leftJoin('ShopmodxProduct', 'Product', "Product.sm_article = {$tmpClass}.article");
        $q->where(array(
            "processed" => self::PROCESSED_STATUS,
            'article is not null',
            'Product.resource_id is not null'
        ));
        $q->select(array(
            "Product.resource_id as resource_id",
            "{$tmpClass}.*",
        ));
        $q->limit($limit);
        
        if ($products = $this->modx->getCollection($tmpClass, $q)) 
        {
            foreach($products as $product){                
                
                $ok = $this->_onGoodSave($product);

                if($ok !== true){
                    $this->addFieldError('event', 'error');
                }
                
                $product->set('processed', self::UNPROCESSED_STATUS);
                $product->save();                                    
                
                continue;
            }
            
        }
        
        if($this->hasErrors()){
            return false;
        }
        return true;
    
    }
}
