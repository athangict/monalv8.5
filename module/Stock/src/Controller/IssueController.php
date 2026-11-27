<?php
namespace Stock\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\Stdlib\ArrayObject;
use Laminas\Validator\File\Size;
use Laminas\Validator\File\Extension;
use Laminas\Authentication\AuthenticationService;
use Interop\Container\ContainerInterface;
use Accounts\Model As Accounts;
use Acl\Model As Acl;
use Administration\Model As Administration;
use Hr\Model As Hr;
use Store\Model As Store;
use Stock\Model As Stock;
class IssueController extends AbstractActionController
{   
	protected $_connection;
	protected $_userloc;
	protected $_hasItemGroupFinanceTransaction = null;
	protected $_itemGroupColumnCache = array();
	private $_container;
	protected $_table; 		// database table 
    protected $_user; 		// user detail
    protected $_login_id; 	// logined user id
    protected $_login_role; // logined user role
    protected $_author; 	// logined user id
    protected $_created; 	// current date to be used as created dated
    protected $_modified; 	// current date to be used as modified date
    protected $_config; 	// configuration details
    protected $_dir; 		// default file directory
    protected $_id; 		// route parameter id, usally used by crude
    protected $_auth; 		// checking authentication
    protected $_safedataObj; //safedata controller plugin

    private function getIssueItemData($itemId)
    {
    	$itemId = (int)$itemId;
    	if($itemId <= 0):
    		return array();
    	endif;

    	$items = $this->getDefinedTable(Store\ItemTable::class)->get($itemId);
    	if(sizeof($items) > 0):
    		foreach($items as $item):
    			$item['_source'] = 'store';
    			return $item;
    		endforeach;
    	endif;

    	$stockItems = $this->getDefinedTable(Stock\ItemTable::class)->get($itemId);
    	if(sizeof($stockItems) > 0):
    		foreach($stockItems as $stockItem):
    			$stockItem['_source'] = 'stock';
    			return $stockItem;
    		endforeach;
    	endif;

    	return array();
    }

	private function getStatusIdByName($statusName, $fallbackId = 0)
	{
		$fallbackId = (int)$fallbackId;
		if($statusName === NULL || $statusName === ''):
			return $fallbackId;
		endif;
		$rows = $this->getDefinedTable(Acl\StatusTable::class)->get(array('status' => $statusName));
		foreach($rows as $row):
			if(isset($row['id']) && (int)$row['id'] > 0):
				return (int)$row['id'];
			endif;
		endforeach;
		return $fallbackId;
	}

    private function resolveLinkedCreditSubhead($head, array $item)
    {
    	$head = (int)$head;
    	if($head <= 0):
    		return array();
    	endif;

		$inventorySubhead = $this->resolveGroupMappedSubhead($item, array('fn_inventory', 'inventory', 'inventory_transaction', 'finance_transaction'));
		return (isset($inventorySubhead['id']) && isset($inventorySubhead['head_id'])) ? $inventorySubhead : array();
    }

	private function hasItemGroupColumn($column)
	{
		if(isset($this->_itemGroupColumnCache[$column])):
			return $this->_itemGroupColumnCache[$column];
		endif;
		$sql = "SHOW COLUMNS FROM `st_item_group` LIKE '".$column."'";
		$columns = $this->_container->get('Laminas\Db\Adapter\Adapter')->query($sql, 'execute')->toArray();
		$this->_itemGroupColumnCache[$column] = (sizeof($columns) > 0);
		return $this->_itemGroupColumnCache[$column];
	}

	private function getItemGroupIdFromItem(array $item)
	{
		if(isset($item['item_group_id']) && (int)$item['item_group_id'] > 0):
			return (int)$item['item_group_id'];
		endif;
		if(isset($item['item_group']) && (int)$item['item_group'] > 0):
			return (int)$item['item_group'];
		endif;
		return 0;
	}

	private function resolveGroupMappedSubhead(array $item, array $candidateColumns)
	{
		$itemGroupId = $this->getItemGroupIdFromItem($item);
		if($itemGroupId <= 0):
			return array();
		endif;

		foreach($candidateColumns as $column):
			if(!$this->hasItemGroupColumn($column)):
				continue;
			endif;
			$subheadId = (int)$this->getDefinedTable(Stock\ItemGroupTable::class)->getColumn($itemGroupId, $column);
			if($subheadId <= 0):
				continue;
			endif;
			$subheadRows = $this->getDefinedTable(Accounts\SubheadTable::class)->get($subheadId);
			foreach($subheadRows as $subheadRow):
				return $subheadRow;
			endforeach;
		endforeach;

		return array();
	}

	private function resolveLinkedDebitSubhead($head, array $item)
	{
		$head = (int)$head;
		if($head <= 0):
			return array();
		endif;

		$expenseSubhead = $this->resolveGroupMappedSubhead($item, array('fn_expense', 'expense', 'expense_transaction'));
		if(isset($expenseSubhead['id']) && isset($expenseSubhead['head_id'])):
			return $expenseSubhead;
		endif;

		return array();
	}

	private function hasItemGroupFinanceTransactionColumn()
	{
		if($this->_hasItemGroupFinanceTransaction !== null):
			return $this->_hasItemGroupFinanceTransaction;
		endif;
		$this->_hasItemGroupFinanceTransaction = $this->hasItemGroupColumn('finance_transaction');
		return $this->_hasItemGroupFinanceTransaction;
	}

	private function resolveGroupFinanceSubhead(array $item)
	{
		if(!$this->hasItemGroupFinanceTransactionColumn()):
			return array();
		endif;

		$itemGroupId = 0;
		if(isset($item['item_group_id']) && (int)$item['item_group_id'] > 0):
			$itemGroupId = (int)$item['item_group_id'];
		elseif(isset($item['item_group']) && (int)$item['item_group'] > 0):
			$itemGroupId = (int)$item['item_group'];
		endif;
		if($itemGroupId <= 0):
			return array();
		endif;

		$financeSubheadId = (int)$this->getDefinedTable(Stock\ItemGroupTable::class)->getColumn($itemGroupId, 'finance_transaction');
		if($financeSubheadId <= 0):
			return array();
		endif;

		$subheadRows = $this->getDefinedTable(Accounts\SubheadTable::class)->get($financeSubheadId);
		foreach($subheadRows as $subheadRow):
			return $subheadRow;
		endforeach;

		return array();
	}

	private function resolveClassFinanceSubhead(array $item)
	{
		$itemGroupId = 0;
		if(isset($item['item_group_id']) && (int)$item['item_group_id'] > 0):
			$itemGroupId = (int)$item['item_group_id'];
		elseif(isset($item['item_group']) && (int)$item['item_group'] > 0):
			$itemGroupId = (int)$item['item_group'];
		endif;
		if($itemGroupId <= 0):
			return array();
		endif;

		$itemClassId = (int)$this->getDefinedTable(Stock\ItemGroupTable::class)->getColumn($itemGroupId, 'item_class');
		if($itemClassId <= 0):
			return array();
		endif;

		$financeSubheadId = (int)$this->getDefinedTable(Stock\ItemClassTable::class)->getColumn($itemClassId, 'finance_transaction');
		if($financeSubheadId <= 0):
			return array();
		endif;

		$subheadRows = $this->getDefinedTable(Accounts\SubheadTable::class)->get($financeSubheadId);
		foreach($subheadRows as $subheadRow):
			return $subheadRow;
		endforeach;

		return array();
	}

    private function getItemLocationStockSummary($itemGroup, $itemId, $locationId)
    {
    	$itemGroup = (int)$itemGroup;
    	$itemId = (int)$itemId;
    	$locationId = (int)$locationId;
    	if($itemId <= 0 || $locationId <= 0):
    		return array('quantity' => 0, 'rate' => 0);
    	endif;

    	if($itemGroup == 1):
    		$sql = "SELECT COALESCE(SUM(ad.quantity),0) AS quantity, COALESCE(AVG(ad.rate),0) AS rate
    				FROM in_asset_dtls ad
    				INNER JOIN in_asset a ON a.id = ad.asset
    				WHERE a.item = {$itemId} AND ad.location = {$locationId}";
    	else:
    		$sql = "SELECT COALESCE(SUM(sd.quantity),0) AS quantity, COALESCE(AVG(sd.rate),0) AS rate
    				FROM in_storespare_details sd
    				INNER JOIN in_storespare s ON s.id = sd.storespare
    				WHERE s.item = {$itemId} AND sd.location = {$locationId}";
    	endif;

    	$results = $this->_container->get('Laminas\Db\Adapter\Adapter')->query($sql, 'execute')->toArray();
    	if(sizeof($results) > 0):
    		return $results[0];
    	endif;

    	return array('quantity' => 0, 'rate' => 0);
    }

    private function getOpeningStockSummary($itemId, $locationId)
    {
    	$itemId = (int)$itemId;
    	$locationId = (int)$locationId;
    	if($itemId <= 0 || $locationId <= 0):
    		return array('quantity' => 0, 'rate' => 0);
    	endif;
    	$sql = "SELECT COALESCE(SUM(od.quantity),0) AS quantity, COALESCE(AVG(od.selling_price),0) AS rate
    			FROM st_opening_stock_dtls od
    			INNER JOIN st_opening_stock o ON o.id = od.opening_stock
    			WHERE o.item = {$itemId} AND od.location = {$locationId}";
    	$results = $this->_container->get('Laminas\Db\Adapter\Adapter')->query($sql, 'execute')->toArray();
    	if(sizeof($results) > 0):
    		return $results[0];
    	endif;
    	return array('quantity' => 0, 'rate' => 0);
    }

    private function getIssueSourceQuantity($itemId, $itemGroup, $sourceLocation)
    {
    	$itemId = (int)$itemId;
    	$itemGroup = (int)$itemGroup;
    	$sourceLocation = (int)$sourceLocation;
    	if($itemId <= 0 || $sourceLocation <= 0):
    		return 0;
    	endif;

    	$source_qty = 0;
    	$stockItemData = $this->getDefinedTable(Stock\ItemTable::class)->get($itemId);
    	$stockItem = array();
    	foreach($stockItemData as $row):
    		$stockItem = $row;
    	endforeach;

    	if(sizeof($stockItemData) > 0):
    		$item_valuation = isset($stockItem['valuation']) ? (int)$stockItem['valuation'] : 1;
    		if($item_valuation == 1):
    			$source_qty = $this->getDefinedTable(Stock\MovingItemTable::class)->getColumn(array('item'=>$itemId,'location'=>$sourceLocation),'quantity');
    		else:
    			$batchObj = $this->getDefinedTable(Stock\BatchTable::class);
    			$batchdltObj = $this->getDefinedTable(Stock\BatchDetailsTable::class);
    			$batch_id = $batchObj->getMax('id', array('item' => $itemId));
    			if($batch_id > 0):
    				$source_qty = $batchdltObj->getColumn(array('batch'=>$batch_id,'location'=>$sourceLocation),'quantity');
    			else:
    				$source_qty = 0;
    			endif;
    		endif;
    		if(!is_numeric($source_qty) || (float)$source_qty <= 0):
    			$sourceOpening = $this->getOpeningStockSummary($itemId, $sourceLocation);
    			$source_qty = isset($sourceOpening['quantity']) ? $sourceOpening['quantity'] : $source_qty;
    			if((!is_numeric($source_qty) || (float)$source_qty <= 0) && $itemGroup > 0):
    				$legacySource = $this->getItemLocationStockSummary($itemGroup, $itemId, $sourceLocation);
    				$source_qty = isset($legacySource['quantity']) ? $legacySource['quantity'] : $source_qty;
    			endif;
    		endif;
    	else:
    		if($itemGroup > 0):
    			$legacySource = $this->getItemLocationStockSummary($itemGroup, $itemId, $sourceLocation);
    			$source_qty = isset($legacySource['quantity']) ? $legacySource['quantity'] : 0;
    		endif;
    	endif;

    	return (is_numeric($source_qty)) ? (float)$source_qty : 0;
    }

    private function reduceIssueSourceStock($itemId, $itemGroup, $sourceLocation, $issueQty, $legacyAssetSspId = 0)
    {
    	$itemId = (int)$itemId;
    	$itemGroup = (int)$itemGroup;
    	$sourceLocation = (int)$sourceLocation;
    	$issueQty = (float)$issueQty;
    	$legacyAssetSspId = (int)$legacyAssetSspId;
    	if($itemId <= 0 || $sourceLocation <= 0 || $issueQty <= 0):
    		return false;
    	endif;

    	$stockItems = $this->getDefinedTable(Stock\ItemTable::class)->get($itemId);
    	$stockItem = array();
    	foreach($stockItems as $row):
    		$stockItem = $row;
    	endforeach;

    	if(sizeof($stockItems) > 0):
    		$itemValuation = isset($stockItem['valuation']) ? (int)$stockItem['valuation'] : 1;
    		if($itemValuation == 1):
    			$movingItems = $this->getDefinedTable(Stock\MovingItemTable::class)->get(array('item'=>$itemId,'location'=>$sourceLocation));
    			$movingItem = array();
    			foreach($movingItems as $row):
    				$movingItem = $row;
    			endforeach;
    			if(isset($movingItem['id'])):
    				$newQty = (float)$movingItem['quantity'] - $issueQty;
    				$updateMoving = array(
    					'id' => $movingItem['id'],
    					'quantity' => ($newQty > 0) ? $newQty : 0,
    					'author' => $this->_author,
    					'modified' => $this->_modified,
    				);
    				$updateMoving = $this->_safedataObj->rteSafe($updateMoving);
    				return $this->getDefinedTable(Stock\MovingItemTable::class)->save($updateMoving) > 0;
    			endif;
    		else:
    			$batchId = $this->getDefinedTable(Stock\BatchTable::class)->getMax('id', array('item'=>$itemId));
    			if($batchId > 0):
    				$batchDetails = $this->getDefinedTable(Stock\BatchDetailsTable::class)->get(array('batch'=>$batchId,'location'=>$sourceLocation));
    				$batchDetail = array();
    				foreach($batchDetails as $row):
    					$batchDetail = $row;
    				endforeach;
    				if(isset($batchDetail['id'])):
    					$newQty = (float)$batchDetail['quantity'] - $issueQty;
    					$updateBatchDetail = array(
    						'id' => $batchDetail['id'],
    						'quantity' => ($newQty > 0) ? $newQty : 0,
    						'author' => $this->_author,
    						'modified' => $this->_modified,
    					);
    					$updateBatchDetail = $this->_safedataObj->rteSafe($updateBatchDetail);
    					return $this->getDefinedTable(Stock\BatchDetailsTable::class)->save($updateBatchDetail) > 0;
    				endif;
    			endif;
    		endif;
    	endif;

    	if($legacyAssetSspId > 0 && $itemGroup == 1):
    		$assetDetails = $this->getDefinedTable(Store\AssetDetailsTable::class)->get(array('asset'=>$legacyAssetSspId,'location'=>$sourceLocation));
    		$assetDetail = array();
    		foreach($assetDetails as $row):
    			$assetDetail = $row;
    		endforeach;
    		if(isset($assetDetail['id'])):
    			$newQty = (float)$assetDetail['quantity'] - $issueQty;
    			$updateAsset = array(
    				'id' => $assetDetail['id'],
    				'quantity' => ($newQty > 0) ? $newQty : 0,
    				'author' => $this->_author,
    				'modified' => $this->_modified,
    			);
    			$updateAsset = $this->_safedataObj->rteSafe($updateAsset);
    			return $this->getDefinedTable(Store\AssetDetailsTable::class)->save($updateAsset) > 0;
    		endif;
    	endif;

    	if($legacyAssetSspId > 0 && $itemGroup != 1):
    		$sspDetails = $this->getDefinedTable(Store\StoreSpareDetailsTable::class)->get(array('storespare'=>$legacyAssetSspId,'location'=>$sourceLocation));
    		$sspDetail = array();
    		foreach($sspDetails as $row):
    			$sspDetail = $row;
    		endforeach;
    		if(isset($sspDetail['id'])):
    			$newQty = (float)$sspDetail['quantity'] - $issueQty;
    			$updateSsp = array(
    				'id' => $sspDetail['id'],
    				'quantity' => ($newQty > 0) ? $newQty : 0,
    				'author' => $this->_author,
    				'modified' => $this->_modified,
    			);
    			$updateSsp = $this->_safedataObj->rteSafe($updateSsp);
    			return $this->getDefinedTable(Store\StoreSpareDetailsTable::class)->save($updateSsp) > 0;
    		endif;
    	endif;

    	$openingRows = $this->_container->get('Laminas\Db\Adapter\Adapter')->query(
    		"SELECT od.id, od.quantity
    		 FROM st_opening_stock_dtls od
    		 INNER JOIN st_opening_stock o ON o.id = od.opening_stock
    		 WHERE o.item = {$itemId} AND od.location = {$sourceLocation}
    		 ORDER BY od.id DESC LIMIT 1",
    		'execute'
    	)->toArray();
    	if(sizeof($openingRows) > 0 && isset($openingRows[0]['id'])):
    		$newQty = (float)$openingRows[0]['quantity'] - $issueQty;
    		$updateOpening = array(
    			'id' => $openingRows[0]['id'],
    			'quantity' => ($newQty > 0) ? $newQty : 0,
    			'author' => $this->_author,
    			'modified' => $this->_modified,
    		);
    		$updateOpening = $this->_safedataObj->rteSafe($updateOpening);
    		return $this->getDefinedTable(Stock\OpeningStockDtlsTable::class)->save($updateOpening) > 0;
    	endif;

    	if($itemGroup == 1):
    		$assetRows = $this->_container->get('Laminas\Db\Adapter\Adapter')->query(
    			"SELECT ad.id, ad.quantity
    			 FROM in_asset_dtls ad
    			 INNER JOIN in_asset a ON a.id = ad.asset
    			 WHERE a.item = {$itemId} AND ad.location = {$sourceLocation}
    			 ORDER BY ad.id ASC LIMIT 1",
    			'execute'
    		)->toArray();
    		if(sizeof($assetRows) > 0 && isset($assetRows[0]['id'])):
    			$newQty = (float)$assetRows[0]['quantity'] - $issueQty;
    			$updateAsset = array(
    				'id' => $assetRows[0]['id'],
    				'quantity' => ($newQty > 0) ? $newQty : 0,
    				'author' => $this->_author,
    				'modified' => $this->_modified,
    			);
    			$updateAsset = $this->_safedataObj->rteSafe($updateAsset);
    			return $this->getDefinedTable(Store\AssetDetailsTable::class)->save($updateAsset) > 0;
    		endif;
    	else:
    		$sspRows = $this->_container->get('Laminas\Db\Adapter\Adapter')->query(
    			"SELECT sd.id, sd.quantity
    			 FROM in_storespare_details sd
    			 INNER JOIN in_storespare s ON s.id = sd.storespare
    			 WHERE s.item = {$itemId} AND sd.location = {$sourceLocation}
    			 ORDER BY sd.id ASC LIMIT 1",
    			'execute'
    		)->toArray();
    		if(sizeof($sspRows) > 0 && isset($sspRows[0]['id'])):
    			$newQty = (float)$sspRows[0]['quantity'] - $issueQty;
    			$updateSsp = array(
    				'id' => $sspRows[0]['id'],
    				'quantity' => ($newQty > 0) ? $newQty : 0,
    				'author' => $this->_author,
    				'modified' => $this->_modified,
    			);
    			$updateSsp = $this->_safedataObj->rteSafe($updateSsp);
    			return $this->getDefinedTable(Store\StoreSpareDetailsTable::class)->save($updateSsp) > 0;
    		endif;
    	endif;

    	return false;
    }
    
