<?php
abstract class importParser extends modProcessor
{
    /**
     * XMLReader with Expand - DOM/DOMXpath
     */
    abstract public function parseXML($ABS_FILE_NAME);
    #
    
    /**
     * статусы обработки временных записей
     */
    const PROCESSED_STATUS = 1;
    const UNPROCESSED_STATUS = 0;
    #
    
    protected $events = array();
    
    /**
     */
    protected function getReader($ABS_FILE_NAME) 
    {
        $reader = new XMLReader();
        if(!is_file($ABS_FILE_NAME)){
            $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Can\'t load the file', '', __CLASS__);
            return false;
        }
        $reader->open($ABS_FILE_NAME);
        return $reader;
    }
    #
    
    /**
     * check the xml-node
     */
    protected function isNode($nodeName, XMLReader $reader) 
    {
        return (($reader->nodeType == XMLReader::ELEMENT) && ($reader->name == $nodeName)) ? true : false;
    }
    #
    
    /**
     * check the text-xml-node
     */
    protected function isNodeText(XMLReader $reader) 
    {
        return ($reader->nodeType == XMLReader::TEXT || $reader->nodeType == XMLReader::SIGNIFICANT_WHITESPACE) ? true : false;
    }
    #
    
    /**
     * check the end of xml-node
     */
    protected function isNodeEnd($nodeName = null, XMLReader $reader) 
    {
        $cond = $reader->nodeType == XMLReader::END_ELEMENT;
        if(!$nodeName){
            return $cond;
        }
        # else        
        return ($cond && ($reader->name == $nodeName)) ? true : false;
    }
    #
    
    /**
     * get xml-node
     */
    protected function getXMLNode($reader) 
    {
        return simplexml_load_string($reader->readOuterXML());
    }
    #
    
    /**
     *  get the node key 
     */
    protected function getNodeName(XMLReader $reader){
        return $reader->name;
    }
    
    /**
     * insert rows to the DB
     */
    protected function insertInDataBase($table, array $rows, array $columns) 
    {
        $columns_str = implode(", ", $columns);
        $sql = "INSERT INTO {$table} 
            ({$columns_str}) 
            VALUES \n";
        $sql.= implode(",\n", $rows) . ";";
        $s = $this->modx->prepare($sql);
        
        if($this->getProperty('debug')){
            $this->modx->log(xPDO::LOG_LEVEL_DEBUG, print_r($sql,1), '', __CLASS__);
        }

        $result = $s->execute();
        if (!$result) 
        {
            $this->modx->log(xPDO::LOG_LEVEL_WARN, 'SQL ERROR Import');
            $this->modx->log(xPDO::LOG_LEVEL_WARN, print_r($s->errorInfo() , 1));
            $this->modx->log(xPDO::LOG_LEVEL_WARN, $sql);
        }
        return $result;
    }
    #
    
    /**
     * log items left
     */
    protected function logCount($class, xPDOQuery & $q, $name = 'items') 
    {
        $c = clone $q;
        $c->limit(0);
        $count = $this->modx->getCount($class, $c);
        
        if($this->getProperty('debug')){
            $this->modx->log(xPDO::LOG_LEVEL_DEBUG, "{$count} {$name} left…");        
        }
    }
    #
    
    /**
     */
    public function process() 
    {
        return true;
    }
    #
    
        
    // Находим ID документа по артикулу
    protected function getResourceIdByArticle($article) 
    {
        $result = null;
        $article_tv_id = $this->modx->getOption('shopmodx1c.article_tv_id');
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
