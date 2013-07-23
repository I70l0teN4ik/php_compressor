<?php
namespace samson\compressor;

use samson\core\ExternalModule;
use samson\core\Core;
use samson\core\iModule;
use samson\core\File;
use samson\core\Config;
use samson\core\ConfigType;

/**
 * Класс для собирания веб-сайта 
 *
 * @package SamsonPHP
 * @author Vitaly Iegorov <vitalyiegorov@gmail.com>
 * @version 0.5
 */
// TODO: Интегрировать обратку представлений внутри шаблона а не дублировать одинаковый код
// TODO: Анализатор классов которые добавляются, а вдруг они вообще не нужны?
// TODO: Собирать "голый" код отдельно для его цельного выполнения
// TODO: Обработка NS {} c фигурными скобками
class Compressor extends ExternalModule
{
	/** Идентификатор модуля */
	protected $id = 'compressor';
	
	/** Identifier of global namespace */
	const NS_GLOBAL = '';
	
	/** Array key for storing last generated data */
	const VIEWS = 'views';
	
	/** Output path for compressed web application */
	public $output;
	
	/** Ignored resource extensions */
	public $ignored_extensions = array( 'php', 'js', 'css', 'md', 'map', 'dbs' );
	
	/** Ignored resource files */
	public $ignored_resources = array( 'composer.json', '.project', '.buildpath', '.gitignore' );
	
	/** Папка где размещается исходное веб-приложение */
	public $input = __SAMSON_CWD__;
	
	/** View rendering mode */
	protected $view_mode = Core::RENDER_VARIABLE;
	
	/** Указатель на текущий сворачиваемый модуль */
	protected $current;
	
	/** Коллекция уже обработанных файлов */
	protected $files = array();
		
	/** Collection for storing all php code by namespace */
	protected $php = array( self::NS_GLOBAL => array() );
		
	/** @see \samson\core\ExternalModule::prepare() */
	public function prepare()
	{
		// If output path not specified - set to final.HTTP_HOST/WEB_APP
		if( !isset($this->output))
		{
			$this->output = str_replace( $_SERVER['HTTP_HOST'], 'final.'.$_SERVER['HTTP_HOST'], $_SERVER['DOCUMENT_ROOT']).url()->base();
		}
		
		return parent::prepare();
	}
	
	/**
	 * Свернуть файл представления
	 * @param string 	$view_file 	Полный путь к файлу представления
	 * @param iModule 	$module		Указатель на модуль которому принадлежит это представление 
	 */
	public function compress_view( $view_file, iModule & $module )
	{	
		// Build relative path to module view
		$out_rel_path  = str_replace( __SAMSON_VIEW_PATH, '', ($module->id()=='local'?'':$module->id().'/').str_replace( $module->path(), '', $view_file));
		$rel_path  = ($module->id()=='local'?'':$module->id().'/').str_replace( $module->path(), '', $view_file);
		
		elapsed('  -- Preparing view: '.$view_file.'('.$rel_path.')' );
		
		// Прочитаем файл представления
		$view_html = file_get_contents( $view_file );
		
		if( ! isset($view_file{0}) ) return e('View: ##(##) is empty', E_SAMSON_SNAPSHOT_ERROR, array($view_file, $rel_path) );
		
		// Найдем обращения к роутеру ресурсов
		$view_html = preg_replace_callback( '/(<\?php)*\s*src\s*\(\s*(\'|\")*(?<path>[^\'\"\)]+)(\s*,\s*(\'|\")(?<module>[^\'\"\)]+))*(\'|\")*\s*\)\s*;*\s*(\?>)*/uis', array( $this, 'src_replace_callback'), $view_html );
		
		// Сожмем HTML
		$view_html = Minify_HTML::minify($view_html);
		
		// Iterating throw render stack, with one way template processing
		foreach ( s()->render_stack as & $renderer )
		{
			// Put view throught renderer handler
			$view_html = call_user_func( $renderer, $view_html, array(), $this );
		}	
		
		// Template generator
		$view_html = s()->generate_template( $view_html );
		
		// If rendering from array
		if( $this->view_mode == Core::RENDER_ARRAY ) $view_php = '\''.$out_rel_path.'\';'; 		
		// If rendering from variables is selected
		else if( $this->view_mode == Core::RENDER_VARIABLE ) $view_php = "<<<'EOT'"."\n".$view_html."\n"."EOT;"; 		
	
		// Add view code to final global namespace
		$this->php[ self::NS_GLOBAL ][ self::VIEWS ] .= "\n".'$GLOBALS["__compressor_files"]["'.$rel_path.'"] = '.$view_php;
	}
	