	public function __construct(ContainerInterface $container)
    {
        $this->_container = $container;
    }
   /**
	 * Laminas Default TableGateway
	 * Table name as the parameter
	 * returns obj
	 */
	public function getDefaultTable($table)
	{
		$this->_table = new TableGateway($table, $this->_container->get('Laminas\Db\Adapter\Adapter'));
		return $this->_table;
	}
	/**
	 * User defined Model
	 * Table name as the parameter
	 * returns obj
	 */
	public function getDefinedTable($table)
    {
        if ($table === Acl\UserroleTable::class) {
            $usersTable = $this->_container->get(Administration\UsersTable::class);

            return new class($usersTable) {
                private $usersTable;

                public function __construct($usersTable)
                {
                    $this->usersTable = $usersTable;
                }

                public function get($param)
                {
                    $where = array();
                    $subroles = array();
                    $hasUserFilter = false;

                    if (is_array($param)) {
                        if (isset($param['user']) && $param['user'] !== '') {
                            $where['id'] = $param['user'];
                            $hasUserFilter = true;
                        }

                        if (isset($param['subrole'])) {
                            $subroles = is_array($param['subrole']) ? $param['subrole'] : array($param['subrole']);
                        }

                        foreach ($param as $key => $value) {
                            if (is_int($key) || ctype_digit((string) $key)) {
                                $subroles[] = $value;
                            }
                        }
                    }

                    $subroles = array_values(array_filter(array_unique(array_map('strval', $subroles)), 'strlen'));
                    $users = $this->usersTable->get($where);
                    $results = array();

                    foreach ($users as $user) {
                        $roles = array_values(array_filter(array_map('trim', explode(',', (string) $user['role'])), 'strlen'));
                        $matchedRoles = empty($subroles) ? $roles : array_intersect($roles, $subroles);
                        if ($hasUserFilter && empty($matchedRoles) && !empty($subroles)) {
                            $matchedRoles = $subroles;
                        }

                        foreach ($matchedRoles as $role) {
                            $results[] = array(
                                'user' => $user['id'],
                                'subrole' => (string) $role,
                            );
                        }
                    }

                    return $results;
                }
            };
        }

        if ($table === Acl\UsersTable::class) {
            $table = Administration\UsersTable::class;
        }

        if ($table === Store\GroupTable::class) {
            $groupTable = $this->_container->get(Stock\ItemGroupTable::class);

            return new class($groupTable) {
                private $groupTable;

                public function __construct($groupTable)
                {
                    $this->groupTable = $groupTable;
                }

                public function getAll()
                {
                    $rows = $this->groupTable->getAll();
                    foreach ($rows as &$row) {
                        $row['name'] = isset($row['name']) ? $row['name'] : (isset($row['item_group']) ? $row['item_group'] : '');
                        $row['code'] = isset($row['code']) ? $row['code'] : $row['name'];
                    }

                    return $rows;
                }

                public function get($param)
                {
                    $rows = $this->groupTable->get($param);
                    foreach ($rows as &$row) {
                        $row['name'] = isset($row['name']) ? $row['name'] : (isset($row['item_group']) ? $row['item_group'] : '');
                        $row['code'] = isset($row['code']) ? $row['code'] : $row['name'];
                    }

                    return $rows;
                }

                public function getColumn($param, $column)
                {
                    if ($column === 'name' || $column === 'code') {
                        return $this->groupTable->getColumn($param, 'item_group');
                    }

                    return $this->groupTable->getColumn($param, $column);
                }

                public function __call($name, $arguments)
                {
                    return call_user_func_array(array($this->groupTable, $name), $arguments);
                }
            };
        }

        if ($table === Administration\LocationTable::class) {
            $locationTable = $this->_container->get($table);

            return new class($locationTable) {
                private $locationTable;

                public function __construct($locationTable)
                {
                    $this->locationTable = $locationTable;
                }

                public function get($param)
                {
                    if (is_array($param)) {
                        $normalized = array();
                        foreach ($param as $key => $value) {
                            $normalized[preg_replace('/^l\./', '', (string) $key)] = $value;
                        }
                        $param = $normalized;
                    }

                    return $this->locationTable->get($param);
                }

                public function __call($name, $arguments)
                {
                    return call_user_func_array(array($this->locationTable, $name), $arguments);
                }
            };
        }

        return $this->_container->get($table);
    }
    /**
     * initial set up
     * general variables are defined here
     */
	public function init()
	{
		$this->_auth = new AuthenticationService;
		
		if(!$this->_auth->hasIdentity()):
			$this->flashMessenger()->addMessage('error^ You dont have right to access this page!');
			$this->redirect()->toRoute('auth', array('action' => 'login'));
		endif;
		
		$this->_config = $this->_container->get('Config');	
		$this->_user = $this->identity();		
		$this->_login_id = $this->_user->id;  
		$this->_login_role = $this->_user->role;  
		$this->_author = $this->_user->id;  		
		$this->_id = $this->params()->fromRoute('id');		
	    $this->_created = date('Y-m-d H:i:s');
		$this->_modified = date('Y-m-d H:i:s');		
		//$this->_dir =realpath($fileManagerDir);
		//$this->_safedataObj =  $this->SafeDataPlugin();
		$this->_userloc = $this->_user->location;  
		$this->_safedataObj = $this->safedata();
		$this->_connection = $this->_container->get('Laminas\Db\Adapter\Adapter')->getDriver()->getConnection();

	}
	/**
	 * index of the Batch Controller
	 */
	public function indexAction()
	{
		$this->init();
		$currentYear = (int)date('Y');
		$currentMonth = (int)date('n');
		$year = $currentYear;
		$month = $currentMonth;
		if($this->getRequest()->isPost())
		{
			$form = $this->getRequest()->getPost();
			$postedYear = isset($form['year']) ? (int)$form['year'] : 0;
			$postedMonth = isset($form['month']) ? (int)$form['month'] : 0;
			if($postedYear > 0):
				$year = $postedYear;
			endif;
			if($postedMonth >= 1 && $postedMonth <= 12):
				$month = $postedMonth;
			endif;
		}
		
		$data = array(
				'year' => $year,
				'month' => $month,
		);
	    $subRoles = $this->getDefinedTable(Acl\UserroleTable::class)->get(array('user'=>$this->_author, 'subrole'=>array('1','3','15')));  
		if(sizeof($subRoles) > 0){ $role_flag = true; } else{ $role_flag = false; }
		$admin_locs = $this->getDefinedTable(Acl\UsersTable::class)->getColumn($this->_author,'location');
		$admin_loc_array = explode(',',$admin_locs);
		$issues = $this->getDefinedTable(Store\IssueTable::class)->getDateWise('issue_date',$year,$month,$this->_userloc, $admin_loc_array, $role_flag);
		$issuedStatusId = $this->getStatusIdByName('Issued', 13);
		return new ViewModel( array(
				'title' => "Goods Issue",
				'issues' => $issues,
				'issueObj' => $this->getDefinedTable(Store\IssueTable::class),
				'issue_detailsObj' => $this->getDefinedTable(Store\IssueDetailsTable::class),
				'itemObj' => $this->getDefinedTable(Store\ItemTable::class),
				'locationObj' => $this->getDefinedTable(Administration\LocationTable::class),
				'activityObj' => $this->getDefinedTable(Administration\ActivityTable::class),
				'departmentObj' => $this->getDefinedTable(Administration\DepartmentTable::class),
				'minYear' => $this->getDefinedTable(Store\IssueTable::class)->getMin('issue_date'),
				'data' => $data,
				'employeeObj' => $this->getDefinedTable(Hr\EmployeeTable::class),
				'groupObj' => $this->getDefinedTable(Store\GroupTable::class),
				'issued_status_id' => $issuedStatusId,
		) );
	}

	protected function logIssueActivity($issueId, $remarks)
	{
		$issueStatus = $this->getDefinedTable(Store\IssueTable::class)->getColumn($issueId, 'status');
		if($issueStatus === null || $issueStatus === false || $issueStatus === ''):
			return false;
		endif;
		$activity = array(
			'process' => 11,
			'process_id' => (int)$issueId,
			'status' => $issueStatus,
			'remarks' => $remarks,
			'role' => $this->_login_role,
			'author' => $this->_author,
			'created' => $this->_created,
			'modified' => $this->_modified,
		);
		$activity = $this->_safedataObj->rteSafe($activity);
		return $this->getDefinedTable(Acl\ActivityLogTable::class)->save($activity) > 0;
	}
	
	/**
	 * Add Issue
	 */
	public function addissueAction()
	{
		$this->init();
		
		if($this->getRequest()->isPost()):
			$request= $this->getRequest();
			$form = $request->getPost();
			$item = isset($form['item']) ? $form['item'] : array();
			$from_balance = isset($form['from_balance']) ? $form['from_balance'] : array();
			$quantity = isset($form['quantity']) ? $form['quantity'] : array();
			if(sizeof($item) == 0):
				$this->flashMessenger()->addMessage("error^ Please add at least one item for stock issuance.");
				return $this->redirect()->toRoute('inissue',array('action'=>'addissue'));
			endif;
			for($i = 0; $i < sizeof($item); $i++):
				if(isset($item[$i], $quantity[$i], $from_balance[$i]) && is_numeric($quantity[$i]) && is_numeric($from_balance[$i]) && (float)$quantity[$i] > (float)$from_balance[$i]):
					$this->flashMessenger()->addMessage("error^ Issue quantity must be less than or equal to stock quantity.");
					return $this->redirect()->toRoute('inissue',array('action'=>'addissue'));
				endif;
			endfor;
             
			$location_prefix = $this->getDefinedTable(Administration\LocationTable::class)->getcolumn($form['from_location'],'prefix');
			$date = date('ym',strtotime($form['issue_date']));
			$tmp_INo = $location_prefix."DC".$date;
			$results = $this->getDefinedTable(Store\IssueTable::class)->getMonthlyINO($tmp_INo);
			$dc_no_list = array();
			$prefix_length = strlen($tmp_INo);
			foreach($results as $result):
				$serial_part = substr($result['challan_no'], $prefix_length);
				if($serial_part !== '' && ctype_digit((string)$serial_part)):
					array_push($dc_no_list, (int)$serial_part);
				endif;
			endforeach;
			$next_serial = (sizeof($dc_no_list) > 0) ? (max($dc_no_list) + 1) : 1;
				
			switch(strlen($next_serial)){
				case 1: $next_dc_serial = "000".$next_serial; break;
				case 2: $next_dc_serial = "00".$next_serial;  break;
				case 3: $next_dc_serial = "0".$next_serial;   break;
				default: $next_dc_serial = $next_serial;       break;
			}	
			$challan_no = $tmp_INo.$next_dc_serial;
			$fcb_transport = (isset($form['fcb_transport']) && $form['fcb_transport'] !== '') ? $form['fcb_transport'] : '1';
			$transporter = '';
			if(isset($form['transporter_fcb']) || isset($form['transporter_nonfcb'])):
				$transporter = ($fcb_transport == 1)
					? (isset($form['transporter_fcb']) ? $form['transporter_fcb'] : '')
					: (isset($form['transporter_nonfcb']) ? $form['transporter_nonfcb'] : '');
			endif;
			$issued_to = isset($form['issued_to']) ? (int)$form['issued_to'] : (isset($form['received_by']) ? (int)$form['received_by'] : 0);
			if($issued_to <= 0):
				$this->flashMessenger()->addMessage("error^ Please select Issue To Employee.");
				return $this->redirect()->toRoute('inissue',array('action'=>'addissue'));
			endif;
			$to_location = isset($form['to_location']) ? (int)$form['to_location'] : 0;
			if($to_location <= 0 && $issued_to > 0):
				$to_location = (int)$this->getDefinedTable(Hr\EmployeeTable::class)->getColumn($issued_to, 'location');
			endif;
			$data =array(
					'issue_date'    => $form['issue_date'],
					'challan_no'    => $challan_no,
					'from_location' => $form['from_location'],
					'to_location'   => $to_location,
					'received_by'   => $issued_to,
					'sub_head'      => isset($form['sub_head']) ? $form['sub_head'] : NULL,
					'activity'   => $form['activity'],
					'department'    => isset($form['department']) ? $form['department'] : NULL,
					'party' => isset($form['party']) ? $form['party'] : NULL,
					'total_amount' => $form['payable_amount'],
					'note' => $form['note'],
					'status' => 2,
					'author' =>$this->_author,
					'created' =>$this->_created,
					'modified' =>$this->_modified
			);
			$data = $this->_safedataObj->rteSafe($data);
			//echo "<pre>";print_r($data); exit;
			$result = $this->getDefinedTable(Store\IssueTable::class)->save($data);
			
			if($result>0):
				$item= $form['item'];
				$from_balance = $form['from_balance'];
				$rate= $form['rate'];
				$quantity= $form['quantity'];
				$uom= $form['uom'];
				$amount= $form['amount'];
				$remarks = $form['remarks'];
				for($i=0; $i < sizeof($item); $i++):
					if(isset($item[$i]) && is_numeric($quantity[$i])):
						$issue_detail_data = array(
							'issue' => $result,
							'item' => $item[$i],
							'uom' => $uom[$i],
							'stock_quantity' => isset($from_balance[$i]) ? $from_balance[$i] : 0,
							'quantity' => $quantity[$i],
							'rate' => $rate[$i],
							'amount' => $amount[$i],
							'remarks' => $remarks[$i],
							'author' =>$this->_author,
							'created' =>$this->_created,
							'modified' =>$this->_modified,
						);
						//echo "<pre>";print_r($issue_detail_data); exit;
						$issue_detail_data = $this->_safedataObj->rteSafe($issue_detail_data);
						$this->getDefinedTable(Store\IssueDetailsTable::class)->save($issue_detail_data);
					endif;
				endfor;
				$this->logIssueActivity((int)$result, 'Issue created: '.$challan_no);
				$this->flashMessenger()->addMessage("success^ Successfully added new issue :".$challan_no);
				return $this->redirect()->toRoute('inissue',array('action'=>'viewissue','id'=>$result));
			else:
				$this->flashMessenger()->addMessage("Failed^ Failed to add new issue");
				return $this->redirect()->toRoute('inissue');
			endif;
		endif;
		$admin_locs = $this->getDefinedTable(Acl\UsersTable::class)->getColumn($this->_author,'admin_location');
        $admin_loc_array = explode(',',$admin_locs);
		return new ViewModel( array(
				'title' 		=> "Add Issue",
				'user_location' => $this->_userloc,
				'regionObj'     => $this->getDefinedTable(Administration\RegionTable::class),
				'locationObj' 	=> $this->getDefinedTable(Administration\LocationTable::class),
				'departments'   => $this->getDefinedTable(Administration\DepartmentTable::class)->getAll(),
				'admin_locs' 	=> $this->getDefinedTable(Administration\LocationTable::class)->get(array('l.id'=>$admin_loc_array)),
				'activities'  	=> $this->getDefinedTable(Administration\ActivityTable::class)->getAll(),
				'parties'   	=> $this->getDefinedTable(Accounts\PartyTable::class)->get(array('p.role'=>4)),
				'employee_driver' => $this->getDefinedTable(Hr\EmployeeTable::class)->getEmployee(array('e.position_title'=>array(26,30,34,38,39,25))),
				'subheads'   	=> $this->getDefinedTable(Accounts\SubheadTable::class)->getAll(),
				'user_location' => $this->_userloc,
				'userRoleObj'  => $this->getDefinedTable(Acl\UserroleTable::class),
				'userID' => $this->_author,
		));
	}

	public function getissuedtoemployeeAction()
	{
		$this->init();
		$form = $this->getRequest()->getPost();
		$department = isset($form['department']) ? (int)$form['department'] : 0;
		$employees = ($department > 0)
			? $this->getDefinedTable(Hr\EmployeeTable::class)->get(array('e.department' => $department, 'e.status' => 1))
			: array();

		$options = "<option value=''></option>";
		foreach($employees as $employee):
			$employee_id = isset($employee['id']) ? (int)$employee['id'] : 0;
			if($employee_id <= 0):
				continue;
			endif;
			$employee_location = isset($employee['location_id']) ? $employee['location_id'] : (isset($employee['location']) ? $employee['location'] : '');
			$employee_name = isset($employee['full_name']) ? $employee['full_name'] : $employee_id;
			$employee_designation = isset($employee['designation']) ? $employee['designation'] : (isset($employee['position_title']) ? $employee['position_title'] : '');
			$designation_text = ($employee_designation !== '') ? " (".$employee_designation.")" : '';
			$options .= "<option value='".$employee_id."' data-location='".$employee_location."'>".$employee_name.$designation_text."</option>";
		endforeach;

		echo json_encode(array(
			'employees' => $options,
		));
		exit;
	}

	public function getissuedepartmentAction()
	{
		$this->init();
		$departments = $this->getDefinedTable(Administration\DepartmentTable::class)->getAll();
		$options = "<option value=''></option>";
		foreach($departments as $department):
			if(isset($department['status']) && (int)$department['status'] !== 1):
				continue;
			endif;
			$options .= "<option value='".$department['id']."'>".$department['department']."</option>";
		endforeach;
		echo json_encode(array(
			'departments' => $options,
		));
		exit;
	}
	
