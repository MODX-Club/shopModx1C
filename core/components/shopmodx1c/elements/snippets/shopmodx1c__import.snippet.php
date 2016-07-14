<?php
print '<pre>';
ini_set('display_errors', 1);
$modx->switchContext('web');
$modx->setLogLevel(3);
$modx->setLogTarget('HTML');

$_SESSION['SM_1C_IMPORT'] = array(
    // 'zip' => MODX_CORE_PATH . 'components/shopmodx1c/import_files/import.zip',
    "NS" => array(
        "STEP" => 0    
    )
);
 
$namespace = 'shopmodx1c';        // Неймспейс комопонента

$params = array(  
    'mode' => 'import',
    'outputCharset' => 'utf-8',
    'filename' => MODX_CORE_PATH . 'components/shopmodx1c/import_files/import.xml',
    'debug' => true

);

// if(!$response = $modx->runProcessor('1c/web/exchange/catalog/import',
if(!$response = $modx->runProcessor('1c/web/exchange/catalog/import',
$params
, array(
'processors_path' => $modx->getObject('modNamespace', $namespace)->getCorePath().'processors/',
))){
print "Не удалось выполнить процессор";
return;
}
 
$memory = round(memory_get_usage(true)/1024/1024, 4).' Mb';
print "<div>Memory: {$memory}</div>";
$totalTime= (microtime(true) - $modx->startTime);
$queryTime= $modx->queryTime;
$queryTime= sprintf("%2.4f s", $queryTime);
$queries= isset ($modx->executedQueries) ? $modx->executedQueries : 0;
$totalTime= sprintf("%2.4f s", $totalTime);
$phpTime= $totalTime - $queryTime;
$phpTime= sprintf("%2.4f s", $phpTime);
print "<div>TotalTime: {$totalTime}</div>";

print_r($response->getResponse());


$objects = $response->getObject();
foreach($objects as $object){
}