	/**
	 * Свернуть модуль
	 * @param iModule $module Указатель на модуль для сворачивания
	 */
	public function compress_module( iModule & $module, array & $data )
	{
		// Идентификатор модуля
		$id = $module->id();	
		$module_path = $module->path();
		
		elapsed('  - Compressing module: '.$id.' from '.$module_path );
			
		// Сохраним указатель на текущий модуль
		$this->current = & $module;
		
		// Build output module path
		$module_output_path = $id == 'local' ? '' : $id.'/';
			
		// Call special method enabling module personal resource pre-management on compressing
		if( $module->beforeCompress( $this, $this->php ) !== false )
		{
			// Iterate module resources
			foreach ( $data['resources'] as $extension => $resources )
			{
				// Iterate only allowed resource types
				if( !in_array( $extension , $this->ignored_extensions ) ) foreach ( $resources as $resource )
				{
					// Get only filename
					$filename = basename( $resource );
						
					// Copy only allowed resources
					if( !in_array( $filename, $this->ignored_resources ) )
					{
						// Build relative module resource path
						$relative_path = str_replace( $module_path, '', $resource );
		
						// Build correct destination folder
						$dst = $this->output.$module_output_path.$relative_path;
							
						// Copy/update file if nessesary
						$this->copy_resource( $resource, $dst );
					}
				}
			}
		
			// Internal collection of module php code, not views
			$module_php = array();
		
			// Iterate module plain php code
			foreach ( $data['php'] as $php ) $this->compress_php( $php, $module, $module_php );
			// Iterate module controllers php code
			foreach ( $data['controllers'] as $php ) $this->compress_php( $php, $module, $module_php );
			// Iterate module model php code
			foreach ( $data['models'] as $php ) $this->compress_php( $php, $module, $module_php );
			// Iterate module views
			foreach ( $data['views'] as $php ) $this->compress_view( $php, $module );			
		}	
			
		// Call special method enabling module personal resource post-management on compressing
		$module->afterCompress( $this, $this->php );
		
		// Gather all code in to global code collection with namespaces
		$this->code_array_combine( $module_php, $this->php );
		
		// Change module path
		$module->path( $id.'/' );
	}
	
	/**
	 * Copy resource handler for CSS rseources with rewriting url's
	 * @param string $src	Path to source CSS file
	 * @param string $dst	Path to destination CSS file
	 * @param string $action Action to perform 
	 */
	public function copy_css( $src, $dst, $action )
	{		
		// If we must create new CSS resource - delete all old CSS resources
		if( $action == 'Creating' )	foreach ( File::dir( pathname($dst), 'css' ) as $path) 
		{
			File::clear($path);
			break; // limit to one delete for safety
		}	
		
		// Read source file
		$text = file_get_contents( $src );
		
		// Найдем ссылки в ресурса
		if( preg_match_all( '/url\s*\(\s*(\'|\")*(?<url>[^\'\"\)]+)\s*(\'|\")*\)/i', $text, $matches ) )
		{			
			// Если мы нашли шаблон - переберем все найденные патерны
			if( isset( $matches['url']) ) for ($i = 0; $i < sizeof($matches['url']); $i++)
			{
				// Получим путь к ресурсу используя маршрутизацию
				if( m('resourcer')->parseURL( $matches['url'][$i], $module, $path ))
				{
					//trace($matches['url'][$i].'-'.url()->base().$module.'/'.$path);
					// Заменим путь в исходном файле
					$text = str_replace( $matches['url'][$i], url()->base().($module == 'local'?'':$module.'/').$path, $text );
				}	
			}
		}
	
		// Write destination file
		file_put_contents( $dst, $text );	
	}
	
