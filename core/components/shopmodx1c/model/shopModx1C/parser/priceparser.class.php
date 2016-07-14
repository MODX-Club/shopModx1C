<?php
require_once __DIR__ . '/importparser.class.php';

class priceParser extends importParser
{
    /**
     * stuff
     */
    protected $tmpPriceClass = 'Shopmodx1cTmpPrice';
    protected $tmpPriceTypeClass = 'Shopmodx1cTmpPriceType';
    #
    private $_prices = array();
    private $_priceTypes = array();
    # 
    
    protected function getJsonSchema($schema_name){
        return json_decode($this->modx->getOption("shopmodx1c.{$schema_name}", '{}',1));
    }
    
    protected function getSchemaNodeByKey(& $schema, $nodeKey){                    
        
        if(!is_object($schema)){
            return null;
        }
        
        $keys = array_keys((array)$schema);
        
        if(count($keys) == 1 && $nodeKey == current($keys)){
            $schema = $schema->$nodeKey;            
        }
        
        return $schema;
    }
    
    public function parseXML($ABS_FILE_NAME){
        $schema = $this->getJsonSchema('json_schema_price');
        $reader = $this->getReader($ABS_FILE_NAME);
        #        
        
        /**/
        while ($reader->read()){
            
            $node = $this->getNodeName($reader);            
            
            if(!$this->isNodeText($reader) && $this->getSchemaNodeByKey($schema, $node)){                                

                if(isset($schema->parse) && $schema->parse){
                    
                    $xml = $this->getXMLNode($reader);                    
                    
                    $result = $this->parseTMPPrice($reader, $schema);
                    if(count($result)){
                        $this->_prices[] = $result;                                            
                    }       
                    
                    $reader->next();                                 
                }                               
                
            }            
        }
        

        if($this->hasErrors()){
            return false;
        }
        
        $this->insertTMPPricesToDB();
        $this->_prices = array();
        
        return true;
        
    }
    
    protected function parseMultipleObjectInstances($xml, $nodeKey, $schema, & $_price){
        foreach($xml->$nodeKey as $v){                        
            
            $validateRules = $schema->$nodeKey->validate;
            if($validateRules){
                
                if($validateRules->cond == 'gt:0'){                            
                    $validateKey = $validateRules->key;
                    
                    if(isset($v->$validateKey) && $v->$validateKey <= 0){
                        continue;
                    }
                }                                                      
            }            

            array_walk($schema->$nodeKey, function($node, $key) use (& $v, & $_price){
  
                if(!isset($v->$key)){
                    return;
                }else{
                    $value = $v->$key;
                }                
                
                if(isset($node->type)){
                    settype($value, $node->type);
                }
                
                $_price[isset($node->field) ? $node->field : $key] = $value;                
            });
    
        } 
        
        return $_price;
    }
    
    protected function parseTMPPrice($reader, stdClass $schema){
        $xml = $this->getXMLNode($reader);
        $_price = array();        
        
        $schemaArray = (array)$schema;
        array_walk($schemaArray, function($schemaNode, $schemaKey) use (& $_price, $xml, $schema){

            if(is_object($schemaNode) && ($value = $xml->$schemaKey)){
                $firstIndex = current(array_keys((array)$value));                
                
                if($firstIndex === 0){
                    
                    if(isset($schemaNode->type)){
                        settype($value, $schemaNode->type);
                    }
                    $_price[isset($schemaNode->field) ? $schemaNode->field : $schemaKey] = $value; 
                }else{
                    
                    $_price = $this->parseMultipleObjectInstances($value, $firstIndex, $schema->$schemaKey, $_price);
                    
                }
                
            }                                    
        });

        return $_price;
    }
    
