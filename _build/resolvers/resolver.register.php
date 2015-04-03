<?php
$pkgName = 'modTVGroups';
$pkgNameLower = strtolower($pkgName);
if ($object->xpdo) 
{
    $modx = & $object->xpdo;
    $modelPath = $modx->getOption("{$pkgNameLower}.core_path", null, $modx->getOption('core_path') . "components/{$pkgNameLower}/") . 'model/';
    switch ($options[xPDOTransport::PACKAGE_ACTION]) 
    {
    case xPDOTransport::ACTION_INSTALL:
    case xPDOTransport::ACTION_UPGRADE:
        if (!$FCProfile = $modx->getObject('modFormCustomizationProfile', array(
            'name' => $pkgName
        ))) 
        {
            $FCProfile = $modx->newObject('modFormCustomizationProfile');
        }
        $FCProfile->set('name', $pkgName);
        $FCProfile->set('active', 1);
        $modx->log(xPDO::LOG_LEVEL_INFO, 'Adding FC profile…');
        $_Sets = array();
        $action = $modx->getObject('modAction', array(
            'controller' => 'resource/update'
        ));
        $setData = array(
            'action' => $action->id,
            'constraint_class' => 'modResource',
            'active' => 1
        );
        if (!$FCSet = $modx->newObject('modFormCustomizationSet', $setData)) 
        {
            $FCSet = $modx->newObject('modFormCustomizationSet');
        }
        $FCSet->fromArray($setData);
        $modx->log(xPDO::LOG_LEVEL_INFO, 'Adding FC set…');
        $_Rules = array();
        $actionData = array(
            'name' => 'modx-panel-resource-tv-groups',
            'action' => $action->id,
            'container' => 'modx-resource-tabs',
            'rule' => 'tabNew',
            'for_parent' => 1,
            'value' => 'tv_groups'
        );
        if (!$actionDom = $modx->getObject('modActionDom', $actionData)) 
        {
            $actionDom = $modx->newObject('modActionDom');
        }
        $actionDom->fromArray($actionData);
        $modx->log(xPDO::LOG_LEVEL_INFO, 'Adding DOM action…');
        $_Rules[] = $actionDom;
        $FCSet->Rules = $_Rules;
        $_Sets[] = $FCSet;
        $FCProfile->Sets = $_Sets;
        $modx->log(xPDO::LOG_LEVEL_INFO, 'FC profile saving…');
        $FCProfile->save();
        // register extension
        if ($modx instanceof modX) 
        {
            $modx->addExtensionPackage($pkgName, "[[++core_path]]components/{$pkgNameLower}model/", array(
                'serviceName' => $pkgName,
                'serviceClass' => $pkgName,
            ));
            $modx->log(xPDO::LOG_LEVEL_INFO, 'Adding ext package');
        }
    break;
    case xPDOTransport::ACTION_UNINSTALL:
        if ($modx instanceof modX) 
        {
            $modx->removeExtensionPackage($pkgName);
        }
    break;
    }
}
return true;
