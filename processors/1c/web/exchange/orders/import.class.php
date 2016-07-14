<?php
require_once dirname(dirname(__FILE__)) . '/import.class.php';
class mod1cWebExchangeOrdersImportProcessor extends mod1cWebExchangeImportProcessor
{
    /**
     */
    protected function loadParsers() 
    {
        if (!property_exists($this->modx, 'orderparser')) 
        {
            $this->modx->getService('orderparser', 'shopModx1C.parser.orderParser', $this->getModulePath() , array(
                'linesPerStep' => $this->linesPerStep
            ));
        }
        if (!property_exists($this->modx, 'goodparser')) 
        {
            $this->modx->getService('goodparser', 'shopModx1C.parser.goodParser', $this->getModulePath() , array(
                'linesPerStep' => $this->linesPerStep
            ));
        }
    }
    #
    
    /**
     */
    protected function setSteps($ABS_FILE_NAME, &$NS, &$strMessage, &$strError) 
    {
        switch ($NS["STEP"]) 
        {
        case 0:
            $this->importOrders($ABS_FILE_NAME);
            $NS["STEP"] = 1;
            $this->addOutput("Swtich to the {$NS['STEP']}-th step");
        break;
        case 1:
            $this->saveGoods();
            $NS["STEP"] = 2;
        break;
        default:
            return $this->end(true, 'success');
        break;
        }
    }
    #
    
    /**
     * try to parse orders
     */
    protected function importOrders($ABS_FILE_NAME) 
    {
        $ok = $this->modx->orderparser->parseXML($ABS_FILE_NAME);
        if ($ok !== true) 
        {
            return $ok;
        }
        return true;
    }
    #
    
    /**
     */
    protected function saveGoods() 
    {
        $ok = $this->modx->goodparser->saveTMPGoods($this);
        if ($ok !== true) 
        {
            return $ok;
        }
        return true;
    }
}
return 'mod1cWebExchangeOrdersImportProcessor';
