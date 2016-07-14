<?php
require_once __DIR__ . '/importparser.class.php';
class categoryParser extends importParser
{
    /**
     * stuff
     */
    protected $_categories = array();
    protected $_categoryParents = array();
    
    protected $categoryTMPClass = 'Shopmodx1cTmpCategory';
    protected $categoryClass = 'modResource';
    
    /**
     * XMLReader с Expand - DOM/DOMXpath
     */     
    public function parseXML($ABS_FILE_NAME) 
    {
        $reader = $this->getReader($ABS_FILE_NAME);
        
        static $depth = 1;
        
        while ($reader->read() && $depth) 
        {
            if ($this->isNode('КоммерческаяИнформация', $reader)) 
            {                
                while ($reader->read() && $depth) 
                {   
                    
                    if ($this->isNode('Классификатор', $reader)) 
                    {                        
                        while ($reader->read() && $depth) 
                        {                                                        
                            
                            if($this->getProperty('debug') && !$this->isNodeText($reader)){
                                $this->modx->log(xPDO::LOG_LEVEL_DEBUG, $this->prepareShift($depth) . $reader->name . ' - ' . var_export($this->isNodeEnd(null, $reader),1));
                            }

                            if ($this->isNode('Группы', $reader)) 
                            {
                                $depth++;   
                                #                                
                            }

                            if($this->isNode('Группа', $reader))
                            {                                
                                $depth++;
                                #                                                                                                                                                           
                                $this->_categoryParents[$depth] = $this->parseTMPParent($reader, $depth);
                                $this->_categories[] = $this->parseTMPCategory($reader, $depth);
                            }
                            
                            if ($this->isNodeEnd('Группы', $reader)) 
                            {
                                $depth--;
                                # 
                                
                                $this->insertTMPCategoriesToDB();                                
                                
                                $reader->next();
                            }
                            
                            if($this->isNodeEnd('Группа', $reader))
                            {
                                $depth--;
                                # 
                            }
                                                        
                            if ($this->isNodeEnd('Классификатор', $reader)) 
                            {
                                break;
                            }
                        }
                    }
                    elseif ($this->isNode('Каталог')){
                        $reader->next();
                    }
                    elseif ($this->isNodeEnd('КоммерческаяИнформация', $reader)) 
                    {
                        break;
                    }
                }
            }
        }
            
        if ($this->hasErrors()) 
        {
            return false;
        }
        return true;
    }
    #
    
    protected function prepareShift($depth = 1){
        $shift = '';
        for($i = 1; $i <= $depth; $i++){
            $shift .= "\t";
        }
        return $shift;
    }
    # 
    
    /**/
    protected function parseTMPParent($reader, $depth){                                      
        $xml = $this->getXMLNode($reader);    
        
        $categoryName = (string)$xml->Наименование;
        
        return array(
            'id' => (string)$xml->Ид,
            'name' => $categoryName
        );
    }
    # 
    
    /**/
    protected function parseTMPCategory($reader, $depth){                                      
        $xml = $this->getXMLNode($reader);    
        
        $categoryName = (string)$xml->Наименование;
        
        if($this->getProperty('debug')){
            $this->modx->log(xPDO::LOG_LEVEL_DEBUG, $this->prepareShift($depth) . "«<b>" . $categoryName . "</b>»");
        }
        
        $category = array(
            'id' => (string)$xml->Ид,
            'name' => $categoryName
        );
        
        if($parent = $this->getParentId($depth)){
            $category['parent'] = $parent;
        }
        
        return $category;
    }
    # 
    
    /**/
    protected function getParentId($depth){
        if($depth <= 1){
            return null;
        }
        
        $keys = array_keys($this->_categoryParents);
        $currentDepthKey = array_search($depth, $keys);
        
        if($currentDepthKey == 0){
            return null;
        }
        # else
        return $this->_categoryParents[$keys[$currentDepthKey-1]]['id'];
    }
    # 
    
    /**/
    protected function flushCategories(){
        $this->_categories = array();
    }
    # 
    
