<?php

class Shopmodx1c extends modProcessor{
    
    public function process(){
        return true;
    }
    
    public function getModulePath() 
    {
        return MODX_CORE_PATH . "components/shopmodx1c/model/";
    }
    
}