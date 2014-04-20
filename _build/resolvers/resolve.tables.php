<?php
$pkgName = 'shopModx1C';

if ($object->xpdo) {
    switch ($options[xPDOTransport::PACKAGE_ACTION]) {
        case xPDOTransport::ACTION_INSTALL:
        case xPDOTransport::ACTION_UPGRADE:
            $modx =& $object->xpdo;
            $modelPath = $modx->getOption('shopmodx1c.core_path',null,$modx->getOption('core_path').'components/shopmodx1c/').'model/';
            $modx->addPackage($pkgName, $modelPath);

            $manager = $modx->getManager();
            $modx->setLogLevel(modX::LOG_LEVEL_ERROR);
            
            $objects = array(
                'Shopmodx1cTmpCategory',
                'Shopmodx1cTmpProduct',
            );
            
            foreach($objects as $o){
                $manager->createObjectContainer($o);
            }
            $modx->setLogLevel(modX::LOG_LEVEL_INFO);
            break;
    }
}
return true;