	/**
	 * Copy resource handler for JS resources with rewriting url's
	 * @param string $src	Path to source CSS file
	 * @param string $dst	Path to destination CSS file
	 * @param string $action Action to perform
	 */
	public function copy_js( $src, $dst, $action )
	{
		// If we must create new CSS resource - delete all old CSS resources
		if( $action == 'Creating' )	foreach ( File::dir( pathname($dst), 'js' ) as $path)
		{
			File::clear($path);
			break; // limit to one delete for safety
		}
	
		// Read source file
		$text = file_get_contents( $src );		
	
		// Write destination file
		file_put_contents( $dst, $text );
	}
	
	/**
	 * Обработчик замены роутера ресурсов
	 * @param array $matches Найденые совпадения по шаблону
	 * @return string Обработанный вариант пути к ресурсу
	 */
	public function src_replace_callback( $matches )
	{
		// Получим относительный путь к ресурсу
		$path = $matches['path'];
	
		// Путь к модуля после сжимания
		$module_path = $this->current->id().'/';
	
		// Если передана переменная мы не можем гарантировать её значение
		if( strpos( $path, '$' ) !== false ) $path = '<?php echo \''.$module_path.'\'.'.$path.'; ?>';
		// Просто строка
		else $path = $module_path.$path;
	
		return $path;
		//e('Файл представления ## - Обращение к роутеру ресурсов через переменную ##', E_SAMSON_SNAPSHOT_ERROR, array($view_path, $path));
	}
	
	/** Prepare core serialized string only with nessesar and correct data	*/	
	public function compress_core()
	{
		// Unload all modules from core that does not implement interface iModuleCompressable
		foreach ( s()->module_stack as $id => $m ) 
		{
			if ( !( is_a( $m, ns_classname( 'iModuleCompressable', 'samson\core')))) 
			{					
				s()->unload( $id );
			}
		}
		
		// Set core rendering model
		s()->render_mode = $this->view_mode;		
		
		// Load production configuration
		Config::load( ConfigType::PRODUCTION );	
		
		// Change system path to relative type
		s()->path('');
		
		// Create serialized copy
		$core_code = serialize(s());
		
		// Find all class description in serialized core string
		if( ($this->view_mode == Core::RENDER_ARRAY) && preg_match_all('/O:(?<length>\d+):\"(?<class>[^\"]+)\"/', $core_code, $matches ))
		{
			// Remove namespaces in class definition
			for ( $i = 0; $i < sizeof($matches[0]); $i++ )
			{
				// Generate correct class name without namespaces
				$class = ns_classname( classname($matches[ 'class' ][ $i ]), nsname(classname($matches[ 'class' ][ $i ])));
		
				// Change class description in serialized string
				$core_code = str_ireplace( $matches[0][$i], 'O:'.strlen($class).':"'.$class.'"', $core_code);
			}
		}		
	
		return $core_code;
	}	
	
	/**
	 * Copy file from source location to destination location with
	 * analyzing last file modification time, and copying only changed files
	 * 
	 * @param string $src source file
	 * @param string $dst destination file
	 */
	public function copy_resource( $src, $dst, $handler = null )
	{
		if( !file_exists( $src )  ) return e('Cannot copy file - Source file(##) does not exists', E_SAMSON_SNAPSHOT_ERROR, $src );
		
		// Action to do
		$action = null;
		
		// If destination file does not exists
		if( !file_exists( $dst ) ) $action = 'Creating';
		// If source file has been changed
		else if( filemtime( $src ) <> filemtime( $dst ) ) $action = 'Updating';		

		// If we know what to do
		if( isset( $action ))
		{
			elapsed( '  -- '.$action.' file '.$dst.' from '.$src );
			
			// Create folder structure if nessesary
			$dir_path = pathname( $dst );
			if( !file_exists( $dir_path )) 
			{
				elapsed( '  -- Creating folder structure '.$dir_path.' from '.$src );
				mkdir( $dir_path, 0755, true );
			}
			
			// If file handler specified 
			if( is_callable($handler) ) call_user_func( $handler, $src, $dst, $action );
			// Copy file
			else copy( $src, $dst );
				
			// Sync source file with copied file
			touch( $src );		
		}
	}
	