	/**
	 * get items according to item group
	 */
	public function getitemgroupAction()
	{
		$this->init();
		$form = $this->getRequest()->getPost();
		
		$item_group_id = isset($form['item_group']) ? (int)$form['item_group'] : 0;
		$source_loc = isset($form['source_loc']) ? (int)$form['source_loc'] : 0;
		
		$items = '';
		$items .="<option value=''></option>";
		$itemOptions = array();
		if($item_group_id > 0):
			$stockItems = $this->getDefinedTable(Stock\ItemTable::class)->get(array('i.item_group' => $item_group_id));
			foreach($stockItems as $stockItem):
				$itemId = isset($stockItem['id']) ? (int)$stockItem['id'] : 0;
				if($itemId <= 0):
					continue;
				endif;
				$label = isset($stockItem['name']) ? $stockItem['name'] : (isset($stockItem['code']) ? $stockItem['code'] : $itemId);
				$itemOptions[$itemId] = $label;
			endforeach;
		elseif($source_loc > 0):
			$sql = "SELECT DISTINCT item_id FROM (
						SELECT a.item AS item_id
						FROM in_asset_dtls ad
						INNER JOIN in_asset a ON a.id = ad.asset
						WHERE ad.location = {$source_loc} AND ad.quantity > 0
						UNION
						SELECT s.item AS item_id
						FROM in_storespare_details sd
						INNER JOIN in_storespare s ON s.id = sd.storespare
						WHERE sd.location = {$source_loc} AND sd.quantity > 0
					) t
					WHERE t.item_id IS NOT NULL AND t.item_id > 0
					ORDER BY item_id";
			$availableRows = $this->_container->get('Laminas\Db\Adapter\Adapter')->query($sql, 'execute')->toArray();
			foreach($availableRows as $availableRow):
				$itemId = isset($availableRow['item_id']) ? (int)$availableRow['item_id'] : 0;
				if($itemId <= 0):
					continue;
				endif;
				$itemName = $this->getDefinedTable(Store\ItemTable::class)->getColumn($itemId, 'name');
				if($itemName === NULL || $itemName === ''):
					$itemName = $this->getDefinedTable(Stock\ItemTable::class)->getColumn($itemId, 'name');
				endif;
				if($itemName === NULL || $itemName === ''):
					$itemName = (string)$itemId;
				endif;
				$itemOptions[$itemId] = $itemName;
			endforeach;
		endif;

		if(sizeof($itemOptions) <= 0):
			$stockItems = $this->getDefinedTable(Stock\ItemTable::class)->getAll();
			foreach($stockItems as $stockItem):
				$itemId = isset($stockItem['id']) ? (int)$stockItem['id'] : 0;
				if($itemId <= 0):
					continue;
				endif;
				$label = isset($stockItem['name']) ? $stockItem['name'] : (isset($stockItem['code']) ? $stockItem['code'] : $itemId);
				$itemOptions[$itemId] = $label;
			endforeach;
		endif;

