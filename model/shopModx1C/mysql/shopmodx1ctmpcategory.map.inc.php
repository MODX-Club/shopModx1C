<?php
$xpdo_meta_map['Shopmodx1cTmpCategory']= array (
  'package' => 'shopModx1C',
  'version' => '1.1',
  'table' => 'shopmodx1c_tmp_categories',
  'extends' => 'xPDOSimpleObject',
  'fields' => 
  array (
    'article' => NULL,
    'title' => NULL,
    'parent' => NULL,
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
    'parent' => 
    array (
      'dbtype' => 'char',
      'precision' => '36',
      'phptype' => 'string',
      'null' => true,
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
  ),
);