	/**
	 * Compress web-application
	 * 
	 * @param string $php_version 	PHP version to support
	 * @param boolean $minify_php 	Remove comments new lines and multiple spaces in PHP
	 * @param boolean $no_errors 	Disable errors output
	 *
	 */
	public function compress( $php_version = PHP_VERSION, $minify_php = true, $no_errors = false  )
	{	
		elapsed('Compressing web-application from: '.$this->input.' to '.$this->output);
		
		// Get realpath to web application
		$realpath = s()->path();
		
		// !!!BUG!!!
		if( !isset($php_version) ) $php_version = PHP_VERSION;		
				
		// Define rendering model depending on PHP version
		if( version_compare( $php_version, '5.3.0', '<' ) ) $this->view_mode = Core::RENDER_ARRAY;
						
		// Создадим папку для свернутого сайта
		if( !file_exists($this->output)) mkdir( $this->output, 0775, true );	

		// Define global views collection
		$this->php[ self::NS_GLOBAL ][ self::VIEWS ] = "\n".'$GLOBALS["__compressor_files"] = array();';	
		
		// Iterate core ns resources collection
		foreach ( s()->load_stack as $ns => & $data )
		{	
			// Get module instance				
			$module = & s()->module_stack[ $data['id'] ];		
			
			// Work only with copressable modules
			if ( is_a( $module, ns_classname( 'iModuleCompressable', 'samson\core')))
			{						
				$this->compress_module( $module, $data );					
			}		
		}
		
		// Iterate only local modules
		foreach ( s()->module_stack as $id => & $module )
		{			
			if ( is_a( $module, ns_classname( 'CompressableLocalModule', 'samson\core')))
			{
				// Change path to module			
				$module->path('');
			}
		}		
		
		// If resourcer is loaded - copy css and js
		if( isset( s()->module_stack['resourcer'] )) 
		{
			// Link
			$rr = & s()->module_stack['resourcer'];
						
			// Copy cached js resource
			$this->copy_resource( $realpath.$rr->cached['js'], $this->output.basename($rr->cached['js']), array( $this, 'copy_js'));		
			
			// Copy cached css resource
			$this->copy_resource( $realpath.$rr->cached['css'], $this->output.basename($rr->cached['css']), array( $this, 'copy_css') );			
		}		
		
		// Set errors output
		$this->php[ self::NS_GLOBAL ][ self::VIEWS ] .= "\n".'\samson\core\Error::$OUTPUT = '.($no_errors?'false':'true').';';

		// Add global base64 serialized core string
		$this->php[ self::NS_GLOBAL ][ self::VIEWS ] .= "\n".'$GLOBALS["__CORE_SNAPSHOT"] = \''.base64_encode($this->compress_core()).'\';';
								
		// Remove standart framework entry point from index.php	- just preserve default controller	
		if( preg_match('/start\(\s*(\'|\")(?<default>[^\'\"]+)/i', $this->php[ self::NS_GLOBAL ][ $realpath.'index.php' ], $matches ))
		{
			$this->php[ self::NS_GLOBAL ][ self::VIEWS ] .= "\n".'s()->start(\''.$matches['default'].'\');';
		}
		else e('Default module definition not found - possible errors at compressed version');
		
		// Clear default entry point
		$this->php[ self::NS_GLOBAL ][ $realpath.'index.php' ] = '';
	
		// Set global namespace as last
		$global_ns = $this->php[ self::NS_GLOBAL ];
		unset( $this->php[ self::NS_GLOBAL ] );
		$this->php[ self::NS_GLOBAL ] = $global_ns;
		
		// Set view data to the end of global namespace
		$s = $this->php[ self::NS_GLOBAL ][ self::VIEWS ];
		unset( $this->php[ self::NS_GLOBAL ][ self::VIEWS ] );
		$this->php[ self::NS_GLOBAL ][ self::VIEWS ] = $s;		
		
		// Исправим порядок следования файлов в модуле на правильный
		// т.к. в PHP описание классов должно идти строго по порядку 
		$classes = array();
		
		// Соберем коллекцию загруженных интерфейсов их файлов по пространствам имен
		$this->classes_to_ns_files( get_declared_interfaces(), $classes ); 
		
		// Соберем коллекцию загруженных классов их файлов по пространствам имен
		$this->classes_to_ns_files( get_declared_classes(), $classes );
				
		// Исправим порядок файлов
		foreach ( $this->php as $ns => & $files )
		{					
			// Изменим порядок элементов в массиве файлов на правильный для конкретного NS
			if( isset( $classes [ $ns ] ) ) $files = array_merge( $classes [ $ns ], $files );			 			
		}		
		
		// Соберем весь PHP код в один файл
		$index_php = $this->code_array_to_str( $this->php, ($this->view_mode == Core::RENDER_ARRAY) );		
				
		// Запишем пусковой файл
		file_put_contents( $this->output.'index.php', '<?php '.$index_php."\n".'?>' );		
		
		// Уберем пробелы, новые строки и комментарии из кода
		//$php = php_strip_whitespace( $this->output.'index.php' );
		//file_put_contents( $this->output.'index.php', $php );
		
		elapsed('Site has been successfully compressed to '.$this->output);
	}		
	