		foreach($itemOptions as $itemId => $label):
			$items .="<option value='".$itemId."'>".$label."</option>";
		endforeach;
		echo json_encode(array(
			'items' => $items,
		));
		exit;
	}
	
	/**
	 * get details of issue
	 */
	public function getdetailsAction()
	{
		$this->init();		
		echo json_encode(array(
			'assetssp' => "<option value=''></option>",
		));
		exit;
	}
	
	/**
	 * get Stock Balance from batch and moving_items
	 */
	public function getstockbalanceAction()
	{
		$this->init();
		$form = $this->getRequest()->getPost();
		
		$source_loc = isset($form['source_loc']) ? (int)$form['source_loc'] : 0;
		$destination_loc = isset($form['destination_loc']) ? (int)$form['destination_loc'] : 0;
		$item_id = isset($form['item_id']) ? (int)$form['item_id'] : 0;
		
		$itemData = $this->getDefinedTable(Stock\ItemTable::class)->get($item_id);
		$item = array();
		foreach($itemData as $itemRow):
			$item = $itemRow;
		endforeach;
		$item_source = (sizeof($itemData) > 0) ? 'stock' : '';
		$item_group = isset($item['item_group']) ? (int)$item['item_group'] : (isset($form['item_group']) ? (int)$form['item_group'] : 0);
		$st_uom_id = isset($item['st_uom_id']) ? (int)$item['st_uom_id'] : (isset($item['uom']) ? (int)$item['uom'] : 0);
		$item_uoms = ($st_uom_id > 0) ? $this->getDefinedTable(Stock\UomTable::class)->get($st_uom_id) : array();
		$selected_uom = '';
        
        foreach($item_uoms as $item_uom):
			$selected_uom .="<option value='".$item_uom['id']."'>".$item_uom['code']."</option>";
		endforeach;
		
		if($item_source === 'stock'):
			$item_valuation = isset($item['valuation']) ? (int)$item['valuation'] : 1;
			if($item_valuation == 1):
				$movingitemObj = $this->getDefinedTable(Stock\MovingItemTable::class);
				$source_qty = $movingitemObj->getColumn(array('item'=>$item_id,'location'=>$source_loc),'quantity');
				$destination_qty = $movingitemObj->getColumn(array('item'=>$item_id,'location'=>$destination_loc),'quantity');
				$rate = $movingitemObj->getColumn(array('item'=>$item_id,'location'=>$source_loc),'selling_price');
			else:
				$batchObj = $this->getDefinedTable(Stock\BatchTable::class);
				$batchdltObj = $this->getDefinedTable(Stock\BatchDetailsTable::class);
				$batch_id = $batchObj->getMax('id', array('item' => $item_id));
				if($batch_id > 0):
					$source_qty = $batchdltObj->getColumn(array('batch'=>$batch_id,'location'=>$source_loc),'quantity');
					$destination_qty = $batchdltObj->getColumn(array('batch'=>$batch_id,'location'=>$destination_loc),'quantity');
					$rate = $batchdltObj->getColumn(array('batch'=>$batch_id,'location'=>$source_loc),'selling_price');
				else:
					$source_qty = 0;
					$destination_qty = 0;
					$rate = 0;
				endif;
			endif;
			if(!is_numeric($source_qty) || (float)$source_qty <= 0):
				$sourceOpening = $this->getOpeningStockSummary($item_id, $source_loc);
				$source_qty = isset($sourceOpening['quantity']) ? $sourceOpening['quantity'] : $source_qty;
				if(!is_numeric($rate) || (float)$rate <= 0):
					$rate = isset($sourceOpening['rate']) ? $sourceOpening['rate'] : $rate;
				endif;
				if((!is_numeric($source_qty) || (float)$source_qty <= 0) && $item_group > 0 && $source_loc > 0):
					$legacySource = $this->getItemLocationStockSummary($item_group, $item_id, $source_loc);
					$source_qty = isset($legacySource['quantity']) ? $legacySource['quantity'] : $source_qty;
					if(!is_numeric($rate) || (float)$rate <= 0):
						$rate = isset($legacySource['rate']) ? $legacySource['rate'] : $rate;
					endif;
				endif;
			endif;
			if(!is_numeric($destination_qty) || (float)$destination_qty <= 0):
				$destinationOpening = $this->getOpeningStockSummary($item_id, $destination_loc);
				$destination_qty = isset($destinationOpening['quantity']) ? $destinationOpening['quantity'] : $destination_qty;
				if((!is_numeric($destination_qty) || (float)$destination_qty <= 0) && $item_group > 0 && $destination_loc > 0):
					$legacyDestination = $this->getItemLocationStockSummary($item_group, $item_id, $destination_loc);
					$destination_qty = isset($legacyDestination['quantity']) ? $legacyDestination['quantity'] : $destination_qty;
				endif;
			endif;
		else:
			$source_qty = 0;
			$destination_qty = 0;
			$rate = 0;
		endif;
		
		$source_qty = (is_numeric($source_qty))?$source_qty:"0.00";
		$destination_qty = (is_numeric($destination_qty))?$destination_qty:"0.00";
		$rate = (is_numeric($rate))?$rate:"0.00";
		
		echo json_encode(array(
				'source_qty' => $source_qty,
				'destination_qty' => $destination_qty,
				'uom' => $selected_uom,
				'rate' => $rate,
		));
		exit;
	}
	/**
	 * View Goods Dispatch
	 */
	public function viewissueAction()
	{
		$this->init();		
		$params = explode("-", (string) $this->_id);
		//echo "<pre>"; print_r($params); exit;
		if(isset($params[1], $params[2]) && $params[1] == '1' && $params[2] > 0){
			$flag = $this->getDefinedTable(Acl\NotifyTable::class)->getColumn($params['2'], 'flag'); 
			if($flag == "0") {
				$notify = array('id' => $params['2'], 'flag'=>'1');
               	$this->getDefinedTable(Acl\NotifyTable::class)->save($notify); 	
			}				
		}		
		$issue_ID = isset($params[0]) ? $params[0] : 0;
        //echo "<pre>"; print_r($issue_ID); exit;		
		$issuedStatusId = $this->getStatusIdByName('Issued', 13);
		return new ViewModel(array(
				'title' => 'View Goods Dispatch',
				'activityObj' => $this->getDefinedTable(Administration\ActivityTable::class),
				'locationObj' => $this->getDefinedTable(Administration\LocationTable::class),
				'employeeObj' => $this->getDefinedTable(Hr\EmployeeTable::class),
				'usersObj' => $this->getDefinedTable(Acl\UsersTable::class),
				'uomObj' => $this->getDefinedTable(Stock\UomTable::class),
				'assetObj' => $this->getDefinedTable(Store\AssetTable::class),
				'asset_detailsObj' => $this->getDefinedTable(Store\AssetDetailsTable::class),
				'issues' => $this->getDefinedTable(Store\IssueTable::class)->get($issue_ID),
				'issue_detailsObj' => $this->getDefinedTable(Store\IssueDetailsTable::class),	
				'ssp_detailsObj' => $this->getDefinedTable(Store\StoreSpareDetailsTable::class),				
				'itemObj' => $this->getDefinedTable(Store\ItemTable::class),
				'sspObj' => $this->getDefinedTable(Store\StoreSpareTable::class),
				'user_location' => $this->_userloc,
				'userRoleObj'  => $this->getDefinedTable(Acl\UserroleTable::class),
				'transactionObj'  => $this->getDefinedTable(Accounts\TransactionTable::class),
				'subheadObj' => $this->getDefinedTable(Accounts\SubheadTable::class),
				'groupObj'  => $this->getDefinedTable(Store\GroupTable::class),
				'userID' => $this->_author,
				'issued_status_id' => $issuedStatusId,
		));
	}
	
	/**
	 * edit goods dispatch action
	 */
	public function editissueAction()
	{
		$this->init();	
		$issue_ID = $this->_id;
		if($this->getRequest()->isPost()):
			$request= $this->getRequest();
			$form = $request->getPost();
			$transporter = ($form['fcb_transport'] == 1)?$form['transporter_fcb']:$form['transporter_nonfcb'];
			$issued_to = isset($form['issued_to']) ? (int)$form['issued_to'] : 0;
			$to_location = isset($form['to_location']) ? (int)$form['to_location'] : 0;
			if($to_location <= 0 && $issued_to > 0):
				$to_location = (int)$this->getDefinedTable(Hr\EmployeeTable::class)->getColumn($issued_to, 'location');
			endif;
			$data =array(
					'id' => $form['issue_id'],
					'issue_date' => $form['issue_date'],
					'from_location' => $form['from_location'],
					'to_location' => $to_location,
					'received_by' => $issued_to,
					'sub_head' => isset($form['sub_head']) ? $form['sub_head'] : NULL,
					'cost_center' => $form['activity'],
					'department' => isset($form['department']) ? $form['department'] : NULL,
					'fcb_transport' => $form['fcb_transport'],
					'transporter' => $transporter,
					'party' => $form['party'],
					'vehicle_no' => $form['vehicle_no'],
					'total_amount' => $form['payable_amount'],
					'note' => $form['note'],
					'status' => 2,
					'author' =>$this->_author,
					'modified' =>$this->_modified
			);
			$data = $this->_safedataObj->rteSafe($data);
			//echo "<pre>"; print_r($data);exit;
			$result = $this->getDefinedTable(Store\IssueTable::class)->save($data);
			if($result>0):
				
				$details_id   = $form['details_id'];
				$item= $form['item'];
				$from_balance = $form['from_balance'];
				$quantity= $form['quantity'];
				$uom= $form['uom'];
				$rate= $form['rate'];
				$amount= $form['amount'];
				$remarks = $form['remarks'];
				$delete_rows = $this->getDefinedTable(Store\IssueDetailsTable::class)->getNotIn($details_id, array('issue' => $result));
				
				for($i=0; $i < sizeof($item); $i++):
					if(isset($item[$i]) && is_numeric($quantity[$i])):
						$issue_detail_data = array(
							'id' => $details_id[$i],
							'issue' => $result,
							'item' => $item[$i],
							'uom' => $uom[$i],
							'stock_quantity' => isset($from_balance[$i]) ? $from_balance[$i] : 0,
							'quantity' => $quantity[$i],
							'rate' => $rate[$i],
							'amount' => $amount[$i],
							'remarks' => $remarks[$i],
							'author' =>$this->_author,
							'modified' =>$this->_modified,
						);
						$issue_detail_data = $this->_safedataObj->rteSafe($issue_detail_data);
						//echo "<pre>"; print_r($issue_detail_data);exit;
						$this->getDefinedTable(Store\IssueDetailsTable::class)->save($issue_detail_data);
					endif;
				endfor;
				
				//deleting deleted table rows form database table;
				//print_r($delete_rows);exit;
				foreach($delete_rows as $delete_row):
				//echo $delete_row['id'];
					$this->getDefinedTable(Store\IssueDetailsTable::class)->remove($delete_row['id']);
				endforeach;
				
				$challan_no = $this->getDefinedTable(Store\IssueTable::class)->getColumn($form['issue_id'],'challan_no');
				$this->logIssueActivity((int)$form['issue_id'], 'Issue updated: '.$challan_no);
				$this->flashMessenger()->addMessage("success^ Successfully updated challan no. ".$challan_no);
			else:
				$this->flashMessenger()->addMessage("Failed^ Failed to update Issue details");
			endif;
			return $this->redirect()->toRoute('inissue',array('action'=>'viewissue','id'=>$form['issue_id']));
		endif;
		$admin_locs = $this->getDefinedTable(Acl\UsersTable::class)->getColumn($this->_author,'admin_location');
        $admin_loc_array = explode(',',$admin_locs);
		return new ViewModel(array(
				'title' => 'Edit Issue',
				'regionObj'     	=> $this->getDefinedTable(Administration\RegionTable::class),
				'locationObj' 	=> $this->getDefinedTable(Administration\LocationTable::class),
			    'admin_locs' 	=> $this->getDefinedTable(Administration\LocationTable::class)->get(array('l.id'=>$admin_loc_array)),
				'activityObj'  	=> $this->getDefinedTable(Administration\ActivityTable::class),
				'parties'   	=> $this->getDefinedTable(Accounts\PartyTable::class)->get(array('p.role'=>4)),
				'assetObj' => $this->getDefinedTable(Store\AssetTable::class),
				'asset_detailsObj' => $this->getDefinedTable(Store\AssetDetailsTable::class),
				'issues' => $this->getDefinedTable(Store\IssueTable::class)->get($issue_ID),
				'issue_detailsObj' => $this->getDefinedTable(Store\IssueDetailsTable::class),	
				'ssp_detailsObj' => $this->getDefinedTable(Store\StoreSpareDetailsTable::class),				
				'itemObj' => $this->getDefinedTable(Store\ItemTable::class),
				'sspObj' => $this->getDefinedTable(Store\StoreSpareTable::class),
				'employee_driver' => $this->getDefinedTable(Hr\EmployeeTable::class)->getEmployee(array('e.position_title'=>array(25,29,26,30,34,38,39))),
				'userRoleObj'  => $this->getDefinedTable(Acl\UserroleTable::class),
				'userID' => $this->_author,
				'user_location' => $this->_userloc,
				'uomObj' => $this->getDefinedTable(Stock\UomTable::class),
				'departments' => $this->getDefinedTable(Administration\DepartmentTable::class)->getAll(),
				'employeeObj' => $this->getDefinedTable(Hr\EmployeeTable::class),
				'subheads'   	=> $this->getDefinedTable(Accounts\SubheadTable::class)->getAll(),
		));
	}
	
	/**
	 * receive goods Action
	 */
	public function receiveissueAction()
	{
		$this->init();	
		
		if($this->getRequest()->isPost()):
			$request= $this->getRequest();
			$form = $request->getPost();
			$data =array(
					'id' => $form['issue_id'],
					'status' => 10,
					'note' => $form['note'],
					'received_by' => $form['received_by'],
					'received_on' => $form['received_date'],
					'author' 	  => $this->_author,					
					'modified'    => $this->_modified
			);
			$data = $this->_safedataObj->rteSafe($data);
			$this->_connection->beginTransaction(); //***Transaction begins here***//
			$result = $this->getDefinedTable(Store\IssueTable::class)->save($data);
			$challan_no = $this->getDefinedTable(Store\IssueTable::class)->getColumn($form['issue_id'],'challan_no');
			if($result>0):
			/*** Receiving Goods Dispatch ***/
				$item_id         = $form['item'];
				$uom_id          = $form['uom'];
				$details_id  	 = $form['issue_detail_id'];
				$accept_qty      = $form['accept_qty'];
				$sound_qty		 = $form['sound_qty'];
				$demage_qty      = $form['damage_qty'];
				$shortage_qty    = $form['shortage_qty'];
				
				for($i=0; $i < sizeof($details_id); $i++):
				if(isset($details_id[$i])):
					$issue_detail_data = array(
						'id' 			     => $details_id[$i],
						'issue' 		     => $result,
						'accepted_quantity'  => $accept_qty[$i],
						'sound_quantity'	 => $sound_qty[$i],
						'demage_quantity'    => $demage_qty[$i],
						'shortage_quantity'  => $shortage_qty[$i],
						'author' 		     => $this->_author,
						'modified' 		     => $this->_modified,
					);
					$issue_detail_data = $this->_safedataObj->rteSafe($issue_detail_data);
					//echo "<pre>"; print_r($issue_detail_data);
					$this->getDefinedTable(Store\IssueDetailsTable::class)->save($issue_detail_data);
				endif;
				endfor;
				$issueHeader = array();
				$issueHeaderRows = $this->getDefinedTable(Store\IssueTable::class)->get($form['issue_id']);
				foreach($issueHeaderRows as $issueHeaderRow):
					$issueHeader = $issueHeaderRow;
				endforeach;
				$issue_destination = isset($issueHeader['to_location']) ? (int)$issueHeader['to_location'] : 0;
				if($issue_destination <= 0 && isset($issueHeader['received_by']) && (int)$issueHeader['received_by'] > 0):
					$issue_destination = (int)$this->getDefinedTable(Hr\EmployeeTable::class)->getColumn((int)$issueHeader['received_by'], 'location');
				endif;
				$source_location = isset($issueHeader['from_location']) ? (int)$issueHeader['from_location'] : 0;
				$issue_date = isset($issueHeader['issue_date']) ? $issueHeader['issue_date'] : date('Y-m-d');
				$issue_dtls = $this->getDefinedTable(Store\IssueDetailsTable::class)->get(array('issue' => $form['issue_id']));
				foreach($issue_dtls as $issue_dtl):
					//echo "<pre>";print_r($issue_dtl); exit;
					$item_id = $issue_dtl['item_id'];	
					foreach($this->getDefinedTable(Stock\ItemTable::class)->get($item_id) as $itemdtl);
					//echo "<pre>";print_r($itemdtl);
					$item_group = $itemdtl['item_group_id'];
					$item_id    = $itemdtl['item_id'];
					$asssetssp_id    = $itemdtl['asssetssp'];
					if($item_group == 1):
					    $asset_check = $this->getDefinedTable(Store\AssetDetailsTable::class)->get(array('asset'=>$issue_dtl['assetssp'],'location'=>$issue_destination));
						    //echo "<pre>";print_r($asset_check); exit;
							if($asset_check > 0){
								$insert_asset_data = array(
									'id'             => $asset_id,	
									'item'           => $item_id,
									'assetssp'       => $asssetssp_id,
									'location'       => $dispatch_destination,
									'quantity'       => 0,
									'selling_price'  => $selling_price,
									'author'         => $this->_author,
									'created'        => $this->_created,
									'modified'       => $this->_modified
								);
							}else{
								$insert_asset_data = array(
									'item'           => $item_id,
									'uom'            => $batch_uom,
									'assetssp'       => $asssetssp_id,
									'location'       => $dispatch_destination,
									'quantity'       => 0,
									'selling_price'  => $selling_price,
									'author'         => $this->_author,
									'created'        => $this->_created,
									'modified'       => $this->_modified
								);
							}
							//echo "<pre>";print_r($insert_mi_data);exit;
							$insert_asset_Result = $this->getDefinedTable(Store\AssetDetailsTable::class)->save($insert_asset_data);
					else:
					    $check_assetssp = $this->getDefinedTable(Store\StoreSpareDetailsTable::class)->get(array('storespare'=>$issue_dtl['assetssp'],'location'=>$issue_destination));
						if($asset_check > 0){
								$insert_asset_data = array(
									'id'             => $asset_id,	
									'item'           => $item_id,
									'assetssp'       => $asssetssp_id,
									'location'       => $dispatch_destination,
									'quantity'       => 0,
									'selling_price'  => $selling_price,
									'author'         => $this->_author,
									'created'        => $this->_created,
									'modified'       => $this->_modified
								);
							}else{
								$insert_asset_data = array(
									'item'           => $item_id,
									'uom'            => $batch_uom,
									'assetssp'       => $asssetssp_id,
									'location'       => $dispatch_destination,
									'quantity'       => 0,
									'selling_price'  => $selling_price,
									'author'         => $this->_author,
									'created'        => $this->_created,
									'modified'       => $this->_modified
								);
							}
					endif;
				endforeach;
				$this->_connection->commit(); 
				if($insert_asset_Result){
					$this->flashMessenger()->addMessage("success^ Successfully received challan no. ".$challan_no." . And Location Costing added for the Destination Location.");
				}else{
					$this->flashMessenger()->addMessage("success^ Successfully received challan no. ".$challan_no);
				}
			else:
				$this->_connection->rollback();
				$this->flashMessenger()->addMessage("Failed^ Failed to receive issue");
			endif;
			return $this->redirect()->toRoute('inissue',array('action'=>'viewissue','id'=>$form['issue_id']));
		endif;
		
		return new ViewModel(array(
				'title' => 'Receive the Issued Goods',
				'locationObj' 	=> $this->getDefinedTable(Administration\LocationTable::class),
				'activityObj'  	=> $this->getDefinedTable(Administration\ActivityTable::class),
				'issues'		=> $this->getDefinedTable(Store\IssueTable::class)->get($this->_id),
				'issue_detailsObj' => $this->getDefinedTable(Store\IssueDetailsTable::class),
				'assetObj' => $this->getDefinedTable(Store\AssetTable::class),
				'sspObj' => $this->getDefinedTable(Store\StoreSpareTable::class),
				'itemObj'  => $this->getDefinedTable(Store\ItemTable::class), 
				'uomObj'    => $this->getDefinedTable(Stock\UomTable::class),
				'assetdetailsObj' => $this->getDefinedTable(Store\AssetDetailsTable::class),
				'sspdetailsObj' => $this->getDefinedTable(Store\StoreSpareDetailsTable::class),
				'employeeObj' => $this->getDefinedTable(Hr\EmployeeTable::class),
				'userRoleObj'  => $this->getDefinedTable(Acl\UserroleTable::class),
				'userID' => $this->_author,
				'user_location' => $this->_userloc,
				'usersObj' => $this->getDefinedTable(Acl\UsersTable::class),
				'groupObj' => $this->getDefinedTable(Store\GroupTable::class),
		));
	}
	
	/**
	 * pending goods issue action {Issue}
	 */
	public function pendingissueAction()
	{
		$this->init();	
		$issues = $this->getDefinedTable(Store\IssueTable::class)->get($this->_id);
		$isuue_details = $this->getDefinedTable(Store\IssueDetailsTable::class)->get(array('issue'=>$this->_id));
		//echo "<pre>"; print_r($isuue_details); exit;
		foreach($issues as $issue);
		$lessThanQty = array();
		$insufficientRows = array();
		foreach($isuue_details as $isuue_detail):
			$itemId = isset($isuue_detail['item_id']) ? (int)$isuue_detail['item_id'] : (isset($isuue_detail['item']) ? (int)$isuue_detail['item'] : 0);
			$itemData = $this->getIssueItemData($itemId);
			$item_group = isset($itemData['item_group']) ? (int)$itemData['item_group'] : 0;
			$issueQty = isset($isuue_detail['quantity']) ? $isuue_detail['quantity'] : (isset($isuue_detail['issue_qty']) ? $isuue_detail['issue_qty'] : (isset($isuue_detail['issue_quantity']) ? $isuue_detail['issue_quantity'] : 0));
			$issueQty = (is_numeric($issueQty)) ? (float)$issueQty : 0;
			$checkQty = $this->getIssueSourceQuantity($itemId, $item_group, isset($issue['from_location']) ? (int)$issue['from_location'] : 0);
			if($checkQty < $issueQty):
				array_push($lessThanQty,$isuue_detail['id']);
				$uomId = isset($isuue_detail['uom']) && is_numeric($isuue_detail['uom']) ? (int)$isuue_detail['uom'] : 0;
				$uomLabel = ($uomId > 0) ? $this->getDefinedTable(Stock\UomTable::class)->getColumn($uomId, 'code') : (isset($isuue_detail['uom']) ? $isuue_detail['uom'] : '');
				$insufficientRows[] = array(
					'id' => isset($isuue_detail['id']) ? $isuue_detail['id'] : 0,
					'item' => isset($isuue_detail['item']) ? $isuue_detail['item'] : $itemId,
					'uom' => $uomLabel,
					'remaining_qty' => number_format((float)$checkQty, 2, '.', ''),
					'issue_qty' => number_format((float)$issueQty, 2, '.', ''),
				);
			endif;
		endforeach;
		$ViewModel = new ViewModel(array(
				'title' => 'Check Issue Quantity',
				'id' => $this->_id,
				'issues' => $issues,
				'subheads' => $this->getDefinedTable(Accounts\SubheadTable::class)->getAll(),
				'isuuedtls' => $lessThanQty,
				'insufficientRows' => $insufficientRows,
				'assetsspdtlsObj' => $this->getDefinedTable(Store\StoreSpareDetailsTable::class),
				'issuedtlsObj' => $this->getDefinedTable(Store\IssueDetailsTable::class),
				'assetdtlsObj' => $this->getDefinedTable(Store\AssetDetailsTable::class),
				'isuueObj' => $this->getDefinedTable(Store\IssueTable::class),
				'itemObj' => $this->getDefinedTable(Store\ItemTable::class),
		));
		$ViewModel->setTerminal(True);
		return $ViewModel;
	}
	/**
	 *
	 *do dispatch and do store adjustment
	 */
	public function doissueAction()
	{
		$this->init();
		
		$center_location = $this->getDefinedTable(Store\IssueTable::class)->getColumn($this->_id,"from_location");
		$issues = $this->getDefinedTable(Store\IssueTable::class)->get($this->_id);
		foreach($issues as $issue):
		$form = $this->params()->fromPost();
		$selected_sub_head = isset($form['sub_head']) ? (int)$form['sub_head'] : 0;
		$issue_sub_head = ($selected_sub_head > 0) ? $selected_sub_head : (isset($issue['sub_head']) ? (int)$issue['sub_head'] : 0);
		$this->getDefinedTable(Store\IssueTable::class)->save(array(
			'id' => $this->_id,
			'sub_head' => ($issue_sub_head > 0) ? $issue_sub_head : NULL,
			'modified' => $this->_modified,
			'author' => $this->_author,
		));
		$issue_head = 16;
		if($issue_sub_head > 0):
			$selected_sub_heads = $this->getDefinedTable(Accounts\SubheadTable::class)->get($issue_sub_head);
			foreach($selected_sub_heads as $selected_sub_head):
				$issue_head = isset($selected_sub_head['head_id']) ? (int)$selected_sub_head['head_id'] : $issue_head;
			endforeach;
		endif;
		
		$voucher_amount = $issue['total_amount'];
		$issuedVoucherNo = '';
		$issueActivity = isset($issue['cost_center']) ? (int)$issue['cost_center'] : (isset($issue['activity']) ? (int)$issue['activity'] : 0);
		if($issueActivity <= 0):
			$issueActivity = 5;
		endif;
		$creditLocation = isset($issue['to_location']) ? (int)$issue['to_location'] : 0;
		if($creditLocation <= 0):
			$creditLocation = isset($issue['from_location']) ? (int)$issue['from_location'] : 0;
		endif;
		$issue_no = $issue['challan_no'];
			
		$this->_connection->beginTransaction(); //***Transaction begins here***//	
		$transactionId = 0;
		if($center_location > 0)
		{ 
			
			
			$location = $this->getDefinedTable(Administration\LocationTable::class)->getcolumn($center_location, 'prefix');
			$voucherType = '4'; //Journal
			$prefix = $this->getDefinedTable(Accounts\JournalTable::class)->getcolumn($voucherType,'prefix');	 
			$date = date('ym',strtotime($issue['issue_date']));
			$tmp_VCNo = $location.$prefix.$date;
			$results = $this->getDefinedTable(Accounts\TransactionTable::class)->getSerial($tmp_VCNo);
			$pltp_no_list = array();
			foreach($results as $result):
				$current_serial = substr((string)$result['voucher_no'], strlen($tmp_VCNo));
				if(is_numeric($current_serial)):
					array_push($pltp_no_list, (int)$current_serial);
				endif;
			endforeach;
			$next_serial = (sizeof($pltp_no_list) > 0) ? (max($pltp_no_list) + 1) : 1;
				
			switch(strlen($next_serial)){
			case 1: $next_vc_serial = "000".$next_serial; break;
			case 2: $next_vc_serial = "00".$next_serial;  break;
			case 3: $next_vc_serial = "0".$next_serial;   break;
			default: $next_vc_serial = $next_serial;       break;
			}	
			$voucher_no = $tmp_VCNo.$next_vc_serial;
			$issuedVoucherNo = $voucher_no;
			$insert_data_transaction = array(
				'voucher_date' => $issue['issue_date'],
				'voucher_type' => $voucherType,
				'doc_id' => $issue_no,
				'doc_type' => '',
				'voucher_no' => $voucher_no,
				'voucher_amount' => $voucher_amount,
				'remark' => 'Issuance of Stock - '.$issue_no,
				'status' => 4, // committed
				'author' =>$this->_author,
				'created' =>$this->_created,
				'modified' =>$this->_modified,
			); 
            //echo "<pre>";print_r($insert_data_transaction); exit;						
			$insert_data_transaction = $this->_safedataObj->rteSafe($insert_data_transaction);
			$result = $this->getDefinedTable(Accounts\TransactionTable::class)->save($insert_data_transaction);
			$transactionId = (int)$result;
			if($transactionId <= 0):
				$this->_connection->rollback();
				$this->flashMessenger()->addMessage("error^ Failed to create transaction for issue.");
				return $this->redirect()->toRoute('inissue', array('action' => 'viewissue', 'id' => $this->_id));
			endif;
			$bank_ref_type = '';
			$cheque_no = '';
			//echo $result; echo 
			if($result > 0 && $issue['item_group'] == 1 )// Assets
			{
					$issueDtls = $this->getDefinedTable(Store\IssueDetailsTable::class)->get(array('issue'=>$this->_id));
					if(sizeof($issueDtls) <= 0):
						$this->_connection->rollback();
						$this->flashMessenger()->addMessage("error^ Failed to issue asset: issue details not found.");
						return $this->redirect()->toRoute('inissue', array('action' => 'viewissue', 'id' => $this->_id));
					endif;
					//echo "<pre>";print_r($issueDtls); exit;			
					foreach($issueDtls as $issueDtl):
						$assetSspId = isset($issueDtl['asset']) ? (int)$issueDtl['asset'] : (isset($issueDtl['asset_id']) ? (int)$issueDtl['asset_id'] : 0);
						$itemId = isset($issueDtl['item_id']) ? (int)$issueDtl['item_id'] : (isset($issueDtl['item']) ? (int)$issueDtl['item'] : 0);
						if($itemId <= 0 && $assetSspId > 0):
							$itemId = (int)$this->getDefinedTable(Store\AssetTable::class)->getColumn($assetSspId, 'item');
						endif;
						$items = ($itemId > 0) ? $this->getDefinedTable(Store\ItemTable::class)->get($itemId) : array();
						if((!is_array($items) || sizeof($items) <= 0) && $itemId > 0):
							$items = $this->getDefinedTable(Stock\ItemTable::class)->get($itemId);
						endif;
						if((!is_array($items) || sizeof($items) <= 0) && isset($issueDtl['item']) && !is_numeric($issueDtl['item'])):
							$items = $this->getDefinedTable(Stock\ItemTable::class)->get(array('i.name' => trim((string)$issueDtl['item'])));
						endif;
						$item = array();
						foreach($items  as $row):
							$item = $row;
							break;
						endforeach;
						if(!isset($item['id'])):
							$this->_connection->rollback();
							$detailId = isset($issueDtl['id']) ? $issueDtl['id'] : 0;
							$this->flashMessenger()->addMessage("error^ Failed to issue: item master link missing for issue detail #".$detailId.". Please update item in issue details and try again.");
							return $this->redirect()->toRoute('inissue', array('action' => 'viewissue', 'id' => $this->_id));
						endif;
						$issueQty = isset($issueDtl['quantity']) ? (float)$issueDtl['quantity'] : (isset($issueDtl['issue_qty']) ? (float)$issueDtl['issue_qty'] : (isset($issueDtl['issue_quantity']) ? (float)$issueDtl['issue_quantity'] : 0));
						$issueRate = isset($issueDtl['rate']) ? (float)$issueDtl['rate'] : 0;
						$issueAmount = isset($issueDtl['amount']) ? (float)$issueDtl['amount'] : 0;
						if($issueAmount <= 0 && $issueQty > 0 && $issueRate > 0):
							$issueAmount = $issueQty * $issueRate;
						endif;
						if($issueAmount <= 0):
							$issueAmount = (float)$voucher_amount;
						endif;
						$head = ($item['item_sub_group_id'] == 5) ? 3 : (($item['item_sub_group_id'] == 6) ? 5 : $issue_head);
						$debitSubhead = $this->resolveLinkedDebitSubhead($head, $item);
						if(!isset($debitSubhead['id']) || !isset($debitSubhead['head_id'])):
							$this->_connection->rollback();
							$this->flashMessenger()->addMessage("error^ Failed to create debit transaction: expense mapping not found on item group.");
							return $this->redirect()->toRoute('inissue', array('action' => 'viewissue', 'id' => $this->_id));
						endif;
						$debitTdetailData = array(
							'transaction'  => $transactionId,
							'location'     => $issue['from_location'],
							'activity'     => $issueActivity,
							'head'         => $debitSubhead['head_id'],
							'sub_head'     => $debitSubhead['id'],
							'bank_ref_type'=> $bank_ref_type,
							'cheque_no'    => '',
							'debit'        => $issueAmount,
							'credit'       => 0.00,
							'ref_no'       => '',
							'type'         => '2',
							'author'       => $this->_author,
							'created'      => $this->_created,
							'modified'     => $this->_modified,
						);
						$debitTdetailData = $this->_safedataObj->rteSafe($debitTdetailData);
						$resultDebitDetail = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->save($debitTdetailData);
						if($resultDebitDetail <= 0):
							$this->_connection->rollback();
							$this->flashMessenger()->addMessage("error^ Failed to save the debit transaction details.");
							return $this->redirect()->toRoute('inissue', array('action' => 'viewissue', 'id' => $this->_id));
						endif;

						$assetdtls = ($assetSspId > 0)
							? $this->getDefinedTable(Store\AssetDetailsTable::class)->get(array('asset'=>$assetSspId,'location'=>$issue['from_location']))
							: array();
						$assetdtl = array();
						foreach($assetdtls as $row):
							$assetdtl = $row;
							break;
						endforeach;

						if(!isset($assetdtl['id'])):
							$resultLegacyAssetUpdate = $this->reduceIssueSourceStock($itemId, 1, isset($issue['from_location']) ? (int)$issue['from_location'] : 0, $issueQty, 0);
							if(!$resultLegacyAssetUpdate):
								$this->_connection->rollback();
								$this->flashMessenger()->addMessage("error^ Failed to issue asset: source stock details not found.");
								return $this->redirect()->toRoute('inissue', array('action' => 'viewissue', 'id' => $this->_id));
							endif;
							$sub_hR = $this->resolveLinkedCreditSubhead($head, $item);
							if(!isset($sub_hR['id']) || !isset($sub_hR['head_id'])):
								$this->_connection->rollback();
								$this->flashMessenger()->addMessage("error^ Failed to create credit transaction: inventory mapping not found on item group.");
								return $this->redirect()->toRoute('inissue', array('action' => 'viewissue', 'id' => $this->_id));
							endif;
							$tdetailsdata1 = array(
								'transaction' 	=> $transactionId,
								'location' 		=> $creditLocation,
								'activity' 		=> $issueActivity,
								'head' 			=> $sub_hR['head_id'],
								'sub_head' 		=> $sub_hR['id'],
								'bank_ref_type' => $bank_ref_type,
								'cheque_no' 	=> $cheque_no,
								'debit' 		=> 0.00,
								'credit' 		=> $issueAmount,
								'ref_no'		=> '',
								'type' 			=> '2',
								'author' 		=> $this->_author,
								'created' 		=> $this->_created,
								'modified' 		=> $this->_modified,
							);
							$tdetailsdata1 = $this->_safedataObj->rteSafe($tdetailsdata1);
							$resultTdetailsdata1 = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->save($tdetailsdata1);
							if($resultTdetailsdata1 <= 0):
								$this->_connection->rollback();
								$this->flashMessenger()->addMessage("error^ Failed to save the credit transaction details.");
								return $this->redirect()->toRoute('inissue', array('action' => 'viewissue', 'id' => $this->_id));
							endif;
							continue;
						endif;

						$assets = $this->getDefinedTable(Store\AssetTable::class)->get(array('a.id'=>$assetdtl['asset']));
						$asset = array();
						foreach($assets  as $row):
							$asset = $row;
							break;
						endforeach;
						if(!isset($asset['id'])):
							$this->_connection->rollback();
							$this->flashMessenger()->addMessage("error^ Failed to issue asset: asset master details not found.");
							return $this->redirect()->toRoute('inissue', array('action' => 'viewissue', 'id' => $this->_id));
						endif;
						$remaining_qty = (float)$assetdtl['quantity'] - $issueQty;				
						$update_assetdtls_data = array(
							'id'	   => $assetdtl['id'],
							'quantity' => $remaining_qty,
							'author'   => $this->_author,
							'created'  => $this->_modified,
						);
						$update_assetdtls_data   = $this->_safedataObj->rteSafe($update_assetdtls_data);
						//echo "<pre>";print_r($update_assetdtls_data); exit;			
						$resultAssetdtls = $this->getDefinedTable(Store\AssetDetailsTable::class)->save($update_assetdtls_data);						
						if($resultAssetdtls > 0 )
						{	
							$insert_asset_data = array(
								'name' => $item['name'],
								'code' => $asset['asset'],
								'fund' => '13',
								'purchase_date' =>$asset['asset_date'],
								'asset_value' => $assetdtl['rate'],
								'depreciation' =>'1',
								'rate' => $assetdtl['rate'],
								'method' => '1', 
								'cumulative' =>'',
								'activity' =>5, 
								'location' =>$issue['to_location'],
								'depreciation_date' => date('Y-m-d'), 
								'author' =>$this->_author,
								'created' =>$this->_created,
								'modified' =>$this->_modified,
							); 
							//echo "<pre>";print_r($insert_asset_data); exit;						
							$insert_asset_data = $this->_safedataObj->rteSafe($insert_asset_data);
							$resultAsset = $this->getDefinedTable(Accounts\AssetsTable::class)->save($insert_asset_data);
							if($resultAsset > 0)
							{
								$insert_subhead_data = array(
									'head'   => $head,
									'type'   => 1,
									'ref_id' =>$item['item_sub_group_id'],
									'code'   =>$item['name']." - ".$asset['asset'],
									'name'   => $item['name']." - ".$asset['asset'],
									'author' =>$this->_author,
									'created' =>$this->_created,
									'modified' =>$this->_modified,
								); 
								$insert_subhead_data = $this->_safedataObj->rteSafe($insert_subhead_data);
								//echo "<pre>";print_r($insert_subhead_data); exit;						
								$resultSubhead = $this->getDefinedTable(Accounts\SubheadTable::class)->save($insert_subhead_data);
								if($resultSubhead > 0)
								{
									$issueCreditAmount = isset($issueDtl['amount']) ? (float)$issueDtl['amount'] : 0;
									if($issueCreditAmount <= 0):
										$tmpQty = isset($issueDtl['quantity']) ? (float)$issueDtl['quantity'] : (isset($issueDtl['issue_qty']) ? (float)$issueDtl['issue_qty'] : (isset($issueDtl['issue_quantity']) ? (float)$issueDtl['issue_quantity'] : 0));
										$tmpRate = isset($issueDtl['rate']) ? (float)$issueDtl['rate'] : 0;
										if($tmpQty > 0 && $tmpRate > 0):
											$issueCreditAmount = $tmpQty * $tmpRate;
										endif;
									endif;
									if($issueCreditAmount <= 0):
										$issueCreditAmount = (float)$voucher_amount;
									endif;
									$tdetailsdata1 = array(
										'transaction' 	=> $transactionId,
										'location' 		=> $creditLocation,
										'activity' 		=> $issueActivity,
										'head' 			=> $head,
										'sub_head' 		=> $resultSubhead,
										'bank_ref_type' => $bank_ref_type,
										'cheque_no' 	=> $cheque_no,
										'debit' 		=> 0.00,
										'credit' 		=> $issueCreditAmount,
										'ref_no'		=> '',
										'type' 			=> '2', //System Generated
										'author' 		=>$this->_author,
										'created' 		=>$this->_created,
										'modified' 		=>$this->_modified,
									);
									$tdetailsdata1 = $this->_safedataObj->rteSafe($tdetailsdata1);
									$resultTdetailsdata1 = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->save($tdetailsdata1);
									if($resultTdetailsdata1 <= 0){
										$this->_connection->rollback(); // rollback transaction over failure
										$this->flashMessenger()->addMessage("error^ Failed to save the data in transaction details table. Please check ");			
										return $this->redirect()->toRoute('inissue', array('action' => 'viewissue', 'id' => $this->_id));	
									}
								}else{
								$this->_connection->rollback(); // rollback transaction over failure
								$this->flashMessenger()->addMessage("error^ Failed to save the data in transaction details table and mapping is not done . Please check ");			
								return $this->redirect()->toRoute('inissue', array('action' => 'viewissue', 'id' => $this->_id));	
								}
							}else{
							$this->_connection->rollback(); // rollback transaction over failure
							$this->flashMessenger()->addMessage("error^ Failed to save the data in transaction details table and mapping is not done . Please check ");			
							return $this->redirect()->toRoute('inissue', array('action' => 'viewissue', 'id' => $this->_id));	
							}
						}else{
						$this->_connection->rollback(); // rollback transaction over failure
						$this->flashMessenger()->addMessage("error^ Failed to save the data in assets details table. Please check ");			
						return $this->redirect()->toRoute('inissue', array('action' => 'viewissue', 'id' => $this->_id));
						}
				    endforeach; 									

			}
			
			else //Store and Spare
			
			{ 
				$issueDtls = $this->getDefinedTable(Store\IssueDetailsTable::class)->get(array('issue'=>$this->_id));
				$resultSspdtls = 1;
				foreach($issueDtls as $issueDtl):
					$itemId = isset($issueDtl['item_id']) ? (int)$issueDtl['item_id'] : (isset($issueDtl['item']) ? (int)$issueDtl['item'] : 0);
					if($itemId <= 0 && isset($issueDtl['item']) && !is_numeric($issueDtl['item'])):
						$itemRows = $this->getDefinedTable(Stock\ItemTable::class)->get(array('i.name' => trim((string)$issueDtl['item'])));
						foreach($itemRows as $itemRow):
							$itemId = isset($itemRow['id']) ? (int)$itemRow['id'] : 0;
							if($itemId > 0):
								break;
							endif;
						endforeach;
					endif;
					if($itemId <= 0):
						$this->_connection->rollback();
						$this->flashMessenger()->addMessage("error^ Failed to issue store/spare: item details not found.");
						return $this->redirect()->toRoute('inissue', array('action' => 'viewissue', 'id' => $this->_id));
					endif;

					$itemData = $this->getIssueItemData($itemId);
					$itemGroup = $this->getItemGroupIdFromItem($itemData);
					if($itemGroup <= 0):
						$itemGroup = isset($issue['item_group']) ? (int)$issue['item_group'] : 2;
					endif;

					$issueQty = isset($issueDtl['quantity']) ? $issueDtl['quantity'] : (isset($issueDtl['issue_qty']) ? $issueDtl['issue_qty'] : (isset($issueDtl['issue_quantity']) ? $issueDtl['issue_quantity'] : 0));
					$issueQty = (is_numeric($issueQty)) ? (float)$issueQty : 0;
					$legacyAssetSspId = 0;
					$resultSspdtls = $this->reduceIssueSourceStock($itemId, $itemGroup, isset($issue['from_location']) ? (int)$issue['from_location'] : 0, $issueQty, $legacyAssetSspId);
					if(!$resultSspdtls):
						$this->_connection->rollback();
						$this->flashMessenger()->addMessage("error^ Failed to update source stock details. Source stock record not found.");
						return $this->redirect()->toRoute('inissue', array('action' => 'viewissue', 'id' => $this->_id));
					endif;
				endforeach; 
				if($resultSspdtls > 0 )
				{
					$creditRowsInserted = 0;
					$creditIssueDtls = $this->getDefinedTable(Store\IssueDetailsTable::class)->get(array('issue'=>$this->_id));
					$aggregatedIssueRows = array();
					foreach($creditIssueDtls as $creditIssueDtl):
						$creditItemId = isset($creditIssueDtl['item_id']) ? (int)$creditIssueDtl['item_id'] : (isset($creditIssueDtl['item']) ? (int)$creditIssueDtl['item'] : 0);
						$creditItem = $this->getIssueItemData($creditItemId);
						if(!isset($creditItem['id'])):
							$this->_connection->rollback();
							$this->flashMessenger()->addMessage("error^ Failed to create credit transaction: item details not found.");
							return $this->redirect()->toRoute('inissue', array('action' => 'viewissue', 'id' => $this->_id));
						endif;
						$creditQty = isset($creditIssueDtl['quantity']) ? (float)$creditIssueDtl['quantity'] : (isset($creditIssueDtl['issue_qty']) ? (float)$creditIssueDtl['issue_qty'] : (isset($creditIssueDtl['issue_quantity']) ? (float)$creditIssueDtl['issue_quantity'] : 0));
						$creditRate = isset($creditIssueDtl['rate']) ? (float)$creditIssueDtl['rate'] : 0;
						$creditAmount = isset($creditIssueDtl['amount']) ? (float)$creditIssueDtl['amount'] : 0;
						if($creditAmount <= 0 && $creditQty > 0 && $creditRate > 0):
							$creditAmount = $creditQty * $creditRate;
						endif;
						if($creditAmount <= 0):
							$creditAmount = (float)$voucher_amount;
						endif;
						$itemKey = (string)$creditItemId;
						if(!isset($aggregatedIssueRows[$itemKey])):
							$aggregatedIssueRows[$itemKey] = array(
								'item' => $creditItem,
								'amount' => 0,
							);
						endif;
						$aggregatedIssueRows[$itemKey]['amount'] += $creditAmount;
					endforeach;
					$groupedDebitEntries = array();
					$groupedCreditEntries = array();
					foreach($aggregatedIssueRows as $aggregatedIssueRow):
						$creditItem = $aggregatedIssueRow['item'];
						$creditAmount = (float)$aggregatedIssueRow['amount'];
						if($creditAmount <= 0):
							continue;
						endif;
						$debitSubhead = $this->resolveLinkedDebitSubhead($issue_head, $creditItem);
						if(!isset($debitSubhead['id']) || !isset($debitSubhead['head_id'])):
							$this->_connection->rollback();
							$this->flashMessenger()->addMessage("error^ Failed to create debit transaction: expense mapping not found on item group.");
							return $this->redirect()->toRoute('inissue', array('action' => 'viewissue', 'id' => $this->_id));
						endif;
						$debitKey = implode('|', array((int)$issue['from_location'], (int)$issueActivity, (int)$debitSubhead['head_id'], (int)$debitSubhead['id']));
						if(!isset($groupedDebitEntries[$debitKey])):
							$groupedDebitEntries[$debitKey] = array(
								'transaction'  => $transactionId,
								'location'     => $issue['from_location'],
								'activity'     => $issueActivity,
								'head'         => $debitSubhead['head_id'],
								'sub_head'     => $debitSubhead['id'],
								'bank_ref_type'=> $bank_ref_type,
								'cheque_no'    => '',
								'debit'        => 0.00,
								'credit'       => 0.00,
								'ref_no'       => '',
								'type'         => '2',
								'author'       => $this->_author,
								'created'      => $this->_created,
								'modified'     => $this->_modified,
							);
						endif;
						$groupedDebitEntries[$debitKey]['debit'] += $creditAmount;
						$creditSubhead = $this->resolveLinkedCreditSubhead($issue_head, $creditItem);
						if(!isset($creditSubhead['id']) || !isset($creditSubhead['head_id'])):
							$this->_connection->rollback();
							$this->flashMessenger()->addMessage("error^ Failed to create credit transaction: inventory mapping not found on item group.");
							return $this->redirect()->toRoute('inissue', array('action' => 'viewissue', 'id' => $this->_id));
						endif;
						$creditKey = implode('|', array((int)$creditLocation, (int)$issueActivity, (int)$creditSubhead['head_id'], (int)$creditSubhead['id']));
						if(!isset($groupedCreditEntries[$creditKey])):
							$groupedCreditEntries[$creditKey] = array(
								'transaction'  => $transactionId,
								'location'     => $creditLocation,
								'activity'     => $issueActivity,
								'head'         => $creditSubhead['head_id'],
								'sub_head'     => $creditSubhead['id'],
								'bank_ref_type'=> $bank_ref_type,
								'cheque_no'    => '',
								'debit'        => 0.00,
								'credit'       => 0.00,
								'ref_no'       => '',
								'type'         => '2',
								'author'       => $this->_author,
								'created'      => $this->_created,
								'modified'     => $this->_modified,
							);
						endif;
						$groupedCreditEntries[$creditKey]['credit'] += $creditAmount;
					endforeach;

					foreach($groupedDebitEntries as $debitEntry):
						$debitEntry = $this->_safedataObj->rteSafe($debitEntry);
						$resultDebitDetail = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->save($debitEntry);
						if($resultDebitDetail <= 0):
							$this->_connection->rollback();
							$this->flashMessenger()->addMessage("error^ Failed to save the debit transaction details.");
							return $this->redirect()->toRoute('inissue', array('action' => 'viewissue', 'id' => $this->_id));
						endif;
					endforeach;

					foreach($groupedCreditEntries as $creditEntry):
						$creditEntry = $this->_safedataObj->rteSafe($creditEntry);
						$resultCreditDetail = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->save($creditEntry);
						if($resultCreditDetail <= 0):
							$this->_connection->rollback();
							$this->flashMessenger()->addMessage("error^ Failed to save the credit transaction details.");
							return $this->redirect()->toRoute('inissue', array('action' => 'viewissue', 'id' => $this->_id));
						endif;
						$creditRowsInserted++;
					endforeach;
					if($creditRowsInserted <= 0):
						$this->_connection->rollback();
						$this->flashMessenger()->addMessage("error^ Failed to create credit transaction row.");
						return $this->redirect()->toRoute('inissue', array('action' => 'viewissue', 'id' => $this->_id));
					endif;
                }else{		
				$this->_connection->rollback(); // rollback transaction over failure
				$this->flashMessenger()->addMessage("error^ Failed to save the data in store and spare details table.");			
				return $this->redirect()->toRoute('inissue', array('action' => 'viewissue', 'id' => $this->_id));
				}				
			}
	    }
		
		else // location is not equal to 7
		
		{
			$issueDtls = $this->getDefinedTable(Store\IssueDetailsTable::class)->get(array('issue'=>$this->_id));
		    foreach($issueDtls as $issueDtl):
			$itemId = isset($issueDtl['item_id']) ? (int)$issueDtl['item_id'] : (isset($issueDtl['item']) ? (int)$issueDtl['item'] : 0);
			$itemData = $this->getIssueItemData($itemId);
			$itemGroup = isset($itemData['item_group']) ? (int)$itemData['item_group'] : 0;
			$assetSspId = 0;
			$issueQty = isset($issueDtl['quantity']) ? $issueDtl['quantity'] : (isset($issueDtl['issue_qty']) ? $issueDtl['issue_qty'] : (isset($issueDtl['issue_quantity']) ? $issueDtl['issue_quantity'] : 0));
			$issueQty = (is_numeric($issueQty)) ? (float)$issueQty : 0;
			$result1 = $this->reduceIssueSourceStock($itemId, $itemGroup, isset($issue['from_location']) ? (int)$issue['from_location'] : 0, $issueQty, $assetSspId);
			if(!$result1):
				$this->_connection->rollback();
				$this->flashMessenger()->addMessage("error^ Failed to update source stock details. Source stock record not found.");
				return $this->redirect()->toRoute('inissue', array('action' => 'viewissue', 'id' => $this->_id));
			endif;
			endforeach;
		}
		// Updating the status and transaction id in the issue table
		$data = array(
				'id'			=> $this->_id,
				'transaction_id'=> $transactionId,
				'status' 		=> 2,
				'author'	    => $this->_author,
				'modified'      => $this->_modified,
		);
		$data   = $this->_safedataObj->rteSafe($data);				
		$result = $this->getDefinedTable(Store\IssueTable::class)->save($data);
		$notification_data = array(
			'route'         => 'inissue',
			'action'        => 'viewissue',
			'key' 		    => $this->_id,
			'description'   => 'dispatch of Goods',
			'author'	    => $this->_author,
			'created'       => $this->_created,
			'modified'      => $this->_modified,   
		);
		$notificationResult = $this->getDefinedTable(Acl\NotificationTable::class)->save($notification_data);
		//echo "<pre>";print_r($notification_data); exit;			
		if($notificationResult > 0 ){
			/*Get users under destination location with sub role Depoy Manager*/
			$sourceLocation = isset($issue['to_location']) ? (int)$issue['to_location'] : 0;
			if($sourceLocation <= 0 && isset($issue['received_by']) && (int)$issue['received_by'] > 0):
				$sourceLocation = (int)$this->getDefinedTable(Hr\EmployeeTable::class)->getColumn((int)$issue['received_by'], 'location');
			endif;
			$depoyManagers = $this->getDefinedTable(Acl\UserroleTable::class)->get(array('subrole'=>'9','21'));
			foreach($depoyManagers as $row):
				$user_location_id = $this->getDefinedTable(Acl\UsersTable::class)->getColumn($row['user'], 'location');
				if($user_location_id == $sourceLocation ):
					$notify_data = array(
						'notification' => $notificationResult,
						'user'    	   => $row['user'],
						'flag'    	 => '0',
						'desc'    	 => 'Goods have been dispatched to your location',
						'author'	 => $this->_author,
						'created'    => $this->_created,
						'modified'   => $this->_modified,  
					);
					$notifyResult = $this->getDefinedTable(Acl\NotifyTable::class)->save($notify_data);
				//echo "<pre>";print_r($notify_data); exit;			
				endif;
			endforeach;
		}
		endforeach;
		$this->_connection->commit(); // commit transaction over success
		if($issuedVoucherNo !== ''):
			$this->flashMessenger()->addMessage("success^ Successfully issued ".$issue_no.". Transaction Voucher No.: ".$issuedVoucherNo);
		else:
			$this->flashMessenger()->addMessage("success^ Successfully issued ".$issue_no.".");
		endif;
		return $this->redirect()->toRoute('inissue', array('action' => 'viewissue', 'id' => $this->_id));
	}
	
	/**
	 * confirm dispatch goods received
	 */
	public function confirmreceiveAction()
	{
		$this->init();
		
		$issue_id = $this->_id;
		
		$issues = $this->getDefinedTable(Store\IssueTable::class)->get($issue_id);
		foreach($issues as $issue):
		//echo "<pre>";print_r($issue); exit;
		if($issue['party'] != '323'):
			/*** Generate Transport No ***/
			$location_prefix = $this->getDefinedTable(Administration\LocationTable::class)->getcolumn($issue['to_location'],'prefix');
			$date = date('ym',strtotime($this->_created));
			$tmp_SerialNo = $location_prefix."TC".$date;
			$results = $this->getDefinedTable(Store\TransportChargeTable::class)->getMonthlySerialNo($tmp_SerialNo);
			
			if(sizeof($results) <= 0):
				$next_serial = 1;
			else:
				$serial_no_list = array();
				foreach($results as $result):
					array_push($serial_no_list, substr($result['transport_no'], 8));
				endforeach;
				$next_serial = max($serial_no_list) + 1;
			endif;
			switch(strlen($next_serial)){
				case 1: $next_serial_no = "000".$next_serial; break;
				case 2: $next_serial_no = "00".$next_serial;  break;
				case 3: $next_serial_no = "0".$next_serial;   break;
				default: $next_serial_no = $next_serial;      break;
			}
			
			$transport_no = $tmp_SerialNo.$next_serial_no;
			/****** Transit Loss Details *******/
			$issue_details = $this->getDefinedTable(Store\IssueDetailsTable::class)->get(array('d.issue'=>$issue_id));
			//echo "<pre>";print_r($issue_details); exit;
			$issue_date = $this->getDefinedTable(Store\IssueTable::class)->getColumn($issue_id,'issue_date');
			//Sum of Dispatch Quantities
			foreach($issue_details as $issue_detail):
			    $item_group = $this->getDefinedTable(Store\ItemTable::class)->getColumn($issue_detail['item_id'],'item_group');
			    $total_quantity = 0;
				if($issue_detail['shortage_quantity'] > 0):
					$amount = $issue_detail['shortage_quantity'] * $issue_detail['rate'];
					$transit_loss = array(
							'issue' => $issue_id,
							'item_group' => $item_group,
							'item' => $issue_detail['item_id'],
							'assetssp' => $issue_detail['assetssp'],
							'uom' => $issue_detail['uom'],
							'qty_loss' => $issue_detail['shortage_quantity'],
							'rate' => $issue_detail['rate'],
							'amount' => $amount,
							'author' => $this->_author,
							'created' => $this->_created,
							'modified' => $this->_modified					
					);
					$total_amount += $amount;
					$transit_loss = $this->_safedataObj->rteSafe($transit_loss);
					//echo "<pre>"; print_r($transit_loss); exit;
					$transit_result = $this->getDefinedTable(Store\TransitLossTable::class)->save($transit_loss); 
				endif;
				$total_quantity += $issue_detail['shortage_quantity'] ;
			endforeach;
			
			/****** Transport Charge Details *******/
			
            $trip_id = $this->getDefinedTable(Stock\TripTable::class)->getColumn(array('status' => 1),'id');
			$tripsdtls = $this->getDefinedTable(Stock\TripDtlsTable::class)->get(array('trip' => $trip_id,'source_location'=>$issue['from_location'],'destination_location'=>$issue['to_location']));
			foreach($tripsdtls as $trip);
			//echo"<pre>"; print_r($trip);  exit;
			
			$check = $trip['hill_distance'] + $trip['plain_distance'];
			if($check > 0):
				$hill_transp_charge = $trip['hill_distance'] * $trip['hill_rate'] * $total_quantity;
				$plain_transp_charge = $trip['plain_distance'] * $trip['plain_rate'] * $total_quantity;
				$total_charge = $hill_transp_charge + $plain_transp_charge;
			else:
				$tripsrev = $this->getDefinedTable(Stock\TripDtlsTable::class)->get(array('trip' => $trip_id,'source_location'=>$issue['to_location'],'destination_location'=>$issue['from_location']));
				foreach($tripsrev as $trip);
				
				$hill_transp_charge = $trip['hill_distance'] * $trip['hill_rate'] * $total_quantity;
				$plain_transp_charge = $trip['plain_distance'] * $trip['plain_rate'] * $total_quantity;
				$total_charge = $hill_transp_charge + $plain_transp_charge;
			endif;
			
			$transp_charge = $total_charge - $total_amount;
			
			$data = array(
					'transport_no' => $transport_no,
					'transport_date' => date('Y-m-d'),
					'issue' => $issue_id,
					'location' => $issue['to_location'],
					'item_group' => $issue['item_group'],
					'activity' => $issue['cost_center'],
					'transporter' => $issue['party'],
					'source_location' => $issue['from_location'],
					'destination_location' => $issue['to_location'],
					'hill_distance' => $trip['hill_distance'],
					'hill_rate' => $trip['hill_rate'],
					'plain_distance' => $trip['plain_distance'],
					'plain_rate' => $trip['plain_rate'],
					'qty' => $total_quantity,
					'transportation_charge' => $transp_charge,
					'status' => 1,
					'author' => $this->_author,
					'created' => $this->_created,
					'modified' => $this->_modified
			);
			
			$data = $this->_safedataObj->rteSafe($data);
			//echo "<pre>"; print_r($data); exit;
			$result = $this->getDefinedTable('Store\TransportChargeTable')->save($data);
		endif;//endof fcb_transport == 0
		
		/*** Change in Asset Details Table/Store Spare Details Table and Change the Status in Issue Table ***/
		$issue_details = $this->getDefinedTable(Store\IssueDetailsTable::class)->get(array('issue'=>$this->_id));
		//echo "<pre>";print_r($issue_details); exit;
		
		if($issue['received_on'] > 0):
			foreach($issue_details as $detail):
				$item_group = $this->getDefinedTable(Store\ItemTable::class)->getColumn($detail['item_id'],'item_group');
				if($item_group == 1){ //Asset
					$assetdtls = $this->getDefinedTable(Store\AssetDetailsTable::class)->get(array('asset'=>$detail['assetssp'],'location'=>$issue['to_location']));
					//echo "<pre>"; print_r($assetdtls); exit;
					foreach($assetdtls as $assetdtl);
					if(sizeof($assetdtl) > 0 )
					{
						$remaining_qty = $assetdtl['quantity'] + $detail['accepted_quantity'];
						$data1 = array(
							'id'	=> $assetdtl['id'],
							'quantity' => $remaining_qty,
							'author' => $this->_author,
							'created' => $this->_modified,
						);
					}
					else
					{
						$data1 = array(
							'quantity' => $detail['accepted_quantity'],
							'location' => $issue['to_location'],
							'asset'    => $detail['assetssp'],
							'rate'     => $detail['rate'],
                                                        'amount'   =>$detail['amount'],
							'author'   => $this->_author,
							'created'  => $this->created,
							'modified'  => $this->_modified,
						);	
					}
					$data1   = $this->_safedataObj->rteSafe($data1);
					//echo "<pre>"; print_r($data1); exit;
					$result1 = $this->getDefinedTable(Store\AssetDetailsTable::class)->save($data1);
				}else{ //Store and Spare
					$sspdtls = $this->getDefinedTable(Store\StoreSpareDetailsTable::class)->get(array('storespare' => $detail['assetssp'],'location'=>$issue['to_location']));
					foreach($sspdtls as $sspdtl);
					if(sizeof($sspdtl) > 0 )
					{	
						$remaining_qty = $sspdtl['quantity'] + $detail['accepted_quantity'];					
						$data1 = array(
							'id'	=> $sspdtl['id'],
							'quantity' => $remaining_qty,
                                                        'rate'     =>$sspdtl['rate'],
                                                        'amount'   =>$sspdtl['amount'],
							'author' => $this->_author,
							'created' => $this->_modified,
						);
					}
					else
					{
						$data1 = array(
							'quantity'   => $detail['accepted_quantity'],
							'location'   => $issue['to_location'],
							'storespare' => $detail['assetssp'],
							'rate'       => $detail['rate'],
                                                        'amount'     =>$detail['amount'],
							'author'     => $this->_author,
							'created'    => $this->created,
							'modified'   => $this->_modified,
						);	
					}
					$data1   = $this->_safedataObj->rteSafe($data1);
					//echo "<pre>";print_r($data1); exit;
					$result1 = $this->getDefinedTable(Store\StoreSpareDetailsTable::class)->save($data1);
				}
			endforeach;
		endif;
		//exit;
		
		$issue_data = array(
			'id' => $issue_id,
			'status' => 3,
			'author' => $this->_author,
			'modified' => $this->_modified,		
		);
		$issue_data   = $this->_safedataObj->rteSafe($issue_data);
		$result2 = $this->getDefinedTable(Store\IssueTable::class)->save($issue_data);
		
		$challan_no = $this->getDefinedTable(Store\IssueTable::class)->getColumn($issue_id,'challan_no');
		if($result2){
			if($result)
				$this->flashMessenger()->addMessage("success^ Successfully Committed Issue No. ".$challan_no.". And New Transport Charge successfully added with TC No. ".$transport_no);
			else{
				$this->flashMessenger()->addMessage("success^ Successfully Committed Issue Received");
			}
		}else{
			$this->flashMessenger()->addMessage("error^ Failed to Commit the Received Issue");
		}
		return $this->redirect()->toRoute('inissue',array('action'=>'viewissue','id'=>$issue_id));
	endforeach;
	}
	
	/**
	 * Revice Issue Destination Location
	**/
	public function reviceissueAction()
	{
		$this->init();
		$issue_id = $this->_id;
	
		if($this->getRequest()->isPost()){
			$form = $this->getRequest()->getPost();
			$data = array(
				'id' => $form['issue_id'],
				'to_location' => $form['to_location'],
				'note' => $form['note'],
				'author'      => $this->_author,
				'modified'    => $this->_modified,
			);
			$data = $this->_safedataObj->rteSafe($data); 
			$result = $this->getDefinedTable(Store\IssueTable::class)->save($data);
			if($result > 0):
				$this->flashMessenger()->addMessage("success^ Successfully updated the Issue Destination Location");
			else:
				$this->flashMessenger()->addMessage("error^ Failed to update Issue Destination Location");
			endif;
			return $this->redirect()->toRoute('inissue', array('action' => 'viewissue', 'id' => $form['issue_id']));
		}
		$ViewModel = new ViewModel(array(
				'title' => 'Revice Issue',
				'issues' => $this->getDefinedTable(Store\IssueTable::class)->get($issue_id),
				'locationObj' => $this->getDefinedTable(Administration\LocationTable::class),
				'regions' => $this->getDefinedTable(Administration\RegionTable::class)->getAll(),
		));
		$ViewModel->setTerminal(True);
		return $ViewModel;
	}
	/**
	 * Dispatch Report
	**/
	public function issuereportAction()
	{
		$this->init();
		$admin_locs = $this->getDefinedTable(Acl\UsersTable::class)->getColumn($this->_author,'admin_location');
		$admin_loc_array = array_filter(array_map('trim', explode(',', (string)$admin_locs)));
		if(sizeof($admin_loc_array) == 0 && (int)$this->_userloc > 0):
			$admin_loc_array = array((int)$this->_userloc);
		endif;
		
		if($this->getRequest()->isPost()){
			$form = $this->getRequest()->getPost();
			$data = array(
				'item_group'           => $form['item_group'],
				'source_location'      => $form['source_location'],
				'destination_location' => $form['destination_location'],
				'start_date'           => $form['start_date'],
				'end_date'             => $form['end_date'],
			);
		}else{
			$today = date('Y-m-d');
			$data = array(
				'item_group'           => '-1',
				'source_location'      => '-1',
				'destination_location' => '-1',
				'start_date'           => $today,
				'end_date'             => $today,
			);
		}
		
		return new ViewModel(array(
			'title'       => 'Issue Report',
			'activityObj' => $this->getDefinedTable(Administration\ActivityTable::class),
			'regionObj'   => $this->getDefinedTable(Administration\RegionTable::class),
			'locationObj' => $this->getDefinedTable(Administration\LocationTable::class),
			'data'        => $data,
			'issueObj'    => $this->getDefinedTable(Store\IssueTable::class),
			'itemObj'     => $this->getDefinedTable(Store\ItemTable::class),
			'stockItemObj'=> $this->getDefinedTable(Stock\ItemTable::class),
			'uomObj'      => $this->getDefinedTable(Stock\UomTable::class),
			'groupObj'    => $this->getDefinedTable(Store\GroupTable::class),
			'assetObj'    => $this->getDefinedTable(Store\AssetTable::class),
			'assetsspObj' => $this->getDefinedTable(Store\StoreSpareTable::class),
			'employeeObj' => $this->getDefinedTable(Hr\EmployeeTable::class),
			'admin_locs'  => $this->getDefinedTable(Administration\LocationTable::class)->get(array('l.id' => $admin_loc_array)),
			'admin_loc_ids' => $admin_loc_array,
		));
	}
	/**
	 * asset opening
	**/
	public function assetopeningAction()
	{
		$this->init();
		$this->flashMessenger()->addMessage("notice^ Store opening features have been removed.");
		return $this->redirect()->toRoute('inissue', array('action' => 'index'));
		$year='';
		$month='';
		if($this->getRequest()->isPost())
		{
			$form = $this->getRequest()->getPost();

			$year = $form['year'];
			$month = $form['month'];

			$month = ($month == 0)? date('m'):$month;
			$year = ($year == 0)? date('Y'):$year;
			$data = array(
				'year' => $year,
				'month' => $month,
			);
		}else{
			$month = ($month == 0)? date('m'):$month;
			$year = ($year == 0)? date('Y'):$year;

			$data = array(
				'year' => $year,
				'month' => $month,
			);
		}
		$openingstores = $this->getDefinedTable(Store\AssetOpeningTable::class)->getDateWise('opening_date',$year,$month);
		
		return new ViewModel(array(
		
			'title'         => 'Store Asset Opening',
			'openingstores' => $openingstores,
			'minYear' 		=> $this->getDefinedTable(Store\AssetOpeningTable::class)->getMin('opening_date'),
			'groupObj'      => $this->getDefinedTable(Store\GroupTable::class),
			'itemObj'       => $this->getDefinedTable(Store\ItemTable::class),
			'uomObj'        => $this->getDefinedTable(Stock\UomTable::class),
			'data'          => $data,
		));
	}
	/**
	 * store and spare opening
	**/
	public function sspopeningAction()
	{
		$this->init();
		$this->flashMessenger()->addMessage("notice^ Store opening features have been removed.");
		return $this->redirect()->toRoute('inissue', array('action' => 'index'));
		$year='';
		$month='';
		if($this->getRequest()->isPost())
		{
			$form = $this->getRequest()->getPost();

			$year = $form['year'];
			$month = $form['month'];

			$month = ($month == 0)? date('m'):$month;
			$year = ($year == 0)? date('Y'):$year;
			$data = array(
					'year' => $year,
					'month' => $month,
			);
		}else{
			$month = ($month == 0)? date('m'):$month;
			$year = ($year == 0)? date('Y'):$year;
			$item_group = '-1';

			$data = array(
					'year' => $year,
					'month' => $month,
			);
		}
		$openingstores = $this->getDefinedTable(Store\SspOpeningTable::class)->getDateWise('opening_date',$year,$month);
		return new ViewModel(array(
			'title'         => 'Store and Spare Opening',
			'openingstores' => $openingstores,
			 'minYear' 		=> $this->getDefinedTable(Store\SspOpeningTable::class)->getMin('opening_date'),
			'groupObj'      => $this->getDefinedTable(Store\GroupTable::class),
			'itemObj'       => $this->getDefinedTable(Store\ItemTable::class),
			'uomObj'        => $this->getDefinedTable(Stock\UomTable::class),
			'data'          => $data,
		));
	}
	/**
	 * OPENING STOCK ENTRY
	 * Add Stock Opening Action
	 * Initiate Status
	**/
	public function addsspopeningAction() 
	{
		$this->init();
		$this->flashMessenger()->addMessage("notice^ Store opening features have been removed.");
		return $this->redirect()->toRoute('inissue', array('action' => 'index'));
        if($this->getRequest()->isPost()){
			
			$form = $this->getRequest()->getPost();
			$item_detls = $this->getDefinedTable(Store\ItemTable::class)->get(array('i.id'=>$form['item']));
			foreach($item_detls as $item_detl):
			
			if($item_detl['item_group_id'] == 2): //Assets
			
				$check_ssp = $this->getDefinedTable(Store\SspOpeningTable::class)->get(array('storespare'=>$form['assetssp']));
				if(sizeof($check_ssp) > 0 ):
				    $this->flashMessenger()->addMessage("error^ You cannot save the same store and spare no.,but you can edit it.");			
					return $this->redirect()->toRoute('inissue', array('action' => 'addsspopening'));
			    else:
					$ssp_data = array(
						'storespare'     => $form['assetssp'],
						'item'           => $item_detl['id'],
						'uom'            => $item_detl['uom'],
						'quantity'       => $form['qty'],
						'opening_date'   => '2018-01-01',
						'status' 		 => 2,
						'author'	     => $this->_author,
						'created'        => $this->_created,
						'modified'       => $this->_modified,
					   );
					$ssp_data   = $this->_safedataObj->rteSafe($ssp_data);
					$storespID = $this->getDefinedTable(Store\SspOpeningTable::class)->save($ssp_data);
					if($storespID > 0 ){
						
					 $item		  = $form['item'];
					 $amount	  = $form['amount'];
					 $quantity	  = $form['qty'];
					 $location	  = $form['location'];
					 for($i=0; $i < sizeof($item); $i++):
						if(isset($item[$i]) && $item[$i] > 0):	
						    $rate = $amount[$i] / $quantity[$i];
							$storespDtls = array(
								'storespare'     => $storespID,
								'location'       => $location[$i],
								'quantity'       => $quantity[$i],
								'amount'         => $amount[$i],
								'rate'         => $rate,
								'status'         => 2,
								'author'         => $this->_author,
								'created'        => $this->_created,
								'modified'       => $this->_modified
							);	
							$storespDtls   = $this->_safedataObj->rteSafe($storespDtls);
							$storespDtlsId = $this->getDefinedTable(Store\SspOpeningDetailsTable::class)->save($storespDtls);
							if($storespDtlsId){
							$this->getDefinedTable(Store\SspOpeningTable::class)->save(array('id'=>$storespID,'quantity'=>$quantity[$i]));
							}else{
								$this->_connection->rollback(); // rollback transaction over failure
								$this->flashMessenger()->addMessage("error^ Unsuccessful to update Store and spares details.");
							}
						endif;
					endfor;	
						if($storespDtlsId < 0):
						   $this->_connection->rollback(); // rollback transaction over failure
						   $this->flashMessenger()->addMessage("error^ Failed to save the data in store and spare details table. Please check ");			
						   return $this->redirect()->toRoute('inissue', array('action' => 'addsspopening'));
						endif;
					}
					else{			   	     
						$this->_connection->rollback(); // rollback transaction over failure
						$this->flashMessenger()->addMessage("error^ Failed to save the data in store and spare table. Please Check");
						return $this->redirect()->toRoute('inissue', array('action' => 'addsspopening'));
					}
					$this->flashMessenger()->addMessage("success^ Successfully save the data in store and spare table. Please Check");
					return $this->redirect()->toRoute('inissue', array('action' => 'viewsspopening','id'=>$storespID));
				endif;
		    endif;
		endforeach;
		}
					
		return new ViewModel(array(
				'title'           => 'Add Store and Spare Opening',
				'locationObj'     => $this->getDefinedTable(Administration\LocationTable::class),
				'regionObj'     => $this->getDefinedTable(Administration\RegionTable::class),
				'activityObj'     => $this->getDefinedTable(Administration\ActivityTable::class),
				'itemObj'         => $this->getDefinedTable(Store\ItemTable::class),
				'groupObj'         => $this->getDefinedTable(Store\GroupTable::class),
		));
	}
	/**
	 * OPENING STOCK ENTRY
	 * Add Stock Opening Action
	 * Initiate Status
	**/
	public function addassetopeningAction() 
	{
		$this->init();
		$this->flashMessenger()->addMessage("notice^ Store opening features have been removed.");
		return $this->redirect()->toRoute('inissue', array('action' => 'index'));
        if($this->getRequest()->isPost()){
			$form = $this->getRequest()->getPost();	
			$counts = (int)$form['qty'];
			$this->_connection->beginTransaction();
			for($x=0; $x < $counts ; $x++)
			{
				$itemTypeCode = $this->getDefinedTable(Store\ItemTable::class)->getColumn($form['item'],'item_type_code');
				$itemSpecificationCode = $this->getDefinedTable(Store\ItemTable::class)->getColumn($form['item'],'item_specification_code');	
				$itemsg_id = $this->getDefinedTable(Store\ItemTable::class)->getColumn($form['item'],'item_sub_group');
				$prefix = $this->getDefinedTable(Store\SubGroupTable::class)->getColumn($itemsg_id,'prefix');		
				$activity_prefix = $this->getDefinedTable(Administration\ActivityTable::class)->getColumn(5,'prefix');		
				$year = date('Y',strtotime($form['opening_date']));
				$tmp_ACNo = "FCBL"."/".$activity_prefix."/".$year."/".$prefix."/".$itemTypeCode."/".$itemSpecificationCode."/";
				$results  = $this->getDefinedTable(Store\AssetTable::class)->getMonthlyAssetCode($tmp_ACNo);							
				$sheet_no_list = array();
				foreach($results as $result):			   	
					array_push($sheet_no_list, substr($result['asset'],21));
				endforeach;
				if(sizeof($sheet_no_list)>0){
					$next_serial = max($sheet_no_list) + 1 + $x;
				}else{
					$next_serial = 1 + $x;
				}			   	  
				switch(strlen($next_serial)){
					case 1: $next_sc_serial  = "000".$next_serial;  break;
					case 2: $next_sc_serial  = "00".$next_serial;   break;
					case 3: $next_sc_serial  = "0".$next_serial;   break;
					default: $next_sc_serial = $next_serial;      break;
				}
				$asset_code = $tmp_ACNo.$next_sc_serial;
				$asset_data = array(
					'asset'         =>$asset_code,
					'item'          =>$form['item'],
					'uom'           =>$form['basic_uom'],
					'quantity'      =>1,
					'opening_date'  =>$form['opening_date'],
					'status' 		=>2,
					'author'	    =>$this->_author,
					'created'       =>$this->_created,
					'modified'      =>$this->_modified,
				);
				$asset_data   = $this->_safedataObj->rteSafe($asset_data);
				$assetID = $this->getDefinedTable(Store\AssetOpeningTable::class)->save($asset_data);
				$amount = (int)$form['amount']/(int)$form['qty'];
		        $location = $form['location'];
				$assetDtls = array(
					'asset'          => $assetID,
					'location'       => $location,
					'quantity'       => 1,
					'rate'           => $amount,
					'amount'         => $amount,
					'status'         => 2,
					'author'         => $this->_author,
					'created'        => $this->_created,
					'modified'       => $this->_modified
				);	
				$assetDtls   = $this->_safedataObj->rteSafe($assetDtls);
				$assetDtlsId = $this->getDefinedTable(Store\AssetOpeningDetailsTable::class)->save($assetDtls);			
			}
			if($assetDtlsId > 0){
                $this->_connection->commit(); // commit transaction over success
				$this->flashMessenger()->addMessage("success^ Successfully updated new asset No");			
				}
			else{			   	     
				$this->_connection->rollback(); // rollback transaction over failure
				$this->flashMessenger()->addMessage("error^ Failed to save the data in assets table. Please Check");
				return $this->redirect()->toRoute('inissue', array('action' =>'addassetopening'));
			}
			return $this->redirect()->toRoute('inissue', array('action' =>'assetopening'));
		}
					
		return new ViewModel(array(
				'title'           => 'Add Asset Opening',
				'locationObj'     => $this->getDefinedTable(Administration\LocationTable::class),
				'regionObj'       => $this->getDefinedTable(Administration\RegionTable::class),
				'activityObj'     => $this->getDefinedTable(Administration\ActivityTable::class),
				'itemObj'         => $this->getDefinedTable(Store\ItemTable::class),
				'groupObj'         => $this->getDefinedTable(Store\GroupTable::class),
		));
	}
    /**
	 * Get Basic Uom
	**/
	public function getitemuomAction()
	{
		$this->init();		
		$form = $this->getRequest()->getPost();		
		$itemID = $form['item'];
		$opening_date = $form['opening_date'];
		
		$itemDtls = $this->getDefinedTable(Store\ItemTable::class)->get($itemID);
		$uom_for .="<option value=''></option>";
		foreach($itemDtls as $dtl):
			$uom_for .="<option value='".$dtl['st_uom_id']."' selected>".$dtl['uom_code']."</option>";		
		endforeach;		
		
		$item_group_id = $this->getDefinedTable(Store\ItemTable::class)->getColumn($itemID,'item_group');	
		
		if($item_group_id == 1):
		 
		    $itemTypeCode = $this->getDefinedTable(Store\ItemTable::class)->getColumn($form['item'],'item_type_code');
			$itemSpecificationCode = $this->getDefinedTable(Store\ItemTable::class)->getColumn($form['item'],'item_specification_code');	
			$itemsg_id = $this->getDefinedTable(Store\ItemTable::class)->getColumn($form['item'],'item_sub_group');
			$prefix = $this->getDefinedTable(Store\SubGroupTable::class)->getColumn($itemsg_id,'prefix');		
			$activity_prefix = $this->getDefinedTable(Administration\ActivityTable::class)->getColumn(5,'prefix');		
			$year = date('Y',strtotime($opening_date));
			$tmp_ACNo = "FCBL"."/".$activity_prefix."/".$year."/".$prefix."/".$itemTypeCode."/".$itemSpecificationCode."/";
			$results  = $this->getDefinedTable(Store\AssetTable::class)->getMonthlyAssetCode($tmp_ACNo);		   	  
					 
			$sheet_no_list = array();
			foreach($results as $result):			   	
				array_push($sheet_no_list, substr($result['asset'], 21));
			endforeach;
			if(sizeof($sheet_no_list)>0){
				$next_serial = max($sheet_no_list) + 1;
			}else{
				$next_serial = 1;
			}			   	  
			switch(strlen($next_serial)){
				case 1: $next_b_serial  = "000".$next_serial;  break;
				case 2: $next_b_serial  = "00".$next_serial;  break;
				case 3: $next_b_serial  = "0".$next_serial;   break;
				default: $next_b_serial = $next_serial;      break;
			}			   	  
			$assetssp =$tmp_ACNo.$next_b_serial; 
			
		else:
		
		    $existing_SSP_codes =  $this->getDefinedTable(Store\StoreSpareTable::class)->getExistingSSPCode(array('item'=>$itemID,'status'=>'3','storespare_date'=>'2018-01-01'));
			
			$multiple_array = array();
			foreach($existing_SSP_codes as $existing_SSP_code):
				array_push($multiple_array,$existing_SSP_code['id']);
			endforeach;
			$max_existing_SSP_code = max($multiple_array);
			
			$existing_SSPCode =  $this->getDefinedTable(Store\StoreSpareTable::class)->getExistingSSPCode($max_existing_SSP_code);
			if(sizeof($existing_SSPCode) > 0):
				foreach($existing_SSPCode as $existing_SSPC);
				$assetssp = $existing_SSPC['storespare'];
				
			else:
				
				$itemsg_id = $this->getDefinedTable(Store\ItemTable::class)->getColumn($form['item'],'item_sub_group');
				$prefix = $this->getDefinedTable(Store\SubGroupTable::class)->getColumn($itemsg_id,'prefix');		
				$activity_prefix = $this->getDefinedTable(Administration\ActivityTable::class)->getColumn(5,'prefix');		
				$year = date('Y', strtotime($opening_date));
				$tmp_SSPCNo = "FCBL"."/".$activity_prefix."/".$year."/".$prefix."/";
				
				$results  = $this->getDefinedTable(Store\StoreSpareTable::class)->getMonthlySSPCode($tmp_SSPCNo);		 
				$sheet_no_list = array();
				foreach($results as $result):			   	
					array_push($sheet_no_list, substr($result['storespare'], 15));
				endforeach;
				if(sizeof($sheet_no_list)>0){
					$next_serial = max($sheet_no_list) + 1;
				}else{
					$next_serial = 1;
				}			   	  
				switch(strlen($next_serial)){
					case 1: $next_sc_serial  = "000".$next_serial;  break;
					case 2: $next_sc_serial  = "00".$next_serial;   break;
					case 3: $next_sc_serial  = "0".$next_serial;   break;
					default: $next_sc_serial = $next_serial;      break;
				}
				$assetssp = $tmp_SSPCNo.$next_sc_serial;
		    endif;
		endif;
		
		echo json_encode(array(
				'uom' =>$uom_for,
				'assetssp' =>$assetssp,
		));
		exit;
	}
	
	/**
	 * store opening
	**/
	public function viewassetopeningAction()
	{
		$this->init();
		$this->flashMessenger()->addMessage("notice^ Store opening features have been removed.");
		return $this->redirect()->toRoute('inissue', array('action' => 'index'));
		
		return new ViewModel( array(
			'assetOpening' => $this->getDefinedTable(Store\AssetOpeningTable::class)->get($this->_id),
			'assetOpeningdtlsObj' => $this->getDefinedTable(Store\AssetOpeningDetailsTable::class),
			'locationObj'      => $this->getDefinedTable(Administration\LocationTable::class),
			'locationtypeObj'      => $this->getDefinedTable(Administration\LocationTypeTable::class),
			'statusObj'        => $this->getDefinedTable(Acl\StatusTable::class),
			'itemObj' => $this->getDefinedTable(Store\ItemTable::class),
			'itemuomObj'  => $this->getDefinedTable(Stock\ItemUomTable::class),
			'uomObj'=> $this->getDefinedTable(Stock\UomTable::class),
			'usersObj'=> $this->getDefinedTable(Acl\UsersTable::class),
			'author'=> $this->_author,
		) );
	}
	/**
	 * store opening
	**/
	public function viewsspopeningAction()
	{
		$this->init();
		$this->flashMessenger()->addMessage("notice^ Store opening features have been removed.");
		return $this->redirect()->toRoute('inissue', array('action' => 'index'));
		
		return new ViewModel( array(
			'sspOpening' => $this->getDefinedTable(Store\SspOpeningTable::class)->get($this->_id),
			'sspOpeningdtlsObj' => $this->getDefinedTable(Store\SspOpeningDetailsTable::class),
			'locationObj'      => $this->getDefinedTable(Administration\LocationTable::class),
			'locationtypeObj'      => $this->getDefinedTable(Administration\LocationTypeTable::class),
			'statusObj'        => $this->getDefinedTable(Acl\StatusTable::class),
			'itemObj' => $this->getDefinedTable(Store\ItemTable::class),
			'itemuomObj'  => $this->getDefinedTable(Stock\ItemUomTable::class),
			'uomObj'=> $this->getDefinedTable(Stock\UomTable::class),
			'usersObj'=> $this->getDefinedTable(Acl\UsersTable::class),
			'author'=> $this->_author,
		) );
	}
	/**
	 * Update to batch_table
	**/
	public function updateassetAction()
	{
		$this->init();
		$this->flashMessenger()->addMessage("notice^ Store opening features have been removed.");
		return $this->redirect()->toRoute('inissue', array('action' => 'index'));

		$opening_stores = $this->getDefinedTable(Store\AssetOpeningTable::class)->get($this->_id);
		foreach($opening_stores as $opening);
		foreach($this->getDefinedTable(Store\ItemTable::class)->get($opening['item']) as $itemdtls);
		
		if($itemdtls['item_group_id'] == 1):
		
			if($opening['status']==2): //opening stock status = 2 pending.
				$data = array(
						'asset' => $opening['asset'],
						'item'  => $opening['item'],
						'uom'   => $opening['uom'],	
						'quantity' => $opening['quantity'],
						'barcode' => '',
						'asset_date' => $opening['opening_date'],
						'expiry_date' => '',
						'status' => '3',
						'author' => $this->_author,
						'created' => $this->_created,
						'modified' => $this->_modified,					
				);
				$data = $this->_safedataObj->rteSafe($data);
				$this->_connection->beginTransaction(); //***Transaction begins here***//
				$assetID = $this->getDefinedTable(Store\AssetTable::class)->save($data);
				if($assetID > 0){
					$opening_dtls = $this->getDefinedTable(Store\AssetOpeningDetailsTable::class)->get(array('asset' =>$opening['id'],'status'=>'2','author'=>$this->_author));
					foreach($opening_dtls as $detail):
						$assetDtls = array(
							'asset'          => $assetID,
							'location'       => $detail['location'],
							'quantity'       => $detail['quantity'],
							'rate'           => $detail['rate'],
                                                        'amount'         => $detail['amount'],
							'author'         => $this->_author,
							'created'        => $this->_created,
							'modified'       => $this->_modified,
						);
						$assetDtls = $this->_safedataObj->rteSafe($assetDtls);
						$assetDtlsId = $this->getDefinedTable(Store\AssetDetailsTable::class)->save($assetDtls);
						if($assetDtlsId){
						$this->getDefinedTable(Store\AssetOpeningDetailsTable::class)->save(array('id'=>$detail['id'],'status'=>'3'));
						}else{
							$this->_connection->rollback(); // rollback transaction over failure
							$this->flashMessenger()->addMessage("error^ Unsuccessful to update batch details.");
						}
					endforeach;
					if($assetDtlsId){
						$result1 = $this->getDefinedTable(Store\AssetOpeningTable::class)->save(array('id'=>$this->_id,'status'=>'3','modified'=>_modified));
						if($result1):
							$this->_connection->commit(); // commit transaction over success
							$this->flashMessenger()->addMessage("success^ Successfully updated new asset No : ". $opening['asset']);
						else:
							$this->_connection->rollback(); // rollback transaction over failure
							$this->flashMessenger()->addMessage("error^ Failed to update asset.");
						endif;
					}else{
						$this->_connection->rollback(); // rollback transaction over failure
						$this->flashMessenger()->addMessage("error^ Unsuccessful to new update asset details");
					}
				}else{
					$this->_connection->rollback(); // rollback transaction over failure
					$this->flashMessenger()->addMessage("error^ Unsuccessful to update new asset");
				}
			return $this->redirect()->toRoute('inissue', array('action' =>'viewassetopening','id'=>$this->_id));
			endif;
		endif;
	}
	/**
	 * Update to store spare
	**/
	public function updatesspAction()
	{
		$this->init();
		$this->flashMessenger()->addMessage("notice^ Store opening features have been removed.");
		return $this->redirect()->toRoute('inissue', array('action' => 'index'));
		
		$opening_stores = $this->getDefinedTable(Store\SspOpeningTable::class)->get($this->_id);
		
		foreach($opening_stores as $opening);
		foreach($this->getDefinedTable(Store\ItemTable::class)->get($opening['item']) as $itemdtls);

		if($itemdtls['item_group_id'] == 2):// asset or spare
		
       		if($opening['status']==2): // opening stock status = 2 pending.
			
			    $check_ssp =$this->getDefinedTable(Store\StoreSpareTable::class)->get(array('storespare' =>$opening['storespare'])); 
			    if(sizeof($check_ssp) > 0 ):
				    foreach($check_ssp as $row);
					    $new_qty = $row['quantity'] + $opening['quantity'];
						
					    $data = array(
						'id'         => $row['id'],
						'quantity'   => $new_qty,
						'author' => $this->_author,
						'modified' => $this->_modified,					
					);
					$data = $this->_safedataObj->rteSafe($data);
					$this->_connection->beginTransaction(); //***Transaction begins here***//
					$sspID = $this->getDefinedTable(Store\StoreSpareTable::class)->save($data); 
					if($sspID > 0){
						$opening_dtls = $this->getDefinedTable(Store\SspOpeningDetailsTable::class)->get(array('storespare' =>$opening['id'],'status'=>'2','author'=>$this->_author));
						$ssp_dtls = $this->getDefinedTable(Store\StoreSpareDetailsTable::class)->get(array('storespare' =>$opening['id']));
						foreach($ssp_dtls as $row);
						foreach($opening_dtls as $detail):
							$sspDtls = array(
								'id'             => $row['id'],
								'quantity'       => $detail['quantity'] + $row['quantity'],
								'rate'           => $detail['rate'],
								'amount'         => $detail['amount'],
								'author'         => $this->_author,
								'created'        => $this->_created,
								'modified'       => $this->_modified,
							);
							$sspDtls = $this->_safedataObj->rteSafe($sspDtls);
							$sspDtlsId = $this->getDefinedTable(Store\StoreSpareDetailsTable::class)->save($sspDtls);
							if($sspDtlsId){
							$this->getDefinedTable(Store\SspOpeningDetailsTable::class)->save(array('id'=>$detail['id'],'status'=>'3'));
							}else{
								$this->_connection->rollback(); // rollback transaction over failure
								$this->flashMessenger()->addMessage("error^ Unsuccessful to update store and spare details.");
							}
						endforeach;
						if($sspDtlsId){
							$result1 = $this->getDefinedTable(Store\SspOpeningTable::class)->save(array('id'=>$this->_id,'status'=>'3','modified'=>_modified));
							if($result1):
								$this->_connection->commit(); // commit transaction over success
								$this->flashMessenger()->addMessage("success^ Successfully updated new store and spare,Store and Spare No : ". $opening['storespare']);
							else:
								$this->_connection->rollback(); // rollback transaction over failure
								$this->flashMessenger()->addMessage("error^ Failed to update in opening stock.");
							endif;
						}else{
							echo "This is my testing"; exit;
							$this->_connection->rollback(); // rollback transaction over failure
							$this->flashMessenger()->addMessage("error^ Unsuccessful to new update store and spare details");
						}
					}else{
						$this->_connection->rollback(); // rollback transaction over failure
						$this->flashMessenger()->addMessage("error^ Unsuccessful to update new store and spare");
					}
					return $this->redirect()->toRoute('inissue', array('action' =>'viewsspopening','id'=>$this->_id));
					
				else: 
				
					$data = array(
							'storespare' => $opening['storespare'],
							'item'       => $opening['item'],
							'uom'        => $opening['uom'],	
							'quantity'   => $opening['quantity'],
							'barcode'    => '',
							'storespare_date' => $opening['opening_date'],
							'expiry_date' => '',
							'status' => '3',
							'author' => $this->_author,
							'created' => $this->_created,
							'modified' => $this->_modified,					
					);
					$data = $this->_safedataObj->rteSafe($data);
					//
					$this->_connection->beginTransaction(); //***Transaction begins here***//
					$sspID = $this->getDefinedTable(Store\StoreSpareTable::class)->save($data); 
					if($sspID > 0){
						$opening_dtls = $this->getDefinedTable(Store\SspOpeningDetailsTable::class)->get(array('storespare' =>$opening['id'],'status'=>'2','author'=>$this->_author));
						foreach($opening_dtls as $detail):
							$sspDtls = array(
								'storespare'     => $sspID,
								'location'       => $detail['location'],
								'quantity'       => $detail['quantity'],
								'rate'           => $detail['rate'],
								'amount'           => $detail['amount'],
								'author'         => $this->_author,
								'created'        => $this->_created,
								'modified'       => $this->_modified,
							);
							$sspDtls = $this->_safedataObj->rteSafe($sspDtls);
							$sspDtlsId = $this->getDefinedTable(Store\StoreSpareDetailsTable::class)->save($sspDtls);
							//print_r($sspDtls); exit;
							if($sspDtlsId){
								$this->getDefinedTable(Store\SspOpeningDetailsTable::class)->save(array('id'=>$detail['id'],'status'=>'3'));
							}else{
								$this->_connection->rollback(); // rollback transaction over failure
								$this->flashMessenger()->addMessage("error^ Unsuccessful to update store and spare details.");
							}
						endforeach;
						if($sspDtlsId){
							$result1 = $this->getDefinedTable(Store\SspOpeningTable::class)->save(array('id'=>$this->_id,'status'=>'3','modified'=>_modified));
							if($result1):
								$this->_connection->commit(); // commit transaction over success
								$this->flashMessenger()->addMessage("success^ Successfully updated new store and spare,Store and Spare No : ". $opening['storespare']);
							else:
								$this->_connection->rollback(); // rollback transaction over failure
								$this->flashMessenger()->addMessage("error^ Failed to update in opening stock.");
							endif;
						}else{
							$this->_connection->rollback(); // rollback transaction over failure
							$this->flashMessenger()->addMessage("error^ Unsuccessful to new update store and spare details");
						}
					}else{
						$this->_connection->rollback(); // rollback transaction over failure
						$this->flashMessenger()->addMessage("error^ Unsuccessful to update new store and spare");
					}
			        return $this->redirect()->toRoute('inissue', array('action' =>'viewsspopening','id'=>$this->_id));
			    endif;
		    endif;
		endif;
		return new ViewModel(array());
	}
	/**
	 * editsspopeningdetail Action
	 */
	public function editsspopeningdetailAction()
	{
		$this->init();
		$this->flashMessenger()->addMessage("notice^ Store opening features have been removed.");
		return $this->redirect()->toRoute('inissue', array('action' => 'index'));
		if($this->getRequest()->isPost()){
			$form = $this->getRequest()->getPost();
			$openingdtl_id = $form['openingdtl_id'];
			$openingdtls = $this->getDefinedTable(Store\SspOpeningDetailsTable::class)->get($openingdtl_id);
			foreach($openingdtls as $openingdtl);
			$openings = $this->getDefinedTable(Store\SspOpeningTable::class)->get($openingdtl['storespare']);
			foreach($openings as $opening);
			
			if($openingdtl['status']==3):
			   
				$sspID = $this->getDefinedTable(Store\StoreSpareTable::class)->getColumn(array('item' => $opening['item'],'storespare'=>$opening['storespare']),'id');
				if($sspID > 0):
				
					$sspdtls = $this->getDefinedTable(Store\StoreSpareDetailsTable::class)->get(array('storespare'=>$sspID,'location'=>$openingdtl['location']));
					if(sizeof($sspdtls)>0):
						foreach($sspdtls as $row);
						$nullify_qty = $row['quantity'] - $openingdtl['quantity'];
						$qty = $nullify_qty + $form['quantity'];
						$rate = $form['amount'] / $qty;
						$ssp_dtl_data = array(
							'id'             => $row['id'],
							'quantity'       => $qty,
							'rate'           => $rate,
							'amount'         => $form['amount'],
							'author'         => $this->_author,
							'modified'       => $this->_modified,
						);
						$ssp_dtl_data = $this->_safedataObj->rteSafe($ssp_dtl_data);
						$this->_connection->beginTransaction(); //***Transaction begins here***//
						$result = $this->getDefinedTable(Store\StoreSpareDetailsTable::class)->save($ssp_dtl_data); 
						if($result):
							$qty = $this->getDefinedTable(Store\StoreSpareDetailsTable::class)->getSum(array('storespare'=>$sspID),'quantity');
							$result1 = $this->getDefinedTable(Store\StoreSpareTable::class)->save(array('id'=>$sspID,'quantity'=>$qty,'modified'=>$this->_modified));
							if($result1):
								$data = array(
									'id'            => $openingdtl['id'],
									'quantity'      => $form['quantity'],
									'rate'           => $rate,
									'amount'         => $form['amount'],
									'status'        => '3',
									'author'        => $this->_author,
									'modified'      => $this->_modified,
								);
								$data = $this->_safedataObj->rteSafe($data);
								$result3 = $this->getDefinedTable(Store\SspOpeningDetailsTable::class)->save($data);
								if($result3):
									$qty = $this->getDefinedTable(Store\SspOpeningDetailsTable::class)->getSUM(array('sd.storespare'=>$opening['id']),'sd.quantity');
									$result4 = $this->getDefinedTable(Store\SspOpeningTable::class)->save(array('id'=>$opening['id'],'quantity'=>$qty,'modified'=>$this->_modified));
									if($result4):
										$this->_connection->commit(); // commit transaction over success
										$this->flashMessenger()->addMessage("success^ Successfully updated opening store and spare details.");
									else:
										$this->_connection->rollback(); // rollback transaction over failure
										$this->flashMessenger()->addMessage("error^ Failed to update sum opening store and spare table.");
									endif;
								else:
									$this->_connection->rollback(); // rollback transaction over failure
									$this->flashMessenger()->addMessage("error^ Failed to update opening store and spare details.");
								endif;
							else:
								$this->_connection->rollback(); // rollback transaction over failure
								$this->flashMessenger()->addMessage("error^ Failed to update the sum of quantity in opening store and store table.");
							endif;
						else:
							$this->_connection->rollback(); // rollback transaction over failure
							$this->flashMessenger()->addMessage("error^ Failed to update ssp details.");
						endif;
					else:
						$this->flashMessenger()->addMessage("notice^ Store and Spare not found. Please check.");
					endif;
				else:
					$this->flashMessenger()->addMessage("notice^ Store and Spare  not found in store and spare table. Please check.");
				endif;
			else:
			    $rate = $form['amount']/$form['quantity'];
				$data = array(
					'id'            => $openingdtl['id'],
					'quantity'      => $form['quantity'],
					'rate'          => $rate,
					'amount'        => $form['amount'],
					'status'        => '2',
					'author'        => $this->_author,
					'modified'      => $this->_modified,
				);
				$data = $this->_safedataObj->rteSafe($data);
				$this->_connection->beginTransaction(); //***Transaction begins here***//
				$result = $this->getDefinedTable(Store\SspOpeningDetailsTable::class)->save($data);
				if($result):
					$qty = $this->getDefinedTable(Store\SspOpeningDetailsTable::class)->getSMSUM(array('storespare'=>$opening['id']),'quantity');
					$result1 = $this->getDefinedTable(Store\SspOpeningTable::class)->save(array('id'=>$opening['id'],'quantity'=>$qty,'modified'=>$this->_modified));
					if($result1):
						$this->_connection->commit(); // commit transaction over success
						$this->flashMessenger()->addMessage("success^ Successfully updated opening store and spare details. Please update to the main store and store table.");
					else:
						$this->_connection->rollback(); // rollback transaction over failure
						$this->flashMessenger()->addMessage("error^ Failed to update sum in opening store and spare.");
					endif;
				else:
					$this->_connection->rollback(); // rollback transaction over failure
					$this->flashMessenger()->addMessage("error^ Failed to update opening store and spare details.");
				endif;
			endif;
			return $this->redirect()->toRoute('inissue',array('action' => 'viewsspopening','id'=>$opening['id']));
		}
		$ViewModel = new ViewModel(array(
				'title'       => 'Edit Opening Details',
				'locationObj' => $this->getDefinedTable(Administration\LocationTable::class),
				'openingdtls' => $this->getDefinedTable(Store\SspOpeningDetailsTable::class)->get($this->_id),
		));
		$ViewModel->setTerminal(True);
		return $ViewModel;
	}
							
	/**
	 * deletestockopeningdetail Action
	 */
	public function editassetopeningdetailAction()
	{
		$this->init();
		$this->flashMessenger()->addMessage("notice^ Store opening features have been removed.");
		return $this->redirect()->toRoute('inissue', array('action' => 'index'));
		if($this->getRequest()->isPost()){
			$form = $this->getRequest()->getPost();
			$openingdtl_id = $form['openingdtl_id'];
			$openingdtls = $this->getDefinedTable(Store\AssetOpeningDetailsTable::class)->get($openingdtl_id);
			foreach($openingdtls as $openingdtl);
			//echo "<pre>"; print_r($openingdtls); exit;
			$openings = $this->getDefinedTable(Store\AssetOpeningTable::class)->get($openingdtl['asset']);
			foreach($openings as $opening);
			
			if($openingdtl['status']==3):
			
				$assetID = $this->getDefinedTable(Store\AssetTable::class)->getColumn(array('item' => $opening['item'],'asset'=>$opening['asset']),'id');
				//echo $assetID; exit;
				if($assetID > 0):
				
					$assetdtls = $this->getDefinedTable(Store\AssetDetailsTable::class)->get(array('asset'=>$assetID,'location'=>$openingdtl['location']));
					if(sizeof($assetdtls)>0):
						foreach($assetdtls as $row);
						$nullify_qty = $row['quantity'] - $openingdtl['quantity'];
						$qty = $nullify_qty + $form['quantity'];
						$asset_dtl_data = array(
								'id'             => $row['id'],
								'quantity'       => $qty,
								'rate'           => $form['rate'],
								'author'         => $this->_author,
								'modified'       => $this->_modified,
						);
						$asset_dtl_data = $this->_safedataObj->rteSafe($asset_dtl_data);
						$this->_connection->beginTransaction(); //***Transaction begins here***//
						$result = $this->getDefinedTable(Store\AssetDetailsTable::class)->save($asset_dtl_data); 
						if($result):
							$qty = $this->getDefinedTable(Store\AssetDetailsTable::class)->getSum(array('asset'=>$assetID),'quantity');
							$result1 = $this->getDefinedTable(Store\AssetTable::class)->save(array('id'=>$assetID,'quantity'=>$qty,'modified'=>$this->_modified));
							if($result1):
								$data = array(
										'id'            => $openingdtl['id'],
										'quantity'      => $form['quantity'],
										'rate'          => $form['rate'],
										'status'        => '3',
										'author'        => $this->_author,
										'modified'      => $this->_modified,
								);
								$data = $this->_safedataObj->rteSafe($data);
								$result3 = $this->getDefinedTable(Store\AssetOpeningDetailsTable::class)->save($data);
								if($result3):
									$qty = $this->getDefinedTable(Store\AssetOpeningDetailsTable::class)->getSMSUM(array('asset'=>$opening['id']),'quantity');
									$result4 = $this->getDefinedTable(Store\AssetOpeningTable::class)->save(array('id'=>$opening['id'],'quantity'=>$qty,'modified'=>$this->_modified));
									if($result4):
										$this->_connection->commit(); // commit transaction over success
										$this->flashMessenger()->addMessage("success^ Successfully updated opening assets details.");
									else:
										$this->_connection->rollback(); // rollback transaction over failure
										$this->flashMessenger()->addMessage("error^ Failed to update sum opening asset table.");
									endif;
								else:
									$this->_connection->rollback(); // rollback transaction over failure
									$this->flashMessenger()->addMessage("error^ Failed to update opening assets details.");
								endif;
							else:
								$this->_connection->rollback(); // rollback transaction over failure
								$this->flashMessenger()->addMessage("error^ Failed to update the sum of quantity in opening asset table.");
							endif;
						else:
							$this->_connection->rollback(); // rollback transaction over failure
							$this->flashMessenger()->addMessage("error^ Failed to update asset details.");
						endif;
					else:
						$this->flashMessenger()->addMessage("notice^ Asset not found. Please check.");
					endif;
				else:
					$this->flashMessenger()->addMessage("notice^ Store and Spare  not found in asset table. Please check.");
				endif;
			else:
				$data = array(
						'id'            => $openingdtl['id'],
						'quantity'      => $form['quantity'],
						'rate'          => $form['rate'],
						'status'        => '2',
						'author'        => $this->_author,
						'modified'      => $this->_modified,
				);
				$data = $this->_safedataObj->rteSafe($data);
				$this->_connection->beginTransaction(); //***Transaction begins here***//
				$result = $this->getDefinedTable(Store\AssetOpeningDetailsTable::class)->save($data);
				if($result):
					$qty = $this->getDefinedTable(Store\AssetOpeningDetailsTable::class)->getSMSUM(array('asset'=>$opening['id']),'quantity');
					$result1 = $this->getDefinedTable(Store\SspOpeningTable::class)->save(array('id'=>$opening['id'],'quantity'=>$qty,'modified'=>$this->_modified));
					if($result1):
						$this->_connection->commit(); // commit transaction over success
						$this->flashMessenger()->addMessage("success^ Successfully updated opening asset details. Please update to the main asset table.");
					else:
						$this->_connection->rollback(); // rollback transaction over failure
						$this->flashMessenger()->addMessage("error^ Failed to update sum in opening asset.");
					endif;
				else:
					$this->_connection->rollback(); // rollback transaction over failure
					$this->flashMessenger()->addMessage("error^ Failed to update opening asset details.");
				endif;
			endif;
			return $this->redirect()->toRoute('inissue',array('action' => 'viewassetopening','id'=>$opening['id']));
		}
		$ViewModel = new ViewModel(array(
				'title'       => 'Edit Opening Details',
				'locationObj' => $this->getDefinedTable(Administration\LocationTable::class),
				'openingdtls' => $this->getDefinedTable(Store\AssetDetailsTable::class)->get($this->_id),
		));
		$ViewModel->setTerminal(True);
		return $ViewModel;
	}
	 /* OPENING STOCK ENTRY
	 * Fetch items via item group ID
	 */
	public function getitemsAction()
	{
		$this->init();
		
		$form = $this->getRequest()->getPost();
		$group_id = $form['item_group'];
		
		if($group_id == '1'):
			$items = $this->getDefinedTable(Store\ItemTable::class)->get(array('i.item_group' => $group_id));
		else:
			$items = $this->getDefinedTable(Store\ItemTable::class)->get(array('i.item_group' => $group_id));
		endif;
		$stock_items.="<option value=''></option>";
		foreach($items as $item):
		    $item_id =  $item['id'];
			if($item_id):
				$stock_items .="<option value='".$item_id."'>".$item['name']."</option>";
			endif;
		endforeach;
		echo json_encode(array(
				'stock_items' => $stock_items,
		));
		exit;
	}
}




