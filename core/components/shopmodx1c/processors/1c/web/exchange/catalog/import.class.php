<?php

/*
    Импорт данных из 1С.
    Следует учитывать, что все это выполняется не за один запрос,
	а за условно бесконечное, пока не будет выполнен импорт до успеха или ошибки.
	За это отвечают ответы success|progress|failure
*/

require_once dirname(dirname(__FILE__)) . '/exchange.class.php';

class mod1cWebExchangeCatalogImportProcessor extends mod1cWebExchangeExchangeProcessor{
    
	protected $tmpCategoriesClass = 'Shopmodx1cTmpCategory';
	protected $tmpProductsClass = 'Shopmodx1cTmpProduct';
    
    protected $groups = array();    // Импортируемые группы
    
    
    public function initialize(){
        
        if(!$this->getProperty('mode')){
            return 'Mode not exists';
        }
        
        $this->setProperties(array(
    		"FILE_SIZE_LIMIT" => (int)$this->modx->getOption('shopmodx1c.postfile_size_limit', null, 200) * 1024,
    		"USE_ZIP" => $this->modx->getOption('shopmodx1c.use_zip', null, true),
            "DIR_NAME"  => $this->modx->getOption('shopmodx1c.import_dir', null, MODX_CORE_PATH . 'components/shopmodx1c/import_files/'),
            "process_items_per_step"    => 50,  // Сколько элементов обрабатывать за шаг (create|update)
		));
        
        return parent::initialize();
    }
    
    
    public function process(){
        
        $Params = $this->getProperties();
        
        $DIR_NAME = $Params['DIR_NAME'];
        
        $mode = $Params['mode'];
        
		// Авторизация
        if($mode == "checkauth"){
            
            if(
                !$response = $this->modx->runProcessor('security/login',
                array(
                    "username" => $_SERVER['PHP_AUTH_USER'],
                    "password"  => $_SERVER['PHP_AUTH_PW'],
                )
            )){
                $this->addOutput("Ошибка выполнения запроса.");
                return $this->failure("failure");
            }
            
            // else
            if($response->isError()){
				if(!$msg = $response->getMessage()){
					$msg = "Ошибка авторизации.";
				}
                $this->addOutput($msg);
                return $this->failure("failure");
            }
            
            $this->addOutput(session_name());
            $this->addOutput(session_id());
            
            return $this->success("success");
        }
        
        // else
		// Проверка, что пользователь авторизован
		if(!$this->modx->user->isAuthenticated($this->modx->context->key))
        {
        	$this->addOutput("ERROR_AUTHORIZE");
            return $this->failure('failure');
        }
        
        // else
        $ABS_FILE_NAME = false;
        $WORK_DIR_NAME = false;
        
        if($filename = $this->getProperty('filename'))
        {
            $ABS_FILE_NAME = $DIR_NAME.$filename;
        }
        
		/*
            Первичная инициализация
        */
        if($mode=="init"){
            
            /*
                Проверяем основные настройки
            */
            if(!$this->modx->getOption('shopmodx1c.article_tv')){
                $error = "Не указан ID TV-параметра для артикулов 1С";
                $this->modx->log(1, $error);
                $this->addOutput($error);
                return $this->failure("failure");
            }
            
            if(!$this->modx->getOption('shopmodx1c.catalog_root_id')){
                $error = "Не указан ID корневого раздела каталога";
                $this->modx->log(1, $error);
                $this->addOutput($error);
            	return $this->failure("failure");
            }
            
            if(!$this->modx->getOption('shopmodx1c.product_default_template')){
                $error = "Не указан ID шаблона для товаров";
                $this->modx->log(1, $error);
                $this->addOutput($error);
            	return $this->failure("failure");
            }
            
            if(!$this->modx->getOption('shopmodx1c.category_default_template')){
                $error = "Не указан ID шаблона для категории";
                $this->modx->log(1, $error);
                $this->addOutput($error);
            	return $this->failure("failure");
            }
            
            /**********************************/
            
            // Очищаем импорт-директорию
            $this->clearImportDir();
        
        	if(!is_dir($DIR_NAME))
        	{
        		$this->addOutput('ERROR_INIT');
        		return $this->failure("failure");
        	}
        	else
        	{
        		$_SESSION["SM_1C_IMPORT"] = array(
        			"zip" => $Params["USE_ZIP"] && class_exists("ZipArchive"),
        			"NS" => array(
        				"STEP" => 0,
        			),
        		);
        		
                $this->addOutput("zip=".($_SESSION["SM_1C_IMPORT"]["zip"]? "yes": "no"));
                $this->addOutput("file_limit=".$Params["FILE_SIZE_LIMIT"]);
        		return $this->success('');
        	}
        }
        
		// Сохраняем импортируемый файл-выгрузку
        elseif(($mode == "file") && $ABS_FILE_NAME)
        {
            
        	if(function_exists("file_get_contents"))
        		$DATA = file_get_contents("php://input");
        	elseif(isset($GLOBALS["HTTP_RAW_POST_DATA"]))
        		$DATA = &$GLOBALS["HTTP_RAW_POST_DATA"];
        	else
        		$DATA = false;
            
        	$DATA_LEN = mb_strlen($DATA, 'latin1');
            
        	if(isset($DATA) && $DATA !== false)
        	{
        		if($fp = fopen($ABS_FILE_NAME, "ab"))
        		{
        			$result = fwrite($fp, $DATA);
                    
        			if($result === $DATA_LEN)
        			{
                        if($_SESSION["SM_1C_IMPORT"]["zip"]){
                            $_SESSION["SM_1C_IMPORT"]["zip"] = $ABS_FILE_NAME;
                        }
                        return $this->success("success");
        			}
        			else
        			{
                        $this->addOutput("ERROR_FILE_WRITE");
                        return $this->failure('failure');
        			}
        		}
        		else
        		{
                    $this->addOutput("ERROR_FILE_OPEN");
        			return $this->failure('failure');
        		}
        	}
        	else
        	{
                $this->addOutput("ERROR_HTTP_READ");
                return $this->failure('failure');
        	}
        }
		
		
		// Если переданный файл был zip-архивом, распаковываем его
        elseif(($mode == "import") && !empty($_SESSION["SM_1C_IMPORT"]["zip"]))
        {
            
            $result = false;
            
            if($this->modx->loadClass('compression.xPDOZip', XPDO_CORE_PATH, true, true)){
                $from = $_SESSION["SM_1C_IMPORT"]["zip"];
                $to = $this->getProperty('DIR_NAME');
                
                $archive = new xPDOZip($this->modx, $from);
                if ($archive) {
                    $result = $archive->unpack($to);
                    $archive->close();
                }
            }
            
            if(!$result){
                $this->addOutput("Ошибка распаковки архива.");
                return $this->failure("failure");
            }
            
            // else
            $_SESSION["SM_1C_IMPORT"]["zip"] = false;
            
            $this->addOutput("Распаковка архива завершена.");
            return $this->success("progress");
        }
		
		/*
			Когда все данные переданы со стороны 1С, 
			выполняем непосредственно обновление информации на стороне MODX-а.
		*/
        elseif(($mode == "import") && $ABS_FILE_NAME)
        {
            
        	$NS = &$_SESSION["SM_1C_IMPORT"]["NS"];
        	$strError = "";
        	$strMessage = "";
            
            $this->modx->log(1, "STEP: ". $NS["STEP"]);
            
			
            switch($NS["STEP"]){
                
    			// Очищаем временные таблицы
            	case 0:
                    $classes = array(
                        $this->tmpCategoriesClass,
                        $this->tmpProductsClass,
                    );
                    
                    foreach($classes as $class){
                        if($table = $this->modx->getTableName($class)){
                            $this->modx->exec("TRUNCATE TABLE {$table}");
                        }
                    }
                    
            		$NS["STEP"] = 1;
                    
                    break;
    			
                
                // Перемещаем картинки в папку изображений товаров
            	case 1:
                    
                    $DIR_NAME = $this->getProperty('DIR_NAME');

                    $source = $DIR_NAME . 'import_files/';
                    
                    $target = $this->modx->getOption('shopmodx1c.images_path', null, MODX_ASSETS_PATH . 'images/').  "import_files/";
                        
                    $this->modx->loadClass('modCacheManager', '', true, false);
                    
                    $modCacheManager = new modCacheManager($this->modx);
                    
                    $r = $modCacheManager ->copyTree($source, $target);
        
                    $NS["STEP"] = 2;
                    
                    break;
                
    			
            	case 2:
                    
                    // Парсинг большого документа посредством XMLReader с Expand - DOM/DOMXpath 
                    $reader = new XMLReader();
                    
                    $reader->open($ABS_FILE_NAME);
                    
                    while ($reader->read()) {
                        
                        if (($reader->nodeType == XMLReader::ELEMENT) && ($reader->name == 'КоммерческаяИнформация')) {
                            
                            while ($reader->read()) {
                                    
                                if (($reader->nodeType == XMLReader::ELEMENT) && ($reader->name == 'Классификатор')) {
                                    
                                    while ($reader->read()) {
                                        
                                        if (($reader->nodeType == XMLReader::ELEMENT) && ($reader->name == 'Группы')) {
                                            while ($reader->read()) {
                                                
                                                if (($reader->nodeType == XMLReader::ELEMENT && ($reader->name == 'Группа'))) {
                                                    
                                                    $node = $reader->readOuterXML();
                                                      
                                                    $xml = simplexml_load_string($node);
                                                    
                                                    // Парсим группы-категории
                                                    $this->parseGroup($xml);
                                                    
                                        			// Сохраняем первичные данные во временную таблицу
                                                    $this->insertGroupsInDataBase();
                                                    
                                                    $reader->next();
                                                }
                                                
                                                if ($reader->nodeType == XMLReader::END_ELEMENT && $reader->localName == 'Группы'){
                                                    break;
                                                }
                                            }
                                        }
                                        
                                        
                                        if ($reader->nodeType == XMLReader::END_ELEMENT && $reader->localName == 'Классификатор'){
                                            break;
                                        }
                                    }
                                } 
                                
                                elseif (($reader->nodeType == XMLReader::ELEMENT) && ($reader->name == 'Каталог')) {
                                    $reader->next();
                                }
                            }
                        }
                        
                    }
                    
                    $NS["STEP"] = 3;
                    
                    break;
            	 
    			
                // Парсим товары
                case 3:
                    
                    $table = $this->modx->getTableName($this->tmpProductsClass);
                    $columns = array(
                        "article",
                        "title",
                        "description",
                        "image",
                        "groups",
                    );
                    $rows = array();
                    $linesPerStep = 500;
                    $i = 0;
                    
                    // Парсинг большого документа посредством XMLReader с Expand - DOM/DOMXpath 
                    $reader = new XMLReader();
                    
                    $reader->open($ABS_FILE_NAME);
                    
                    while ($reader->read()) {
                        
                        if (($reader->nodeType == XMLReader::ELEMENT) && ($reader->name == 'КоммерческаяИнформация')) {
                            
                            while ($reader->read()) {
                                    
                                if (($reader->nodeType == XMLReader::ELEMENT) && ($reader->name == 'Классификатор')) {
                                    $reader->next();
                                } 
                                
                                elseif (($reader->nodeType == XMLReader::ELEMENT) && ($reader->name == 'Каталог')) {
                                    
                                    while ($reader->read()) {
                                        
                                        if (($reader->nodeType == XMLReader::ELEMENT) && ($reader->name == 'Товары')) {
                                            
                                            while ($reader->read()) {
                                                    
                                                if (($reader->nodeType == XMLReader::ELEMENT && ($reader->name == 'Товар'))) {
                                                    
                                                    $i++; 
                                                    
                                                    $node = $reader->readOuterXML();
                                                      
                                                    $product = simplexml_load_string($node);
                                                    
                                            		// Сохраняем первичные данные во временную таблицу
                                                    
                                                    $article = (string)$product->Ид;
                                                    $title = str_replace("'", "\'", (string)$product->Наименование);
                                                    $description = str_replace("'", "\'", (string)$product->Описание);
                                                    $image = (string)$product->Картинка;
                                                    
                                                    // Необходимо переделать на множество групп
                                                    $groups = array(
                                                        (string)current($product->Группы->Ид)
                                                    );    
                                                    $groups = json_encode($groups);
                                                    
                                                    $rows[] = "('{$article}', '{$title}', '{$description}', '{$image}', '{$groups}')";
                                                    
                                                    if($i % $linesPerStep == 0){
                                                        if(!$this->insertInDataBase($table, $rows, $columns)){
                                                            return $this->failure("Не удалось выполнить запрос");
                                                        }
                                                        $rows = array();
                                                    }
                                                    
                                                    $reader->next();
                                                }
                                                
                                                if ($reader->nodeType == XMLReader::END_ELEMENT && $reader->localName == 'Товары'){
                                                    
                                                    // Если еще есть массив с данными, то сохраняем их
                                                    if($rows){
                                                        $this->insertInDataBase($table, $rows, $columns);
                                                    }
                                                    
                                                    break;
                                                }
                                            }
                                            
                                        } 
                                    }
                                    
                                }
                            }
                        }
                        
                    }
                    
                    $NS["STEP"] = 4;
                    
                    break;
                
                // Сохраняем категории
            	case 4:
    				/*
    					Выполняем необходимые действия над данными,
    					пока не будет импорт выполнен до конца.
    				*/
                    $tmpClass = $this->tmpCategoriesClass;
                    $article_tv_id = $this->modx->getOption('shopmodx1c.article_tv');
                    $catalog_root_id = $this->modx->getOption('shopmodx1c.catalog_root_id');
                    $category_template = $this->modx->getOption('shopmodx1c.category_default_template');
                    
                    $limit = $this->getProperty('process_items_per_step');
                    
                    $q = $this->modx->newQuery($tmpClass);
                    $q->leftJoin('modTemplateVarResource', 'tv_article', "tv_article.tmplvarid = {$article_tv_id} AND {$tmpClass}.article = tv_article.value");
                    $q->where(array(
                        "tv_article.id" => null,
                        "processed"     => 0,
                    ));
                    $q->sortby("{$tmpClass}.id");
                    $q->limit($limit);
                    
                    // Получаем все группы/категории из 1С
                    if($groups = $this->modx->getCollection($tmpClass, $q)){
                        
                        // Проходимся по каждому и создаем категорию
                        foreach($groups as $group){
                            
                            /*
                                Создаем новую категорию
                            */
                            
                            // Если указан артикул родителя, то пытаемся его получить
                            if($group->parent){
                                if(!$parent = $this->getResourceIdByArticle($group->parent)){
                                    $error = "Не был получен раздел с артикулом '{$group->parent}'";
                                    $this->modx->log(1, $error);
                                    $this->addOutput($error);
                                    return $this->failure($error);
                                }
                            }
                            else{
                                $parent = $catalog_root_id;
                            }

                            $data = array(
                                "pagetitle" => $group->title,
                                "parent"    => $parent,
                                "template"  => $category_template,
                                "isfolder"  => 1,
                                "tv{$article_tv_id}"      => $group->article,
                            );
                            
                            if(!$response = $this->modx->runProcessor('resource/create', $data)){
                                $error = "Ошибка выполнения процессора";
                                $this->modx->log(1, $error);
                                $this->addOutput($error);
                                return $this->failure($error);
                            }
                            
                            //else
                            if($response->isError()){
                                if(!$error = $response->getMessage()){
                                    $error = "Не удалось создать раздел с артикулом '{$article}'";
                                }
                                $this->modx->log(1, $error);
                                $this->addOutput($error);
                                return $this->failure($error);
                            }
                            
                            // else
                            $o = $response->getObject();
                            $category_id = $o['id'];
                            
                            if($object = $this->modx->getObject('modResource', $category_id)){
                                $object->set('alias', $object->id);
                                $object->save();
                            }
                            
                            $group->set('processed', 1);
                            $group->save();
                        }
                    }
                    
                    // else
                    else $NS["STEP"] = 5;
                
                    break;
    			
                // Сохраняем/обновляем товары
            	case 5:
    				/*
    					Выполняем необходимые действия над данными,
    					пока не будет импорт выполнен до конца.
    				*/
                    $tmpClass = $this->tmpProductsClass;
                    $article_tv_id = $this->modx->getOption('shopmodx1c.article_tv');
                    $image_tv_id = $this->modx->getOption('shopmodx1c.product_image_tv');
                    $currency = $this->modx->getOption('shopmodx.default_currency');
                    $products_template = $this->modx->getOption('shopmodx1c.product_default_template');
                    
                    $limit = $this->getProperty('process_items_per_step');
                    
                    
                    // Получаем первичные данные по не обработанным товарам
                    $q = $this->modx->newQuery($tmpClass);
                    $q->leftJoin('modTemplateVarResource', 'tv_article', "tv_article.tmplvarid = {$article_tv_id} AND {$tmpClass}.article = tv_article.value");
                    $q->where(array(
                        "processed"     => 0,
                    ));
                    
                    $q->select(array(
                        "tv_article.id as tv_article_id",
                        "tv_article.contentid as resource_id",
                        "{$tmpClass}.*",
                    ));
                    
                    $q->limit($limit);
                    
                    // Получаем все группы/категории из 1С
                    if($products = $this->modx->getCollection($tmpClass, $q)){
                        
                        // Проходимся по каждому товару
                        foreach($products as $product){
                            $article = $product->article;
                            /*
                                Если товар уже есть в магазине, то обновляем его
                            */
                            
                            if($product->resource_id){
                                
                                
                            }
                            
                            // иначе создаем новый
                            else{
                                // Определяем категорию, куда товар создавать
                                if(
                                    !$groups = json_decode($product->groups, 1)
                                    OR ! $group = current($groups)
                                ){
                                    $error = "Не был получен раздел для товара с артикулом '{$article}'";
                                    $this->modx->log(1, $error);
                                    $this->addOutput($error);
                                    return $this->failure($error);
                                }
                                
                                // else
                                // Проверяем наличие категории в каталоге
                                if(!$parent = $this->getResourceIdByArticle($group)){
                                    $error = "Не был получен раздел с артикулом '{$group}'";
                                    $this->modx->log(1, $error);
                                    $this->addOutput($error);
                                    return $this->failure($error);
                                }
                                
                                $content = preg_replace("/[\n]/", "<br />\n", $product->description);
                                
                                $data = array(
                                    "class_key" => "ShopmodxResourceProduct",
                                    "pagetitle" => $product->title,
                                    "content"   => $content,
                                    "parent"    => $parent,
                                    "template"  => $products_template,
                                    "isfolder"  => 0,
                                    "tv{$article_tv_id}"      => $article,
                                    "sm_currency"   => $currency,
                                );
                                
                                if($product->image && $image_tv_id){
                                    $data["tv{$image_tv_id}"] = $product->image;
                                }
                                
                                if(!$response = $this->modx->runProcessor('resource/create', $data)){
                                    $error = "Ошибка выполнения процессора";
                                    $this->modx->log(1, $error);
                                    $this->addOutput($error);
                                    return $this->failure($error);
                                }
                                
                                //else
                                if($response->isError()){
                                    if(!$error = $response->getMessage()){
                                        $error = "Не удалось создать товар с артикулом '{$article}'";
                                    }
                                    $this->modx->log(1, $error);
                                    $this->modx->log(1, print_r($response->getResponse(), true));
                                }
                            }
                            
                            
                            
                            $product->set('processed', 1);
                            $product->save();
                        }
                    }
                    
                    // else
                    else return $this->success('success');
                
                    break;
            }
            
            
            return $this->success('progress');
        }
        else
        {
            $this->addOutput('UNKNOWN_COMMAND');
            return $this->failure('failure');
        }
         
        return $this->success('');
    }
    
    
    /*
        Очистка временной директории
    */
    protected function clearImportDir(){
        $DIR_NAME = $this->getProperty('DIR_NAME');
        
        $this->modx->loadClass('modCacheManager', '', true, false);

        $modCacheManager = new modCacheManager($this->modx);
        
        $options = array(
            'deleteTop' => false, 
            'skipDirs' => false, 
            'extensions' => false,
            'delete_exclude_items'  => array('.gitignore',),
        );
        
        $r = $modCacheManager ->deleteTree($DIR_NAME, $options);
        
        return;
    }
    
    
    // Парсим группу
    protected function parseGroup(SimpleXMLElement & $group, $parent = null){
        
        $this->groups[] = array(
            "article"   => (string)$group->Ид,
            "title"     => (string)$group->Наименование,
            "parent"    => (string)$parent,
        );
        
        if(!empty($group->Группы)){
            $this->parseGroups($group->Группы, $group->Ид);
        }
        
        return;
    }
    
    
    // Парсим группы
    protected function parseGroups(SimpleXMLElement & $groups, $parent = null){
        
        foreach($groups->Группа as $group){
            $this->groups[] = array(
                "article"   => (string)$group->Ид,
                "title"     => (string)$group->Наименование,
                "parent"    => (string)$parent,
            );
            
            if(!empty($group->Группы)){
                $this->parseGroups($group->Группы, $group->Ид);
            }
        }
        
        return;
    }
    
    
    /*
        Общая функция для составления запроса на массовую вставку записей
    */
    protected function insertInDataBase($table, array $rows, array $columns){
        
        $columns_str = implode(", ", $columns);
        
        $sql = "INSERT INTO {$table} 
            ({$columns_str}) 
            VALUES \n";
            
        $sql .= implode(",\n", $rows). ";";
        
        $s = $this->modx->prepare($sql);
        
        $result = $s->execute();
        if(!$result){
            $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'SQL ERROR Import');
            $this->modx->log(xPDO::LOG_LEVEL_ERROR, print_r($s->errorInfo(), 1));
            $this->modx->log(xPDO::LOG_LEVEL_ERROR, $sql);
        }
        return $result;
    }
    
    protected function insertGroupsInDataBase(){
        $table = $this->modx->getTableName($this->tmpCategoriesClass);
        $columns = array(
            "article",
            "title",
            "parent",
        );
        $rows = array();
        $linesPerStep = 500;
        $i = 0;
        
        foreach($this->groups as $group){
            $i++; 
            
            $article = $group['article'];
            $title = str_replace("'", "\'", $group['title']);
            $parent = ($group['parent'] ? "'{$group['parent']}'" : "NULL");
            
            $rows[] = "('{$article}', '{$title}', {$parent})";
            
            if($i % $linesPerStep == 0){
                if(!$this->insertInDataBase($table, $rows, $columns)){
                    return $this->failure("Не удалось выполнить запрос");
                }
                $rows = array();
            }
        }
        
        if($rows){
            $this->insertInDataBase($table, $rows, $columns);
        }
        
        // Сбрасываем массив групп
        $this->groups = array();
        
        return;
    }
    
    
    // Находим ID документа по артикулу
    protected function getResourceIdByArticle($article){
        $result = null;
        
        $article_tv_id = $this->modx->getOption('shopmodx1c.article_tv');
        
        if($article){
            $q = $this->modx->newQuery('modTemplateVarResource', array(
                "tmplvarid" => $article_tv_id,
                "value"     => $article,
            ));
            
            $q->select(array(
                'contentid',
            ));
            
            $q->limit(1);

            $result = $this->modx->getValue($q->prepare());
        }
        
        return $result;
    }
    
}

return 'mod1cWebExchangeCatalogImportProcessor';