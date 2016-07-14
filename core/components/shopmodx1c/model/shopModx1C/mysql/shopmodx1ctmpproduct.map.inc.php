<?php
$xpdo_meta_map['Shopmodx1cTmpProduct']= array (
  'package' => 'ShopModx1C',
  'version' => '1.1',
  'table' => 'shopmodx1c_tmp_products',
  'extends' => 'xPDOSimpleObject',
  'fields' => 
  array (
    'externalKey' => NULL,
    'article' => NULL,
    'title' => NULL,
    'longtitle' => NULL,
    'description' => NULL,
    'image' => NULL,
    'weight' => 0,
    'groups' => NULL,
    'properties' => NULL,
    'requisites' => NULL,
    'processed' => '0',
    'extended' => NULL,
  ),
  'fieldMeta' => 
  array (
    'externalKey' => 
    array (
      'dbtype' => 'char',
      'precision' => '36',
      'phptype' => 'string',
      'null' => false,
      'index' => 'index',
    ),
    'article' => 
    array (
      'dbtype' => 'varchar',
      'precision' => '100',
      'phptype' => 'string',
      'null' => true,
      'index' => 'index',
    ),
    'title' => 
    array (
      'dbtype' => 'varchar',
      'precision' => '256',
      'phptype' => 'string',
      'null' => true,
    ),
    'longtitle' => 
    array (
      'dbtype' => 'varchar',
      'precision' => '255',
      'phptype' => 'string',
      'null' => true,
    ),
    'description' => 
    array (
      'dbtype' => 'text',
      'phptype' => 'string',
      'null' => true,
    ),
    'image' => 
    array (
      'dbtype' => 'varchar',
      'precision' => '1024',
      'phptype' => 'string',
      'null' => true,
    ),
    'weight' => 
    array (
      'dbtype' => 'float',
      'precision' => '10,2',
      'attributes' => 'unsigned',
      'phptype' => 'float',
      'null' => true,
      'default' => 0,
    ),
    'groups' => 
    array (
      'dbtype' => 'text',
      'phptype' => 'string',
      'null' => true,
    ),
    'properties' => 
    array (
      'dbtype' => 'text',
      'phptype' => 'string',
      'null' => true,
    ),
    'requisites' => 
    array (
      'dbtype' => 'text',
      'phptype' => 'string',
      'null' => true,
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
    'extended' => 
    array (
      'dbtype' => 'text',
      'phptype' => 'string',
      'null' => false,
    ),
  ),
  'indexes' => 
  array (
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
    'externalKey' => 
    array (
      'alias' => 'externalKey',
      'primary' => false,
      'unique' => false,
      'type' => 'BTREE',
      'columns' => 
      array (
        'externalKey' => 
        array (
          'length' => '',
          'collation' => 'A',
          'null' => false,
        ),
      ),
    ),
    'article' => 
    array (
      'alias' => 'article',
      'primary' => false,
      'unique' => false,
      'type' => 'BTREE',
      'columns' => 
      array (
        'article' => 
        array (
          'length' => '',
          'collation' => 'A',
          'null' => true,
        ),
      ),
    ),
  ),
);
