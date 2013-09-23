<?php
/**
 * KumbiaPHP web & app Framework
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://wiki.kumbiaphp.com/Licencia
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@kumbiaphp.com so we can send you a copy immediately.
 *
 * @category   Kumbia
 * @package    ActiveRecord
 * @copyright  Copyright (c) 2005-2013 Kumbia Team (http://www.kumbiaphp.com)
 * @license    http://wiki.kumbiaphp.com/Licencia     New BSD License
 */
 
namespace ActiveRecord;
 
// @see Db
require_once __DIR__ . '/Db.php';
 
/**
 * Implementación de patrón ActiveRecord sin ayudantes de consultas SQL
 * 
 */
class LiteRecord
{
	/**
	 * Constructor
	 * 
	 * @param array $data
	 */
	public function __construct($data = null)
	{
		if($data) $this->_dump($data);
	}
	
	/**
	 * Cargar datos al objeto
	 * 
	 * @param array $data
	 */
	protected function _dump($data)
	{
		foreach($data as $k => $v) {
			$this->$k = $v;
		}
	}
	
	/**
	 * Alias de los campos
	 * 
	 * @return array
	 */
	public static function alias() 
	{
		return array();
	}
	
	/**
	 * Invoca el callback
	 * 
	 * @param string $callback
	 * @return mixed
	 */
	protected function _callback($callback)
	{
		if(\method_exists($this, $callback)) return $this->$callback();
		return null;
	}
	
	/**
	 * Crear registro
	 * 
	 * @param array $data
	 * @return boolean 
	 * @throw PDOException
	 */
	public function create($data = null) 
	{
		if($data) $this->_dump($data);
		
		// Callback antes de crear
		if($this->_callback('_beforeCreate') === false) return false; 
		
		$data = array();
		$columns = array();
		$values = array();
		$withDefault = self::metadata()->getWithDefault();
		$autoFields = self::metadata()->getAutoFields();
		
		// Preparar consulta
		foreach(self::metadata()->getFieldsList() as $field) {
			if(isset($this->$field) && $this->$field != '') {
				$data[":$field"] = $this->$field;
				$columns[] = $field;
				$values[] = ":$field";
			} elseif(!\in_array($field, $withDefault) && !\in_array($field, $autoFields)) {
				$columns[] = $field;
				$values[] = 'NULL';
			}
		}
		$columns = \implode(',', $columns);
		$values = \implode(',', $values);
		
		$source = self::getSource();
		$sql = "INSERT INTO $source ($columns) VALUES ($values)";
		
		if(!self::prepare($sql)->execute($data)) return false;
		
		// Verifica si la PK es autogenerada
		$pk = self::metadata()->getPK();
		if(!isset($this->$pk) && \in_array($pk, $autoFields)) {
			require_once __DIR__ . '/Query/query_exec.php';
			$this->$pk = Query\query_exec(self::getDatabase(), 'last_insert_id', self::_dbh(), $pk, self::getTable(), self::getSchema());
		}
		
		// Callback despues de crear
		$this->_callback('_afterCreate');
		
		return true;
	}
	
	/**
	 * Actualizar registro
	 * 
	 * @param array $data
	 * @return boolean 
	 */
	public function update($data = null)
	{
		if($data) $this->_dump($data);
		
		// Callback antes de actualizar
		if($this->_callback('_beforeUpdate') === false) return false; 
		
		$pk = self::metadata()->getPK();
		if(!isset($this->$pk) || $this->$pk == '') throw new \KumbiaException('No se ha especificado valor para la clave primaria');
		
		$data = array();
		$set = array();
		
		// Preparar consulta
		foreach(self::metadata()->getFieldsList() as $field) {
			if(isset($this->$field) && $this->$field != '') {
				$data[":$field"] = $this->$field;
				if($field != $pk) $set[] = "$field = :$field";
			} else {
				$set[] = "$field = NULL";
			}
		}
		$set = \implode(', ', $set);
		
		$ource = self::getSource();
		
		$sql = "UPDATE $source SET $set WHERE $pk = :$pk";
		
		if(!self::prepare($sql)->execute($data)) return false;
		
		// Callback despues de actualizar
		$this->_callback('_afterUpdate');
		
		return true;
	}
	
