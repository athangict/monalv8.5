<?php
namespace Store\Model;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\TableGateway\AbstractTableGateway;
use Laminas\Db\ResultSet\HydratingResultSet;
use Laminas\Db\Adapter\AdapterAwareInterface;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Sql;
use Laminas\Db\Sql\Expression;

class IssueTable extends AbstractTableGateway 
{
	protected $table = 'in_issue'; //tablename
	protected $tableColumns;
	protected $issueDetailsTableColumns;

	public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
    }	

	/**
	 * Return All records of table
	 * @return Array
	 */
	public function getAll()
	{  
	    $adapter = $this->adapter;
	    $sql = new Sql($adapter);
	    $select = $sql->select();
	    
		$select->from($this->table)
	    	   ->order(array('id DESC'));
	    
	    $selectString = $sql->getSqlStringForSqlObject($select);
	    $results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
	    return $results;
	}
	
	/**
	 * Return records of given year and month
	 * @param Int $id
	 * @return Array
	 */
	public function getDateWise($column, $year, $month, $location, $param, $flag)
	{	
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$hasToLocation = $this->hasColumn('to_location');
		$con = $this->buildSelectableColumns(array(
			'id','issue_date','challan_no','from_location','to_location','cost_center','item_group',
			'fcb_transport','vehicle_no','note','status','received_by','received_on','author','created','modified',
		));
		$con['year'] = new Expression('YEAR('.$column.')');
		$con['month'] = new Expression('MONTH('.$column.')');
		if($flag){
			$select->from($this->table)
                   ->columns($con)->having(array('year' => $year, 'month' => $month))
				   ->order(array('id DESC'));			
		}
		else{ 
			$select->from($this->table)
                   ->columns($con)->having(array('year' => $year, 'month' => $month))
				   ->order(array('id DESC'));
			if($hasToLocation):
				$select->where->equalTo('from_location', $location)
					->or
					->in('to_location', $param);
			else:
				$select->where(array('from_location' => $location));
			endif;
		}	   
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		return $results;
	}
	
	/**
	 * Return records of given start date and end date
	 * @param Int | Array $param
	 * @param Date $start_date
	 * @param Date $end_date
	 * @return Array
	 */
	public function getByDate($start_date,$end_date,$param)
	{	
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from($this->table)
				->where($where)
				->order(array('id ASC'))
				->where->between('dispatch_date', $start_date, $end_date);
				
		$selectString = $sql->getSqlStringForSqlObject($select);
		
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		return $results;
	}
	
	/**
	 * Return records of given condition array | given id
	 * @param Int $id
	 * @return Array
	 */
	public function get($param)
	{
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$columns = array('*');
		if(!$this->hasColumn('cost_center') && $this->hasColumn('activity')):
			$columns['cost_center'] = new Expression('activity');
		endif;
		if(!$this->hasColumn('activity') && $this->hasColumn('cost_center')):
			$columns['activity'] = new Expression('cost_center');
		endif;
		if(!$this->hasColumn('to_location')):
			$columns['to_location'] = new Expression('NULL');
		endif;
		$select->from($this->table)
			   ->columns($columns)
		       ->where($where);
		
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		return $results;
	}
	
	/**
	 * Return column value of given id
	 * @param Int $id
	 * @param String $column
	 * @return String | Int
	 */
	public function getColumn($param, $column)
	{
		$where = ( is_array($param) )? $param: array('id' => $param);
		$fetch = array($column);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from($this->table);
		$select->columns($fetch);
		$select->where($where);
	
		$selectString = $sql->getSqlStringForSqlObject($select);
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
	
		foreach ($results as $result):
		$columns =  $result[$column];
		endforeach;
		 
		return $columns;
	}
	
	/**
	 * Save record
	 * @param String $array
	 * @return Int
	 */
	public function save($data)
	{
	    if ( !is_array($data) ) $data = $data->toArray();
		if(isset($data['cost_center']) && !$this->hasColumn('cost_center') && $this->hasColumn('activity') && !isset($data['activity'])):
			$data['activity'] = $data['cost_center'];
		endif;
		if(isset($data['activity']) && !$this->hasColumn('activity') && $this->hasColumn('cost_center') && !isset($data['cost_center'])):
			$data['cost_center'] = $data['activity'];
		endif;
		$data = $this->filterDataByExistingColumns($data);
	    $id = isset($data['id']) ? (int)$data['id'] : 0;
	    
	    if ( $id > 0 )
	    {
	    	$result = ($this->update($data, array('id'=>$id)))?$id:0;
	    } else {
	        $this->insert($data);
	    	$result = $this->getLastInsertValue(); 
	    }	    	    
	    return $result;	     
	}

	/**
     *  Delete a record
     *  @param int $id
     *  @return true | false
     */
	public function remove($id)
	{
		return $this->delete(array('id' => $id));
	}
	/**
	* check particular row is present in the table 
	* with given column and its value
	* 
	*/
	public function isPresent($column, $value)
	{
		$column = $column; $value = $value;
		$resultSet = $this->select(function(Select $select) use ($column, $value){
			$select->where(array($column => $value));
		});
		
		$resultSet = $resultSet->toArray();
		return (sizeof($resultSet)>0)? TRUE:FALSE;
	}  
	
	/**
	 * Return records of given condition array
	 * @param Int $column
	 * @param Int $param
	 * @return Array
	 */
	public function getMonthlyINO($prefix_PO_code)
	{
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from($this->table);
		$select->where->like('challan_no', $prefix_PO_code."%");
			
		$selectString = $sql->getSqlStringForSqlObject($select);
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
	
		return  $results;
	}
	
	/**
	 * Return Min value of the column
	 * @param Array $where
	 * @param String $column
	 * @return String | Int
	 */
	public function getMin($column, $where = NULL)
	{
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from($this->table);
		$select->columns(array(
				'min' => new Expression('MIN('.$column.')')
		));
		if($where!=NULL){
			$select->where($where);
		}
		$selectString = $sql->getSqlStringForSqlObject($select);
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
	
		foreach ($results as $result):
			$column =  $result['min'];
		endforeach;
		
		return $column;
	}
	
	/**
	 * Return records of given condition array | given id
	 * @param Start_date 
	 * @param Int $id
	 * @return Array
	*/ 
	public function getSMOpening($start_date, $param, $column)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from($this->table)
		        ->where($where)
				->order(array('id DESC'))
				->where->lessThan($column,$start_date);
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		return $results;
	}
	
	/**
	 * Return records of given condition array | given id
	 * @param Start_date & End_date
	 * @param Int $id
	 * @return Array
	*/ 
	public function getSMQuantity($start_date, $end_date, $param, $column)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from($this->table)
		        ->where($where)
				->order(array('id DESC'))
				//->where->lessThan($column,$start_date);
				->where->between($column, $start_date, $end_date);
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		return $results;
	}
	/**
	 * Return records of given condition array | given id
	 * @param End_date
	 * @param Int $id
	 * @return Array
	*/ 
	public function getSMIntransitQuantity($end_date, $param, $column)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from($this->table)
		        ->where($where)
				->order(array('id DESC'))
				->where->lessThanOrEqualTo($column, $end_date);
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		return $results;
	}
	
	/**
	 * STOCK MOVEMENT
	 * Return sum of given condition array | given id
	 * @param $Start_date 
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function getSMOpeningSUM($start_date, $location, $param, $col_date, $col_loc, $col_qty,$openingDate)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
			   ->join(array('dd'=>'st_dispatch_details'), 'd.id = dd.dispatch', array());
				$select->columns(array(
					'sum' => new Expression('SUM('.$col_qty.')')
			    ));
				$select->where($where);
				//$select->order(array('o.id DESC'));
				$select->where->lessThan($col_date,$start_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
                                        $select->where->greaterThanOrEqualTo($col_date,$openingDate);
				}
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		foreach ($results as $result):
			$column =  $result['sum'];
		endforeach;
	
		return $column;
	}
        /**
	 * STORE REPORT - fetch opening sum 
	 * Return sum of given condition array | given id
	 * @param $Start_date 
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function fetchSROpeningforSspSUMATM($start_date, $location, $param, $col_date, $col_loc, $col_qty)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
			   ->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array());
				$select->columns(array(
					'sum' => new Expression('SUM('.$col_qty.')')
			    ));
				$select->where($where);
				$select->where->lessThan($col_date,$start_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		foreach ($results as $result):
			$column =  $result['sum'];
		endforeach;
	
		return $column;
	}
	/**
	 * STORE REPORT - fetch opening sum 
	 * Return sum of given condition array | given id
	 * @param $Start_date 
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function fetchSROpeningforSspSUMRt($start_date, $location, $param, $col_date, $col_loc, $col_qty)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
			   ->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array());
				$select->columns(array(
					'sum' => new Expression('SUM('.$col_qty.')')
			    ));
				$select->where($where);
				$select->where->lessThan($col_date,$start_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		foreach ($results as $result):
			$column =  $result['sum'];
		endforeach;
	
		return $column;
	}
	/**
	 * STOCK MOVEMENT
	 * Return sum of given condition array | given id
	 * @param $Start_date 
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function getSMQuantitySUM($start_date, $end_date, $location, $param, $col_date, $col_loc, $col_qty)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
				->join(array('dd'=>'st_dispatch_details'), 'd.id = dd.dispatch', array());
				$select->columns(array(
					'sum' => new Expression('SUM('.$col_qty.')')
			    ));
				$select->where($where);
				$select->where->between($col_date, $start_date, $end_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		foreach ($results as $result):
			$column =  $result['sum'];
		endforeach;
	
		return $column;
	}
	/**
	 * STOCK MOVEMENT
	 * Return sum of given condition array | given id
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function getSMIntransitQtySUM($end_date, $location, $param, $col_date, $col_loc, $col_qty,$openingDate)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
			   ->join(array('dd'=>'st_dispatch_details'), 'd.id = dd.dispatch', array());
				$select->columns(array(
					'sum' => new Expression('SUM('.$col_qty.')')
			    ));
				$select->where($where);
				//$select->order(array('o.id DESC'));
				$select->where->lessThanOrEqualTo($col_date,$end_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
					$select->where->greaterThanOrEqualTo($col_date,$openingDate);
				}
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		foreach ($results as $result):
			$column =  $result['sum'];
		endforeach;
	
		return $column;
	}
	/**
	 * Issue REPORT
	 * Return records of given condition array | given id
	 * @param Start_date & End_date
	 * @param Int $id
	 * @return Array
	*/ 
	public function getIssue($item_group, $source_loc, $destination_loc, $start_date, $end_date, $permittedSourceLocs = array())
	{   
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$permittedSourceLocIds = array();
		foreach((array)$permittedSourceLocs as $locId):
			$locId = (int)$locId;
			if($locId > 0):
				$permittedSourceLocIds[] = $locId;
			endif;
		endforeach;
		$permittedSourceLocIds = array_values(array_unique($permittedSourceLocIds));
		$hasToLocation = $this->hasColumn('to_location');
		$hasReceivedBy = $this->hasColumn('received_by');
		$hasIssueItemGroup = $this->hasColumn('item_group');
		$resolvedItem = $this->resolveIssueDetailsColumn(array('item', 'item_id', 'st_item_id'));
		$resolvedUom = $this->resolveIssueDetailsColumn(array('uom', 'uom_id', 'st_uom_id'));
		$resolvedQuantity = $this->resolveIssueDetailsQuantityColumn();
		$resolvedAcceptedQty = $this->resolveIssueDetailsColumn(array('accepted_quantity', 'accepted_qty'));
		$resolvedSoundQty = $this->resolveIssueDetailsColumn(array('sound_quantity', 'sound_qty'));
		$resolvedDamagedQty = $this->resolveIssueDetailsColumn(array('damaged_quantity', 'demage_quantity', 'damage_quantity', 'damaged_qty', 'demage_qty', 'damage_qty'));
		$resolvedShortageQty = $this->resolveIssueDetailsColumn(array('shortage_quantity', 'shortage_qty'));
		$issueDetailsSelect = array(
			'item' => $this->resolveIssueDetailsExpression($resolvedItem),
			'issue' => $this->resolveIssueDetailsExpression('issue'),
			'uom' => $this->resolveIssueDetailsExpression($resolvedUom),
			'assetssp' => $this->resolveIssueDetailsExpression('assetssp'),
			'rate' => $this->resolveIssueDetailsExpression('rate'),
			'quantity' => $this->resolveIssueDetailsExpression($resolvedQuantity),
			'accepted_quantity' => $this->resolveIssueDetailsExpression($resolvedAcceptedQty),
			'sound_quantity' => $this->resolveIssueDetailsExpression($resolvedSoundQty),
			'damaged_quantity' => $this->resolveIssueDetailsExpression($resolvedDamagedQty),
			'shortage_quantity' => $this->resolveIssueDetailsExpression($resolvedShortageQty),
			'amount' => $this->resolveIssueDetailsExpression('amount'),
			'remarks' => $this->resolveIssueDetailsExpression('remarks'),
		);
		$select->from(array('i' => $this->table))
			   ->join(array('d'=>'in_issue_details'), 'i.id = d.issue', $issueDetailsSelect);
		if ($hasIssueItemGroup) {
			$select->join(
				array('igh' => 'in_item_group'),
				'igh.id = i.item_group',
				array(
					'header_group_name' => 'name',
					'header_group_code' => 'code',
				),
				Select::JOIN_LEFT
			);
		}
		if ($resolvedItem) {
			$select->join(
				array('it' => 'in_items'),
				'it.id = d.'.$resolvedItem,
				array(
					'detail_item_group' => 'item_group',
					'detail_item_code'  => 'code',
					'detail_item_name'  => 'name',
				),
				Select::JOIN_LEFT
			)->join(
				array('ig' => 'in_item_group'),
				'ig.id = it.item_group',
				array(
					'detail_group_name' => 'name',
					'detail_group_code' => 'code',
				),
				Select::JOIN_LEFT
			)->join(
				array('sit' => 'st_items'),
				'sit.id = d.'.$resolvedItem,
				array(
					'detail_item_group_stock' => 'item_group',
					'detail_item_code_stock'  => 'code',
					'detail_item_name_stock'  => 'name',
				),
				Select::JOIN_LEFT
			)->join(
				array('sig' => 'st_item_group'),
				'sig.id = sit.item_group',
				array(
					'detail_group_name_stock' => 'item_group',
				),
				Select::JOIN_LEFT
			);
		}
		$select->order(array('i.id DESC'))
			   ->where->between('i.issue_date', $start_date, $end_date);
		if($item_group != '-1'){
			if($hasIssueItemGroup){
				$select->where(array('i.item_group' => $item_group));
			}elseif($resolvedItem){
				$select->where
					->nest
						->equalTo('it.item_group', $item_group)
						->or
						->equalTo('sit.item_group', $item_group)
					->unnest;
			}
		}
		if(sizeof($permittedSourceLocIds) > 0){
			$select->where->in('i.from_location', $permittedSourceLocIds);
		}
		if($source_loc != '-1'){
			$select->where(array('i.from_location' => (int)$source_loc));
		}
		if($destination_loc != '-1'){
			if($hasReceivedBy){
				$select->where(array('i.received_by' =>$destination_loc));
			}elseif($hasToLocation){
				$select->where(array('i.to_location' =>$destination_loc));
			}
		}
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		return $results;
	}

	protected function filterDataByExistingColumns(array $data)
	{
		$columns = $this->getTableColumns();
		if (empty($columns)) {
			return $data;
		}
		return array_intersect_key($data, array_flip($columns));
	}

	protected function buildSelectableColumns(array $columns)
	{
		$selectColumns = array();
		foreach ($columns as $column) {
			if ($this->hasColumn($column)) {
				$selectColumns[] = $column;
			} elseif ($column === 'cost_center' && $this->hasColumn('activity')) {
				$selectColumns[$column] = new Expression('activity');
			} elseif ($column === 'activity' && $this->hasColumn('cost_center')) {
				$selectColumns[$column] = new Expression('cost_center');
			} else {
				$selectColumns[$column] = new Expression('NULL');
			}
		}
		return $selectColumns;
	}

	protected function hasColumn($column)
	{
		return in_array($column, $this->getTableColumns(), true);
	}

	protected function getTableColumns()
	{
		if (is_array($this->tableColumns)) {
			return $this->tableColumns;
		}
		$sql = sprintf('SHOW COLUMNS FROM `%s`', $this->table);
		$results = $this->adapter->query($sql, $this->adapter::QUERY_MODE_EXECUTE)->toArray();
		$columns = array();
		foreach ($results as $row) {
			if (isset($row['Field'])) {
				$columns[] = $row['Field'];
			}
		}
		$this->tableColumns = $columns;
		return $this->tableColumns;
	}

	protected function normalizeIssueDetailsColumn($column)
	{
		$parts = explode('.', $column);
		$columnName = end($parts);
		if (in_array($columnName, array('quantity', 'issue_qty', 'issue_quantity'), true)) {
			$resolved = $this->resolveIssueDetailsQuantityColumn();
			if (count($parts) > 1) {
				$parts[count($parts) - 1] = $resolved;
				return implode('.', $parts);
			}
			return $resolved;
		}
		return $column;
	}

	protected function resolveIssueDetailsQuantityColumn()
	{
		if ($this->hasIssueDetailsColumn('quantity')) {
			return 'quantity';
		}
		if ($this->hasIssueDetailsColumn('issue_qty')) {
			return 'issue_qty';
		}
		if ($this->hasIssueDetailsColumn('issue_quantity')) {
			return 'issue_quantity';
		}
		return 'quantity';
	}

	protected function resolveIssueDetailsColumn(array $candidates)
	{
		foreach ($candidates as $column) {
			if ($this->hasIssueDetailsColumn($column)) {
				return $column;
			}
		}
		return null;
	}

	protected function resolveIssueDetailsExpression($column)
	{
		if ($column && $this->hasIssueDetailsColumn($column)) {
			return new Expression('d.'.$column);
		}
		return new Expression('NULL');
	}

	protected function hasIssueDetailsColumn($column)
	{
		return in_array($column, $this->getIssueDetailsColumns(), true);
	}

	protected function getIssueDetailsColumns()
	{
		if (is_array($this->issueDetailsTableColumns)) {
			return $this->issueDetailsTableColumns;
		}
		$sql = 'SHOW COLUMNS FROM `in_issue_details`';
		$results = $this->adapter->query($sql, $this->adapter::QUERY_MODE_EXECUTE)->toArray();
		$columns = array();
		foreach ($results as $row) {
			if (isset($row['Field'])) {
				$columns[] = $row['Field'];
			}
		}
		$this->issueDetailsTableColumns = $columns;
		return $this->issueDetailsTableColumns;
	}
   	/**
	 * STOCK MOVEMENT 4 - filter process
	 * Return sum of given condition array | given id
	 * @param $Start_date 
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function filterSRItem($start_date, $end_date, $location, $param, $col_date, $col_loc,$item)
	{  
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
				->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array());
				$select->columns(array(
					'item' => new Expression('DISTINCT(dd.item)')
			    ));
				$select->where($where);
				$select->where->between($col_date, $start_date, $end_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
				if($item!='-1'){
					$select->where(array('dd.item'=>$item));
			    }
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		
		return $results;
	}
	/**
	 * STOCK MOVEMENT 4 - filter process
	 * Return sum of given condition array | given id
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function filterSRItemTransitDate($end_date, $location, $param, $dispatch_date, $received_date, $col_loc,$item)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
			   ->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array());
				$select->columns(array(
					'item' => new Expression('DISTINCT(dd.item)')
			    ));
				$select->where($where);
				//$select->order(array('o.id DESC'));
				$select->where->lessThanOrEqualTo($dispatch_date,$end_date);
				$select->where->greaterThan($received_date,$end_date);
				
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
				if($item!='-1'){
					$select->where(array('dd.item'=>$item));
			    }
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
	
		return $results;
	}
		/**
	 * STOCK MOVEMENT 4 - filter process
	 * Return sum of given condition array | given id
	 * @param $Start_date 
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function filterSRReceiptItem($end_date, $location, $param, $col_date, $col_loc,$item)
	{  
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
				->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array());
				$select->columns(array(
					'item' => new Expression('DISTINCT(dd.item)')
			    ));
				$select->where($where);
				$select->where->lessThanOrEqualTo($col_date,$end_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
				if($item!='-1'){
					$select->where(array('dd.item'=>$item));
			    }
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		
		return $results;
	}
	/**
	 * STOCK MOVEMENT 4 - filter process
	 * Return sum of given condition array | given id
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function filterSRItemTransitStatus($end_date, $location, $param, $dispatch_date, $col_loc,$item)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
			   ->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array());
				$select->columns(array(
					'item' => new Expression('DISTINCT(dd.item)')
			    ));
				$select->where($where);
				$select->where->lessThanOrEqualTo($dispatch_date,$end_date);
				
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
				if($item!='-1'){
					$select->where(array('dd.item'=>$item));
			    }
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
	
		return $results;
	}
	
	/**
	 * STOCK MOVEMENT 4 - fetch opening sum
	 * Return sum of given condition array | given id
	 * @param $Start_date 
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function fetchSROpeningSUM($start_date, $location, $param, $col_date, $col_loc, $col_qty)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$col_qty = $this->normalizeIssueDetailsColumn($col_qty);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
			   ->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array());
				$select->columns(array(
					'sum' => new Expression('SUM('.$col_qty.')')
			    ));
				$select->where($where);
				//$select->order(array('o.id DESC'));
				$select->where->lessThan($col_date,$start_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		foreach ($results as $result):
			$column =  $result['sum'];
		endforeach;
	
		return $column;
	}
	
	/**
	 * STOCK MOVEMENT 4 - fetch sum of transaction quantity
	 * Return sum of given condition array | given id
	 * @param $Start_date 
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function fetchSRQuantitySUM($start_date, $end_date, $location, $param, $col_date, $col_loc, $col_qty)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$col_qty = $this->normalizeIssueDetailsColumn($col_qty);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
				->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array());
				$select->columns(array(
					'sum' => new Expression('SUM('.$col_qty.')')
			    ));
				$select->where($where);
				$select->where->between($col_date, $start_date, $end_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		foreach ($results as $result):
			$column =  $result['sum'];
		endforeach;
	
		return $column;
	}
		/**
	 * STOCK MOVEMENT 4 - fetch sum of in transit quantity by date
	 * Return sum of given condition array | given id
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function fetchSRTransitQtyDateSUM($end_date, $location, $param, $dispatch_date, $received_date, $col_loc, $col_qty)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
			   ->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array());
				$select->columns(array(
					'sum' => new Expression('SUM('.$col_qty.')')
			    ));
				$select->where($where);
				//$select->order(array('o.id DESC'));
				$select->where->lessThanOrEqualTo($dispatch_date,$end_date);
				$select->where->greaterThan($received_date,$end_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		foreach ($results as $result):
			$column =  $result['sum'];
		endforeach;
	
		return $column;
	}
		/**
	 * STOCK MOVEMENT 4 - fetch sum of in transit quantity by status
	 * Return sum of given condition array | given id
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function fetchSRTransitQtyStatusSUM($end_date, $location, $param, $dispatch_date, $received_date, $col_loc, $col_qty)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
			   ->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array());
				$select->columns(array(
					'sum' => new Expression('SUM('.$col_qty.')')
			    ));
				$select->where($where);
				//$select->order(array('o.id DESC'));
				$select->where->lessThanOrEqualTo($dispatch_date,$end_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		foreach ($results as $result):
			$column =  $result['sum'];
		endforeach;
	
		return $column;
	}
	
	/**
	 * STOCK MOVEMENT 4 - filter process for store and spare
	 * Return sum of given condition array | given id
	 * @param $Start_date 
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function filterSRSsp($start_date, $end_date, $location, $param, $col_date, $col_loc,$array,$item_sub_group,$item)
	{  
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
				->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array())
                               ->join(array('i'=>'in_items'), 'i.id = dd.item', array());
				$select->columns(array(
					'id' => new Expression('DISTINCT(dd.assetssp)')
			    ));
				$select->where($where);
				$select->where->between($col_date, $start_date, $end_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
				if($item!='-1'){
					$select->where(array('dd.item'=>$item));
			    }
				if(sizeof($array)>0):
					$select->where->notIn('dd.assetssp',$array);
				endif;
                            if($item_sub_group!='-1'){
					$select->where(array('i.item_sub_group'=>$item_sub_group));
			    }
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		
		return $results;
	}
	
	/**
	 * STOCK MOVEMENT 4 - filter process for store and spare
	 * Return sum of given condition array | given id
	 * @param $Start_date 
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function filterSMReceiptSsp($end_date, $location, $param, $col_date, $col_loc,$array,$item_sub_group,$item)
	{  
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
				->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array())
                                ->join(array('i'=>'in_items'), 'i.id = dd.item', array());
				$select->columns(array(
					'id' => new Expression('DISTINCT(dd.assetssp)')
			    ));
				$select->where($where);
				$select->where->lessThanOrEqualTo($col_date,$end_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
				if($item!='-1'){
					$select->where(array('dd.item'=>$item));
			    }
				if(sizeof($array)>0):
					$select->where->notIn('dd.assetssp',$array);
				endif;
                            if($item_sub_group!='-1'){
					$select->where(array('i.item_sub_group'=>$item_sub_group));
			    }
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		
		return $results;
	}
	/**
	 * STOCK MOVEMENT 4 - filter process for store and spare
	 * Return sum of given condition array | given id
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function filterSRSspTransitDate($end_date, $location, $param, $dispatch_date, $received_date, $col_loc,$array,$item_sub_group,$item)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
			   ->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array())
                           			   ->join(array('i'=>'in_items'), 'i.id = dd.item', array());
				$select->columns(array(
					'id' => new Expression('DISTINCT(dd.assetssp)')
			    ));
				$select->where($where);
				//$select->order(array('o.id DESC'));
				$select->where->lessThanOrEqualTo($dispatch_date,$end_date);
				$select->where->greaterThan($received_date,$end_date);
				
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
				if($item!='-1'){
					$select->where(array('dd.item'=>$item));
			    }
				if(sizeof($array)>0):
					$select->where->notIn('dd.assetssp',$array);
				endif;
                            if($item_sub_group!='-1'){
					$select->where(array('i.item_sub_group'=>$item_sub_group));
			    }
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
	
		return $results;
	}
	
	/**
	 * STOCK MOVEMENT 4 - filter process for store and spare
	 * Return sum of given condition array | given id
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function filterSRSspTransitStatus($end_date, $location, $param, $dispatch_date, $col_loc,$array,$item_sub_group,$item)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
			   ->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array())
                           ->join(array('i'=>'in_items'), 'i.id = dd.item', array());
				$select->columns(array(
					'id' => new Expression('DISTINCT(dd.assetssp)')
			    ));
				$select->where($where);
				//$select->order(array('o.id DESC'));
				$select->where->lessThanOrEqualTo($dispatch_date,$end_date);
				
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
				if($item!='-1'){
					$select->where(array('dd.item'=>$item));
			    }
				if(sizeof($array)>0):
					$select->where->notIn('dd.assetssp',$array);
				endif;
                              if($item_sub_group!='-1'){
					$select->where(array('i.item_sub_group'=>$item_sub_group));
			    }
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
	
		return $results;
	}
	
		/**
	 * STOCK MOVEMENT 4 - fetch opening sum 
	 * Return sum of given condition array | given id
	 * @param $Start_date 
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function fetchSROpeningforSspSUM($start_date, $location, $param, $col_date, $col_loc, $col_qty)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
			   ->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array());
				$select->columns(array(
					'sum' => new Expression('SUM('.$col_qty.')')
			    ));
				$select->where($where);
				$select->where->lessThan($col_date,$start_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		foreach ($results as $result):
			$column =  $result['sum'];
		endforeach;
	
		return $column;
	}
	
	/**
	 * STOCK MOVEMENT 4 - fetch sum of transaction quantity for stores and spares
	 * Return sum of given condition array | given id
	 * @param $Start_date 
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function fetchSRQuantityforSsspSUM($start_date, $end_date, $location, $param, $col_date, $col_loc, $col_qty)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
				->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array());
				$select->columns(array(
					'sum' => new Expression('SUM('.$col_qty.')')
			    ));
				$select->where($where);
				$select->where->between($col_date, $start_date, $end_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		foreach ($results as $result):
			$column =  $result['sum'];
		endforeach;
	
		return $column;
	}
	/**
	 * STOCK MOVEMENT 4 - fetch sum of transaction quantity  for stores and spares
	 * Return sum of given condition array | given id
	 * @param $Start_date 
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function fetchSRQuantityforSspSUM($start_date, $end_date, $location, $param, $col_date, $col_loc, $col_qty)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
				->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array());
				$select->columns(array(
					'sum' => new Expression('SUM('.$col_qty.')')
			    ));
				$select->where($where);
				$select->where->between($col_date, $start_date, $end_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		foreach ($results as $result):
			$column =  $result['sum'];
		endforeach;
	
		return $column;
	}
	/**
	 * STORE REPORT - fetch sum of transaction quantity for stores and spares
	 * Return sum of given condition array | given id
	 * @param $Start_date 
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function fetchSRQuantityforSsspSUMATM($start_date, $end_date, $location, $param, $col_date, $col_loc, $col_qty)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
				->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array());
				$select->columns(array(
					'sum' => new Expression('SUM('.$col_qty.')')
			    ));
				$select->where($where);
				$select->where->between($col_date, $start_date, $end_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		foreach ($results as $result):
			$column =  $result['sum'];
		endforeach;
	
		return $column;
	}
	/**
	 * STORE REPORT - fetch sum of transaction quantity for stores and spares
	 * Return sum of given condition array | given id
	 * @param $Start_date 
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function fetchSRQuantityforSsspSUMRt($start_date, $end_date, $location, $param, $col_date, $col_loc, $col_qty)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
				->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array());
				$select->columns(array(
					'sum' => new Expression('SUM('.$col_qty.')')
			    ));
				$select->where($where);
				$select->where->between($col_date, $start_date, $end_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		foreach ($results as $result):
			$column =  $result['sum'];
		endforeach;
	
		return $column;
	}
	/**
	 * STOCK MOVEMENT 4 - fetch sum of in transit quantity by date
	 * Return sum of given condition array | given id
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function fetchSRTransitQtyDateforSspSUM($end_date, $location, $param, $dispatch_date, $received_date, $col_loc, $col_qty)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
			   ->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array());
				$select->columns(array(
					'sum' => new Expression('SUM('.$col_qty.')')
			    ));
				$select->where($where);
				$select->where->lessThan($dispatch_date,$end_date);
				$select->where->greaterThan($received_date,$end_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		foreach ($results as $result):
			$column =  $result['sum'];
		endforeach;
	
		return $column;
	}
	/**
	 * STOCK MOVEMENT 4 - fetch sum of in transit quantity by status
	 * Return sum of given condition array | given id
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function fetchSMTransitQtyStatusforSspSUM($end_date, $location, $param, $dispatch_date, $received_date, $col_loc, $col_qty)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
			   ->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array());
				$select->columns(array(
					'sum' => new Expression('SUM('.$col_qty.')')
			    ));
				$select->where($where);
				//$select->order(array('o.id DESC'));
				$select->where->lessThan($dispatch_date,$end_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		foreach ($results as $result):
			$column =  $result['sum'];
		endforeach;
	
		return $column;
	}
	
	/**
	 * STOCK MOVEMENT 4 - filter process for Assets
	 * Return sum of given condition array | given id
	 * @param $Start_date 
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function filterSRAsset($start_date, $end_date, $location, $param, $col_date, $col_loc,$array,$item_sub_group,$item)
	{  
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
				->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array())
                                ->join(array('i'=>'in_items'), 'i.id = dd.item', array());
				$select->columns(array(
					'id' => new Expression('DISTINCT(dd.assetssp)')
			    ));
				$select->where($where);
				$select->where->between($col_date, $start_date, $end_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
				if($item!='-1'){
					$select->where(array('dd.item'=>$item));
			         }
				if(sizeof($array)>0):
					$select->where->notIn('dd.assetssp',$array);
				endif;
                                if($item_sub_group!='-1'){
				    $select->where(array('i.item_sub_group'=>$item_sub_group));
			        }
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		
		return $results;
	}
	/**
	 * STOCK MOVEMENT 4 - filter process for Assets
	 * Return sum of given condition array | given id
	 * @param $Start_date 
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function filterSRReceiptAsset($end_date, $location, $param, $col_date, $col_loc,$array,$item_sub_group,$item)
	{  
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
				->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array())
                                ->join(array('i'=>'in_items'), 'i.id = dd.item', array());
				$select->columns(array(
					'id' => new Expression('DISTINCT(dd.assetssp)')
			    ));
				$select->where($where);
				$select->where->lessThanOrEqualTo($col_date,$end_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
				if($item!='-1'){
					$select->where(array('dd.item'=>$item));
			    }
				if(sizeof($array)>0):
					$select->where->notIn('dd.assetssp',$array);
				endif;
                            if($item_sub_group!='-1'){
					$select->where(array('i.item_sub_group'=>$item_sub_group));
			    }
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		
		return $results;
	}
	
	/**
	 * STOCK MOVEMENT 4 - filter process
	 * Return sum of given condition array | given id
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function filterSRAssetTransitDate($end_date, $location, $param, $dispatch_date, $received_date, $col_loc,$array,$item_sub_group,$item)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
			   ->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array())
                             ->join(array('i'=>'in_items'), 'i.id = dd.item', array());
				$select->columns(array(
					'id' => new Expression('DISTINCT(dd.assetssp)')
			    ));
				$select->where($where);
				//$select->order(array('o.id DESC'));
				$select->where->lessThanOrEqualTo($dispatch_date,$end_date);
				$select->where->greaterThan($received_date,$end_date);
				
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
				if($item!='-1'){
					$select->where(array('dd.item'=>$item));
			    }
				if(sizeof($array)>0):
					$select->where->notIn('dd.assetssp',$array);
				endif;
                            if($item_sub_group!='-1'){
					$select->where(array('i.item_sub_group'=>$item_sub_group));
			    }
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
	
		return $results;
	}
	
	/**
	 * STOCK MOVEMENT 4 - filter process
	 * Return sum of given condition array | given id
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function filterSRAssetTransitStatus($end_date, $location, $param, $dispatch_date, $col_loc,$array,$item_sub_group,$item)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
			   ->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array())
                           ->join(array('i'=>'in_items'),'i.id=dd.item',array());
				$select->columns(array(
					'id' => new Expression('DISTINCT(dd.assetssp)')
			    ));
				$select->where($where);
				//$select->order(array('o.id DESC'));
				$select->where->lessThanOrEqualTo($dispatch_date,$end_date);
				
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
				if($item!='-1'){
					$select->where(array('dd.item'=>$item));
			    }
				if(sizeof($array)>0):
					$select->where->notIn('dd.assetssp',$array);
				endif;
                            if($item_sub_group!='-1'){
					$select->where(array('i.item_sub_group'=>$item_sub_group));
			    }
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
	
		return $results;
	}
	
	/**
	 * STORE REPORT - fetch opening sum for asset
	 * Return sum of given condition array | given id
	 * @param $Start_date 
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function fetchSROpeningforAssetSUM($start_date, $location, $param, $col_date, $col_loc, $col_qty)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
			   ->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array());
				$select->columns(array(
					'sum' => new Expression('SUM('.$col_qty.')')
			    ));
				$select->where($where);
				$select->where->lessThan($col_date,$start_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		foreach ($results as $result):
			$column =  $result['sum'];
		endforeach;
	
		return $column;
	}
	
	/**
	 * STOCK MOVEMENT 4 - fetch sum of transaction quantity
	 * Return sum of given condition array | given id
	 * @param $Start_date 
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function fetchSRQuantityforAssetSUM($start_date, $end_date, $location, $param, $col_date, $col_loc, $col_qty)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
				->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array());
				$select->columns(array(
					'sum' => new Expression('SUM('.$col_qty.')')
			    ));
				$select->where($where);
				$select->where->between($col_date, $start_date, $end_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		foreach ($results as $result):
			$column =  $result['sum'];
		endforeach;
	
		return $column;
	}
	/**
	 * STOCK MOVEMENT 4 - fetch sum of in transit quantity by date
	 * Return sum of given condition array | given id
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function fetchSRTransitQtyDateforAssetSUM($end_date, $location, $param, $dispatch_date, $received_date, $col_loc, $col_qty)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
			   ->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array());
				$select->columns(array(
					'sum' => new Expression('SUM('.$col_qty.')')
			    ));
				$select->where($where);
				$select->where->lessThanOrEqualTo($dispatch_date,$end_date);
				$select->where->greaterThan($received_date,$end_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		foreach ($results as $result):
			$column =  $result['sum'];
		endforeach;
	
		return $column;
	}
	/**
	 * STOCK MOVEMENT 4 - fetch sum of in transit quantity by status
	 * Return sum of given condition array | given id
	 * @param $location
	 * @param $param
	 * @return SUM
	**/ 
	public function fetchSRTransitQtyStatusforAssetSUM($end_date, $location, $param, $dispatch_date, $received_date, $col_loc, $col_qty)
	{   
		$where = ( is_array($param) )? $param: array('id' => $param);
		$adapter = $this->adapter;
		$sql = new Sql($adapter);
		$select = $sql->select();
		$select->from(array('d' => $this->table))
			   ->join(array('dd'=>'in_issue_details'), 'd.id = dd.issue', array());
				$select->columns(array(
					'sum' => new Expression('SUM('.$col_qty.')')
			    ));
				$select->where($where);
				//$select->order(array('o.id DESC'));
				$select->where->lessThanOrEqualTo($dispatch_date,$end_date);
				if($location != '-1'){
					$select->where(array($col_loc => $location));
				}
		$selectString = $sql->getSqlStringForSqlObject($select);
		//echo $selectString; exit;
		$results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
		foreach ($results as $result):
			$column =  $result['sum'];
		endforeach;
	
		return $column;
	}
}
