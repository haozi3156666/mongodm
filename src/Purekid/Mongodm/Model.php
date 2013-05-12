<?php 

namespace Purekid\Mongodm;

/**
 * Mongodm - A PHP Mongodb ORM
 *
 * @package  Mongodm
 * @version  1.0.0
 * @author   Michael Gan <gc1108960@gmail.com>
 * @link     http://github.com/purekid
 */
abstract class Model 
{
	public $cleanData = array();
	public $exists = false;
	
	protected static $config = 'default';
	protected static $use_timestamps = false;
	protected static $attrs = array();
	protected $dirtyData = array();
	protected $ignoreData = array();
	private $_connection = null;
	private $_cache = array();
	
	public function __construct($data = array())
	{
		if (is_null($this->_connection))
		{
			if(isset($this::$config)){
				$config = $this::$config;
			}else{
				$config = self::config;
			}
			$this->_connection = MongoDB::instance($config);
		}
		
 		$this->update($data,true);
 		$this->initAttrs();
	}
	
	/**
	 * Update data by a array
	 * @param array $cleanData
	 * @return MongoId Object
	 */
	public function update($cleanData,$isInit = false)
	{
		foreach($cleanData as $key => $value){
			if($isInit){
				$this->cleanData[$key] = $value;
			}else{
				$this->$key = $value;
			}
		}
		return true;
	}
	
	/**
	 * get MongoId of this record
	 *
	 * @return MongoId Object
	 */
	public function getId()
	{
		if(isset($this->cleanData['_id'])){
			return new \MongoId($this->cleanData['_id']);
		}
		return null;
	}

	/**
	 * Delete this record
	 *
	 * @param  array $options
	 * @return boolean 
	 */
	public function delete($options = array())
	{
		$this->__beforeDelete();
		
		if($this->exists){
			$deleted =  $this->_connection->remove($this->collectionName(), array("_id" => $this->getId() ), $options);
			if($deleted){
				$this->exists = false;
			}
		}
		return true;
	}

	/**
	 * Set an index for collection
	 *
	 * @param  $keys
	 * @return void
	 */
	public function set_index($keys)
	{
		return $this->_connection->ensure_index($this->collectionName(), $keys);
	}
	
	public function save($options = array())
	{
	
		$this->__beforeSave();
		
		if ($this->exists and empty($this->dirtyData)) return true;
	
		if($this->use_timestamps)
		{
			$this->timestamp();
		}
		if ($this->exists)
		{
			$success = $this->_connection->update($this->collectionName(), array('_id' => $this->getId()), array('$set' => $this->dirtyData), $options);
		}
		else
		{
			$insert = $this->_connection->insert($this->collectionName(), $this->cleanData, $options);
			$success = !is_null($this->cleanData['$id'] = $insert['_id']);
		}
	
		$this->exists = true ;
		$this->dirtyData = array();
	
		return $success;
		
	}
	
	static function count($params = array())
	{
	
		$count = self::connection()->count(self::collectionName(),$params);
		return $count;
	
	}
	
	/**
	 * Export datas to array
	 *
	 * @return array
	 */
	public function toArray()
	{
		
		return $this->cleanData;
		
	}
	
	/**
	 * determine exist in the database
	 */
	public function exist(){
		return $this->exists;
	}
	
	/**
	 * 
	 *
	 * @return array
	 */
	/**
	 * Find a record by MongoId
	 * @param mixed $id 
	 * @return Model
	 */
	static function id($id)
	{
		
		if($id){
			$id = new \MongoId($id);
		}else{
			return null;
		}
		return self::one( array( "_id" => $id ));
	
	}
	
	/**
	 * Find a record
	 *
	 * @param  array $params
	 * @param  array $fields
	 * @return Model
	 */
	static function one($params = array(),$fields = array())
	{
		$result =  self::connection()->find_one(static::$collection, $params , $fields);
		if($result){
			return  Hydrator::hydrate(get_called_class(), $result ,"one");
		}
	
		return null;
	}
	
	/**
	 * Find records
	 *
	 * @param  array $params
	 * @param  array $sort
	 * @param  array $fields
	 * @param  int $limit
	 * @param  int $skip
	 * @return ModelSet Object
	 */
	static function find($params = array(), $sort = array(), $fields = array() , $limit = null , $skip = null)
	{
	
		$results =  self::connection()->find(static::$collection, $params, $fields);
	
		$count = $results->count();
	
		if ( ! is_null($limit))
		{
			$results->limit($limit);
		}
	
		if( !  is_null($skip))
		{
			$results->skip($skip);
		}
	
		if ( ! empty($sort))
		{
			$results->sort($sort);
		}
	
		return Hydrator::hydrate(get_called_class(), $results);
	
	}
	
	/**
	 * Find records
	 *
	 * @param  array $sort
	 * @param  array $fields
	 * @return ModelSet Object
	 */
	static function all( $sort = array() , $fields = array())
	{
	
		return self::find(array(),$fields,$sort);
	
	}
	
	
	public static function collectionName()
	{
		$class = get_called_class();
		$collection = $class::$collection;
		return $collection;
	}
	
	
	
	/**
	 * retrieve a record by MongoRef
	 * @param mixed $ref
	 * @return Purekid\Mongodm\Model 
	 */
	public static function ref( $ref ){
		if(isset($ref['$id'])){
			if($ref['$ref'] == self::collectionName()){
				return self::id($ref['$id']);
			}
		}
		return null;
	}
	
