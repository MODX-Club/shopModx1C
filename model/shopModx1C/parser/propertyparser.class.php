<?php
require_once __DIR__ . '/importparser.class.php';

class proeprtyParser extends importParser
{

    
    public function saveTMPRecords(){
        
        #                 // создаем необходимые тв-параметры
        #                 $tmpClass = $this->tmpPropertiesClass;
        #                 $product_template = $this->modx->getOption('shopmodx1c.product_default_template');
        #                 $limit = $this->getProperty('process_items_per_step');
        #                 $q = $this->modx->newQuery($tmpClass);
        #                 // создаем только несуществующие тв
        #                 $q->leftJoin('modTemplateVar', 'tv', "tv.name = {$tmpClass}.article");
        #                 $q->where(array(
        #                     "tv.id" => null,
        #                     "processed" => self::UNPROCESSED_STATUS,
        #                 ));
        #                 $q->sortby("{$tmpClass}.id");
        #                 // получаем опцию, содержащую имя уник. идентификатора, для определения уник. ключа импорта
        #                 $_keyOption = $this->modx->getOption('shopmodx1c.article_field_name');
        #                 # исключаем из списка тв-параметров твшку, которая хранит ключ товара из 1c
        #                 if ($_keyOption) 
        #                 {
        #                     $q->where(array(
        #                         'title:!=' => $_keyOption
        #                     ));
        #                 }
        #                 $q->limit($limit);
        #                 #
        #                 
        #                 /**
        #                  * Получаем все свойства из 1С
        #                  */
        #                 if ($tvs = $this->modx->getCollection($tmpClass, $q)) 
        #                 {
        #                     /**
        #                      * logging
        #                      */
        #                     $this->logCount($tmpClass, $q, 'tvs');
        #                     #
        #                     foreach ($tvs as $tv) 
        #                     {
        #                         $data = array(
        #                             "caption" => $tv->title,
        #                             "name" => $tv->article,
        #                             "locked" => true,
        #                             "template" => $product_template,
        #                             "templates" => array(
        #                                 array(
        #                                     'access' => true,
        #                                     'id' => $product_template
        #                                 )
        #                             )
        #                         );
        #                         if (!$response = $this->modx->runProcessor('element/tv/create', $data)) 
        #                         {
        #                             $error = "Ошибка выполнения процессора";
        #                             $this->modx->log(1, $error);
        #                             $this->addOutput($error);
        #                             return $this->failure('failure');
        #                         }
        #                         //else
        #                         $name = $tv->title;
        #                         if ($response->isError()) 
        #                         {
        #                             if (!$error = $response->getMessage()) 
        #                             {
        #                                 $error = "Не удалось создать tv с именем '{$name}'";
        #                             }
        #                             $this->modx->log(1, $error);
        #                             $this->addOutput($error);
        #                             return $this->failure('failure');
        #                         }
        #                         $tv->set('processed', self::PROCESSED_STATUS);
        #                         $tv->save();
        #                     }
        #                     #
        #                     
        #                 }
        
        
        /**
         * ставим флаг «обработано» для товара
         */
        $product->set('processed', self::PROCESSED_STATUS);
        if(!$product->save()){
            $error = 'Не удалось обновить временный товар';
            $this->addFieldError('processed', $error);
            continue;
        }
    }

}