	/**
	 * Преобразовать коллекцию полученного кода в виде NS/Files в строку
	 * с правильными NS
	 * 
	 * @param array $code Коллекция кода полученная функцией @see compress_php()
	 * @param boolean $no_ns Флаг убирания NS из кода
	 * @return string Правильно собранный код в виде строки
	 */
	public function code_array_to_str( array $code, $no_ns = false )
	{			
		// Соберем весь PHP код модуля
		$php_code = '';
		foreach ( $code as $ns => $files ) 
		{	
			if( !$no_ns )$php_code .= "\n".'namespace '.$ns.'{';			
			
			// Сначала вставим use 
			if( !$no_ns ) foreach ( array_unique($files['uses']) as $use ) 
			{				
				$php_code .= "\n".'use '.$use.';';
			}		
			
			// Вставим код файлов
			foreach ( $files as $file => $php ) 
			{					
				if( $file == 'uses' ) continue;
								
				$php_code .= $php; 
			}
			
			if( !$no_ns ) $php_code .= "\n".'}';	
		}	

		// Crear all namespace classnames, ommitting global namespace
		if( $no_ns) foreach ( $code as $ns => $files ) if( $ns != self::NS_GLOBAL )
		{
			elapsed('Clearing namespace: '.$ns);
			
			$php_code = str_ireplace( array( '\\'.$ns.'\\', $ns.'\\'), '', $php_code );
		}
		
		return $php_code;
	}
	
	public function code_array_combine( array & $source, array & $target )
	{
		foreach ( $source as $ns => $files) 
		{			
			// Если в целевом массиве нет нужного NS - создадим
			if( !isset( $target[ $ns ] ) ) $target[ $ns ] = array();

			// Запишем содержание NS/Files
			foreach ( $files as $file => $php ) 
			{ 				
				if( isset( $target[ $ns ][ $file ] ) && is_string($php)) $target[ $ns ][ $file ] .= $php;
				else if ( isset( $target[ $ns ][ $file ] ) && is_array( $php ) ) 
				{
					$target[ $ns ][ $file ] = array_unique(array_merge( $target[ $ns ][ $file ], $php ));
				}
				else $target[ $ns ][ $file ] = $php;
			}
		}
	}
	
