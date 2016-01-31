<?php
class mod1cWebExchangeExchangeProcessor extends modProcessor
{
    
    public function __construct(modX & $modx,array $properties = array()){
        
        $modx->getService('shopmodx1c', 'classes.Shopmodx1c', MODX_CORE_PATH . 'components/shopmodx1c/model/shopModx1C/');
        $this->shopmodx1c = &$modx->shopmodx1c;
        
        return parent::__construct($modx, $properties);
    }
    
    protected $outputData = array();
    # 
    
    /**
     * режимы импорта
     */
    protected $debug = false;
    #
    
    /**
     * уровни логов импорта
     */
    protected $log_success_level = xPDO::LOG_LEVEL_INFO;
    protected $log_failure_level = xPDO::LOG_LEVEL_ERROR;
    #
    
    /**
     * init
     */
    public function initialize() 
    {
        $this->modx->addPackage('shopModx1C', $this->shopmodx1c->getModulePath());
        $this->setDefaultProperties(array(
            "outputCharset" => "CP1251",
        ));
        return parent::initialize();
    }
    #
    
    /**
     * do the stuff
     */
    public function process() 
    {
        return $this->failure('failure');
    }
    #
    
    /**
     * prepare data for output
     */
    protected function addOutput($string) 
    {
        $this->outputData[] = $string;
    }
    #
    
    /**
     * prepare data 4 output to 1c
     */
    protected function _prepareOutput($msg = '') 
    {
        $data = array();
        $msg ? $data[] = $msg : "";
        $data = array_merge($data, $this->outputData);
        $output = implode("\n", $data);
        return $output;
    }
    /**
     * custom logging
     */
    protected function log($level, $output) 
    {
        $txt = ($level == $this->log_failure_level) ? 'Failure' : 'Success';
        $this->modx->log($level, "{$txt}: " . $output);
    }
    #
    
    /**
     * custom success
     */
    public function success($msg = '', $object = null) 
    {
        $output = $this->_prepareOutput($msg);
        $this->log($this->log_success_level, $output);
        return parent::success($msg, $output);
    }
    #
    
    /**
     * custom failure
     */
    public function failure($msg = '', $object = null) 
    {
        $output = $this->_prepareOutput($msg);
        $this->log($this->log_failure_level, $output);
        return parent::failure($msg ? $msg : $output);
    }
    #
    
}
return 'mod1cWebExchangeExchangeProcessor';