    protected function insertTMPPricesToDB(){                
        $i = 0;
        $table = $this->modx->getTableName($this->tmpPriceClass);
        $rows = array();
        $columns = array(
            "article",
            "type_id",
            "good_id",
            "value",
            "currency_name"
        );
        #
        
        /**/
        $total = count($this->_prices);
        $step = $this->getProperty('linesPerStep');
        array_walk($this->_prices, function ($price) use ($table, &$rows, $columns, &$i, $step, $total) 
        {
            $i++;
            $type_id = isset($price['type_id']) ? $price['type_id'] : "NULL";
            $good_id = (isset($price['good_id']) ? "{$price['good_id']}" : "NULL");
            $article = (isset($price['article']) ? "{$price['article']}" : "NULL");
            $currency_name = (isset($price['currency_name']) ? "{$price['currency_name']}" : "RUR");
            $value = isset($price['value']) ? (float)$price['value'] : 0;
            #
            $rows[] = "('{$article}', '{$type_id}', '{$good_id}', '{$value}', '{$currency_name}')";
            #

            /**
             */
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
    
    public function saveTMPRecords(){
        
        $tmpClass = $this->tmpPriceClass;
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
        $q->leftJoin('Shopmodx1cTmpProduct', "TMPProduct", "TMPProduct.externalKey = {$tmpClass}.good_id");
        $q->where(array(
            "processed" => self::UNPROCESSED_STATUS,
        ));
        $q->select(array(
            "Product.resource_id as resource_id",
            "Product.id as product_id",
            "IF(TMPProduct.longtitle != '', TMPProduct.longtitle,{$tmpClass}.article) as product_name",
            "{$tmpClass}.*",
        ));
        $q->limit($limit);

#         $q->prepare();
#         print $q->toSQL();
#         die;        
        
        if ($prices = $this->modx->getCollection($tmpClass, $q)) 
        {
        
            foreach($prices as $price){
                
                $article = $price->article;
                
                if($pid = $price->product_id){
                    
                    $sm_currency_name = $price->currency_name;
                    if($sm_currency_name == 'руб' || !$sm_currency_name){
                        $sm_currency_name = 'RUR';
                    }
                    
                    $data = array(
                        'sm_price' => $price->value,
                        'sm_currency' => $this->modx->getObject('modResource', array('parent' => 78, 'pagetitle' => $sm_currency_name))->id,
                        'name' => $price->product_name
                    );    

                    $product = $this->modx->getObject('ShopmodxProduct', $pid);
                    
                    $product->fromArray($data);
                    if(!$product->save()){
                        $error = "Не удалось обновить товар c артикулом {$price->article}";
                        $this->addFieldError('processed', $error);
                        
                        $price->set('processed', self::PROCESSED_STATUS);
                        $price->save();
                        continue;
                    }
                    
                }else{
                    
                    if ($catalog_tmp_root_id) 
                    {
                        $catalog_root_id = $catalog_tmp_root_id;
                    }
                    
                    $parent = $catalog_root_id;
                    
                    $shouldGroupProductsByRule = $this->modx->getOption('shopmodx1c.group_products_by_rule');  
                                                            
                    # Если указано правило группировки, то собираем товары в группы по артикулу
                    if($shouldGroupProductsByRule){
                        
                        if($pos = strpos($article, '.')){                        
                            $prefix = substr($article, 0, $pos);                                            
                        }
                        
                    }
                    # 
                    
                    $content = preg_replace("/[\n]/", "<br />\n", $price->description);
                    
                    if(isset($prefix)){
                        
                        $data = array(
                            'class_key' => 'ShopmodxResourceProduct',
                            'pagetitle' => $prefix,
                            'longtitle' => $price->longtitle ? $price->longtitle : $price->title,
                            "content" => $content,
                            "parent" => $price,
                            "template" => $product_template,
                            "isfolder" => 0
                        );                                                                        
                        
                        $existed_resource = $this->modx->getObject('modResource', array('pagetitle' => $prefix));                        
                        
                    }else{
                                                
                        $data = array(
                            "class_key" => "ShopmodxResourceProduct",
                            "pagetitle" => $price->title ? $price->title : $article,
                            'longtitle' => $price->longtitle ? $price->longtitle : $price->title,
                            "content" => $content,
                            "parent" => $parent,
                            "template" => $product_template,
                            "isfolder" => 0
                            
                        );     
                    }
                    
                    $sm_currency_name = $price->currency_name;
                    if($sm_currency_name == 'руб' || !$sm_currency_name){
                        $sm_currency_name = 'RUR';
                    }
                    
                    $data["sm_article"] = $article;
                    $data['name'] = $data['longtitle'];
                    $data['sm_price'] = $price->value;
                    $data['sm_currency'] = $this->modx->getObject('modResource', array('parent' => 78, 'pagetitle' => $sm_currency_name))->id;
                    $data['alias'] = isset($prefix) ? ($data['longtitle'] . '-' . $prefix) : ($data['longtitle'] . '-' . $article);
                    
                    # Перед созданием ресурса нам необходимо проверить на существование ресурса с нужным нам заголовком, соответствующим префиксу артикула.
                    # Если таковой есть, то создаем к нему товар в связку. Нет — создаем и ресурс и товар                    
                    
                    if(isset($existed_resource) && is_object($existed_resource)){                                            
                        
                        unset($data['class_key']);
                        $data['parent'] = $existed_resource->id;
                        $data['name'] = $data['longtitle'];
                        
                        $newProduct = $this->modx->newObject('ShopmodxProduct');
                        $newProduct->fromArray($data);
                        
                        $newProduct->set('resource_id', $existed_resource->id);                        
                        
                        if(!$newProduct->save()){
                            $error = "Не удалось создать товар с артикулом '{$article}'";                        
                            $this->addFieldError('article', $error);
                            
                            $price->set('processed', self::PROCESSED_STATUS);
                            $price->save(); 
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
                            
                            $price->set('processed', self::PROCESSED_STATUS);
                            $price->save(); 
                            continue;                            
                        }
                        //else
                        if ($response->isError()) 
                        {
                            if (!$error = $response->getMessage()) 
                            {
                                $error = "Не удалось создать товар с артикулом '{$article}'";
                            }
                            $this->addFieldError('response', $error);
                            
                            $price->set('processed', self::PROCESSED_STATUS);
                            $price->save(); 
                            continue;                             
                        }
                        
                    }
                  
                }
                
                $prefix = null;
        
                /**
                 * ставим флаг «обработано» для товара
                 */
                $price->set('processed', self::PROCESSED_STATUS);
                if(!$price->save()){
                    $error = 'Не удалось обновить временный товар';
                    $this->addFieldError('processed', $error);
                    continue;
                }
            }
        }
    }

}
