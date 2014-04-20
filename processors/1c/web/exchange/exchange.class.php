<?php

class mod1cWebExchangeExchangeProcessor extends modProcessor{
    
    protected $outputData = array();        
    
    public static function getInstance(modX &$modx,$className,$properties = array()) {
        
        // Здесь мы имеем возможность переопределить реальный класс процессора
        if(!empty($properties['type'])){
             
            switch($properties['type']){
                
                case 'catalog': 
                    require_once dirname(__FILE__) . '/catalog/import.class.php';                    
                    $className =  'mod1cWebExchangeCatalogImportProcessor';
                    break;
                
                default:;
            }
            
        } 
        
        return parent::getInstance($modx,$className,$properties);
    }    
    
    
    public function initialize(){
        
        $this->modx->addPackage('shopModx1C', MODX_CORE_PATH . 'components/shopmodx1c/model/');
        
        return parent::initialize();
    }
    
    
    public function process(){
        
        return $this->failure('failure');
    }
    
    
    protected function addOutput($string){
        $this->outputData[] = $string;
    }
    
    public function success($msg = '',$object = null) {
        
        $data = array();
        
        $msg ? $data[] = $msg : "";
        
        $data = array_merge($data, $this->outputData);
        
        $output = implode("\n", $data);
        
        $this->modx->log(1, "Success: ". $output);
        $this->modx->log(1, print_r($data, 1));
        
        return $output;
    }
    
    public function failure($msg = '',$object = null) {
        
        $data = array();
        
        $msg ? $data[] = $msg : "";
        
        $data = array_merge($data, $this->outputData);
        
        $output = implode("\n", $data);
        
        $this->modx->log(1, "Failure: ". $output);
        $this->modx->log(1, print_r($data, 1));
        
        return $output;
    }
}


return 'mod1cWebExchangeExchangeProcessor';
