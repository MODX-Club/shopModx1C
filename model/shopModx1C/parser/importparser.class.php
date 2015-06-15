<?php
abstract class importParser extends modProcessor
{
    /**
     * XMLReader with Expand - DOM/DOMXpath
     */
    abstract public function parseXML($ABS_FILE_NAME);
    #
    
    /**
     */
    protected function getReader($ABS_FILE_NAME) 
    {
        $reader = new XMLReader();
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
     * check the end of xml-node
     */
    protected function isNodeEnd($nodeName, XMLReader $reader) 
    {
        return (($reader->nodeType == XMLReader::END_ELEMENT) && ($reader->name == $nodeName)) ? true : false;
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
     */
    public function process() 
    {
        return true;
    }
    #
    
}