	/**
	 * Create a Mongodb reference 
	 * @return MongoRef Object
	 */
	public function makeRef()
	{
	
		$model = get_called_class();
		$ref = \MongoDBRef::create($this->collectionName(), $this->getId(),$this->dbName());
		return $ref;
	
	}
	

	
	protected function initAttrs(){
		$attrs = self::getAttrs();
		foreach($attrs as $key => $attr){
			if(! isset($attr['default'])) continue;
			if( !isset($this->cleanData[$key])){
				$this->$key = $attr['default'];
			}
		}
	
	}
	
	protected static function getAttrs(){
	
		$baseClass =  __CLASS__;
		$class = get_called_class();
		$parent = get_parent_class($class);
		if($parent){
			$attrs_parent = $parent::getAttrs();
			$attrs = array_merge($attrs_parent,$class::$attrs);
		}else{
			$attrs = $class::$attrs;
		}
		return $attrs;
			
	}
	
	/**
	 * Set the creation and update timestamps on the model.
	 *
	 * Uses the time() method
	 *
	 * @return void
	 */
	private function timestamp()
	{
		$this->_cache['update_time'] = time();
	
		if ( ! $this->exists ) $this->_cache['create_time'] = $this->_cache['update_time'];
	}
	
	private static function connection()
	{
		$class = get_called_class();
		$config = $class::$config;
		return MongoDB::instance($config);
	}
	
	/**
	 * get current database name
	 * @return string
	 */
	private function dbName()
	{
	
		$dbName = "unknown";
		$config = $this::$config;
		$configs = MongoDB::config($config);
		if($configs){
			$dbName = $configs['connection']['database'];
		}
	
		return $dbName;
	}
	
	private function parseValue($key,$value){
		$attrs = $this->getAttrs();
	
		if( isset($attrs[$key]) && isset($attrs[$key]['type'])){
			$type = $attrs[$key]['type'];
			$type_defined = array('integer','double','timestamp','boolean','array','object');
			if(in_array($type, $type_defined)){
				switch($type){
					case "integer":
						$value = intval($value);
					break;
					case "double":
						$value = floatval($value);
						break;
					case "timestamp":
						if(! ($value instanceof \MongoTimestamp)){
							$value = new \MongoTimestamp($value);
						}
						break;
					case "boolean":
						$value = (boolean) $value;
						break;
					case "object":
						if(!empty($value) && !is_object($value)){
							throw new \Exception("[$key] is not a object");
						}
						$value = (object) $value;
						break;
					case "array":
						if(!empty($value) && !is_array($value)){
							throw new \Exception("[$key] is not a array");
						}
						$value = (array) $value;
						break;
				}
			}
		}
		return $value;
	
	}
	
	private function setRef($key,$value)
	{
		$attrs = $this->getAttrs();
		$cache = &$this->_cache;
		$reference = $attrs[$key];
		$model = $reference['model'];
		$type = $reference['type'];
		// 		$return = null;
	
		if($type == "reference"){
			$model = $reference['model'];
			$type = $reference['type'];
			if($value instanceof $model){
				$ref = $value->makeRef();
				$return = $ref;
			}else{
				throw new \Exception ("{$key} is not instance of '$model'");
			}
				
		}else if($type == "references"){
			$arr = array();
			if(is_array($value)){
				foreach($value as $item){
					$arr[] = $item->makeRef();
				}
				$return = $arr;
				$value = ModelSet::make($value);
			}else if($value instanceof ModelSet){
				$return = $value->makeRef();
			}else{
				throw new \Exception ("{$key} is not instance of '$model'");
			}
		}
	
		$cache[$key] = $value;
		return $return;
	}
	
	private function loadRef($key)
	{
		$attrs = $this->getAttrs();
		$reference = $attrs[$key];
		$cache = &$this->_cache;
		if(isset($this->cleanData[$key])){
			$value = $this->cleanData[$key];
		}else{
			$value = null;
		}
	
		$model = $reference['model'];
		$type = $reference['type'];
		if( isset($cache[$key]) ){
			return $cache[$key];
		}else{
			if(class_exists($model)){
				if($type == "reference"){
					if(\MongoDBRef::isRef($value)){
						$object = $model::id($value['$id']);
						$cache[$key] = $object;
						return $object;
					}
					return null;
				}else if($type == "references"){
					$res = array();
					if(!empty($value)){
						foreach($value as $item){
							$record = $model::id($item['$id']);
							if($record){
								$res[] = $record;
							}
						}
					}
					$set =  ModelSet::make($res);
					$cache[$key] = $set;
					return $set;
				}
			}
		}
	}
	
	/* Hooks */
	protected function __beforeDelete()
	{
	
	}
	
	protected function __beforeSave()
	{
	
	}
	
	/* Magic methods*/
	
	/**
	 * @param  $key
	 * @return mixed
	 */
	public function __get($key)
	{
		$attrs = $this->getAttrs();
	    if( isset($attrs[$key]) && isset($attrs[$key]['type']) 
	    	&& ( $attrs[$key]['type'] == 'reference' or $attrs[$key]['type'] == 'references' )){
			$value = $this->loadRef($key);
			return $value;
		}
		else if (array_key_exists($key, $this->cleanData))
		{
			$value = $this->parseValue($key,$this->cleanData[$key]);
			return $value;
		}
		elseif (array_key_exists($key, $this->ignoreData))
		{
			return $this->ignoreData[$key];
		}
	
	}
	
	/**
	 * Magic Method for setting model data.
	 */
	public function __set($key, $value)
	{
		$attrs = $this->getAttrs();
		if(isset($attrs[$key]) && isset($attrs[$key]['type']) 
	    	&& ( $attrs[$key]['type'] == 'reference' or $attrs[$key]['type'] == 'references' )){
			$value = $this->setRef($key,$value);
		} 
		
		$this->parseValue($key,$value);
		
		$this->cleanData[$key] = $value;
		$this->dirtyData[$key] = $value;
		
	}

}