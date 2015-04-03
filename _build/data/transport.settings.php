<?php
$settings = array();
# $setting_name = PKG_NAME_LOWER . '.setting';
# $setting = $modx->newObject('modSystemSetting');
# $setting->fromArray(array(
#     'key' => $setting_name,
#     'value' => '',
#     'xtype' => 'textfield',
#     'namespace' => NAMESPACE_NAME,
#     'area' => 'default',
# ) , '', true, true);
# $settings[] = $setting;
# unset($setting, $setting_name);
$setting = $modx->newObject('modSystemSetting');
$setting->fromArray(array(
    'key' => 'shopmodx1c.article_tv', // Артикул товара или категории (ID TV-параметра)
    'value' => '',
    'xtype' => 'textfield', //  textfield, numberfield, combo-boolean or other
    'namespace' => NAMESPACE_NAME,
    'area' => 'site',
) , '', true, true);
$settings[] = $setting;
$setting = $modx->newObject('modSystemSetting');
$setting->fromArray(array(
    'key' => 'shopmodx1c.catalog_root_id',
    'value' => '',
    'xtype' => 'textfield',
    'namespace' => NAMESPACE_NAME,
    'area' => 'site',
) , '', true, true);
$settings[] = $setting;
$setting = $modx->newObject('modSystemSetting');
$setting->fromArray(array(
    'key' => 'shopmodx1c.category_default_template',
    'value' => 0,
    'xtype' => 'modx-combo-template',
    'namespace' => NAMESPACE_NAME,
    'area' => 'site',
) , '', true, true);
$settings[] = $setting;
$setting = $modx->newObject('modSystemSetting');
$setting->fromArray(array(
    'key' => 'shopmodx1c.images_path',
    'value' => '{assets_path}images/',
    'xtype' => 'textfield',
    'namespace' => NAMESPACE_NAME,
    'area' => 'site',
) , '', true, true);
$settings[] = $setting;
$setting = $modx->newObject('modSystemSetting');
$setting->fromArray(array(
    'key' => 'shopmodx1c.import_dir',
    'value' => '{core_path}components/shopmodx1c/import_files/',
    'xtype' => 'textfield',
    'namespace' => NAMESPACE_NAME,
    'area' => 'site',
) , '', true, true);
$settings[] = $setting;
$setting = $modx->newObject('modSystemSetting');
$setting->fromArray(array(
    'key' => 'shopmodx1c.postfile_size_limit',
    'value' => 200,
    'xtype' => 'numberfield',
    'namespace' => NAMESPACE_NAME,
    'area' => 'site',
) , '', true, true);
$settings[] = $setting;
$setting = $modx->newObject('modSystemSetting');
$setting->fromArray(array(
    'key' => 'shopmodx1c.use_zip',
    'value' => 1,
    'xtype' => 'combo-boolean',
    'namespace' => NAMESPACE_NAME,
    'area' => 'site',
) , '', true, true);
$settings[] = $setting;
$setting = $modx->newObject('modSystemSetting');
$setting->fromArray(array(
    'key' => 'shopmodx1c.product_default_template',
    'value' => 0,
    'xtype' => 'modx-combo-template',
    'namespace' => NAMESPACE_NAME,
    'area' => 'site',
) , '', true, true);
$settings[] = $setting;
$setting = $modx->newObject('modSystemSetting');
$setting->fromArray(array(
    'key' => 'shopmodx1c.product_image_tv',
    'value' => '',
    'xtype' => 'numberfield',
    'namespace' => NAMESPACE_NAME,
    'area' => 'site',
) , '', true, true);
$settings[] = $setting;
unset($setting);
return $settings;
