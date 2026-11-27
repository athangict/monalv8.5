<?php
namespace Fleet\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\Stdlib\ArrayObject;
use Laminas\Validator\File\Size;
use Laminas\Validator\File\Extension;
use Laminas\Authentication\AuthenticationService;
use Interop\Container\ContainerInterface;
use Accounts\Model As Accounts;
use Asset\Model As Asset;
use Acl\Model As Acl;
use Administration\Model As Administration;
use Hr\Model As Hr;
use Fleet\Model As Fleet;
use Stock\Model As Stock;
class VehicleController extends AbstractActionController
{   
	protected $_userloc;
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
    protected $_connection; //Transaction connection
    
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
        $definedTable = $this->_container->get($table);
        return $definedTable;
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
		$this->_userloc = $this->_user->location;
		
		$this->_id = $this->params()->fromRoute('id');
		
	    $this->_created = date('Y-m-d H:i:s');
		$this->_modified = date('Y-m-d H:i:s');
		
		//$this->_dir =realpath($fileManagerDir);

		//$this->_safedataObj =  $this->SafeDataPlugin();
		
		$this->_safedataObj = $this->safedata();
		$this->_connection = $this->_container->get('Laminas\Db\Adapter\Adapter')->getDriver()->getConnection();

	}
	
	private function getCurrentRoleIds()
	{
		$roles = array();
		foreach (explode(',', (string) $this->_login_role) as $role) {
			$role = trim($role);
			if ($role !== '' && ctype_digit($role)) {
				$roles[] = (int) $role;
			}
		}
		return $roles;
	}
	
	private function isPrivilegedUser()
	{
		$roles = $this->getCurrentRoleIds();
		return in_array(99, $roles, true) || in_array(100, $roles, true);
	}
	
	private function getRepairDetailRow($detailId)
	{
		$rows = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::Class)->get($detailId);
		foreach ($rows as $row) {
			return $row;
		}
		return null;
	}

	private function getVehicleAssets()
	{
		$vehicleTypeIds = array();
		$assetTypes = $this->getDefinedTable(Asset\AssettypeTable::class)->getAll();
		foreach ($assetTypes as $assetType):
			$typeName = strtolower(trim(strip_tags((string)($assetType['name'] ?? ''))));
			$typeCode = strtoupper(trim((string)($assetType['code'] ?? '')));			
			if (($typeName !== '' && strpos($typeName, 'vehicle') !== false) || $typeCode === 'V'):
				$vehicleTypeIds[] = (int)$assetType['id'];
			endif;
		endforeach;
		$vehicleTypeIds = array_values(array_unique(array_filter($vehicleTypeIds)));

		if(!empty($vehicleTypeIds)):
			return $this->getDefinedTable(Asset\AssetmanagementTable::class)->getasset(array('a.asset_type' => $vehicleTypeIds));
		endif;
		return $this->getDefinedTable(Asset\AssetmanagementTable::class)->getasset(array('a.status' => 1));
	}

	private function getOrCreateVehicleSubhead($headId, $vehicleId)
	{
		$headId = (int)$headId;
		$vehicleId = (int)$vehicleId;
		if($headId <= 0 || $vehicleId <= 0):
			return 0;
		endif;

		$subheadCode = trim((string)$this->getDefinedTable(Asset\AssetmanagementTable::class)->getColumn($vehicleId, 'code'));
		$subheadName = trim((string)$this->getDefinedTable(Asset\AssetmanagementTable::class)->getColumn($vehicleId, 'name'));
		if($subheadCode === '' || $subheadName === ''):
			return 0;
		endif;

		$subheadTable = $this->getDefinedTable(Accounts\SubheadTable::class);
		$existingSubhead = $subheadTable->getbanks(array(
			'head'   => $headId,
			'type'   => 1,
			'ref_id' => $vehicleId
		));
		if(!empty($existingSubhead)):
			$existingSubheadId = (int)$existingSubhead[0]['id'];
			$syncData = array(
				'id'       => $existingSubheadId,
				'code'     => $subheadCode,
				'name'     => $subheadName,
				'modified' => $this->_modified,
			);
			$syncData = $this->_safedataObj->rteSafe($syncData);
			$syncedSubheadId = (int)$subheadTable->save($syncData);
			return ($syncedSubheadId > 0) ? $existingSubheadId : 0;
		endif;

		$legacySubhead = $subheadTable->getbanks(array(
			'head' => $headId,
			'type' => 1,
			'code' => $subheadCode,
			'name' => $subheadName
		));
		if(!empty($legacySubhead)):
			$legacySubheadId = (int)$legacySubhead[0]['id'];
			$legacyUpdate = array(
				'id'       => $legacySubheadId,
				'ref_id'   => $vehicleId,
				'modified' => $this->_modified,
			);
			$legacyUpdate = $this->_safedataObj->rteSafe($legacyUpdate);
			$updatedLegacyId = (int)$subheadTable->save($legacyUpdate);
			return ($updatedLegacyId > 0) ? $legacySubheadId : 0;
		endif;

		$newSubhead = array(
			'head'     => $headId,
			'type'     => 1,
			'ref_id'   => $vehicleId,
			'code'     => $subheadCode,
			'name'     => $subheadName,
			'author'   => $this->_author,
			'created'  => $this->_created,
			'modified' => $this->_modified,
		);
		$newSubhead = $this->_safedataObj->rteSafe($newSubhead);
		return (int)$this->getDefinedTable(Accounts\SubheadTable::class)->save($newSubhead);
	}

	private function formatHeadListForDisplay(array $headList)
	{
		foreach($headList as &$headRow):
			$headCode = trim((string)(isset($headRow['code']) ? $headRow['code'] : ''));
			$headName = trim((string)(isset($headRow['name']) ? $headRow['name'] : ''));
			if($headCode !== '' && $headName !== ''):
				$headRow['code'] = $headCode.'-'.$headName;
			endif;
		endforeach;
		unset($headRow);
		return $headList;
	}

	private function getRepairHeadsByLocation($locationId, $classFilter = '')
	{
		$locationScope = $this->getLocationScopeWithGlobal($locationId);
		$vehicleRegisterTable = $this->getDefinedTable(Fleet\VehicleRegisterTable::class);
		$vehicleRegs = $vehicleRegisterTable->get(array(
			'location' => $locationScope
		));
		if(empty($vehicleRegs)):
			$vehicleRegs = $vehicleRegisterTable->getAll();
		endif;
		$headIds = array();
		foreach($vehicleRegs as $vehicleReg):
			$headId = isset($vehicleReg['head']) ? (int)$vehicleReg['head'] : 0;
			if($headId > 0):
				$headIds[] = $headId;
			endif;
		endforeach;
		$headIds = array_values(array_unique($headIds));
		if(empty($headIds)):
			return array();
		endif;

		$headList = $this->getDefinedTable(Accounts\HeadTable::class)->get(array('h.id' => $headIds));
		$classFilter = strtolower(trim((string)$classFilter));
		if($classFilter !== ''):
			$headList = array_values(array_filter($headList, function($headRow) use ($classFilter) {
				$headClass = strtolower(trim((string)(isset($headRow['class']) ? $headRow['class'] : '')));
				$headClassCode = strtolower(trim((string)(isset($headRow['class_code']) ? $headRow['class_code'] : '')));
				$headClassId = isset($headRow['class_id']) ? (int)$headRow['class_id'] : 0;
				if($classFilter === 'income'):
					return strpos($headClass, 'income') !== false
						|| strpos($headClassCode, 'income') !== false
						|| $headClassId === 3;
				endif;
				return $headClass === $classFilter || $headClassCode === $classFilter;
			}));
		endif;
		return $this->formatHeadListForDisplay($headList);
	}

	private function getHeadOptionsHtml(array $headList)
	{
		$options = "<option value=''></option>";
		foreach($headList as $head):
			$options .= "<option value='".$head['id']."'>".$head['code']."</option>";
		endforeach;
		return $options;
	}

	private function getSubheadOptionsHtml(array $subheadList)
	{
		$options = "<option value='0'></option>";
		foreach($subheadList as $subhead):
			$options .= "<option value='".$subhead['id']."'>".$subhead['name']."</option>";
		endforeach;
		return $options;
	}

	private function getLocationScopeWithGlobal($locationId)
	{
		$scope = array(-1);
		$locationId = (int)$locationId;
		if($locationId <= 0):
			$locationId = isset($this->_user->location) ? (int)$this->_user->location : 0;
		endif;
		if($locationId > 0):
			$scope[] = $locationId;
		endif;
		return array_values(array_unique($scope));
	}

	private function getPartySubheadsByLocation($locationId)
	{
		$locationScope = $this->getLocationScopeWithGlobal($locationId);
		$partyRows = $this->getDefinedTable(Accounts\PartyTable::class)->get(array('p.location'=>$locationScope));
		$partyIds = array();
		foreach($partyRows as $partyRow):
			if(isset($partyRow['id']) && (int)$partyRow['id'] > 0):
				$partyIds[] = (int)$partyRow['id'];
			endif;
		endforeach;
		$partyIds = array_values(array_unique($partyIds));
		if(empty($partyIds)):
			return array();
		endif;
		return $this->getDefinedTable(Accounts\SubheadTable::class)->get(array('sh.type'=>2, 'sh.ref_id'=>$partyIds));
	}

	private function getBankAccountsByLocation($locationId)
	{
		$locationScope = $this->getLocationScopeWithGlobal($locationId);
		return $this->getDefinedTable(Accounts\BankaccountTable::class)->get(array('ba.location'=>$locationScope));
	}

	private function getCashAccountsByLocation($locationId)
	{
		$locationScope = $this->getLocationScopeWithGlobal($locationId);
		return $this->getDefinedTable(Accounts\CashaccountTable::class)->get(array('ca.location'=>$locationScope));
	}
	
	private function canAccessRepairDetail($detailId)
	{
		$detail = $this->getRepairDetailRow($detailId);
		if ($detail === null || !isset($detail['repair'])) {
			return false;
		}
		if ($this->isPrivilegedUser()) {
			return true;
		}
		$repairs = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->get($detail['repair']);
		foreach ($repairs as $repair) {
			return isset($repair['author']) && (int) $repair['author'] === (int) $this->_login_id;
		}
		return false;
	}
	/**
	 * vehicle index action
	 */
	public function indexAction()
	{
		$this->init();		
			
		return new ViewModel( array(
				'title' => "Vehicle Set up",
		));
	}
	/**
	 * Vehicle records 
	*/
	public function vehicleAction()
	{
		$this->init();
		return new ViewModel( array(
			'title' => " Transport ",
			'transports' => $this->getDefinedTable(Fleet\TransportTable::class)->getAll(),
			'locationObj' 	=> $this->getDefinedTable(Administration\LocationTable::class),
			'headObj'   	=> $this->getDefinedTable(Accounts\HeadTable::class),
			'subheadObj'   	=> $this->getDefinedTable(Accounts\SubheadTable::class),
			'employeeObj' => $this->getDefinedTable(Hr\EmployeeTable::class),
			'groupObj' => $this->getDefinedTable(Fleet\VehicleGroupTable::class),
		));
	}
	/**
	 * addvehiclerecord Action
	 */
	public function addvehiclerecordAction()
	{
		$this->init();
		if($this->getRequest()->isPost()){
			$form = $this->getRequest()->getpost();					
			$data = array(
				'license_plate'        => $form['license_plate'],
				'location'             => $form['location'],
				'registered_date'       => $form['registered_date'],
				'group'       			=> $form['group'],
				'status' 		       => 1,
				'author'               => $this->_author,
				'created'              => $this->_created,
				'modified'             => $this->_modified
			);
			$data   = $this->_safedataObj->rteSafe($data);
			//$this->_connection->beginTransaction(); //***Transaction begins here***//
			$result = $this->getDefinedTable(Fleet\TransportTable::class)->save($data);
			if($result > 0){ 		
				$this->flashMessenger()->addMessage("success^ Successfully add new transport");
				return $this->redirect()->toRoute('vehicle', array('action' =>'vehicle'));
			}
			else{
				//$this->_connection->rollback(); // rollback transaction on failure
				$this->flashMessenger()->addMessage("error^ Failed to add new transport");
				return $this->redirect()->toRoute('transport');
			}		
		}	
		$ViewModel = new ViewModel(array(
			'title' 		  => "Add Vehicle Record",
			'user_loc'        => $this->_userloc,
			'regionObj'       => $this->getDefinedTable(Administration\RegionTable::class),
			'locationObj' 	  => $this->getDefinedTable(Administration\LocationTable::class),
			'activities'  	  => $this->getDefinedTable(Administration\ActivityTable::class)->getAll(),
			'parties'   	  => $this->getDefinedTable(Accounts\PartyTable::class)->get(array('p.role'=>9)),
			'employee_drivers'=> $this->getDefinedTable(Hr\EmployeeTable::class)->getAllEmployee(),
			'vehiclepartObj'  => $this->getDefinedTable(Fleet\VehiclePartTable::class),
			'rowsets'         => $this->getDefinedTable(Accounts\AssetsTable::class)->getAll(),
			'classesObj'      => $this->getDefinedTable(Fleet\VehicleClassTable::class),
			'makeObj'         => $this->getDefinedTable(Fleet\MakeTable::class),
			'groupObj'        => $this->getDefinedTable(Fleet\VehicleGroupTable::class),
			'fuelObj'         => $this->getDefinedTable(Fleet\FuelTable::class),
			'employees'       => $this->getDefinedTable(Hr\EmployeeTable::class)->getAllEmployee(),
			'head' => $this->getDefinedTable(Accounts\HeadTable::class)->get(array('h.id'=>array(8,176,179,190,198))),
			'subhead' => $this->getDefinedTable(Accounts\SubheadTable::class)->get(array('sh.head'=>array(8,176,179,190,198))),
		));

		$ViewModel->setTerminal(True);
		return $ViewModel;
	}
	
	/**
	 * editvehiclerecord Action
	 */
	public function editvehiclerecordAction()
	{
		$this->init();
		if($this->getRequest()->isPost()):
			$request= $this->getRequest();
			$form = $request->getPost();				
			$data = array(
				'id'                   => $this->_id,
				'license_plate'        => $form['license_plate'],
				'location'             => $form['location'],
				'registered_date'       => $form['registered_date'],
				'group'       			=> $form['group'],
				'status' 		       => 1,
				'author'               => $this->_author,
				'created'              => $this->_created,
				'modified'             => $this->_modified
			);
			$data   = $this->_safedataObj->rteSafe($data);
			$result = $this->getDefinedTable(Fleet\TransportTable::class)->save($data);
			if($result > 0){ 		
					$this->flashMessenger()->addMessage("success^ Successfully add new transport");
					return $this->redirect()->toRoute('vehicle', array('action' =>'vehicle'));
				}
				else{
					//$this->_connection->rollback(); // rollback transaction on failure
					$this->flashMessenger()->addMessage("error^ Failed to add new transport");
					return $this->redirect()->toRoute('transport');
				}					
			endif;	
		$ViewModel = new ViewModel(array(
			'user_loc'        => $this->_userloc,
			'rowset'       => $this->getDefinedTable(Fleet\TransportTable::class)->get($this->_id),
			'regionObj'       => $this->getDefinedTable(Administration\RegionTable::class),
			'locationObj' 	  => $this->getDefinedTable(Administration\LocationTable::class),
			'activities'  	  => $this->getDefinedTable(Administration\ActivityTable::class)->getAll(),
			'employees'       => $this->getDefinedTable(Hr\EmployeeTable::class)->getAllEmployee(),
			'head' => $this->getDefinedTable(Accounts\HeadTable::class)->get(array('h.id'=>array(176,179,190,198,201))),
			'subhead' => $this->getDefinedTable(Accounts\SubheadTable::class)->get(array('sh.head'=>array(176,179,190,198,201))),
			'groupObj'      => $this->getDefinedTable(Fleet\VehicleGroupTable::class),
			
	    ));
		$ViewModel->setTerminal(True);
		return $ViewModel;
	}
	/**
	 * Vehicle records 
	*/
	public function vehiclesecondaryAction()
	{
		$this->init();
		return new ViewModel( array(
			'title' => " Transport ",
			'transports' => $this->getDefinedTable(Fleet\VehicleRegisterTable::class)->getAll(),
			'locationObj' 	=> $this->getDefinedTable(Administration\LocationTable::class),
			'headObj'   	=> $this->getDefinedTable(Accounts\HeadTable::class),
			'subheadObj'   	=> $this->getDefinedTable(Accounts\SubheadTable::class),
			'groupObj'   	=> $this->getDefinedTable(Fleet\VehicleGroupTable::class),
			'assetObj'   	=> $this->getDefinedTable(Asset\AssetmanagementTable::class),
			'employeeObj' => $this->getDefinedTable(Hr\EmployeeTable::class),
			'employeeObj' => $this->getDefinedTable(Hr\EmployeeTable::class),
			'recordObj' => $this->getDefinedTable(Fleet\TransportTable::class),
			
		));
	}
	/**
	 * addvehiclerecord Action
	 */
	public function addvehiclesecondaryAction()
	{
		$this->init();
		if($this->getRequest()->isPost()){
			$form = $this->getRequest()->getpost();
			$vehicleId = isset($form['vehicle']) ? (int)$form['vehicle'] : ((isset($form['license_plate'])) ? (int)$form['license_plate'] : 0);
			$headIds = isset($form['head']) ? (array)$form['head'] : array();

			if($vehicleId <= 0 || empty($headIds)):
				$this->flashMessenger()->addMessage("error^ Please select vehicle and head.");
				return $this->redirect()->toRoute('vehicle', array('action' =>'vehiclesecondary'));
			endif;

			$this->_connection->beginTransaction(); //***Transaction begins here***//
			$createdRows = 0;
			foreach($headIds as $headId):
				$headId = (int)$headId;
				if($headId <= 0):
					continue;
				endif;
				$subheadId = $this->getOrCreateVehicleSubhead($headId, $vehicleId);

				if($subheadId <= 0):
					$this->_connection->rollback(); // rollback transaction on failure
					$this->flashMessenger()->addMessage("error^ Failed to create subhead.");
					return $this->redirect()->toRoute('vehicle', array('action' =>'vehiclesecondary'));
				endif;

				$data = array(
					'asset_vehicle_id' => $vehicleId,
					'vehicle_group'    => isset($form['vehicle_group']) ? (int)$form['vehicle_group'] : null,
					'location'         => $form['location'],
					'head'             => $headId,
					'subhead'          => $subheadId,
					'registered_date'  => $form['registered_date'],
					'pol'              => $form['pol'],
					'status'           => 1,
					'author'           => $this->_author,
					'created'          => $this->_created,
					'modified'         => $this->_modified
				);
				$data   = $this->_safedataObj->rteSafe($data);
				$result = $this->getDefinedTable(Fleet\VehicleRegisterTable::class)->save($data);
				if($result <= 0):
					$this->_connection->rollback(); // rollback transaction on failure
					$this->flashMessenger()->addMessage("error^ Failed to add new transport");
					return $this->redirect()->toRoute('vehicle', array('action' =>'vehiclesecondary'));
				endif;
				$createdRows++;
			endforeach;

			if($createdRows <= 0):
				$this->_connection->rollback(); // rollback transaction on failure
				$this->flashMessenger()->addMessage("error^ Failed to add new transport");
				return $this->redirect()->toRoute('vehicle', array('action' =>'vehiclesecondary'));
			endif;

			$this->_connection->commit(); // commit transaction on success
			$this->flashMessenger()->addMessage("success^ Successfully added transport for selected heads");
			return $this->redirect()->toRoute('vehicle', array('action' =>'vehiclesecondary'));
		}	
		$ViewModel = new ViewModel(array(
			'title' 		  => "Add Vehicle Record",
			'user_loc'        => $this->_userloc,
			'regionObj'       => $this->getDefinedTable(Administration\RegionTable::class),
			'locationObj' 	  => $this->getDefinedTable(Administration\LocationTable::class),
			'activities'  	  => $this->getDefinedTable(Administration\ActivityTable::class)->getAll(),
			'parties'   	  => $this->getDefinedTable(Accounts\PartyTable::class)->get(array('p.role'=>9)),
			'employee_drivers'=> $this->getDefinedTable(Hr\EmployeeTable::class)->getAllEmployee(),
			'vehiclepartObj'  => $this->getDefinedTable(Fleet\VehiclePartTable::class),
			'rowsets'         => $this->getDefinedTable(Accounts\AssetsTable::class)->getAll(),
			'classesObj'      => $this->getDefinedTable(Fleet\VehicleClassTable::class),
			'makeObj'         => $this->getDefinedTable(Fleet\MakeTable::class),
			'pooldtnObj'      => $this->getDefinedTable(Fleet\TransportPDTable::class),
			'fuelObj'         => $this->getDefinedTable(Fleet\FuelTable::class),
			'employees'       => $this->getDefinedTable(Hr\EmployeeTable::class)->getAllEmployee(),
			'head' => $this->getDefinedTable(Accounts\HeadTable::class)->getAll(),
			'subhead' => $this->getDefinedTable(Accounts\SubheadTable::class)->getAll(),
			'groupObj'        => $this->getDefinedTable(Fleet\VehicleGroupTable::class),
			'vehicles' => $this->getVehicleAssets(),
		));

		$ViewModel->setTerminal(True);
		return $ViewModel;
	}
	
	/**
	 * editvehiclerecord Action
	 */
	public function editvehiclesecondaryAction()
	{
		$this->init();
		if($this->getRequest()->isPost()):
			$request= $this->getRequest();
			$form = $request->getPost();				
			$vehicleId = isset($form['vehicle']) ? (int)$form['vehicle'] : ((isset($form['license_plate'])) ? (int)$form['license_plate'] : 0);
			$headId = isset($form['head']) ? (int)$form['head'] : 0;
			if($vehicleId <= 0 || $headId <= 0):
				$this->flashMessenger()->addMessage("error^ Please select valid vehicle and head.");
				return $this->redirect()->toRoute('vehicle', array('action' =>'vehiclesecondary'));
			endif;

			$currentRows = $this->getDefinedTable(Fleet\VehicleRegisterTable::class)->get($this->_id);
			$currentRow = null;
			foreach($currentRows as $row):
				$currentRow = $row;
				break;
			endforeach;
			if($currentRow == null):
				$this->flashMessenger()->addMessage("error^ Vehicle secondary record not found.");
				return $this->redirect()->toRoute('vehicle', array('action' =>'vehiclesecondary'));
			endif;

			$currentSubheadId = 0;
			if(isset($currentRow['subhead'])):
				$currentSubheadRaw = trim((string)$currentRow['subhead']);
				$currentSubheadParts = array_filter(array_map('trim', explode(',', $currentSubheadRaw)));
				if(!empty($currentSubheadParts)):
					$currentSubheadId = (int)$currentSubheadParts[0];
				endif;
			endif;
			$currentVehicleId = isset($currentRow['asset_vehicle_id']) ? (int)$currentRow['asset_vehicle_id'] : 0;

			$this->_connection->beginTransaction(); //***Transaction begins here***//
			if($currentSubheadId > 0 && $currentVehicleId === $vehicleId):
				$subheadCode = trim((string)$this->getDefinedTable(Asset\AssetmanagementTable::class)->getColumn($vehicleId, 'code'));
				$subheadName = trim((string)$this->getDefinedTable(Asset\AssetmanagementTable::class)->getColumn($vehicleId, 'name'));
				if($subheadCode === '' || $subheadName === ''):
					$this->_connection->rollback(); // rollback transaction on failure
					$this->flashMessenger()->addMessage("error^ Failed to resolve vehicle code/name for subhead.");
					return $this->redirect()->toRoute('vehicle', array('action' =>'vehiclesecondary'));
				endif;
				$subheadUpdateData = array(
					'id'       => $currentSubheadId,
					'head'     => $headId,
					'code'     => $subheadCode,
					'name'     => $subheadName,
					'modified' => $this->_modified,
				);
				$subheadUpdateData = $this->_safedataObj->rteSafe($subheadUpdateData);
				$updatedSubheadId = (int)$this->getDefinedTable(Accounts\SubheadTable::class)->save($subheadUpdateData);
				if($updatedSubheadId <= 0):
					$this->_connection->rollback(); // rollback transaction on failure
					$this->flashMessenger()->addMessage("error^ Failed to move subhead to selected head.");
					return $this->redirect()->toRoute('vehicle', array('action' =>'vehiclesecondary'));
				endif;
				$subheadId = $currentSubheadId;
			else:
				$subheadId = $this->getOrCreateVehicleSubhead($headId, $vehicleId);
				if($subheadId <= 0):
					$this->_connection->rollback(); // rollback transaction on failure
					$this->flashMessenger()->addMessage("error^ Failed to create subhead.");
					return $this->redirect()->toRoute('vehicle', array('action' =>'vehiclesecondary'));
				endif;
			endif;

			$data = array(
				'id'                   => $this->_id,
				'asset_vehicle_id'     => $vehicleId,
				'vehicle_group'        => isset($form['vehicle_group']) ? (int)$form['vehicle_group'] : null,
				'location'             => $form['location'],
				'head'             		=> $headId,
				'subhead'             	=> $subheadId,
				'registered_date'       => $form['registered_date'],
				'pol'       			=> $form['pol'],
				'status' 		       => 1,
				'author'               => $this->_author,
				'created'              => $this->_created,
				'modified'             => $this->_modified
			);
			//print_r($data);exit;
			$data   = $this->_safedataObj->rteSafe($data);
			$result = $this->getDefinedTable(Fleet\VehicleRegisterTable::class)->save($data);
			if($result > 0){ 		
					$this->_connection->commit(); // commit transaction on success
					$this->flashMessenger()->addMessage("success^ Successfully add new transport");
					return $this->redirect()->toRoute('vehicle', array('action' =>'vehiclesecondary'));
				}
				else{
					$this->_connection->rollback(); // rollback transaction on failure
					$this->flashMessenger()->addMessage("error^ Failed to add new transport");
					return $this->redirect()->toRoute('transport');
				}					
			endif;	
		$ViewModel = new ViewModel(array(
			'user_loc'        => $this->_userloc,
			'rowset'       => $this->getDefinedTable(Fleet\VehicleRegisterTable::class)->get($this->_id),
			'regionObj'       => $this->getDefinedTable(Administration\RegionTable::class),
			'locationObj' 	  => $this->getDefinedTable(Administration\LocationTable::class),
			'activities'  	  => $this->getDefinedTable(Administration\ActivityTable::class)->getAll(),
			'employees'       => $this->getDefinedTable(Hr\EmployeeTable::class)->getAllEmployee(),
			'head' => $this->getDefinedTable(Accounts\HeadTable::class)->getAll(),
			'subhead' => $this->getDefinedTable(Accounts\SubheadTable::class)->getAll(),
			'groupObj'      => $this->getDefinedTable(Fleet\VehicleGroupTable::class),
			'vehicles'      => $this->getVehicleAssets(),
	    ));
		$ViewModel->setTerminal(True);
		return $ViewModel;
	}
	/**
	 *  View Vehicle Records 
	*/
	public function viewvehicleAction()
	{
		$this->init();
		return new ViewModel( array(
			'title'           => "View Transport",
			'transports'      => $this->getDefinedTable(Fleet\TransportTable::class)->get($this->_id),
			'transports_dtls' => $this->getDefinedTable(Fleet\TransportDetailsTable::class)->get(array('transport'=>$this->_id)),
			'locationObj' 	  => $this->getDefinedTable(Administration\LocationTable::class),
			'assetObj'   	  => $this->getDefinedTable(Accounts\AssetsTable::class),
			'employeeObj'     => $this->getDefinedTable(Hr\EmployeeTable::class),
			'fuelObj'         => $this->getDefinedTable(Fleet\FuelTable::class),
			'makeObj'         => $this->getDefinedTable(Fleet\MakeTable::class),
			'typeObj'         => $this->getDefinedTable(Fleet\TransportTypeTable::class),
			'pooldtnObj'      => $this->getDefinedTable(Fleet\TransportPDTable::class),
			'trans_histories' => $this->getDefinedTable(Fleet\TransportHistoryTable::class)->get(array('transport' =>$this->_id)),
		));
	}
	/**
	 * View trans history records 
	*/
	public function transhistoryAction()
	{
		$this->init();
		if($this->_id <= 0):
            $this->flashmessenger()->addMessage('notice^ Add Transport details first');
            return $this->redirect()->toRoute('vehicle', array('action' => 'addvehiclerecord'));
		endif;
		return new ViewModel( array(
			'title'           => " View Transport ",
			'id'              => $this->_id,
			'transports'      => $this->getDefinedTable(Fleet\TransportTable::class)->get($this->_id),
			'trans_histories' => $this->getDefinedTable(Fleet\TransportHistoryTable::class)->get(array('transport' =>$this->_id)),
			'typeObj'         => $this->getDefinedTable(Fleet\TransportTypeTable::class),
		));
	}
	/**
	 * addtranshistory Action
	*/
	public function addtranshistoryAction()
	{
		$this->init();		
		if($this->getRequest()->isPost()){
			$form = $this->getRequest();
			$data=array(
				'transport'      => $this->_id,
				'recording_date' => $form->getPost('recording_date'),
				'type'           => $form->getPost('type'),
				'start_date'     => $form->getPost('start_date'),
				'end_date'       => $form->getPost('end_date'),
				'amount'         => $form->getPost('amount'),
				'author'         => $this->_author,
				'created'        => $this->_created,
				'modified'       => $this->_modified,
			);
			$data = $this->_safedataObj->rteSafe($data);
			$result = $this->getDefinedTable('Fleet\TransportHistoryTable')->save($data);	
			if($result > 0):
				$this->flashMessenger()->addMessage("success^ New transport history successfully added");
			else:
				$this->flashMessenger()->addMessage("Failed^ Failed to add new New transport history");
			endif;
			return $this->redirect()->toRoute('vehicle', array('action' => 'transhistory', 'id' => $this->_id));
		}	
		$ViewModel = new ViewModel(array(
			'title' => 'Add Transport History',
			'id' => $this->_id,
			'typeObj' => $this->getDefinedTable('Fleet\TransportTypeTable'),
		));

		$ViewModel->setTerminal(True);
		return $ViewModel;
	}
    /**
	 *Edit transaction history action
	**/
	public function edittranshistoryAction()
	{
		$this->init();
		if($this->getRequest()->isPost()){
			$form = $this->getRequest()->getPost();
			$data=array(
			    'id'             => $this->_id,
				'transport'      => $form['transport_his_id'], 
				'recording_date' => $form['recording_date'],
				'type'           => $form['type'],
				'start_date'     => $form['start_date'],
				'end_date'       => $form['end_date'],
				'amount'         => $form['amount'],
				'author'         => $this->_author,
				'modified'       => $this->_modified,
			);
			$data = $this->_safedataObj->rteSafe($data);
			$result = $this->getDefinedTable('Fleet\TransportHistoryTable')->save($data);
	
			if($result > 0):
			$this->flashMessenger()->addMessage("success^ Transport History successfully Updated");
			else:
			$this->flashMessenger()->addMessage("Failed^ Failed to Update Transport History");
			endif;
			return $this->redirect()->toRoute('vehicle', array('action' => 'transhistory', 'id' => $form['transport_his_id']));
		}
		$ViewModel = new ViewModel(array(
				'id'              => $this->_id,
				'title'           => 'Edit Transport History',
				'trans_histories' => $this->getDefinedTable('Fleet\TransportHistoryTable')->get($this->_id),
			    'typeObj'         => $this->getDefinedTable('Fleet\TransportTypeTable'),
		));
		$ViewModel->setTerminal(True);
		return $ViewModel;
	}
	
	/**
	 * View trans history records 
	*/
	public function transdetailAction()
	{
		$this->init();
		if($this->_id <= 0):
            $this->flashmessenger()->addMessage('notice^ Add Transport details first');
            return $this->redirect()->toRoute('vehicle', array('action' => 'addvehiclerecord'));
		endif;
		return new ViewModel( array(
			'title'           => " Transport ",
			'id'              => $this->_id,
			'transports'      => $this->getDefinedTable(Fleet\TransportTable::class)->get($this->_id),
			'trans_dtls'      => $this->getDefinedTable(Fleet\TransportDetailsTable::class)->get(array('transport' =>$this->_id)),
		    'pooldtnObj'      => $this->getDefinedTable(Fleet\TransportPDTable::class),
			'employeeObj'     => $this->getDefinedTable(Hr\EmployeeTable::class),

		));
	}
	/**
	 * addtranshistory Action
	*/
	public function addtransdetailAction()
	{
		$this->init();		
		if($this->getRequest()->isPost()){
			$form = $this->getRequest();
			$data=array(
				'transport'       => $this->_id,
				'date_of_handing' => $form->getPost('date_of_handing'),
				'pool_designated' => $form->getPost('pool_designated'),
				'employee'        => $form->getPost('employee'),
				'driver'          => $form->getPost('driver'),
				'remarks'          => $form->getPost('remark'),
				'author'          => $this->_author,
				'created'         => $this->_created,
				'modified'        => $this->_modified,
			);
			$data = $this->_safedataObj->rteSafe($data);
			$result = $this->getDefinedTable(Fleet\TransportDetailsTable::class)->save($data);	
			if($result > 0):
				$this->flashMessenger()->addMessage("success^ New transport details successfully added");
			else:
				$this->flashMessenger()->addMessage("Failed^ Failed to add new New transport details");
			endif;
			return $this->redirect()->toRoute('vehicle', array('action' => 'transdetail', 'id' => $this->_id));
		}	
		$ViewModel = new ViewModel(array(
			'title'            => 'Add Transport Detail',
			'id'               => $this->_id,
			'pooldtnObj'       => $this->getDefinedTable('Fleet\TransportPDTable'),
			'employee_drivers' => $this->getDefinedTable(Hr\EmployeeTable::class)->getAllEmployee(),
			'employeeObj'      => $this->getDefinedTable(Hr\EmployeeTable::class),
		));

		$ViewModel->setTerminal(True);
		return $ViewModel;
	}
    /**
	 *Edit transaction history action
	**/
	public function edittransdetailAction()
	{
		$this->init();
		if($this->getRequest()->isPost()){
			$form = $this->getRequest()->getPost();
			$data=array(
			    'id'               => $this->_id,
				'transport'        => $form['transport_dtls_id'], 
				'date_of_handing'  => $form['date_of_handing'],
				'pool_designated'  => $form['pool_designated'],
				'employee'         => $form['employee'],
				'driver'           => $form['driver'],
				'remarks'          => $form['remarks'],
				'author'           => $this->_author,
				'modified'         => $this->_modified,
			);
			$data = $this->_safedataObj->rteSafe($data);
			$result = $this->getDefinedTable(Fleet\TransportDetailsTable::class)->save($data);
	
			if($result > 0):
			$this->flashMessenger()->addMessage("success^ Transport Detail successfully Updated");
			else:
			$this->flashMessenger()->addMessage("Failed^ Failed to Update Transport Detail");
			endif;
			return $this->redirect()->toRoute('vehicle', array('action' => 'transdetail', 'id' => $form['transport_dtls_id']));
		}
		$ViewModel = new ViewModel(array(
			'title'            => 'Edit Transport Detail',
			'id'               => $this->_id,
			'pooldtnObj'       => $this->getDefinedTable('Fleet\TransportPDTable'),
			'employee_drivers' => $this->getDefinedTable(Hr\EmployeeTable::class)->getAllEmployee(),
			'employeeObj'      => $this->getDefinedTable(Hr\EmployeeTable::class),
			'trans_dtls'       => $this->getDefinedTable(Fleet\TransportDetailsTable::class)->get($this->_id),
	        'transports'       => $this->getDefinedTable(Fleet\TransportTable::class)->get($this->_id),
		));
		$ViewModel->setTerminal(True);
		return $ViewModel;
	}
	
	/**
	 * Get Uom
	**/
	public function getuomAction()
	{
		$form = $this->getRequest()->getPost();
		$itemId =$form['itemId'];
		$item = $this->getDefinedTable(Fleet\VehiclePartTable::class)->get(array('vp.id'=>$itemId));
		$selectedUomId = $item[0]['uom'];
		$uom = $this->getDefinedTable(Stock\UomTable::class)->get(array('id' => $selectedUomId));
		$uomop = "<option value=''></option>";
		foreach($item as $items);
		$uom = $this->getDefinedTable(Stock\UomTable::class)->get(array('id'=>$items['uom']));
		foreach($uom as $uoms):
			$selected = ($uoms['id'] == $selectedUomId) ? "selected" : "";
        $uomop .= "<option value='" . $uoms['id'] . "' $selected>" . $uoms['code'] . "</option>";
		endforeach;
		echo json_encode(array(
				'uom' => $uomop,
		));
		exit;
	}
	/*
	 *repair Action
	*/
	public function repairAction()
     {
       $this->init();
	   $month = '';
	   $year = '';
		if($this->getRequest()->isPost())
		{
			$form = $this->getRequest()->getPost();
			
			$year = $form['year'];
			$month = $form['month'];
			if(strlen($month)==1){
				$month = '0'.$month;
			}
		}else{
			$month = ($month == '')?date('m'):$month;
			$year = ($year == '')? date('Y'):$year;
		}
		$month = ($month == '')?date('m'):$month;
		$year = ($year == '')? date('Y'):$year;
		
		$minYear = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->getMin('work_order_date');
		$minYear = ($minYear == "")?date('Y-m-d'):$minYear;
		$minYear = date('Y', strtotime($minYear));
		$data = array(
			'year' => $year,
			'month' => $month,
			'minYear' => $minYear,
		);
		$results = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->getLocDateWise('work_order_date',$year,$month,array('type'=>1));
		//print_r($results); exit;
		return new ViewModel(array(
			'title' 	  => 'Repair',
			'data'        => $data,
			'repair_maintaneses' => $results,
		));                
     }
	/**
	 * Vehicle Repair  
	 */
	public function viewrepairAction()
	{
		$this->init();
		$params = explode("-", (string) $this->_id);
		if (isset($params['1']) && $params['1'] == '1' && isset($params['2']) && $params['2'] > 0) {
			$flag = $this->getDefinedTable(Acl\NotifyTable::class)->getColumn($params['2'], 'flag'); 
				if($flag == "0") {
					$notify = array('id' => $params['2'], 'flag'=>'1');
					$this->getDefinedTable(Acl\NotifyTable::class)->save($notify); 	
				}				
		}
        $dispatch_ID = $params['0'];
		return new ViewModel( array(
		    'id'             =>$this->_id,
			'title'              => "View Transport Expenses",
			'repair' 			  => $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->get($this->_id),
			'RMObj'              => $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class),
			'RMDObject'          => $this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::class),
			'locationObj' 	     => $this->getDefinedTable(Administration\LocationTable::class),
			'vehiclepartObj'     => $this->getDefinedTable(Fleet\VehiclePartTable::class),
			'transObj'   => $this->getDefinedTable(Accounts\TransactionTable::class),
			'uomObj'             => $this->getDefinedTable(Stock\UomTable::class),
			'userObj'   => $this->getDefinedTable(Administration\UsersTable::class),
			'serviceObj' => $this->getDefinedTable(Accounts\HeadTable::class),
			'vehicleObj' => $this->getDefinedTable(Accounts\SubheadTable::class),
			'partyObj' => $this->getDefinedTable(Accounts\SubheadTable::class),
			'userID'          => $this->_author,
			'user_location'   => $this->_userloc,
			'role'   =>$this->getDefinedTable(Administration\UsersTable::class)->getColumn($this->_author,'role'),
		));
	}
	
	/**
	 * Get gettransportDetails Action
	**/
	public function gettransportDetailsAction()
	{
		$this->init();		
		$form = $this->getRequest()->getPost();		
		$assets_id = $form['assets_id'];
		
		$assetDtls = $this->getDefinedTable(Fleet\TransportTable::class)->get(array('vehicle_code' =>$assets_id));
		foreach($assetDtls as $dtl);
		$locs .="<option value='".$dtl['location_id']."'selected>".$dtl['location']."</option>";      
		$tr_details = $this->getDefinedTable(Fleet\TransportDetailsTable::class)->get(array('td.transport' =>$dtl['id']));
		$driver .="<option value=''></option>";
		foreach($tr_details as $tr_dtl):
		    $driver .="<option value='".$tr_dtl['driver_id']."'selected>".$tr_dtl['driver']."</option>";
        endforeach;			
		echo json_encode(array(
			'locs' => $locs,
			'driver' =>$driver,
		));
		exit;
	}
	/**
	 * Get Subhaed Action
	**/
	public function getpartyAction()
	{
		$this->init();		
		$form = $this->getRequest()->getPost();		
		$partyid = $form['partyid'];
		$subhead=' ';
		$party = $this->getDefinedTable(Accounts\SubheadTable::class)->get(array('head' =>$partyid));
		$subhead .="<option value=''></option>";
		foreach($party as $partys):
		    $subhead .="<option value='".$partys['id']."'selected>".$partys['name']."</option>";
        endforeach;			
		echo json_encode(array(
			'subhead' =>$subhead,
		));
		exit;
	}
	/**
	 * addrepair Action
	 */
	public function addrepairAction()
	{
		$this->init();
		$location_id = $this->_userloc;
		if($this->getRequest()->isPost()){
			$form = $this->getRequest()->getpost();					
			
			$location_prefix = $this->getDefinedTable(Administration\LocationTable::class)->getcolumn($location_id,'prefix');	
			$date = date('ym',strtotime($form['date']));			
			$tmp_RMNo = $location_prefix."RM".$date; 			
			$results = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->getMonthlyRM($tmp_RMNo);
			
			$rm_no_list = array();
			
			foreach($results as $result):
				array_push($rm_no_list, substr($result['repair_no'], 12));
			endforeach;
			
			$next_serial = empty($rm_no_list) ? 1 : (max($rm_no_list) + 1);
			switch(strlen((string)$next_serial)){
				case 1: $next_rm_serial = "000".$next_serial; break;
				case 2: $next_rm_serial = "00".$next_serial;  break;
				case 3: $next_rm_serial = "0".$next_serial;   break;
				default: $next_rm_serial = $next_serial;       break;
			}
			
			$rm_no = $tmp_RMNo.$next_rm_serial;
	      
			$data = array(
				'repair_no'        => $rm_no,
				'work_order_date'  => $form['date'], 					 
				'location'         => $form['location'],
				'total_amount'     => $form['rm_amount'],
				'status' 		   => '2', 
				'type' 		   	   => '1', 
				'remarks'     => $form['remarks'],
				'author'           => $this->_author,
				'created'          => $this->_created,
				'modified'         => $this->_modified
			);
			$data   = $this->_safedataObj->rteSafe($data);
			$this->_connection->beginTransaction(); //***Transaction begins here***//
			$result = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->save($data);
			if($result > 0){
				$service_item = $form['service_item'];
				$service      = $form['service'];
				$amount       = $form['amount'];
				$remarks      = $form['note'];
				$location      = $form['location'];
				$party     	   = $form['party'];
				$ref_no        = $form['ref_no'];
				$license_plate = $form['license_plate'];
				for($i=0; $i < sizeof($service_item); $i++):
					if(isset($service_item[$i])):
						$rm_details = array(
							'repair'         => $result,
							'service_item'   => $service_item[$i],
							'service'        => $service[$i],
							'amount'      	 => $amount[$i],
							'location'       => $location,
							'cash'       	 => 2,
							'party'       	 => $party,
							'ref_no'       	 => $ref_no,
							'license_plate'  => $license_plate[$i],
							'remarks' 	 	 => isset($remarks[$i]) ? $remarks[$i] : '',
							'author'    	 => $this->_author,
							'created'   	 => $this->_created,
							'modified'  	 => $this->_modified
						);
						$rm_details   = $this->_safedataObj->rteSafe($rm_details);
						$this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::class)->save($rm_details);		
					endif; 		     
				endfor;
				$this->_connection->commit(); // commit transaction on success
				$this->flashMessenger()->addMessage("success^ Successfully updated Vehicle and Maintanese :". $rm_no);
				return $this->redirect()->toRoute('vehicle', array('action' =>'viewrepair', 'id' => $result));
			}
			else{
				$this->_connection->rollback(); // rollback transaction on failuer
				$this->flashMessenger()->addMessage("error^ Failed to add new Vehicle and Maintanese");
				return $this->redirect()->toRoute('repair');
			}		
		}	
		$headList = $this->getRepairHeadsByLocation($this->_userloc);
		if(empty($headList)):
			$headList = $this->formatHeadListForDisplay($this->getDefinedTable(Accounts\HeadTable::class)->getAll());
		endif;

		return new ViewModel( array(
			'title' 		  => "Add Transport Expense",
			'user_loc'        => $this->_userloc,
			'regionObj'       => $this->getDefinedTable(Administration\RegionTable::class),
			'locationObj' 	  => $this->getDefinedTable(Administration\LocationTable::class),
			'vehiclepartObj'  => $this->getDefinedTable(Fleet\VehiclePartTable::class),
			'rowsets'         => $this->getDefinedTable(Accounts\AssetsTable::class)->getAll(),
			'head' 			  => $headList,
			'user_role'			=>$this->_user->role,
			//'party' 		  => $this->getDefinedTable(Accounts\PartyTable::class)->get(array('p.role'=>array(5,7))),
			'party' 		  => $this->getDefinedTable(Accounts\SubheadTable::class)->get(array('sh.type'=>2)),
			'heads' 		  => $this->getDefinedTable(Accounts\HeadTable::class)->getAll(),
	    ));
	}
	/**
	 * editrepair Action
	 */
	public function editrepairAction()
	{
		$this->init();
		
		if($this->getRequest()->isPost()){
			$form = $this->getRequest()->getpost();
			$data = array(
					'id'               => $form['repair_id'],
					'work_order_date'  => $form['date'], 					 
					'location'         => $form['location'],
					'total_amount'     => $form['rm_amount'],
					'status' 		   => '2', 
					'type' 		   	   => '1', 
					'remarks'     => $form['remarks'],
					'author'           => $this->_author,
					'created'          => $this->_created,
					'modified'         => $this->_modified
			);
			$data   = $this->_safedataObj->rteSafe($data);
			$result = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->save($data);
				$deletedDetails = isset($form['deleted_details_id']) ? (array)$form['deleted_details_id'] : array();
				foreach($deletedDetails as $deletedDetailId):
					$deletedDetailId = (int)$deletedDetailId;
					if($deletedDetailId <= 0):
						continue;
					endif;
					$deletedDetailRow = $this->getRepairDetailRow($deletedDetailId);
					if(!empty($deletedDetailRow) && isset($deletedDetailRow['repair']) && (int)$deletedDetailRow['repair'] === (int)$form['repair_id']):
						$this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::class)->remove($deletedDetailId);
					endif;
				endforeach;

				$details_id = isset($form['details_id']) ? (array)$form['details_id'] : array();
			    $service_item = isset($form['service_item']) ? (array)$form['service_item'] : array();
				$service      = isset($form['service']) ? (array)$form['service'] : array();
				$amount       = isset($form['amount']) ? (array)$form['amount'] : array();
				$remarks      = isset($form['note']) ? (array)$form['note'] : array();
				$location      = $form['location'];
				$party     	   = $form['party'];
				$ref_no        = $form['ref_no'];
				$license_plate = isset($form['license_plate']) ? (array)$form['license_plate'] : array();
				for($i=0; $i < sizeof($details_id); $i++):
					if(isset($service_item[$i]) && $service_item[$i] > 0):
					
						$po_details = array(
								'id'             => $details_id[$i],
								'repair'         => $result,
								'service_item'   => $service_item[$i],
								'service'        => $service[$i],
								'amount'      	 => $amount[$i],
								'location'       => $location,
								'cash'       	 => 2,
								'party'       	 => $party,
								'ref_no'       	 => $ref_no,
								'license_plate'  => $license_plate[$i],
								'remarks' 	 	 => isset($remarks[$i]) ? $remarks[$i] : '',
								'author'    	 => $this->_author,
								'created'   	 => $this->_created,
								'modified'  	 => $this->_modified
						);
		     		$po_details   = $this->_safedataObj->rteSafe($po_details);
			     	$this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::class)->save($po_details);		
				   	endif; 		     
				endfor;
				if(sizeof($details_id)!=sizeof($service_item)){
				for($i=sizeof($details_id); $i < sizeof($service_item); $i++):
					if(isset($service_item[$i]) && $service_item[$i] > 0):
					
						$po_details = array(
		      					'repair'         => $result,
								'service_item'   => $service_item[$i],
								'service'        => $service[$i],
								'amount'      	 => $amount[$i],
								'location'       => $location,
								'cash'       	 => 2,
								'party'       	 => $party,
								'ref_no'       	 => $ref_no,
								'license_plate'  => $license_plate[$i],
								'remarks' 	 	 => $remarks[$i],
								'author'    	 => $this->_author,
								'created'   	 => $this->_created,
								'modified'  	 => $this->_modified
						);
		     		$po_details   = $this->_safedataObj->rteSafe($po_details);
			     	$this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::class)->save($po_details);		
				   	endif; 		     
				endfor;
				}
			
		/*	$data   = $this->_safedataObj->rteSafe($data);
			$result1 = $this->getDefinedTable(Purchase\PurchaseOrderTable::class)->save($data);*/
			if($result > 0){
				$this->flashMessenger()->addMessage("success^ Successfully updated Transport Expense. ");
			}
			else{
				$this->flashMessenger()->addMessage("error^ Failed to update Transport Expense");
			}
			 return $this->redirect()->toRoute('vehicle',array('action'=>'viewrepair','id'=>$form['repair_id']));	
		}
		
		$expense_details = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::class)->get(array('rmd.repair' => $this->_id));
		$currentPartyId = 0;
		foreach($expense_details as $expenseDetailRow):
			if(isset($expenseDetailRow['party']) && (int)$expenseDetailRow['party'] > 0):
				$currentPartyId = (int)$expenseDetailRow['party'];
				break;
			endif;
		endforeach;
		$repairMainRows = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->get($this->_id);
		$repairLocationId = 0;
		foreach($repairMainRows as $repairMainRow):
			if(isset($repairMainRow['location_id']) && (int)$repairMainRow['location_id'] > 0):
				$repairLocationId = (int)$repairMainRow['location_id'];
			elseif(isset($repairMainRow['location']) && is_numeric((string)$repairMainRow['location'])):
				$repairLocationId = (int)$repairMainRow['location'];
			endif;
			break;
		endforeach;
		$serviceHeadList = ($repairLocationId > 0) ? $this->getRepairHeadsByLocation($repairLocationId) : array();
		if(empty($serviceHeadList)):
			$serviceHeadList = $this->formatHeadListForDisplay($this->getDefinedTable(Accounts\HeadTable::class)->get(array('h.id'=>array(8,176,179,198))));
		endif;
		$partyList = $this->getDefinedTable(Accounts\SubheadTable::class)->get(array('sh.type'=>2));
		if(empty($partyList)):
			$partyList = $this->getDefinedTable(Accounts\SubheadTable::class)->getAll();
		endif;
		$partyIdList = array();
		foreach($partyList as $partyRow):
			$partyIdList[] = (int)$partyRow['id'];
		endforeach;
		if($currentPartyId > 0 && !in_array($currentPartyId, $partyIdList, true)):
			$currentPartyRows = $this->getDefinedTable(Accounts\SubheadTable::class)->get(array('sh.id'=>$currentPartyId));
			foreach($currentPartyRows as $currentPartyRow):
				$partyList[] = $currentPartyRow;
				break;
			endforeach;
		endif;
		$currentPartyHeadId = ($currentPartyId > 0) ? (int)$this->getDefinedTable(Accounts\SubheadTable::class)->getColumn(array('id'=>$currentPartyId), 'head') : 0;
		return new ViewModel( array(
			'title' 		     => "Edit Transport Expense",
			'expense_details' 	 => $expense_details,
			'user_loc'           => $this->_userloc,
			'user_role'          => $this->_user->role,
			'regionObj'          => $this->getDefinedTable(Administration\RegionTable::class),
			'locationObj' 	     => $this->getDefinedTable(Administration\LocationTable::class),
			'activities'  	     => $this->getDefinedTable(Administration\ActivityTable::class)->getAll(),
			'party' 		  => $partyList,
			'employee_driver'    => $this->getDefinedTable(Hr\EmployeeTable::class)->get(array('e.position_title'=>array(26,30,34,38,39,25))),
			'userRoleObj'        => $this->getDefinedTable(Administration\UsersTable::class),
			'uomObj'             => $this->getDefinedTable(Stock\UomTable::class),
			'itemuomObj'         => $this->getDefinedTable(Stock\ItemUomTable::class),
			'vehiclepartObj'     => $this->getDefinedTable(Fleet\VehiclePartTable::class),
			'rowsets'            => $this->getDefinedTable(Accounts\AssetsTable::class)->getAll(),
			'repair_maintaneses' => $repairMainRows,
			'service' => $serviceHeadList,
			'heads' 		  => $this->getDefinedTable(Accounts\HeadTable::class)->get(array('h.id'=>array(8,62,142,144,233,234,235,236))),
			'current_party_head' => $currentPartyHeadId,
			'service_item' => $this->getDefinedTable(Fleet\VehicleRegisterTable::class)->getAll(),
			'subhead'            =>$this->getDefinedTable(Accounts\SubheadTable::class)->getAll(),
			'RMDObject'          => $this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::class),
	    ));
	}
	/**
	 * Delete expense details
	 */
	public function deleteAction()
	{
		$this->init(); 
		$repairdetails = $this->getRepairDetailRow((int) $this->_id);
		if (empty($repairdetails) || !$this->canAccessRepairDetail((int) $this->_id)) {
			$this->flashMessenger()->addMessage("error^ Invalid expense detail selection");
			return $this->redirect()->toRoute('vehicle',array('action' => 'repair'));
		}
		//foreach($this->getDefinedTable(Sales\SalesTable::Class)->get($salesd['sales']) as $sales);
		$result = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::Class)->remove($this->_id);
		if($result > 0):

				$this->flashMessenger()->addMessage("success^ Item deleted successfully");
			else:
				$this->_connection->rollback(); // rollback transaction over failure
				$this->flashMessenger()->addMessage("error^ Failed to delete item");
			endif;
			//end			
		
			return $this->redirect()->toRoute('vehicle',array('action' => 'editrepair','id'=>$repairdetails['repair']));	
	}
	/**
	 * delete transport expense action
	 */
		public function deleteexpenseAction()
	{
		
		$this->init();
		$repairdetails = $this->getRepairDetailRow((int) $this->_id);
		if (empty($repairdetails) || !$this->canAccessRepairDetail((int) $this->_id)) {
			$this->flashMessenger()->addMessage("error^ Invalid expense detail selection");
			return $this->redirect()->toRoute('vehicle',array('action' => 'repair'));
		}
		$t_id=$repairdetails['repair'];
		foreach($this->getDefinedTable(Accounts\TransactiondetailTable::Class)->get(array('td.transaction'=>$repairdetails['transaction'])) as $trand){
			$result2 = $this->getDefinedTable(Accounts\TransactiondetailTable::Class)->remove($trand['id']);
		}
		$result3=$this->getDefinedTable(Accounts\TransactionTable::Class)->remove($repairdetails['transaction']);
		$result =$this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::Class)->remove($repairdetails['id']);
		if($result > 0):
				$remainingDetails = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::Class)->get(array('repair'=>$t_id));
				if(empty($remainingDetails)):
					$this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::Class)->remove($t_id);
					$this->flashMessenger()->addMessage("success^ deleted successfully");
					return $this->redirect()->toRoute('vehicle',array('action' => 'repair'));
				endif;
				$this->flashMessenger()->addMessage("success^ deleted successfully");
			else:
				
				$this->flashMessenger()->addMessage("error^ Failed to delete");
			endif;
			//end			
		
			return $this->redirect()->toRoute('vehicle',array('action' => 'viewrepair','id'=>$t_id));	
		
	}
	 /* Delete income details
	 */
	public function deleteincomeAction()
	{
		$this->init(); 
		$repairdetails = $this->getRepairDetailRow((int) $this->_id);
		if (empty($repairdetails) || !$this->canAccessRepairDetail((int) $this->_id)) {
			$this->flashMessenger()->addMessage("error^ Invalid income detail selection");
			return $this->redirect()->toRoute('vehicle',array('action' => 'vehicleincome'));
		}
		//foreach($this->getDefinedTable(Sales\SalesTable::Class)->get($salesd['sales']) as $sales);
		$result = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::Class)->remove($this->_id);
		if($result > 0):

				$this->flashMessenger()->addMessage("success^ Item deleted successfully");
			else:
				$this->_connection->rollback(); // rollback transaction over failure
				$this->flashMessenger()->addMessage("error^ Failed to delete item");
			endif;
			//end			
		
			return $this->redirect()->toRoute('vehicle',array('action' => 'editvehicleincome','id'=>$repairdetails['repair']));	
	}
	 /**
	 * commitrm Action
	 */
	public function commitrmAction()
	{
		$this->init();
		//if($this->getRequest()->isPost()){
		$admin_locs = $this->getDefinedTable(Administration\UsersTable::class)->getColumn($this->_author,'location');
        $admin_loc_array = explode(',',$admin_locs);
		//if($this->getRequest()->isPost()){
		//	$form = $this->getRequest()->getPost();
			$xpenseresult = $this->_id;
			$repairdtls = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::class)->get(array('repair'=>$this->_id));
		
		/*	foreach($postage as $postages):
				echo '<pre>';print_r($postages);
			endforeach;exit;*/
			$location = $this->getDefinedTable(Administration\UsersTable::class)->getColumn(array('id'=>$this->_author),'location');
			$region = (int)$this->getDefinedTable(Administration\LocationTable::class)->getColumn($sourceLocation, 'region');
			if($region <= 0):
				$region = (int)$this->getDefinedTable(Administration\UsersTable::class)->getColumn(array('id'=>$this->_author),'region');
			endif;
			
				$statusUpdate = array(
				'id' 	=> $xpenseresult,
				'status' => 4,
				);
		
			$statusUpdate =  $this->_safedataObj->rteSafe($statusUpdate);
			$status= $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->save($statusUpdate);
			foreach($repairdtls as $repairdtl):
				//echo '<pre>';print_r($repairdtl);	endforeach;
			$loc = $this->getDefinedTable(Administration\LocationTable::class)->getcolumn($this->_user->location, 'prefix');
				$prefix = $this->getDefinedTable(Accounts\JournalTable::class)->getcolumn(8,'prefix');
				$date = date('ym',strtotime(date('Y-m-d')));
				$tmp_VCNo = $loc.'-'.$prefix.$date;
				
				$results = $this->getDefinedTable(Accounts\TransactionTable::class)->getSerial($tmp_VCNo);
				
				$pltp_no_list = array();
				foreach($results as $result):
					array_push($pltp_no_list, substr($result['voucher_no'], 14));
				endforeach;
				$next_serial = empty($pltp_no_list) ? 1 : (max($pltp_no_list) + 1);
					
				switch(strlen((string)$next_serial)){
					case 1: $next_dc_serial = "0000".$next_serial; break;
					case 2: $next_dc_serial = "000".$next_serial;  break;
					case 3: $next_dc_serial = "00".$next_serial;   break;
					case 4: $next_dc_serial = "0".$next_serial;    break;
					default: $next_dc_serial = $next_serial;       break;
				}	
				$voucher_no = $tmp_VCNo.$next_dc_serial;
				
			$data2 = array(
				'voucher_date' 	  	=> date('Y-m-d'),
				'voucher_type' 	  	=> 15,
				'region' 	  		=> $region,
				'voucher_no' 	  	=> $voucher_no,
				'voucher_amount' 	=> $repairdtl['amount'],
				'status' 	  		=> 4,
				'doc_id' 	  		=> "fleet",
				'doc_type' 	  		=> " ",
				'remark' 	  		=> $repairdtl['repair_no'],
				'author' 			 =>$this->_author,
				'created' 			 =>$this->_created,
				'modified' 			 =>$this->_modified,
			);
			
			$data2 =  $this->_safedataObj->rteSafe($data2);
			$result2 = $this->getDefinedTable(Accounts\TransactionTable::class)->save($data2);
			$data3 = array(
				'transaction' 	=> $result2,
				'voucher_dates' => $data2['voucher_date'],
				'voucher_types' 	=> 15,
				'location' 	  	=> $location,
				'head' 	  		=> $repairdtl['service'],
				'sub_head' 	  	=> $repairdtl['service_item'],
				'activity'		=>$location,
				'debit' 	  	=> $data2['voucher_amount'],
				'credit' 	  	=> 0,
				'ref_no' 	  	=> $repairdtl['ref_no'],
				'against' 	  	=> 0,
				'status' 	  	=> 4,
				'author' 		=>$this->_author,
				'created' 		=>$this->_created,
				'modified' 		=>$this->_modified,
			);
			$data3 =  $this->_safedataObj->rteSafe($data3);
			$this->_connection->beginTransaction(); //***Transaction begins here***//
			$result3 = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->save($data3);
			$ref=$repairdtl['party'];
				$subhead = $this->getDefinedTable(Accounts\SubheadTable::class)->getColumn(array('id'=>$ref),'id');
				$head = $this->getDefinedTable(Accounts\SubheadTable::class)->getColumn(array('id'=>$subhead),'head');
				$data4 = array(
					'transaction' 	=> $result2,
					'voucher_dates' => $data2['voucher_date'],
					'voucher_types' => 15,
					'location' 	  	=> $location,
					'head' 	  		=> $head,
					'sub_head' 	  	=> $subhead,
					'activity'      =>$location,
					'debit' 	  	=>0,
					'credit' 	  	=> $data2['voucher_amount'],
					'status' 	  	=> 4,
					'ref_no' 	  	=> 0,
					'against' 	  	=> 0,
					'activity'		=>$location,
					'author' 		=>$this->_author,
					'created' 		=>$this->_created,
					'modified' 		=>$this->_modified,
				);
			$data4 =  $this->_safedataObj->rteSafe($data4);
			$result4 = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->save($data4);
			$data5 = array(
				'id' 	=> $repairdtl['id'],
				'transaction' => $result2,
				);
			$data5 =  $this->_safedataObj->rteSafe($data5);
			$result5 = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::class)->save($data5);
		
			if($result5 > 0):
				$this->_connection->commit(); // rollback transaction over failure
				$this->flashMessenger()->addMessage("success^ successfully committed the data");
			else:
				$this->_connection->rollback(); // rollback transaction over failure
				$this->flashMessenger()->addMessage("notice^ Failed to commit  data");
			endif;
		endforeach;
			return $this->redirect()->toRoute('vehicle', array('action'=>'viewrepair','id'=>$xpenseresult));
			
		//}
		$ViewModel = new ViewModel(array(
			'title'   => 'Commit',
			'vehiclemain' => $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->get($this->_id),
			'vehiclemaindtlObj' => $this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::class),
			'party' => $this->getDefinedTable(Accounts\SubheadTable::class)->get(array('head'=>[76,79,90,91,92,93,94,95,96,97,112,118])),
			
		));
			$ViewModel->setTerminal(True);
		return $ViewModel;
	}
	/**
	 * add vehicle income Action
	 */
	public function addvehicleincomeAction()
	{
		$this->init();
		$location_id = $this->_userloc;
		if($this->getRequest()->isPost()){
			$form = $this->getRequest()->getpost();		
			//print_r($form['cash']);exit;
			if(empty($form['cash']) && $form['cash']!=0):
				$this->flashMessenger()->addMessage("warning^ Select Payment Mode");
				return $this->redirect()->toRoute('vehicle',array('action' => 'addvehicleincome'));
			endif;
			if($form['cash']==0 && empty($form['bankacc'])):
				$this->flashMessenger()->addMessage("warning^ Select Cash Account");
				return $this->redirect()->toRoute('vehicle',array('action' => 'addvehicleincome'));
			endif;
			if($form['cash']==1 && empty($form['cashacc'])):
				$this->flashMessenger()->addMessage("warning^ Select Bank Account");
				return $this->redirect()->toRoute('vehicle',array('action' => 'addvehicleincome'));
			endif;
			if($form['cash']==2 && empty($form['party'])):
				$this->flashMessenger()->addMessage("warning^ Select Credit Customer");
				return $this->redirect()->toRoute('vehicle',array('action' => 'addvehicleincome'));
			endif;
			$location_prefix = $this->getDefinedTable(Administration\LocationTable::class)->getcolumn($location_id,'prefix');	
			$date = date('ym',strtotime($form['date']));			
			$tmp_RMNo = $location_prefix."TI".$date; 			
			$results = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->getMonthlyRM($tmp_RMNo);
			
			$rm_no_list = array();
			
			foreach($results as $result):
				array_push($rm_no_list, substr($result['repair_no'], 12));
			endforeach;
			
			$next_serial = empty($rm_no_list) ? 1 : (max($rm_no_list) + 1);
			switch(strlen((string)$next_serial)){
				case 1: $next_rm_serial = "000".$next_serial; break;
				case 2: $next_rm_serial = "00".$next_serial;  break;
				case 3: $next_rm_serial = "0".$next_serial;   break;
				default: $next_rm_serial = $next_serial;       break;
			}
			
			$rm_no = $tmp_RMNo.$next_rm_serial;
	      
			$data = array(
				'repair_no'        => $rm_no,
				'work_order_date'  => $form['date'], 					 
				'location'         => $form['location'],
				'total_amount'     => $form['rm_amount'],
				'status' 		   => '2', 
				'type' 		   	   => '2', 
				'author'           => $this->_author,
				'created'          => $this->_created,
				'modified'         => $this->_modified
			);
			$data   = $this->_safedataObj->rteSafe($data);
			$this->_connection->beginTransaction(); //***Transaction begins here***//
			$result = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->save($data);
			if($result > 0){ 
				$service_item = $form['service_item'];
				$service      = $form['service'];
				$amount       = $form['amount'];
				$remarks      = $form['note'];
				$location      = $form['location'];
				$cash     	   = $form['cash'];
				$journal       = $form['journal_no'];
				$phone     	   = $form['phone'];
				$party     	   = $form['party'];
				$ref_no        = $form['ref_no'];
				$cashacc        = $form['cashacc'];
				$bankacc        = $form['bankacc'];
				if(empty($form['journal_no'])):$journal = 0;else:$journal = $form['journal_no'];endif;
				if(empty($form['phone'])):$phone = 0;else:$phone = $form['phone'];endif;
				if(empty($form['party'])):$party = 0;else:$party = $form['party'];endif;
				if(empty($form['ref_no'])):$ref_no = 0;else:$ref_no = $form['ref_no'];endif;
				if(empty($form['bankacc'])):$bankacc = 0;else:$bankacc = $form['bankacc'];endif;
				if(empty($form['cashacc'])):$cashacc = 0;else:$cashacc = $form['cashacc'];endif;
				//print_r(sizeof($service_item));exit;
				for($i=0; $i < sizeof($service_item); $i++):
					if(isset($service_item[$i])):
						$rm_details = array(
							'repair'         => $result,
							'service_item'   => $service_item[$i],
							'service'        => $service[$i],
							'amount'      	 => $amount[$i],
							'location'       => $location,
							'cash'       	 => $cash,
							'party'       	 => $party,
							'ref_no'       	 => $ref_no,
							'journal'        => $journal,
							'phone'        	 => $phone,
							'bankacc'        => $bankacc,
							'cashacc'        => $cashacc,
							'remarks' 	 	 => $remarks[$i],
							'author'    	 => $this->_author,
							'created'   	 => $this->_created,
							'modified'  	 => $this->_modified
						);
						//echo '<pre>';print_r($rm_details);exit;
						$rm_details   = $this->_safedataObj->rteSafe($rm_details);
						$this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::class)->save($rm_details);		
					endif; 		     
				endfor;
				$this->_connection->commit(); // commit transaction on success
				$this->flashMessenger()->addMessage("success^ Successfully updated Vehicle and Maintanese :". $rm_no);
				return $this->redirect()->toRoute('vehicle', array('action' =>'viewvehicleincome', 'id' => $result));
			}
			else{
				$this->_connection->rollback(); // rollback transaction on failuer
				$this->flashMessenger()->addMessage("error^ Failed to add new Vehicle and Maintanese");
				return $this->redirect()->toRoute('repair');
			}		
		}	
		return new ViewModel( array(
			'title' 		  => "Add Transport Income",
			'user_loc'        => $this->_userloc,
			'regionObj'       => $this->getDefinedTable(Administration\RegionTable::class),
			'locationObj' 	  => $this->getDefinedTable(Administration\LocationTable::class),
			'vehiclepartObj'  => $this->getDefinedTable(Fleet\VehiclePartTable::class),
			'rowsets'         => $this->getDefinedTable(Accounts\AssetsTable::class)->getAll(),
			'head' => $this->getRepairHeadsByLocation($this->_userloc, 'income'),
			'user_role'			=>$this->_user->role,
			//'party' => $this->getDefinedTable(Accounts\PartyTable::class)->get(array('p.role'=>array(11,12,13,15,18,19))),
			'party' => $this->getPartySubheadsByLocation($this->_userloc),
			'bankacc' => $this->getBankAccountsByLocation($this->_userloc),
			'cashacc' => $this->getCashAccountsByLocation($this->_userloc),
			'bankaccObj' => $this->getDefinedTable(Accounts\BankaccountTable::class),
			'cashaccObj' => $this->getDefinedTable(Accounts\CashaccountTable::class),
	    ));
	}
	/**
	 * edit vehicle income Action
	 */
	public function editvehicleincomeAction()
	{
		$this->init();
		
		if($this->getRequest()->isPost()){
			$form = $this->getRequest()->getpost();
			$data = array(
					'id'               => $form['repair_id'],
					'work_order_date'  => $form['date'], 					 
					'location'         => $form['location'],
					'total_amount'     => $form['rm_amount'],
					'status' 		   => '2', 
					'type' 		   	   => '2', 
					'author'           => $this->_author,
					'created'          => $this->_created,
					'modified'         => $this->_modified
			);
			$data   = $this->_safedataObj->rteSafe($data);
			$result = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->save($data);
				$deletedDetails = isset($form['deleted_details_id']) ? (array)$form['deleted_details_id'] : array();
				foreach($deletedDetails as $deletedDetailId):
					$deletedDetailId = (int)$deletedDetailId;
					if($deletedDetailId <= 0):
						continue;
					endif;
					$deletedDetailRow = $this->getRepairDetailRow($deletedDetailId);
					if(!empty($deletedDetailRow) && isset($deletedDetailRow['repair']) && (int)$deletedDetailRow['repair'] === (int)$form['repair_id']):
						$this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::class)->remove($deletedDetailId);
					endif;
				endforeach;

				$details_id = isset($form['details_id']) ? (array)$form['details_id'] : array();
				$service_item = isset($form['service_item']) ? (array)$form['service_item'] : array();
				$service      = isset($form['service']) ? (array)$form['service'] : array();
				$amount       = isset($form['amount']) ? (array)$form['amount'] : array();
				$remarks      = isset($form['note']) ? (array)$form['note'] : array();
				$location      = $form['location'];
				$cash     	   = $form['cash'];
				$journal       = $form['journal_no'];
				$phone     	   = $form['phone'];
				$party     	   = $form['party'];
				$ref_no        = $form['ref_no'];
				$cashacc        = $form['cashacc'];
				$bankacc        = $form['bankacc'];
				if(empty($form['journal_no'])):$journal = 0;else:$journal = $form['journal_no'];endif;
				if(empty($form['phone'])):$phone = 0;else:$phone = $form['phone'];endif;
				if(empty($form['party'])):$party = 0;else:$party = $form['party'];endif;
				if(empty($form['ref_no'])):$ref_no = 0;else:$ref_no = $form['ref_no'];endif;
				if(empty($form['bankacc'])):$bankacc = 0;else:$bankacc = $form['bankacc'];endif;
				if(empty($form['cashacc'])):$cashacc = 0;else:$cashacc = $form['cashacc'];endif;
				for($i=0; $i < sizeof($details_id); $i++):
					if(isset($service_item[$i]) && $service_item[$i] > 0):
					
						$rp_details = array(
								'id'             => $details_id[$i],
								'repair'         => $result,
								'service_item'   => $service_item[$i],
								'service'        => $service[$i],
								'amount'      	 => $amount[$i],
								'location'       => $location,
								'cash'       	 => $cash,
								'party'       	 => $party,
								'ref_no'       	 => $ref_no,
								'journal'        => $journal,
								'phone'        	 => $phone,
								'bankacc'        => $bankacc,
								'cashacc'        => $cashacc,
								'remarks' 	 	 => $remarks[$i],
								'author'    	 => $this->_author,
								'created'   	 => $this->_created,
								'modified'  	 => $this->_modified
						);
		     		$rp_details   = $this->_safedataObj->rteSafe($rp_details);
			     	$this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::class)->save($rp_details);		
				   	endif; 		     
				endfor;
				if(sizeof($details_id)!=sizeof($service_item)){
				for($i=sizeof($details_id); $i < sizeof($service_item); $i++):
					if(isset($service_item[$i]) && $service_item[$i] > 0):
					
						$rp_details = array(
		      					'repair'         => $result,
								'service_item'   => $service_item[$i],
								'service'        => $service[$i],
								'amount'      	 => $amount[$i],
								'location'       => $location,
								'cash'       	 => $cash,
								'party'       	 => $party,
								'ref_no'       	 => $ref_no,
								'journal'        => $journal,
								'phone'        	 => $phone,
								'bankacc'        => $bankacc,
								'cashacc'        => $cashacc,
								'remarks' 	 	 => $remarks[$i],
								'author'    	 => $this->_author,
								'created'   	 => $this->_created,
								'modified'  	 => $this->_modified
						);
		     		$rp_details   = $this->_safedataObj->rteSafe($rp_details);
			     	$this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::class)->save($rp_details);		
				   	endif; 		     
				endfor;
				}
			
		/*	$data   = $this->_safedataObj->rteSafe($data);
			$result1 = $this->getDefinedTable(Purchase\PurchaseOrderTable::class)->save($data);*/
			if($result > 0){
				$this->flashMessenger()->addMessage("success^ Successfully updated Transport Expense. ");
			}
			else{
				$this->flashMessenger()->addMessage("error^ Failed to update Transport Expense");
			}
			 return $this->redirect()->toRoute('vehicle',array('action'=>'viewvehicleincome','id'=>$form['repair_id']));	
		}
		
		$expense_details = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::class)->get(array('rmd.repair' => $this->_id));
		$incomeMainRows = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->get($this->_id);
		$incomeLocationId = 0;
		foreach($incomeMainRows as $incomeMainRow):
			if(isset($incomeMainRow['location_id']) && (int)$incomeMainRow['location_id'] > 0):
				$incomeLocationId = (int)$incomeMainRow['location_id'];
			elseif(isset($incomeMainRow['location']) && is_numeric((string)$incomeMainRow['location'])):
				$incomeLocationId = (int)$incomeMainRow['location'];
			endif;
			break;
		endforeach;
		if($incomeLocationId <= 0):
			$incomeLocationId = (int)$this->_userloc;
		endif;
		return new ViewModel( array(
			'title' 		     => "Edit Transport Income",
			'expense_details' 	 => $expense_details,
			'user_loc'           => $this->_userloc,
			'regionObj'          => $this->getDefinedTable(Administration\RegionTable::class),
			'locationObj' 	     => $this->getDefinedTable(Administration\LocationTable::class),
			'activities'  	     => $this->getDefinedTable(Administration\ActivityTable::class)->getAll(),
			'party' => $this->getPartySubheadsByLocation($incomeLocationId),
			'repair_maintaneses' => $incomeMainRows,
			'service' => $this->getRepairHeadsByLocation($incomeLocationId, 'income'),
			'bankacc' => $this->getBankAccountsByLocation($incomeLocationId),
			'cashacc' => $this->getCashAccountsByLocation($incomeLocationId),
			'subhead'            =>$this->getDefinedTable(Accounts\SubheadTable::class)->getAll(),
			'RMDObject'          => $this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::class),
			'bankaccObj' => $this->getDefinedTable(Accounts\BankaccountTable::class),
			'cashaccObj' => $this->getDefinedTable(Accounts\CashaccountTable::class),
	    ));
	}
	/**
	 * forward transport Action
	 *
	 */
	public function processtpAction()
	{
		$this->init();	
		if($this->getRequest()->isPost()):		
			$form = $this->getRequest()->getPost()->toArray();		
			$this->_connection->beginTransaction(); //***Transaction begins here***//
			   /* Send Request */
					$data = array(
						'id'			=> $form['tp_id'],
						'status' 		=> 6,
						'remarks'        => $form['remarks'],
						'author'	    => $this->_author,
						'modified'      => $this->_modified,
				    );
					$message = "Successfully Applied";
					$desc = "New Transport expense Applied";
					/*Get users under destination location with sub role Depoy Manager*/
					$sourceLocation = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->getColumn($form['tp_id'], 'location');			
			$result = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->save($data);		
			if($result):
				foreach($this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->get($result) as $repair);		
					$region = $this->getDefinedTable(Administration\UsersTable::class)->getColumn(array('id'=>$this->_author),'region');
					$loc = $this->getDefinedTable(Administration\LocationTable::class)->getcolumn($this->_user->location, 'prefix');
					$prefix = $this->getDefinedTable(Accounts\JournalTable::class)->getcolumn(11,'prefix');
					$date = date('ym',strtotime(date('Y-m-d')));
					$tmp_VCNo = $loc.'-'.$prefix.$date;
					
					$results = $this->getDefinedTable(Accounts\TransactionTable::class)->getSerial($tmp_VCNo);
					
					$pltp_no_list = array();
					foreach($results as $result):
						array_push($pltp_no_list, substr($result['voucher_no'], 14));
					endforeach;
					$next_serial = empty($pltp_no_list) ? 1 : (max($pltp_no_list) + 1);
						
					switch(strlen((string)$next_serial)){
						case 1: $next_dc_serial = "0000".$next_serial; break;
						case 2: $next_dc_serial = "000".$next_serial;  break;
						case 3: $next_dc_serial = "00".$next_serial;   break;
						case 4: $next_dc_serial = "0".$next_serial;    break;
						default: $next_dc_serial = $next_serial;       break;
					}	
					$voucher_no = $tmp_VCNo.$next_dc_serial;
						$trans=$this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->getColumn($form['tp_id'], 'transaction');
						if(!empty($trans)){
							$data = array(
								'id'				=> $trans,
								'voucher_date' 		=> $repair['work_order_date'],
								'voucher_type' 		=> 11,
								'region'   			=>$region,
								'doc_id'   			=>"Transport Expense",
								'voucher_amount' 	=> $repair['total_amount'],
								'status' 			=> 6, // status initiated 
								'remark'			=>$repair['remarks'],
								'author' 			=>$this->_author,
								'created' 			=>$this->_created,  
								'modified' 			=>$this->_modified,
							);
						$resultTrans = $this->getDefinedTable(Accounts\TransactionTable::class)->save($data);
						$current_flow = $this->getDefinedTable(Administration\FlowTransactionTable::class)->getMax($column='activity', $where=array('application'=>$resultTrans,'flow'=>2));
						$next_activity_no = $current_flow + 1;
						$flow=array(
							'flow' 				=> 2,
							'application' 		=> $resultTrans,
							'activity'			=>$next_activity_no,
							//'role_id'   		=>' ',
							'actor'   			=>3,
							'action' 			=> "2|4",
							'routing' 			=> 2,
							'status' 			=> 6, // status initiated 
							'routing_status'	=>2,
							'action_performed'	=>0,
							'description'		=>"Transport Expense",
							'author' 			=>$this->_author,
							'created' 			=>$this->_created,  
							'modified' 			=>$this->_modified,
						);
						$flow=$this->getDefinedTable(Administration\FlowTransactionTable::class)->save($flow);
		
						$repairdtls = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::class)->get(array('repair'=>$form['tp_id']));
		
						foreach($repairdtls as $repairdtl);
						$head=$this->getDefinedTable(Accounts\SubheadTable::class)->getColumn(array('id'=>$repairdtl['party']),'head');
						$td=$this->getDefinedTable(Accounts\TransactiondetailTable::class)->get(array('transaction'=>$resultTran));
						foreach($td as $tds):
							if(!empty($tds['credit'])){
								$transactionDtls2 = array(
									'id'				=>$tds['id'],
									'transaction' 		=> $resultTrans,
									'voucher_dates' 	=> $data['voucher_date'],
									'voucher_types' 	=> 11,
									'location' 			=> $sourceLocation,
									'head' 				=>$head,
									'sub_head' 			=>$repairdtl['party'],
									'bank_ref_type' 	=> '',
									'debit' 			=>'0.00',
									'credit' 			=>$repair['total_amount'],
									'ref_no'			=> $repairdtl['ref_no'], 
									'against' 			=> 0,
									'type' 				=> '1',//user inputted  data
									'status' 			=> 6, // status applied
									'activity'			=>$sourceLocation,
									'author' 			=>$this->_author,
									'created' 			=>$this->_created,
									'modified' 			=>$this->_modified,
								);
								$transactionDtls2 = $this->_safedataObj->rteSafe($transactionDtls2);
								$result2 = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->save($transactionDtls2);
							}
							else{
								$repairdtl = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::class)->get(array('repair'=>$form['tp_id']));
								foreach($repairdtl as $row):
									$transactionDtls1 = array(
										'id'				=>$tds['id'],
										'transaction'		=> $resultTrans,
										'voucher_dates' 	=> $data['voucher_date'],
										'voucher_types' 	=> 11,
										'location' 			=> $sourceLocation,
										'head' 	  			=> $row['service'],
										'sub_head' 	  		=> $row['service_item'],
										'against' 			=> 0,
										'bank_ref_type' 	=> '',
										'debit' 			=>$row['amount'],
										'credit' 			=>'0.00',
										'ref_no'			=> '', 
										'type' 				=> '1',//user inputted  data  
										'status' 			=> 6, // status appied
										'activity'			=>$sourceLocation,
										'author' 			=>$this->_author,
										'created' 			=>$this->_created,
										'modified' 			=>$this->_modified,
									);
								$transactionDtls1 = $this->_safedataObj->rteSafe($transactionDtls1);
								$result1 = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->save($transactionDtls1);
							endforeach;
							}
						endforeach;
						
							}
						else{
								$data = array(
									'voucher_date' 		=> $repair['work_order_date'],
									'voucher_type' 		=> 11,
									'region'   			=>$region,
									'doc_id'   			=>"Transport Expense",
									'voucher_no' 		=> $voucher_no,
									'against' 		=> 0,
									'voucher_amount' 	=> $repair['total_amount'],
									'status' 			=> 6, // status initiated 
									'remark'			=>$repair['remarks'],
									'author' 			=>$this->_author,
									'created' 			=>$this->_created,  
									'modified' 			=>$this->_modified,
								);
								$resultTrans = $this->getDefinedTable(Accounts\TransactionTable::class)->save($data);
							
							
							//if($resultTrans >0){
								$flow=array(
									'flow' 				=> 2,
									'application' 		=> $resultTrans,
									'activity'			=>$sourceLocation,
									//'role_id'   		=>' ',
									'actor'   			=>3,
									'action' 			=> "2|4",
									'routing' 			=> 2,
									'status' 			=> 6, // status initiated 
									'routing_status'	=>2,
									'action_performed'	=>0,
									'description'		=>"Transport Expense",
									'author' 			=>$this->_author,
									'created' 			=>$this->_created,  
									'modified' 			=>$this->_modified,
								);
								$flow=$this->getDefinedTable(Administration\FlowTransactionTable::class)->save($flow);
				
								$repairdtls = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::class)->get(array('repair'=>$form['tp_id']));
				
								foreach($repairdtls as $repairdtl);
								$head=$this->getDefinedTable(Accounts\SubheadTable::class)->getColumn(array('id'=>$repairdtl['party']),'head');
								$transactionDtls2 = array(
									'transaction' => $resultTrans,
									'voucher_dates' => $data['voucher_date'],
									'voucher_types' => 11,
									'location' => $sourceLocation,
									'head' =>$head,
									'sub_head' =>$repairdtl['party'],
									'bank_ref_type' => '',
									'debit' =>'0.00',
									'credit' =>$repair['total_amount'],
									'ref_no'=> $repairdtl['ref_no'], 
									'against' 		=> 0,
									'type' => '1',//user inputted  data
									'status' => 6, // status applied
									'activity'=>$sourceLocation,
									'author' =>$this->_author,
									'created' =>$this->_created,
									'modified' =>$this->_modified,
								);
								$transactionDtls2 = $this->_safedataObj->rteSafe($transactionDtls2);
								$result2 = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->save($transactionDtls2);
								$repairdtl = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::class)->get(array('repair'=>$form['tp_id']));
								foreach($repairdtl as $row):
										$transactionDtls1 = array(
											'transaction' => $resultTrans,
											'voucher_dates' => $data['voucher_date'],
											'voucher_types' => 11,
											'location' => $sourceLocation,
											'head' 	  		=> $row['service'],
											'sub_head' 	  	=> $row['service_item'],
											'against' 		=> 0,
											'bank_ref_type' => '',
											'debit' =>$row['amount'],
											'credit' =>'0.00',
											'ref_no'=> '', 
											'type' => '1',//user inputted  data  
											'status' => 6, // status appied
											'activity'=>$sourceLocation,
											'author' =>$this->_author,
											'created' =>$this->_created,
											'modified' =>$this->_modified,
										);
									$transactionDtls1 = $this->_safedataObj->rteSafe($transactionDtls1);
									$result1 = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->save($transactionDtls1);
							
								$data2 = array(
									'id' 	=> $row['id'],
									'transaction' => $resultTrans,
									);
								$data2 =  $this->_safedataObj->rteSafe($data2);
								$result5 = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::class)->save($data2);
								$data2 = array(
									'id' 	=> $form['tp_id'],
									'transaction' => $resultTrans,
									);
								$data2 =  $this->_safedataObj->rteSafe($data2);
								$result5 = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->save($data2);
							endforeach;
						}
						if($resultTrans > 0):
							$notification_data = array(
								'route'         => 'transaction',
								'action'        => 'viewcredit',
								'key' 		    => $resultTrans,
								'description'   => 'Transport Expense Applied',
								'author'	    => $this->_author,
								'created'       => $this->_created,
								'modified'      => $this->_modified,   
							);
							//print_r($notification_data);exit;
							$notificationResult = $this->getDefinedTable(Acl\NotificationTable::class)->save($notification_data);
							if($notificationResult > 0 ){	
								$userList = $this->getDefinedTable(Administration\UsersTable::class)->get(array('region'=>$region,'status'=>1));
								foreach($userList as $row):
									$rowRoles = array_map('trim', explode(',', (string)$row['role']));
									if(!in_array('3', $rowRoles, true)):
										continue;
									endif;
										$notify_data = array(
											'notification' => $notificationResult,
											'user'    	   => $row['id'],
											'flag'    	 => '0',
											'desc'    	 => 'Transport Expense Applied',
											'author'	 => $this->_author,
											'created'    => $this->_created,
											'modified'   => $this->_modified,  
										);
										$notifyResult = $this->getDefinedTable(Acl\NotifyTable::class)->save($notify_data);
								endforeach;
							}
						endif;
				$this->_connection->commit(); // commit transaction over success
				$this->flashMessenger()->addMessage("success^".$message);
			else:
			    $this->_connection->rollback(); // rollback transaction over failure
			    $this->flashMessenger()->addMessage("error^ Cannot send good request");			  
			endif;
			return $this->redirect()->toRoute('vehicle',array('action'=>'viewrepair','id'=>$form['tp_id']));
		endif; 		
		$viewModel =  new ViewModel(array(
			'title' => 'GRN',
			'id'  => $this->_id,
			'userID' => $this->_author,
		));	
		$viewModel->setTerminal('false');
        return $viewModel;	
	}
	
	/**
	 * Vehicle Repair  
	 */
	public function viewvehicleincomeAction()
	{
		$this->init();
		return new ViewModel( array(
		    'id'             =>$this->_id,
			'title'              => "View Transport Income",
			'repair' 			  => $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->get($this->_id),
			'RMObj'              => $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class),
			'RMDObject'          => $this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::class),
			'locationObj' 	     => $this->getDefinedTable(Administration\LocationTable::class),
			'vehiclepartObj'     => $this->getDefinedTable(Fleet\VehiclePartTable::class),
			'transObj'   => $this->getDefinedTable(Accounts\TransactionTable::class),
			'uomObj'             => $this->getDefinedTable(Stock\UomTable::class),
			'userObj'   => $this->getDefinedTable(Administration\UsersTable::class),
			'serviceObj' => $this->getDefinedTable(Accounts\HeadTable::class),
			'vehicleObj' => $this->getDefinedTable(Accounts\SubheadTable::class),
			'bankObj' => $this->getDefinedTable(Accounts\BankaccountTable::class),
			'cashObj' => $this->getDefinedTable(Accounts\CashaccountTable::class),
			'partyObj' => $this->getDefinedTable(Accounts\PartyTable::class),
		));
	}
	/*
	 *vehicle income Action
	*/
	public function vehicleincomeAction()
     {
       $this->init();
	   $month = '';
	   $year = '';
		if($this->getRequest()->isPost())
		{
			$form = $this->getRequest()->getPost();
			
			$year = $form['year'];
			$month = $form['month'];
			if(strlen($month)==1){
				$month = '0'.$month;
			}
		}else{
			$month = ($month == '')?date('m'):$month;
			$year = ($year == '')? date('Y'):$year;
		}
		$month = ($month == '')?date('m'):$month;
		$year = ($year == '')? date('Y'):$year;
		
		$minYear = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->getMin('work_order_date');
		$minYear = ($minYear == "")?date('Y-m-d'):$minYear;
		$minYear = date('Y', strtotime($minYear));
		$data = array(
			'year' => $year,
			'month' => $month,
			'minYear' => $minYear,
		);
		$results = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->getLocDateWise('work_order_date',$year,$month,array('type'=>2));
		//print_r($results); exit;
		return new ViewModel(array(
			'title' 	  => 'Repair',
			'data'        => $data,
			'repair_maintaneses' => $results,
			'role' => $this->getDefinedTable(Administration\UsersTable::class)->getColumn($this->_author,'role'),
		));                
     }
	  /**
	 * commitrm Action
	 */
	public function commitvehicleincomeAction()
	{
		$this->init();
		$incomeresult = $this->_id;
		$repairdtls = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::class)->get(array('repair'=>$incomeresult));
		if(empty($repairdtls)):
			$this->flashMessenger()->addMessage("notice^ No income details found to commit.");
			return $this->redirect()->toRoute('vehicle', array('action'=>'viewvehicleincome','id'=>$incomeresult));
		endif;

		$location = $this->getDefinedTable(Administration\UsersTable::class)->getColumn(array('id'=>$this->_author),'location');
		$region = $this->getDefinedTable(Administration\UsersTable::class)->getColumn(array('id'=>$this->_author),'region');
		$incomeDate = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->getColumn(array('id'=>$incomeresult),'work_order_date');
		$repair_no = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->getColumn(array('id'=>$incomeresult),'repair_no');

		$loc = $this->getDefinedTable(Administration\LocationTable::class)->getcolumn($this->_user->location, 'prefix');
		$prefix = $this->getDefinedTable(Accounts\JournalTable::class)->getcolumn(15,'prefix');
		$date = date('ym',strtotime(date('Y-m-d')));
		$tmp_VCNo = $loc.'-'.$prefix.$date;
		$results = $this->getDefinedTable(Accounts\TransactionTable::class)->getSerial($tmp_VCNo);
		$pltp_no_list = array();
		foreach($results as $result):
			array_push($pltp_no_list, substr($result['voucher_no'], 14));
		endforeach;
		$next_serial = empty($pltp_no_list) ? 1 : (max($pltp_no_list) + 1);
		switch(strlen((string)$next_serial)){
			case 1: $next_dc_serial = "0000".$next_serial; break;
			case 2: $next_dc_serial = "000".$next_serial;  break;
			case 3: $next_dc_serial = "00".$next_serial;   break;
			case 4: $next_dc_serial = "0".$next_serial;    break;
			default: $next_dc_serial = $next_serial;       break;
		}
		$voucher_no = $tmp_VCNo.$next_dc_serial;

		$totalVoucherAmount = 0;
		foreach($repairdtls as $repairdtl):
			$totalVoucherAmount += (float)$repairdtl['amount'];
		endforeach;

		$firstDetail = null;
		foreach($repairdtls as $repairdtl):
			$firstDetail = $repairdtl;
			break;
		endforeach;

		$this->_connection->beginTransaction(); //***Transaction begins here***//

		$statusUpdate = array(
			'id' 	=> $incomeresult,
			'status' => 4,
		);
		$statusUpdate =  $this->_safedataObj->rteSafe($statusUpdate);
		$status = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->save($statusUpdate);
		if($status <= 0):
			$this->_connection->rollback();
			$this->flashMessenger()->addMessage("notice^ Failed to update income status.");
			return $this->redirect()->toRoute('vehicle', array('action'=>'viewvehicleincome','id'=>$incomeresult));
		endif;

		$data2 = array(
			'voucher_date' 	  	=> $incomeDate,
			'voucher_type' 	  	=> 15,
			'region' 	  		=> $region,
			'voucher_no' 	  	=> $voucher_no,
			'voucher_amount' 	=> $totalVoucherAmount,
			'status' 	  		=> 4,
			'doc_id' 	  		=> "fleet",
			'against'			=> 0,
			'doc_type' 	  		=> " ",
			'remark' 	  		=> $repair_no,
			'author' 			=> $this->_author,
			'created' 			=> $this->_created,
			'modified' 			=> $this->_modified,
		);
		$data2 =  $this->_safedataObj->rteSafe($data2);
		$result2 = $this->getDefinedTable(Accounts\TransactionTable::class)->save($data2);
		if($result2 <= 0):
			$this->_connection->rollback();
			$this->flashMessenger()->addMessage("notice^ Failed to create transaction.");
			return $this->redirect()->toRoute('vehicle', array('action'=>'viewvehicleincome','id'=>$incomeresult));
		endif;

		if($firstDetail['cash'] == 0):
			$ref = $firstDetail['bankacc'];
			$subhead = $this->getDefinedTable(Accounts\SubheadTable::class)->getColumn(array('ref_id'=>$ref,'type'=>3),'id');
			$head = $this->getDefinedTable(Accounts\SubheadTable::class)->getColumn(array('id'=>$subhead),'head');
			$data4 = array(
				'transaction' 	    => $result2,
				'voucher_dates'     => $data2['voucher_date'],
				'voucher_types' 	=> 15,
				'location' 	  		=> $location,
				'against'		    => 0,
				'activity'			=> $location,
				'head' 	  			=> $head,
				'sub_head' 	  		=> $subhead,
				'debit' 	  		=> $totalVoucherAmount,
				'credit' 	  		=> 0,
				'status' 	  		=> 4,
				'bank_trans_journal'=> isset($firstDetail['journal_no']) ? $firstDetail['journal_no'] : 0,
				'author' 			=> $this->_author,
				'created' 			=> $this->_created,
				'modified' 			=> $this->_modified,
			);
		elseif($firstDetail['cash'] == 1):
			$ref = $firstDetail['cashacc'];
			$subhead = $this->getDefinedTable(Accounts\SubheadTable::class)->getColumn(array('ref_id'=>$ref,'type'=>6),'id');
			$head = $this->getDefinedTable(Accounts\SubheadTable::class)->getColumn(array('id'=>$subhead),'head');
			$data4 = array(
				'transaction' 	=> $result2,
				'voucher_dates' => $data2['voucher_date'],
				'voucher_types' => 15,
				'location' 	  	=> $location,
				'activity'      => $location,
				'head' 	  		=> $head,
				'sub_head' 	  	=> $subhead,
				'against'		=> 0,
				'debit' 	  	=> $totalVoucherAmount,
				'credit' 	  	=> 0,
				'status' 	  	=> 4,
				'author' 		=> $this->_author,
				'created' 		=> $this->_created,
				'modified' 		=> $this->_modified,
			);
		else:
			$ref = $firstDetail['party'];
			$subhead = $this->getDefinedTable(Accounts\SubheadTable::class)->getColumn(array('id'=>$ref),'id');
			$head = $this->getDefinedTable(Accounts\SubheadTable::class)->getColumn(array('id'=>$subhead),'head');
			$data4 = array(
				'transaction' 	=> $result2,
				'voucher_dates' => $data2['voucher_date'],
				'voucher_types' => 15,
				'location' 	  	=> $location,
				'head' 	  		=> $head,
				'sub_head' 	  	=> $subhead,
				'debit' 	  	=> $totalVoucherAmount,
				'against'		=> 0,
				'activity'      => $location,
				'credit' 	  	=> 0,
				'status' 	  	=> 4,
				'ref_no' 	  	=> isset($firstDetail['ref_no']) ? $firstDetail['ref_no'] : 0,
				'author' 		=> $this->_author,
				'created' 		=> $this->_created,
				'modified' 		=> $this->_modified,
			);
		endif;
		if((int)$head <= 0 || (int)$subhead <= 0):
			$this->_connection->rollback();
			$this->flashMessenger()->addMessage("notice^ Failed to resolve payment account subhead.");
			return $this->redirect()->toRoute('vehicle', array('action'=>'viewvehicleincome','id'=>$incomeresult));
		endif;

		$data4 = $this->_safedataObj->rteSafe($data4);
		$result4 = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->save($data4);
		if($result4 <= 0):
			$this->_connection->rollback();
			$this->flashMessenger()->addMessage("notice^ Failed to save payment transaction detail.");
			return $this->redirect()->toRoute('vehicle', array('action'=>'viewvehicleincome','id'=>$incomeresult));
		endif;

		foreach($repairdtls as $repairdtl):
			$data3 = array(
				'transaction' 	=> $result2,
				'voucher_dates' => $data2['voucher_date'],
				'voucher_types' => 15,
				'location' 	  	=> $location,
				'head' 	  		=> $repairdtl['service'],
				'sub_head' 	  	=> $repairdtl['service_item'],
				'activity'		=> $location,
				'against'		=> 0,
				'debit' 	  	=> 0,
				'credit' 	  	=> $repairdtl['amount'],
				'ref_no' 	  	=> 0,
				'status' 	  	=> 4,
				'author' 		=> $this->_author,
				'created' 		=> $this->_created,
				'modified' 		=> $this->_modified,
			);
			$data3 = $this->_safedataObj->rteSafe($data3);
			$result3 = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->save($data3);
			if($result3 <= 0):
				$this->_connection->rollback();
				$this->flashMessenger()->addMessage("notice^ Failed to save income transaction detail.");
				return $this->redirect()->toRoute('vehicle', array('action'=>'viewvehicleincome','id'=>$incomeresult));
			endif;

			$data5 = array(
				'id' 	=> $repairdtl['id'],
				'transaction' => $result2,
			);
			$data5 = $this->_safedataObj->rteSafe($data5);
			$result5 = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::class)->save($data5);
			if($result5 <= 0):
				$this->_connection->rollback();
				$this->flashMessenger()->addMessage("notice^ Failed to link income detail transaction.");
				return $this->redirect()->toRoute('vehicle', array('action'=>'viewvehicleincome','id'=>$incomeresult));
			endif;
		endforeach;

		$this->_connection->commit();
		$this->flashMessenger()->addMessage("success^ successfully committed the data");
		return $this->redirect()->toRoute('vehicle', array('action'=>'viewvehicleincome','id'=>$incomeresult));
			
		//}
		$ViewModel = new ViewModel(array(
			'title'   => 'Commit',
			'vehiclemain' => $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::class)->get($this->_id),
			'vehiclemaindtlObj' => $this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::class),
			'party' => $this->getDefinedTable(Accounts\SubheadTable::class)->get(array('head'=>[76,79,90,91,92,93,94,95,96,97,112,118])),
			
		));
			$ViewModel->setTerminal(True);
		return $ViewModel;
	}
	/*
	 *vehicle requisition Action
	*/
	public function vehiclerequisitionAction()
     {
       $this->init();
	   $year ='';
	   $month = '';
		if($this->getRequest()->isPost())
		{
			$form = $this->getRequest()->getPost();
			
			$year = $form['year'];
			$month = $form['month'];
			if(strlen($month)==1){
				$month = '0'.$month;
			}
		}else{
			$month = ($month == '')?date('m'):$month;
			$year = ($year == '')? date('Y'):$year;
		}
		$month = ($month == '')?date('m'):$month;
		$year = ($year == '')? date('Y'):$year;
		
		$minYear = $this->getDefinedTable(Fleet\VehicleRequisitionTable::class)->getMin('issue_date');
		$minYear = ($minYear == "")?date('Y-m-d'):$minYear;
		$minYear = date('Y', strtotime($minYear));
		$data = array(
			'year' => $year,
			'month' => $month,
			'minYear' => $minYear,
		);
		$results = $this->getDefinedTable(Fleet\VehicleRequisitionTable::class)->getLocDateWise('issue_date',$year,$month,array('status'=>array(1,2,3)));
		return new ViewModel(array(
			'title' 	   => 'Vehicle Requisition',
			'data'         => $data,
			'requisitions' => $results,
		));                
     }
    /**
	 * addvehiclerequisition Action
	 */
	public function addvehiclerequisitionAction()
	{
		$this->init();
		$location_id = $this->_userloc;
		if($this->getRequest()->isPost()){
			
			$form = $this->getRequest()->getpost();					
			
			$location_prefix = $this->getDefinedTable(Administration\LocationTable::class)->getcolumn($location_id,'prefix');	
			$date = date('ym',strtotime($form['issue_date']));			
			$tmp_RQNo = $location_prefix."RQ".$date; 			
			$results = $this->getDefinedTable(Fleet\VehicleRequisitionTable::class)->getMonthlyRQ($tmp_RQNo);
			
			$rq_no_list = array();
			foreach($results as $result):
				array_push($rq_no_list, substr($result['requisition_no'], 8));
			endforeach;
			$next_serial = empty($rq_no_list) ? 1 : (max($rq_no_list) + 1);
			switch(strlen($next_serial)){
				case 1: $next_rq_serial = "000".$next_serial; break;
				case 2: $next_rq_serial = "00".$next_serial;  break;
				case 3: $next_rq_serial = "0".$next_serial;   break;
				default: $next_rq_serial = $next_serial;       break;
			}	
			$rq_no = $tmp_RQNo.$next_rq_serial;
	      
			$data = array(
				'requisition_no'   => $rq_no,
				'issue_date'       => $form['issue_date'], 					 
				'transport'        => $form['transport'],
				'driver'           => $form['transporter_fcb'],
				'location'         => $form['location'],
				'total_quantity'   => $form['rq_quantity'],
				'previous_reading' => $form['previous_reading'],
				'current_reading'  => $form['current_reading'],
				'km_covered'       => $form['km_covered'],
				'remarks'          => $form['note'],
				'status' 		   => '1', 
				'author'           => $this->_author,
				'created'          => $this->_created,
				'modified'         => $this->_modified
			);
			//print_r($data); exit;
			$data   = $this->_safedataObj->rteSafe($data);
			$this->_connection->beginTransaction(); //***Transaction begins here***//
			$result = $this->getDefinedTable(Fleet\VehicleRequisitionTable::class)->save($data);
			if($result > 0){ 
				$service_item = $form['service_item'];
				$uom          = $form['uom'];
				$quantity     = $form['quantity'];
				$remarks      = $form['note'];
				for($i=0; $i < sizeof($service_item); $i++):
				if(isset($service_item[$i]) && is_numeric($quantity[$i])):
						$rq_details = array(
							'requisition' => $result,
							'item'        => $service_item[$i],
							'uom'         => $uom[$i],
							'quantity'    => $quantity[$i],
							'remarks' 	  => $remarks[$i],
							'author'      => $this->_author,
							'created'     => $this->_created,
							'modified'    => $this->_modified
						);
					//print_r($rq_details); exit;
		     		$rq_details   = $this->_safedataObj->rteSafe($rq_details);
			     	$this->getDefinedTable(Fleet\VehicleRequisitionDtlsTable::class)->save($rq_details);		
				   	endif; 		     
				endfor;
				$this->_connection->commit();
				$this->flashMessenger()->addMessage("success^ Successfully added new Requisition :". $rq_no);
				return $this->redirect()->toRoute('vehicle', array('action' =>'viewrequisition', 'id' => $result));
			}
			else{
				  $this->_connection->rollback();
				$this->flashMessenger()->addMessage("error^ Failed to add new Requisition");
				return $this->redirect()->toRoute('vehiclerequisition');
			}		
		}	
		return new ViewModel( array(
			'title' 		=> "Add Vehicle Requisition",
			'uomObj' => $this->getDefinedTable(Stock\UomTable::class),
			'vehiclepartObj' => $this->getDefinedTable(Fleet\VehiclePartTable::class),
			'rowsets' => $this->getDefinedTable(Accounts\AssetsTable::class)->getAll(),
			'requisition_itemObj' => $this->getDefinedTable('Fleet\RequisitionItemTable'),
	    ));
	}
    /**
	 * editvehiclerequisition Action
	 */
	public function editvehiclerequisitionAction()
	{
		$this->init();
		if($this->getRequest()->isPost()){
			$form = $this->getRequest()->getpost();					
	      
			$data = array(
				'id'               => $form['requisition_id'],
				'issue_date'       => $form['issue_date'], 					 
				'transport'        => $form['transport'],
				'driver'           => $form['transporter_fcb'],
				'location'         => $form['location'],
				'total_quantity'   => $form['rq_quantity'],
				'previous_reading' => $form['previous_reading'],
				'current_reading'  => $form['current_reading'],
				'km_covered'       => $form['km_covered'],
				'remarks'          => $form['note'],
				'status' 		   => '1', 
				'author'           => $this->_author,
				'created'          => $this->_created,
				'modified'         => $this->_modified
			);
			//print_r($data); exit;
			$data   = $this->_safedataObj->rteSafe($data);
			$this->_connection->beginTransaction(); //***Transaction begins here***//
			$result = $this->getDefinedTable(Fleet\VehicleRequisitionTable::class)->save($data);
			if($result > 0){ 
                $rquisition_dtls_id = $form['details_id'];
				$service_item = $form['service_item'];
				$uom          = $form['uom'];
				$quantity     = $form['quantity'];
				$remarks      = $form['note'];
				$delete_rows = $this->getDefinedTable(Fleet\VehicleRequisitionDtlsTable::class)->getNotIn($rquisition_dtls_id, array('requisition' => $result));

				for($i=0; $i < sizeof($service_item); $i++):
				if(isset($service_item[$i]) && is_numeric($quantity[$i])):
						$rq_details = array(
							'id'          => $rquisition_dtls_id,
							'requisition' => $result,
							'item'        => $service_item[$i],
							'uom'         => $uom[$i],
							'quantity'    => $quantity[$i],
							'remarks' 	  => $remarks[$i],
							'author'      => $this->_author,
							'created'     => $this->_created,
							'modified'    => $this->_modified
						);
					//print_r($rq_details); exit;
		     		$rq_details   = $this->_safedataObj->rteSafe($rq_details);
			     	$this->getDefinedTable(Fleet\VehicleRequisitionDtlsTable::class)->save($rq_details);		
				   	endif; 		     
				endfor;
                foreach($delete_rows as $delete_row):
					$this->getDefinedTable(Fleet\VehicleRequisitionDtlsTable::class)->remove($delete_row['id']);
				endforeach;
				$this->_connection->commit();
				$rq_no = $this->getDefinedTable(Fleet\VehicleRequisitionTable::class)->getColumn($form['requisition_id'],'requisition_no');
				$this->flashMessenger()->addMessage("success^ Successfully updated Requisition :". $rq_no);
				return $this->redirect()->toRoute('vehicle', array('action' =>'viewrequisition', 'id' => $result));
			}
			else{
				$this->_connection->rollback();
				$this->flashMessenger()->addMessage("error^ Failed to add new Requisition");
				return $this->redirect()->toRoute('vehiclerequisition');
			}		
		}	
		return new ViewModel( array(
			'title' 		=> "Edit Vehicle Requisition",
			'uomObj' => $this->getDefinedTable(Stock\UomTable::class),
			'vehiclepartObj' => $this->getDefinedTable(Fleet\VehiclePartTable::class),
			'rowsets' => $this->getDefinedTable(Accounts\AssetsTable::class)->getAll(),
            'requisitions' => $this->getDefinedTable(Fleet\VehicleRequisitionTable::class)->get($this->_id),
			'requisitions_dtls' => $this->getDefinedTable(Fleet\VehicleRequisitionDtlsTable::class)->get(array('requisition'=>$this->_id)),
	    ));
	}
	/**
	 * Vehicle requisition 
	*/
	public function viewrequisitionAction()
	{
		$this->init();
		return new ViewModel( array(
			'title' => "Vehicle Requisition",
			'requisitions' => $this->getDefinedTable(Fleet\VehicleRequisitionTable::class)->get($this->_id),
			'requisitions_dtls' => $this->getDefinedTable(Fleet\VehicleRequisitionDtlsTable::class)->get(array('requisition'=>$this->_id)),
			'uomObj' => $this->getDefinedTable(Stock\UomTable::class),
			'vehiclepartObj' => $this->getDefinedTable(Fleet\VehiclePartTable::class),
		));
	}
    /**
	 * Commit requisition 
	*/
    public function commitrequisitionAction()
      { 
      	$this->init();      
		$requisition_status = $this->getDefinedTable(Fleet\VehicleRequisitionTable::class)->getColumn($this->_id,'status'); 
      	//change the status to cancel and revert the credit details table
		if($requisition_status != "3"){              
			$requisition_data = array(
				'id'  => $this->_id,
				'status' => '3',
				'modified' =>$this->_modified
			);
			$this->_connection->beginTransaction(); //***Transaction begins here***//
			$result = $this->getDefinedTable(Fleet\VehicleRequisitionTable::class)->save($requisition_data);
			if($result){
				$this->_connection->commit(); // rollback transaction over failure
				$this->flashMessenger()->addMessage("success^ Requisition successfuly commited the requisition");
				return $this->redirect()->toRoute('vehicle', array('action' =>'viewrequisition', 'id'=>$this->_id));
			}
			else{
				$this->_connection->rollback(); // rollback transaction over failure
				$this->flashMessenger()->addMessage("error^ Fail to commit the requisition");
				return $this->redirect()->toRoute('vehicle', array('action' =>'viewrequisition', 'id'=>$this->_id));
			} 
        }
        else{
            	$this->flashMessenger()->addMessage("error^ Not able to cancel the requisition");
            	return $this->redirect()->toRoute('vehicle', array('action' =>'viewrequisition', 'id'=>$this->_id));
        }              	
      	return new ViewModel(array());      	
      }
	 /**
	 * Get Subhead
	 */
	public function getsubheadAction()
	{		
		$form = $this->getRequest()->getPost();
		$headId = $form['headId'];
		$subhead_list = $this->getDefinedTable(Accounts\SubheadTable::class)->get(array('sh.head'=>$headId));
		$subhead = "<option value=''></option>";
		foreach($subhead_list as $subhead_lists):
			$subhead.="<option value='".$subhead_lists['id']."'>".$subhead_lists['name']."</option>";
		endforeach;
		echo json_encode(array(
				'subhead' => $subhead,
		));
		exit;
	}
	/**
	 * Get linked heads by location
	 */
	public function getlocationheadAction()
	{
		$form = $this->getRequest()->getPost();
		$locationId = isset($form['locationId']) ? (int)$form['locationId'] : 0;
		$headScope = isset($form['headScope']) ? trim((string)$form['headScope']) : '';
		$classFilter = ($headScope === 'income') ? 'income' : '';
		$headList = $this->getRepairHeadsByLocation($locationId, $classFilter);
		$bankOptions = "<option value='0'></option>";
		$cashOptions = "<option value='0'></option>";
		$bankAccounts = $this->getBankAccountsByLocation($locationId);
		$cashAccounts = $this->getCashAccountsByLocation($locationId);

		foreach($bankAccounts as $bankAccount):
			$bankOptions .= "<option value='".$bankAccount['id']."'>".$bankAccount['code'].'_'.$bankAccount['account']."</option>";
		endforeach;
		foreach($cashAccounts as $cashAccount):
			$cashOptions .= "<option value='".$cashAccount['id']."'>".$cashAccount['cash_account_code'].'_'.$cashAccount['cash_account_name']."</option>";
		endforeach;
		$partySubheads = $this->getPartySubheadsByLocation($locationId);
		echo json_encode(array(
			'head' => $this->getHeadOptionsHtml($headList),
			'bank' => $bankOptions,
			'cash' => $cashOptions,
			'party' => $this->getSubheadOptionsHtml($partySubheads),
		));
		exit;
	}
	 /**
	  * Get cash/bank account options by location
	  */
	 public function getpaymentaccountsAction()
	 {
	 	$form = $this->getRequest()->getPost();
	 	$locationId = isset($form['locationId']) ? (int)$form['locationId'] : 0;
	 	$bankOptions = "<option value='0'></option>";
	 	$cashOptions = "<option value='0'></option>";
	 	$bankAccounts = $this->getBankAccountsByLocation($locationId);
	 	$cashAccounts = $this->getCashAccountsByLocation($locationId);

		foreach($bankAccounts as $bankAccount):
	 		$bankOptions .= "<option value='".$bankAccount['id']."'>".$bankAccount['code'].'_'.$bankAccount['account']."</option>";
	 	endforeach;
		foreach($cashAccounts as $cashAccount):
	 		$cashOptions .= "<option value='".$cashAccount['id']."'>".$cashAccount['cash_account_code'].'_'.$cashAccount['cash_account_name']."</option>";
	 	endforeach;

	 	echo json_encode(array(
	 		'bank' => $bankOptions,
	 		'cash' => $cashOptions,
	 	));
	 	exit;
	 }
	 /**
	 * Get Location
	 */
	public function getlocationAction()
	{		
		$form = $this->getRequest()->getPost();
		$vehicleId = isset($form['vehicleId']) ? (int)$form['vehicleId'] : (isset($form['licenseId']) ? (int)$form['licenseId'] : 0);
		$asset_list = $this->getDefinedTable(Asset\AssetmanagementTable::class)->get($vehicleId);
		$locationId = 0;
		foreach($asset_list as $asset_row):
			$locationId = isset($asset_row['location_id']) ? (int)$asset_row['location_id'] : 0;
			if($locationId <= 0 && isset($asset_row['location']) && is_numeric((string)$asset_row['location'])):
				$locationId = (int)$asset_row['location'];
			endif;
			break;
		endforeach;
		if($locationId <= 0 && $vehicleId > 0):
			$locationId = (int)$this->getDefinedTable(Asset\AssetmanagementTable::class)->getColumn($vehicleId, 'location');
		endif;
		$location_list = $this->getDefinedTable(Administration\LocationTable::class)->get(array('id'=>$locationId));
		$location = "";
		foreach($location_list as $location_lists):
			$location.="<option value='".$location_lists['id']."' selected>".$location_lists['location']."</option>";
		endforeach;
		if($location == ""):
			$location = "<option value=''></option>";
		endif;
		echo json_encode(array(
				'location' => $location,
		));
		exit;
	}
	/**
	 * Get Item
	**/
	public function gettransportAction()
	{		
		$form = $this->getRequest()->getPost();
		$serviceId = $form['serviceId'];
		$locationId =$form['locationId'];
		if($serviceId==8):
			$subhead_list = $this->getDefinedTable(Fleet\VehicleRegisterTable::class)->get(array('head'=>$serviceId));
		else:
			$subhead_list = $this->getDefinedTable(Fleet\VehicleRegisterTable::class)->get(array('location'=>$locationId,'head'=>$serviceId));
		endif;
		$item = "<option value=''></option>";
		foreach($subhead_list as $subhead_lists):
			$subheadCode = $this->getDefinedTable(Accounts\SubheadTable::class)->getColumn(array('id'=>$subhead_lists['subhead']), 'code');
			$subheadName = $this->getDefinedTable(Accounts\SubheadTable::class)->getColumn(array('id'=>$subhead_lists['subhead']), 'name');
			$itemLabel = trim((string)$subheadCode) !== '' ? ($subheadCode.' - '.$subheadName) : $subheadName;
			$item.="<option value='".$subhead_lists['subhead']."'>".$itemLabel."</option>";
		endforeach;
		echo json_encode(array(
				'item' => $item,
		));
		exit;
	}
	/**
	 * Get Subhead based on license plate
	**/
	public function getplateAction()
	{		
		$form = $this->getRequest()->getPost();
		$serviceId = $form['serviceId'];
		$itemId = $form['itemId'];
		$subh_list = $this->getDefinedTable(Fleet\VehicleRegisterTable::class)->get(array('head'=>$serviceId,'subhead'=>$itemId));
		foreach($subh_list as $subh_lists);
			$license_plate=$subh_lists['license_plate'];
			echo json_encode(array(
				'license_plate' => $license_plate,
		));
		exit;
	}
	/**
	 * Get Subhead based on license plate
	**/
	public function deletevehicleincomeAction()
	{		
		$this->init(); 
		foreach($this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::Class)->get(array('repair'=>$this->_id)) as $rd):
			$transaction=$this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::Class)->getColumn($rd['id'],'transaction');
			foreach($this->getDefinedTable(Accounts\TransactiondetailTable::Class)->get(array('td.transaction'=>$transaction)) as $td):
				$result1 = $this->getDefinedTable(Accounts\TransactiondetailTable::Class)->remove($td['id']);
			endforeach;
			$result2 = $this->getDefinedTable(Accounts\TransactionTable::Class)->remove($transaction);
			$result3 = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseDtlsTable::Class)->remove($rd['id']);
		endforeach;
		$result = $this->getDefinedTable(Fleet\VehicleRepairMaintaneseTable::Class)->remove($this->_id);
		if($result > 0):

				$this->flashMessenger()->addMessage("success^ Vehicle deleted successfully");
			else:
				$this->_connection->rollback(); // rollback transaction over failure
				$this->flashMessenger()->addMessage("error^ Failed to delete Vehicle");
			endif;
			//end			
		
			return $this->redirect()->toRoute('vehicle',array('action' => 'vehicleincome'));	
	}
}