	/**
	 * Выполнить рекурсивное "собирание" файла
	 *
	 * @param string $path Абсолютный путь к файлу сайта
	 */
	// TODO: Довести до ума разпознование require - убрать точку с зяпятоц которая остается
	// TODO: Убрать пустые линии
	// TODO: Анализатор использования функция и переменных??
	public function compress_php( $path, $module = NULL, & $code = array(), $namespace = self::NS_GLOBAL )
	{				
		//trace(' + Вошли в функцию:'.$path.'('.$namespace.')');
		$path = normalizepath(realpath($path));
	
		// Если мы уже подключили данный файл или он не существует
		if( isset( $this->files[ $path ])  ) 	return elapsed('    ! Файл: '.$path.', уже собран' );
		else if( !is_file($path) )				return elapsed('    ! Файл: '.$path.', не существует' );		
	
		elapsed('  -- Собираю PHP код из файла: '.$path );
	
		//trace('Чтение файла: '.$path );
	
		// Сохраним файл
		$this->files[ $path ] = $path;
	
		// Относительный путь к файлу
		if(isset($rel_path)) $this->files[ $rel_path ] = $path;
			
		// Прочитаем php файл
		$fileStr = file_get_contents( $path );
		
		// Если в файле нет namespace - считаем его глобальным 
		if( strpos( $fileStr, 'namespace' ) === false )
		
		$file_dir = '';
		// Вырежим путь к файлу
		$file_dir = (pathinfo( $path, PATHINFO_DIRNAME ) == '.' ? '' : pathinfo( $path, PATHINFO_DIRNAME ).'/');
	
		// Сюда соберем код программы
		$main_code = "\n".'// Модуль: '.m($module)->id().', файл: '.$path."\n";
		
		// Создадим уникальную коллекцию алиасов для NS
		if( !isset($code[ $namespace ][ 'uses' ] ) ) $code[ $namespace ][ 'uses' ] = array();
		
		// Установим ссылку на коллекцию алиасов
		$uses = & $code[ $namespace ][ 'uses' ];		
	
		// Получим константы документа
		$consts = get_defined_constants();
	
		// Маркеры для отрезания специальных блоков которые не нужны в PRODUCTION
		$rmarker_st = '\/\/\[PHPCOMPRESSOR\(remove\,start\)\]';
		$rmarker_en = '\/\/\[PHPCOMPRESSOR\(remove\,end\)\]';
	
		// Найдем все "ненужные" блоки кода и уберем их
		$fileStr = preg_replace('/'.$rmarker_st.'.*?'.$rmarker_en.'/uis', '', $fileStr );		
		
		// Разберем код программы
		$tokens = token_get_all($fileStr);
		for ($i = 0; $i < sizeof($tokens); $i++)
		{			
			// Получим следующий жетон из кода программы
			$token = $tokens[$i];		
				
			// Если просто строка 
			if ( is_string( $token ) ) $main_code .= $token;
			// Если это специальный жетон		
			else
			{
				// token array
				list( $id, $text ) = $token;
		
				// Перебирем тип комманды
				switch ($id)
				{				
					case T_COMMENT: // Пропускаем все комментарии
					case T_DOC_COMMENT:							
					case T_CLOSE_TAG: // Начало,конец файла
					case T_OPEN_TAG: break;
							
					case T_WHITESPACE:	$main_code .= $text; /*$main_code .= ' ';*/ break;
					
					// Обработаем алиасы
					case T_USE: 
						
						$_use = '';
						
						// Переберем все что иде после комманды алиаса
						for ($j = $i+1; $j < sizeof($tokens); $j++)
						{
							// Получим идентификатор метки и текстовое представление
							$id = isset( $tokens[ $j ][0] ) ? $tokens[ $j ][0] : '';
							$text = isset( $tokens[ $j ][1] ) ? $tokens[ $j ][1] : '';
						
							//trace('"'.$id.'" - "'.$text.'"');
							
							// Если use используется в функции
							if( $id == '(' ) { $j--; break; }
								
							// Если это закрывающая скобка - прекратим собирание пути к файлу
							if( $id == ';' ) break;
						
							// Все пробелы игнорирую
							if( $id == T_WHITESPACE ) continue;
						
							// Если у метки есть текстовое представление
							if( isset( $text ) )
							{
								// Если єто константа
								if( isset( $consts[ $text ])) $_use .= $consts[ $text ];
								// Если это путь
								else $_use .= $text;
							}
						}					
						
						// Если это не use в inline функции - добавим алиас в коллекцию 
						// для данного ns с проверкой на уникальность
						if( $id !== '(' ) 
						{			
							// Нижний регистр
							$_use = strtolower($_use);
							
							// TODO: Вывести замечание что бы код везде был одинаковый
							if( !in_array( $_use, $uses ) )
							{								
								// Преведем все use к одному виду
								if( $_use{0} !== '\\') $_use = '\\'.$_use;
								
								$uses[] = $_use;							
							}
						}
						else $main_code .= ' use ';
						
						// Сместим указатель чтения файла
						$i = $j;
						
					break;
					
					case T_NAMESPACE:				
		
						// Определим временное пространство имен
						$_namespace = '';
						
						// Переберем все что иде после комманды подключения файла
						for ($j = $i+1; $j < sizeof($tokens); $j++)
						{
							// Получим идентификатор метки и текстовое представление
							$id = isset( $tokens[ $j ][0] ) ? $tokens[ $j ][0] : '';
							$text = isset( $tokens[ $j ][1] ) ? $tokens[ $j ][1] : '';
								
							//trace('"'.$id.'" - "'.$text.'"');					
							
							// Если это закрывающая скобка - прекратим собирание пути к файлу
							if( $id == ')' || $id == ';' ||  $id == '{' ) break;						
						
							// Все пробелы игнорирую
							if( $id == T_WHITESPACE ) continue;
								
							// Если у метки есть текстовое представление
							if( isset( $text ) )
							{
								// Если єто константа
								if( isset( $consts[ $text ])) $_namespace .= $consts[ $text ];
								// Если это путь
								else $_namespace .= $text;
							}
						}								
						
						// Если найденный NS отличается от текущего - установим переход к новому NS
						if( $namespace !== $_namespace ) 
						{ 		
							// Сохраним новый как текущий
							$namespace = strtolower($_namespace);
							
							//trace('               #'.$i.' -> Изменили NS с '.$namespace.' на '.$_namespace);

							// Если мы еще не создали данный NS
							if( !isset($code[ $namespace ]) ) $code[ $namespace ] = array();
							// Создадим уникальную коллекцию алиасов для NS
							if( !isset($code[ $namespace ][ 'uses' ] ) ) $code[ $namespace ][ 'uses' ] = array();							
							// Установим ссылку на коллекцию алиасов
							$uses = & $code[ $namespace ][ 'uses' ];
						}				
						
						// Сместим указатель чтения файла					
						$i = $j;								
					
					break;				
						
					// Выделяем код подключаемых файлов
					case T_REQUIRE :
					case T_REQUIRE_ONCE :
					//case T_INCLUDE :
					case T_INCLUDE_ONCE:
					{							
						// Получим путь к подключаемому файлу
						$file_path = '';
			
						//elapsed('Найден подключаемый файл');
			
						// Переберем все что иде после комманды подключения файла
						for ($j = $i+1; $j < sizeof($tokens); $j++)
						{
							// Получим идентификатор метки и текстовое представление
							$id = isset( $tokens[ $j ][0] ) ? $tokens[ $j ][0] : '';
							$text = isset( $tokens[ $j ][1] ) ? $tokens[ $j ][1] : '';
								
							//trace('"'.$id.'" - "'.$text.'"');
								
							// Если это закрывающая скобка - прекратим собирание пути к файлу
							if( $id == ';' ) break;
			
							// Все пробелы игнорирую
							if( $id == T_WHITESPACE ) continue;
								
							// Если у метки есть текстовое представление
							if( isset( $text ) )
							{
								// Если єто константа
								if( isset( $consts[ $text ])) $file_path .= $consts[ $text ];
								// Если это путь
								else $file_path .= $text;
							}
						}
			
						// Если указан путь к файлу
						if( isset($file_path{1}) )
						{
							// Уберем ковычки
							$file_path = str_replace(array("'",'"'), array('',''), $file_path );
									
							// Если это не абсолютный путь - попробуем относительный
							if( !file_exists( $file_path ) ) $file_path = pathname($path).$file_path;						
									
							// Если файл найден - получим его содержимое
							if( file_exists( $file_path ) )
							{	
								//trace('Углубляемся в файл:'.$file_path.'('.$namespace.')');
								
								// Углубимся в рекурсию 
								$this->compress_php( $file_path, $module, $code, $namespace );

								// Измением позицию маркера чтения файла
								$i = $j;
							}
						}
						else $main_code .= $text;
						
					}
					break;
		
					// Собираем основной код программы
					default: $main_code .= $text; break;
				}
			}
		}		
		
		//trace(' - Вышли из функции:'.$path.'('.$namespace.')');
		//trace('');			
		
		// Запишем в коллекцию кода полученный код
		$code[ $namespace ][ $path ] = $main_code;
	}
	
	/**
	 * Преобразовать коллекцию имен классов в коллекцию 
	 * [Namespace][ ClassFileName ]
	 * 
	 * @param array $collection Коллекция имен классов
	 * @param array $classes	Коллекция для возврата результатов
	 */
	private function classes_to_ns_files( $collection, & $classes = array() )
	{		
		// Соберем коллекцию загруженных интерфейсов их файлов по пространствам имен
		foreach ( $collection as $class )
		{
			$ac = new \ReflectionClass( $class );
			
			$ns = $ac->getNamespaceName();
				
			if( $ns != '')
			{
				$ns = strtolower($ns);
				
				if( !isset( $classes[ $ns ]) ) $classes[ $ns ] = array();
					
				$classes[ $ns ][ normalizepath($ac->getFileName()) ] = '';
			}
		}
	}
}