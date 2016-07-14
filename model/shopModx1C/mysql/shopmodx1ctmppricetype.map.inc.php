<?php
$xpdo_meta_map['Shopmodx1cTmpPriceType']= array (
  'package' => 'ShopModx1C',
  'version' => '1.1',
  'table' => 'shopmodx1c_tmp_price_type',
  'extends' => 'xPDOSimpleObject',
  'fields' => 
  array (
    'article' => NULL,
    'processed' => '0',
  ),
  'fieldMeta' => 
  array (
    'article' => 
    array (
      'dbtype' => 'char',
      'precision' => '36',
      'phptype' => 'string',
      'null' => false,
    ),
    'processed' => 
    array (
      'dbtype' => 'enum',
      'precision' => '\'0\',\'1\'',
      'phptype' => 'string',
      'null' => false,
      'default' => '0',
    ),
  ),
);
