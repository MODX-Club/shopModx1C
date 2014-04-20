<?php

/*
    Процессор, определяющий по запрошенному действию какой процессор выполнять
*/

class mod1cimportWebPublicActionProcessor extends modProcessor{
    
    protected static $actualClassName;
    
    public static function getInstance(modX &$modx,$className,$properties = array()) {
        
        // Здесь мы имеем возможность переопределить реальный класс процессора
        if(!empty($properties['_action']) && !self::$actualClassName){
             
            switch($properties['_action']){
                
                case 'exchange/catalog/import':
                    require dirname(dirname(__FILE__)) . '/exchange/catalog/import.class.php';                    
                    self::$actualClassName =  'mod1cWebExchangeCatalogImportProcessor';
                    break; 
                
                default:;
            }
             
        }
        
        if(self::$actualClassName){
            $className = self::$actualClassName;
        }

        return parent::getInstance($modx,$className,$properties);
    }    
     
    
    public function process(){
        $error = 'Действие не существует или не может быть выполнено';
        $this->modx->log(xPDO::LOG_LEVEL_ERROR, __CLASS__ . " - {$error}");
        $this->modx->log(xPDO::LOG_LEVEL_ERROR, print_r($this->getProperties(), true));
        return $this->failure($error);
    }
    
}

return 'mod1cimportWebPublicActionProcessor';