    /**/
    public function insertTMPCategoriesToDB(){
        $i = 0;
        $table = $this->modx->getTableName($this->categoryTMPClass);
        $rows = array();
        $columns = array(
            "article",
            "title",
            "parent"
        );
        #
        
        /**/
        $total = count($this->_categories);
        $step = $this->getProperty('linesPerStep');
        array_walk($this->_categories, function ($category) use ($table, &$rows, $columns, &$i, $total, $step) 
        {            
            $i++;
            $article = $category['id'];
            $parent = (isset($category['parent']) ? "'{$category['parent']}'" : "NULL");
            $title = $category['name'];
            #
            $rows[] = "('{$article}', '{$title}', {$parent})";
            #
            
            /**/
            if ($i % $step == 0 || ($total < $step && $i == $total )) 
            {
                if (!$this->insertInDataBase($table, $rows, $columns)) 
                {
                    $this->addFieldError('insertToDB', 'Не удалось выполнить запрос');
                    return false;
                }
                $rows = array();
                
                $this->flushCategories();
            }
        });
        #

        return true;
    }
    # 
    
    /**/
    public function saveTMPRecords(){
        
        $tmpClass = $this->categoryTMPClass;
        $article_tv_id = $this->modx->getOption('shopmodx1c.article_tv_id');
        $catalog_root_id = $this->modx->getOption('shopmodx1c.catalog_root_id');
        $catalog_tmp_root_id = $this->modx->getOption('shopmodx1c.tmp_catalog_root_id');
        $category_template = $this->modx->getOption('shopmodx1c.category_default_template');
        $limit = $this->getProperty('process_items_per_step');
        #        

        /**
         * если в настройках указан айдишник для временного каталога, то все категории копируем туда (подготовка для сортировки)
         */
        if ($catalog_tmp_root_id) 
        {
            $catalog_root_id = $catalog_tmp_root_id;
        }
        #
        $q = $this->modx->newQuery($tmpClass);
        $q->leftJoin('modTemplateVarResource', 'tv_article', "tv_article.tmplvarid = {$article_tv_id} AND {$tmpClass}.article = tv_article.value");
        $q->where(array(
            "tv_article.id" => null,
            "processed" => self::UNPROCESSED_STATUS,
        ));
        $q->select(array(
            'tv_article.id as article_id',
            "{$tmpClass}.*"
        ));
        $q->sortby("{$tmpClass}.id");
        $q->limit($limit);
        #
        
        /**
         * Получаем все группы/категории из 1С
         */
        if ($groups = $this->modx->getCollection($tmpClass, $q)) 
        {
            /**
             * logging
             */
            $this->logCount($tmpClass, $q, 'categories');
            #
            
            /**
             * Проходимся по каждому и создаем категорию
             */
            foreach ($groups as $group) 
            {
                /*
                    Создаем новую категорию
                */
                // Если указан артикул родителя, то пытаемся его получить                
                
                if ($group->parent) 
                {
                    if (!$parent = $this->getResourceIdByArticle($group->parent)) 
                    {
                        $error = "Не был получен раздел с артикулом '{$group->parent}'";
                        $this->addFieldError('getResourceIdByArticle', $error);
                        continue;
                    }
                }
                else
                {
                    $parent = $catalog_root_id;
                }
                
                /**/
                $data = array(
                    "pagetitle" => $group->title,
                    "parent" => $parent,
                    "template" => $category_template,
                    "isfolder" => 1,
                    "tv{$article_tv_id}" => $group->article,
                );
                
                if (!$response = $this->modx->runProcessor('resource/create', $data)) 
                {
                    $error = "Ошибка выполнения процессора";
                    $this->addFieldError('processor', $error);
                    continue;
                }
                # else
                
                if ($response->isError()) 
                {
                    if (!$error = $response->getMessage()) 
                    {
                        $error = "Не удалось создать раздел с артикулом '{$article}'";
                    }
                    $this->addFieldError('response', $error);
                    continue;
                }
                # else
                
                $group->set('processed', self::PROCESSED_STATUS);
                if(!$group->save()){
                    $error = 'Не удалось обновить временный документ';
                    $this->addFieldError('processed', $error);
                    continue;                   
                }
            }
            #
            
        }
        
        if($this->hasErrors()){
            return false;
        }
        
        return true;
    }
    # 
    
}