	/**
	 * Guardar registro
	 * 
	 * @param array $data
	 * @return boolean 
	 */
	public function save($data = null)
	{
		if($data) $this->_dump($data);
		
		if($this->_callback('_beforeSave') === false) return false; 
		
		$pk = self::metadata()->getPK();
		$ource = self::getSource();
		
		if(!isset($this->$pk) || $this->$pk == '' || !self::exists($this->$pk)) {
			$result = $this->create();
		} else {
			$result = $this->update();
		}
		
		if(!$result) return false;
		
		$this->_callback('_afterSave');
		
		return true;
	}
	
	/**
	 * Eliminar registro
	 * 
	 * @param string $pk valor para clave primaria
	 * @return boolean
	 */
	public static function delete($pk)
	{
		$source = self::getSource();
		$pkField = self::metadata()->getPK();
		
		return self::query("DELETE FROM $source WHERE $pkField = ?", $pk)->rowCount() > 0;
	}
	 
	/**
	 * Obtiene nombre de tabla
	 * 
	 * @return string
	 */
	public static function getTable()
	{
		return \Util::smallcase(\get_called_class());
	}
	
	/**
	 * Obtiene el schema al que pertenece
	 * 
	 * @return string
	 */
	public static function getSchema()
	{
		return null;
	}
	
	/**
	 * Obtiene la combinación de esquema y tabla
	 * 
	 * @return string
	 */
	public static function getSource()
	{
		$source = self::getTable();
		if($schema = self::getSchema()) $source = "$schema.$table";
		return $source;
	}
	
	/**
	 * Obtiene la conexión que se utilizará (contenidas en databases.ini)
	 * 
	 * @return string
	 */
	public static function getDatabase()
	{
		$core = \Config::read('config');
		return $core['application']['database'];
	}
	
	/**
	 * Obtiene metadatos
	 * 
	 * @return Metadata
	 */
	public static function metadata()
	{
		// @see Metadata
		require_once __DIR__ . '/Metadata/Metadata.php';
		
		// Obtiene metadata
		return Metadata\Metadata::get(self::getDatabase(), self::getTable(), self::getSchema());
	}
	
	/**
	 * Obtiene manejador de conexion a la base de datos
	 * 
	 * @param boolean $force forzar nueva conexion PDO
	 * @return PDO
	 */
	protected static function _dbh($force = false) 
	{		
		return Db::get(self::getDatabase(), $force);
	}
	
	/**
	 * Consulta sql preparada
	 * 
	 * @param string $sql
	 * @return PDOStatement
	 * @throw PDOException
	 */
	public static function prepare($sql)
	{
		$sth = self::_dbh()->prepare($sql);
		$class = \get_called_class();
		$sth->setFetchMode(\PDO::FETCH_INTO, new $class);
		return $sth;
	}
	
	/**
	 * Ejecuta consulta sql
	 * 
	 * @param string $sql
	 * @param array | string $values valores
	 * @return PDOStatement
	 */
	public static function query($sql, $values = null)
	{
		$sth = self::prepare($sql);
		
		if($values !== null && !is_array($values)) {
			$values = \array_slice(\func_get_args(), 1);
		}
		
		$sth->execute($values);
		
		return $sth;
	}
	    
    /**
     * Buscar por clave primaria
     * 
     * @param string $pk valor para clave primaria
     * @param string $fields campos que se desean obtener separados por coma
     * @return ActiveRecord
     */
    public static function get($pk, $fields = '*')
    {
		$source = self::getSource();
		$pkField = self::metadata()->getPK();
		
		$sql = "SELECT $fields FROM $source WHERE $pkField = ?";
		
		require_once __DIR__ . '/Query/query_exec.php';
		$sql = Query\query_exec(self::getDatabase(), 'limit', $sql, 1);
		
		return self::query($sql, $pk)->fetch();
	}
	
    /**
     * Verifica si existe el registro
     * 
     * @param string $pk valor para clave primaria
     * @return boolean
     */
    public static function exists($pk)
    {
		$source = self::getSource();
		$pkField = self::metadata()->getPK();
		return self::query("SELECT COUNT(*) AS count FROM $source WHERE $pkField = ?", $pk)->fetch()->count > 0;
	}
	
	/**
	 * Paginar consulta sql
	 * 
     * @param string $sql consulta select sql
     * @param int $page numero de pagina
     * @param int $perPage cantidad de items por pagina
     * @param array $values valores
     * @return Paginator
	 */
	public static function paginateQuery($sql, $page, $perPage, $values = null)
	{
		// Valores para consulta
        if($values !== null && !\is_array($values)) $values = \array_slice(func_get_args(), 3);
        
        require_once __DIR__ . '/Paginator.php';
        return new Paginator(\get_called_class(), $sql, $page, $perPage, $values);
	}
}