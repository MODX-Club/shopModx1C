<?php
require_once __DIR__ . '/importparser.class.php';
class priceParser extends importParser
{
    /**
     * stuff
     */
    protected $tmpPriceClass = 'Shopmodx1cTmpPrice';
    protected $tmpPriceTypeClass = 'Shopmodx1cTmpPriceType';
    protected $priceName = '# Рекомендованная розница';
    #
    private $_prices = array();
    private $_priceTypes = array();
    /**
     * XMLReader с Expand - DOM/DOMXpath
     */
    public function parseXML($ABS_FILE_NAME) 
    {
        $reader = $this->getReader($ABS_FILE_NAME);
        #
        
        /**
         */
        while ($reader->read()) 
        {
            if ($this->isNode('КоммерческаяИнформация', $reader)) 
            {
                while ($reader->read()) 
                {
                    /**
                     * обрабатываем классификатор со справочником данных и мер
                     */
                    if ($this->isNode('Классификатор', $reader)) 
                    {
                        while ($reader->read()) 
                        {
                            /**
                             * парсим «типы цен»
                             */
                            if ($this->isNode('ТипыЦен', $reader)) 
                            {
                                while ($reader->read()) 
                                {
                                    if ($this->isNode('ТипЦены', $reader)) 
                                    {
                                        $xml = $this->getXMLNode($reader);
                                        #
                                        
                                        /**
                                         * На данный момент мы парсим всего лишь одну цену.
                                         * Множественные цены на подходе : )
                                         */
                                        if ($xml->Наименование == $this->priceName) 
                                        {
                                            $this->saveTMPPriceType((array)$xml);
                                        }
                                    }
                                    #
                                    
                                    /**
                                     * break the while when we have the end of subtree
                                     */
                                    if ($this->isNodeEnd('ТипыЦен', $reader)) 
                                    {
                                        break;
                                    }
                                }
                            }
                            #
                            
                            /**
                             * skip wrong trees
                             */
                            if ($this->isNode('Группы', $reader) || $this->isNode('Свойства', $reader) || $this->isNode('Склады', $reader) || $this->isNode('ЕдиницыИзмерения', $reader)) 
                            {
                                $reader->next();
                            }
                            #
                            
                            /**
                             * break the while when we have the end of subtree
                             */
                            if ($this->isNodeEnd('Классификатор', $reader)) 
                            {
                                break;
                            }
                        }
                    }
                    #
                    
                    /**
                     * парсим справочник предложений со списком возможных вариантов цен
                     */
                    elseif ($this->isNode('ПакетПредложений', $reader)) 
                    {
                        while ($reader->read()) 
                        {
                            /**
                             * Парсим предложения
                             */
                            if ($this->isNode('Предложения', $reader)) 
                            {
                                while ($reader->read()) 
                                {
                                    /**
                                     */
                                    if ($this->isNode('Предложение', $reader)) 
                                    {
                                        while ($reader->read()) 
                                        {
                                            if ($this->isNode('Ид', $reader)) 
                                            {
                                                # if we catch "Ид" node we try to get it's value
                                                $reader->read();
                                                $goodId = $reader->value;
                                            }
                                            #
                                            
                                            /**
                                             */
                                            if ($this->isNode('Цены', $reader)) 
                                            {
                                                while ($reader->read()) 
                                                {
                                                    if ($this->isNode('Цена', $reader)) 
                                                    {
                                                        $price = (array)$this->getXMLNode($reader);
                                                        #
                                                        
                                                        /**
                                                         * parse price value
                                                         */
                                                        $this->saveTMPPriceValue($goodId, $price);
                                                    }
                                                    #
                                                    
                                                    /**
                                                     */
                                                    if ($this->isNodeEnd('Цены', $reader)) 
                                                    {
                                                        break;
                                                    };
                                                }
                                            }
                                        }
                                        #
                                        
                                        /**
                                         */
                                        if ($this->isNodeEnd('Предложение', $reader)) 
                                        {
                                            break;
                                        }
                                    }
                                    #
                                    
                                    /**
                                     */
                                    if ($this->isNodeEnd('Предложения', $reader)) 
                                    {
                                        break;
                                    }
                                }
                            }
                            #
                            
                            /**
                             */
                            if ($this->isNodeEnd('ПакетПредложений', $reader)) 
                            {
                                break;
                            }
                        }
                        #
                        
                        /**
                         * try to insert all the prices
                         */
                        $this->insertTMPPricesToDB();
                    }
                    else if ($this->isNode('Каталог', $reader)) 
                    {
                        $reader->next();
                    }
                    else if (!$reader->name == '#text') 
                    {
                        $this->modx->log(xPDO::LOG_LEVEL_INFO, 'Some parent xml trees were skipped');
                    }
                }
            }
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
     * save price type
     */
    public function saveTMPPriceType(array $xml) 
    {
        $data = array(
            'article' => $xml['Ид']
        );
        #
        
        /**
         */
        if (!$priceType = $this->modx->getObject($this->tmpPriceTypeClass, $data)) 
        {
            $priceType = $this->modx->newObject($this->tmpPriceTypeClass);
            $priceType->fromArray($data);
        }
        $priceType->save();
    }
    #
    
    /**
     * save price value
     */
    public function saveTMPPriceValue($goodId, array $price) 
    {
        $this->_prices[] = array(
            'value' => $price['ЦенаЗаЕдиницу'],
            'type_id' => $price['ИдТипаЦены'],
            'good_id' => $goodId
        );
    }
    #
    
    /**
     * insert prices to the database
     */
    public function insertTMPPricesToDB() 
    {
        $i = 0;
        $table = $this->modx->getTableName($this->tmpPriceClass);
        $rows = array();
        $columns = array(
            "value",
            "type_id",
            "good_id",
        );
        #
        
        /**
         */
        array_walk($this->_prices, function ($price) use ($table, &$rows, $columns, &$i) 
        {
            $i++;
            $type_id = $price['type_id'];
            $good_id = ($price['good_id'] ? "'{$price['good_id']}'" : "NULL");
            $value = (float)$price['value'];
            #
            $rows[] = "('{$value}', '{$type_id}', {$good_id})";
            #
            
            /**
             */
            if ($i % $this->getProperty('linesPerStep') == 0) 
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
        $this->_prices = array();
        return true;
    }
    #
    
    /**
     * save prices
     */
    public function savePrices() 
    {
        $c = $this->modx->newQuery($this->tmpPriceClass);
        $ck = $c->getAlias();
        $c->innerJoin($this->tmpPriceTypeClass, 'PriceType', "{$ck}.type_id = PriceType.article");
        #
        
        /**
         */
        #  $_keyOption = $this->modx->getOption('shopmodx1c.article_field_name');
        # if (!empty($_keyOption))
        # {
        #     $c->innerJoin('ShopmodxProduct', 'Product', "{$ck}.good_id = product.sm_article");
        # }
        # else{
        #     $c->innerJoin('ShopmodxProduct', 'Product', "{$ck}.good_id = product.sm_externalKey");
        # }
        $c->innerJoin('ShopmodxProduct', 'Product', "{$ck}.good_id = Product.sm_externalKey");
        $c->where(array(
            "processed" => 0
        ));
        #
        # $c->prepare();
        # print $c->toSQL();
        # $this->modx->log(5, print_r($c->toSQL()));
        # $this->modx->log(5, print_r($this->modx->getCount($this->tmpPriceClass, $c)));
        # die;
        foreach ($this->modx->getCollection($this->tmpPriceClass, $c) as $price) 
        {
            $goodId = $this->modx->getObject('ShopmodxProduct', array(
                'sm_externalKey' => $price->good_id
            ));
            # $this->modx->log(1,print_r($goodId->toArray()))
            if ($goodId) 
            {
                $goodId->set('sm_price', $price->get('value'));
                $goodId->save();
            }
            $price->set('processed', 1);
            $price->save();
        }
        return true;
    }
}
