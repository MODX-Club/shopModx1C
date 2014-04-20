<?php
$xpdo_meta_map['Shopmodx1cTmpProduct']= array (
  'package' => 'ShopModx1C',
  'version' => '1.1',
  'table' => 'shopmodx1c_tmp_products',
  'extends' => 'xPDOSimpleObject',
  'fields' => 
  array (
    'article' => NULL,
    'title' => NULL,
    'description' => NULL,
    'image' => NULL,
    'groups' => NULL,
    'properties' => NULL,
    'requisites' => NULL,
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
      'index' => 'unique',
    ),
    'title' => 
    array (
      'dbtype' => 'varchar',
      'precision' => '256',
      'phptype' => 'string',
      'null' => false,
    ),
    'description' => 
    array (
      'dbtype' => 'text',
      'phptype' => 'string',
      'null' => false,
    ),
    'image' => 
    array (
      'dbtype' => 'varchar',
      'precision' => '1024',
      'phptype' => 'string',
      'null' => false,
    ),
    'groups' => 
    array (
      'dbtype' => 'text',
      'phptype' => 'string',
      'null' => false,
    ),
    'properties' => 
    array (
      'dbtype' => 'text',
      'phptype' => 'string',
      'null' => false,
    ),
    'requisites' => 
    array (
      'dbtype' => 'text',
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
      'index' => 'index',
    ),
  ),
  'indexes' => 
  array (
    'article' => 
    array (
      'alias' => 'article',
      'primary' => false,
      'unique' => true,
      'type' => 'BTREE',
      'columns' => 
      array (
        'article' => 
        array (
          'length' => '',
          'collation' => 'A',
          'null' => false,
        ),
      ),
    ),
    'processed' => 
    array (
      'alias' => 'processed',
      'primary' => false,
      'unique' => false,
      'type' => 'BTREE',
      'columns' => 
      array (
        'processed' => 
        array (
          'length' => '',
          'collation' => 'A',
          'null' => false,
        ),
      ),
    ),
  ),
);
