<?php
$xpdo_meta_map['Shopmodx1cTmpPrice']= array (
  'package' => 'ShopModx1C',
  'version' => '1.1',
  'table' => 'shopmodx1c_tmp_price',
  'extends' => 'xPDOSimpleObject',
  'fields' => 
  array (
    'article' => NULL,
    'good_id' => NULL,
    'type_id' => NULL,
    'value' => NULL,
    'currency_id' => 1,
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
    'good_id' => 
    array (
      'dbtype' => 'char',
      'precision' => '36',
      'phptype' => 'string',
      'null' => false,
    ),
    'type_id' => 
    array (
      'dbtype' => 'char',
      'precision' => '36',
      'phptype' => 'string',
      'null' => false,
    ),
    'value' => 
    array (
      'dbtype' => 'float',
      'precision' => '10,2',
      'phptype' => 'float',
      'null' => false,
    ),
    'currency_id' => 
    array (
      'dbtype' => 'int',
      'precision' => '10',
      'attributes' => 'unsigned',
      'phptype' => 'integer',
      'null' => false,
      'default' => 1,
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
