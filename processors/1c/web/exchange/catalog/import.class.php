<?php
/*
    Импорт данных из 1С.
    Следует учитывать, что все это выполняется не за один запрос,
    а за условно бесконечное, пока не будет выполнен импорт до успеха или ошибки.
    За это отвечают ответы success|progress|failure
*/
require_once dirname(dirname(__FILE__)) . '/import.class.php';
class mod1cWebExchangeCatalogImportProcessor extends mod1cWebExchangeImportProcessor
{
    
    public function initialize(){
        
        if($this->getProperty('debug')){
            $this->modx->setLogTarget("ECHO");            
        }   
        
        if($perstep = $this->getProperty('process_items_per_step')){
            $this->tableRowsPerStep = $perstep;
        }
                
        return parent::initialize();
    }
    
    protected function loadParsers(){
        $ok = parent::loadParsers();
        if($ok !== true){
            return $ok;
        }
        
        $this->modx->getService('priceparser', 'shopModx1C.parser.priceParser', $this->shopmodx1c->getModulePath() , array(
            'process_items_per_step' => $this->tableRowsPerStep,
            'linesPerStep' => $this->linesPerStep, 
            'debug' => $this->getProperty('debug')
        )); 
        
        $this->modx->getService('categoryparser', 'shopModx1C.parser.categoryParser', $this->shopmodx1c->getModulePath() , array(
            'process_items_per_step' => $this->tableRowsPerStep,
            'linesPerStep' => $this->linesPerStep,
            'debug' => $this->getProperty('debug')
        )); 
        
        $this->modx->getService('goodparser', 'shopModx1C.parser.goodParser', $this->shopmodx1c->getModulePath() , array(
            'process_items_per_step' => $this->tableRowsPerStep,
            'linesPerStep' => $this->linesPerStep,
            'debug' => $this->getProperty('debug')
        ));  

        return true;
    }
    
    
    /**/
    protected function setSteps(&$NS, $ABS_FILE_NAME, &$strMessage, &$strError) 
    {
        switch ($NS["STEP"]) 
        {
        case 0:
            /**
             * Перемещаем картинки в папку изображений товаров
             */
            $this->importFiles();
            
            $NS["STEP"] = 1;
            $this->addOutput('Swtich to the 1-th step');
            
            return $this->end(true, 'success');
        break;
        
        case 1:
            
            $ok = $this->modx->categoryparser->parseXML($ABS_FILE_NAME);
            if ($ok !== true)
            {
                return $this->end(false, $ok ? $ok : 'failure');
            }
            
            $NS["STEP"] = 2;
            $this->addOutput('Swtich to the 2-th step');
        break;
        
        case 2:
            
            $ok = $this->modx->goodparser->parseXML($ABS_FILE_NAME);
            if($ok !== true){
                return $this->end(false, $ok ? $ok : 'failure');
            }
            
            $NS['STEP'] = 3;
            $this->addOutput('Switch to the 3-d step');
            
        break;                
            
        case 3:
            
            $ok = $this->modx->priceparser->parseXML($ABS_FILE_NAME);
            if($ok !== true){
                return $this->end(false, $ok ? $ok : 'failure');
            }
            
            $NS['STEP'] = 4;
            $this->addOutput('Switch to the 4-d step');
            
        break;
        
        case 4:
            
            $ok = $this->modx->categoryparser->saveTMPRecords();
            if($ok !== true){
                return $this->end(false, $ok ? $ok : 'failure');
            }
            
            $NS['STEP'] = 5;
            $this->addOutput('Switch to the 5-d step');
            
        break; 
        
        case 5:
            
            $ok = $this->modx->goodparser->saveTMPRecords();
            if($ok !== true){
                return $this->end(false, $ok ? $ok : 'failure');
            }
            
            $NS['STEP'] = 6;
            $this->addOutput('Switch to the 6-d step');
            
        break; 
        
        case 6:
            
            $ok = $this->modx->priceparser->saveTMPRecords();
            if($ok !== true){
                return $this->end(false, $ok ? $ok : 'failure');
            }
            
            $NS['STEP'] = 7;
            $this->addOutput('Switch to the 7-d step');
            
        break;
        
        case 7:
            
            $ok = $this->modx->goodparser->customProcess();
            if($ok !== true){
                return $this->end(false, $ok ? $ok : 'failure');
            }
            
            $NS['STEP'] = 8;
            $this->addOutput('Switch to the 8-d step');
            
        break;                
             
        default:
            return $this->end(true, 'success');
        break;
        }
    }
    #
    
    protected function importFiles(){
        $DIR_NAME = $this->getProperty('DIR_NAME');
        $source = $DIR_NAME . 'import_files/';
        
        $target = $this->modx->getOption('shopmodx1c.images_path', null, MODX_ASSETS_PATH . 'images/') . "import_files/";
        
        $this->modx->loadClass('modCacheManager', '', true, false);
        $modCacheManager = new modCacheManager($this->modx);
        $modCacheManager->copyTree($source, $target);
    }
    
}
return 'mod1cWebExchangeCatalogImportProcessor';
