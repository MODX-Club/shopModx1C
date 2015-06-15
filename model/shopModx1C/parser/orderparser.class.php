<?php
require_once __DIR__ . '/importparser.class.php';
class orderParser extends importParser
{
    /**
     * stuff
     */
    protected $tmpGoodClass = 'Shopmodx1cTmpProduct';
    #
    private $_goods = array();
    #
    private $debug = false;
    #
    
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
                     * обрабатываем документы(заказы)
                     */
                    if ($this->isNode('Документ', $reader)) 
                    {
                        while ($reader->read()) 
                        {
                            /**
                             * парсим товары
                             */
                            if ($this->isNode('Товары', $reader)) 
                            {
                                while ($reader->read()) 
                                {
                                    if ($this->isNode('Товар', $reader)) 
                                    {
                                        $xml = $this->getXMLNode($reader);
                                        #
                                        $this->saveTMPGoodData($xml);
                                    }
                                    #
                                    
                                    /**
                                     * break the while when we have the end of subtree
                                     */
                                    if ($this->isNodeEnd('Товары', $reader)) 
                                    {
                                        break;
                                    }
                                }
                            }
                            #
                            
                            /**
                             * break the while when we have the end of subtree
                             */
                            if ($this->isNodeEnd('Документ', $reader)) 
                            {
                                break;
                            }
                            #
                            
                        }
                    }
                    #
                    else if (!$reader->name == '#text') 
                    {
                        $this->modx->log(xPDO::LOG_LEVEL_INFO, 'Some parent xml trees were skipped');
                    }
                    else
                    {
                        $this->debug ? $this->modx->log(xPDO::LOG_LEVEL_INFO, 'Unknown xml tree was skipped') : '';
                    }
                }
                #
                
                /**
                 */
                $this->insertTMPGoodToDB();
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
     */
    protected function getTMPGoodKey($good) 
    {
        return $good['id'] . $good['title'];
    }
    #
    
    /**
     * save price value
     */
    public function saveTMPGoodData($xml) 
    {
        $good = array(
            'id' => (string)$xml->Ид,
            'article' => (string)$xml->Артикул,
            'price' => (float)$xml->ЦенаЗаЕдиницу,
            'title' => (string)$xml->Наименование
        );
        #
        $key = $this->getTMPGoodKey($good);
        if (array_key_exists($key, $this->_goods)) 
        {
            return;
        }
        #
        $this->_goods[$key] = $good;
    }
    #
    
    /**
     * insert prices to the database
     */
    public function insertTMPGoodToDB() 
    {
        $i = 0;
        $table = $this->modx->getTableName($this->tmpGoodClass);
        $rows = array();
        $columns = array(
            "article",
            "title"
        );
        #
        
        /**
         */
        array_walk($this->_goods, function ($good) use ($table, &$rows, $columns, &$i) 
        {
            $i++;
            $article = $good['id'];
            $title = htmlentities($good['title'], ENT_QUOTES);
            #
            $rows[] = "('{$article}', '{$title}')";
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
        $this->_goods = array();
        return true;
    }
    #
    
}
