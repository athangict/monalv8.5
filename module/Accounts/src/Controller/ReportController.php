<?php
namespace Accounts\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\Db\Sql\Where;
use Laminas\Authentication\AuthenticationService;
use Interop\Container\ContainerInterface;
use Administration\Model As Administration;
use Acl\Model As Acl;
use Accounts\Model As Accounts;
use Accounts\Support\AccountLabel;
use Hr\Model As Hr;
class ReportController extends AbstractActionController
{   
	protected $_connection;
	protected $_safedataObj;
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
		
		if(!isset($this->_config)){
			$this->_config = $this->_container->get('Config');
		}
		if(!isset($this->_user)){
			$this->_user = $this->identity();
		}
		if(!isset($this->_login_id)){
			$this->_login_id = $this->_user->id;  
		}
		if(!isset($this->_login_role)){
			$this->_login_role = $this->_user->role;  
		}
		if(!isset($this->_author)){
			$this->_author = $this->_user->id;  
		}
		$this->_id = $this->params()->fromRoute('id');

		$this->_created = date('Y-m-d H:i:s');
		$this->_modified = date('Y-m-d H:i:s');

		$this->_safedataObj = $this->safedata();
		$this->_connection = $this->_container->get('Laminas\Db\Adapter\Adapter')->getDriver()->getConnection();

	}
	
	/**TRIAL BALANCE ACTION----------------------------------------------------------------------------------------------------*/
	public function trialbalanceAction()
	{
		$this->init();
		if($this->getRequest()->isPost()):
			$form = $this->getRequest()->getPost();
			$tier = 4;
			$activity = $form['location'];//$form['activity'];removed
			$region = $form['region'];
            $location = $form['location'];
			$start_date = $form['start_date'];
			$end_date = $form['end_date'];
		else:
			$tier=4;
			$activity='-1';
			$region = '-1';
			$location = '-1';
			$start_date  = date('Y-01-01');
			$end_date   = date('Y-m-d');
		endif;
		$data = array(
		    'tier'     => $tier,
			'activity' => $activity,
			'region' => $region,
			'location' => $location,
			'start_date' => $start_date,
			'end_date'  => $end_date,
		);
		$scope = $this->resolveReportScope($region, $location, $activity);
		$activity = $scope['activity'];
		$region = $scope['region'];
		$location = $scope['location'];
		$regions = $scope['regions'];
		$locations = $scope['locations'];
		$data['activity'] = $activity;
		$data['region'] = $region;
		$data['location'] = $location;
		$appSettings = $this->getDefinedTable(Administration\AppSettingTable::class)->getSettings();
		$incomeTaxRate = isset($appSettings['income_tax_rate']) ? (float)$appSettings['income_tax_rate'] : 30.0;
		$includeComprehensiveInNet = !empty($appSettings['include_oci_in_net']);
		$existingPeriodSnapshot = $this->getDefinedTable(Accounts\PeriodsnapshotTable::class)->getByPeriod($end_date, $region, $location);
		$hasExistingCloseSnapshot = !empty($existingPeriodSnapshot);
		$canForceReclose = $this->hasPrivilegedMonthlyCloseRole();
		$ViewModel = new ViewModel(array(
			'classObj' => $this->getDefinedTable(Accounts\ClassTable::class),
			'groupObj' => $this->getDefinedTable(Accounts\GroupTable::class),
			'headObj' => $this->getDefinedTable(Accounts\HeadTable::class),
			'headtypeObj' => $this->getDefinedTable(Accounts\HeadtypeTable::class),
			'subheadObj' => $this->getDefinedTable(Accounts\SubheadTable::class),
			'transactiondetailObj' => $this->getDefinedTable(Accounts\TransactiondetailTable::class),
			'data' => $data,
			'region' => $regions,
			'locations' => $locations,
			'activityObj' => $this->getDefinedTable(Administration\ActivityTable::class),
			'locationObj' => $this->getDefinedTable(Administration\LocationTable::class),
			'regionObj' => $this->getDefinedTable(Administration\RegionTable::class),
			'allowAllRegion' => $scope['allow_all_region'],
			'allowAllLocation' => $scope['allow_all_location'],
			'userID' => $this->_author,
		));
		return $ViewModel;
	}
	/** BALANCE SHEET ACTION-------------------------------------------------------------------------------------------------- */
	public function balancesheetAction(){
		$this->init();
		if($this->getRequest()->isPost()):
			$form = $this->getRequest()->getPost();
			$tier = 4;
			$activity = $form['location'];
			$region = $form['region'];
            $location = $form['location'];
			$start_date = $form['start_date'];
			$end_date = $form['end_date'];
		else:
			$tier=4;
			$activity='-1';
			$region = '-1';
			$location = '-1';
			$start_date  = date('Y-m-d');
			$end_date   = date('Y-m-d');
		endif;
		$data = array(
		    'tier'     => $tier,
			'activity' => $activity,
			'region' => $region,
			'location' => $location,
			'start_date' => $start_date,
			'end_date'  => $end_date,
		);
		$scope = $this->resolveReportScope($region, $location, $activity);
		$activity = $scope['activity'];
		$region = $scope['region'];
		$location = $scope['location'];
		$regions = $scope['regions'];
		$locations = $scope['locations'];
		$data['activity'] = $activity;
		$data['region'] = $region;
		$data['location'] = $location;
		$pre_starting_date = date('Y-m-d', strtotime('-1 year', strtotime($start_date)));
		$pre_ending_date = date('Y-m-d', strtotime('-1 year', strtotime($end_date)));
		$retainedCurrent = $this->getRetainedEarningsValue($activity, $region, $location, $start_date, $end_date);
		$retainedPrevious = $this->getRetainedEarningsValue($activity, $region, $location, $pre_starting_date, $pre_ending_date);
		$ViewModel = new ViewModel(array(
			'classObj' => $this->getDefinedTable(Accounts\ClassTable::class),
			'groupObj' => $this->getDefinedTable(Accounts\GroupTable::class),
			'headObj' => $this->getDefinedTable(Accounts\HeadTable::class),
			'subheadObj' => $this->getDefinedTable(Accounts\SubheadTable::class),
			'transactiondetailObj' => $this->getDefinedTable(Accounts\TransactiondetailTable::class),
			'data' => $data,
			'region' => $regions,
			'locations' => $locations,
			'minDate' => $this->getDefinedTable(Accounts\TransactionTable::class)->getMin('voucher_date'),
			'activities' => $this->getDefinedTable(Administration\ActivityTable::class)->getAll(),
			'activityObj' => $this->getDefinedTable(Administration\ActivityTable::class),
			'locationObj' => $this->getDefinedTable(Administration\LocationTable::class),
			'regionObj' => $this->getDefinedTable(Administration\RegionTable::class),
			'allowAllRegion' => $scope['allow_all_region'],
			'allowAllLocation' => $scope['allow_all_location'],
			'retainedCurrent' => $retainedCurrent,
			'retainedPrevious' => $retainedPrevious,
			'userID' => $this->_author,
		));
		return $ViewModel; 
	}
    /**PROFIT LOSS ACTION-------------------------------------------------------------------------------------------------------------*/
	public function profitlossAction(){
		$this->init();
		$monthlyCloseRequested = false;
		$forceReclose = 0;
		$closeNote = '';
		$periodStart = '';
		$periodEnd = '';
		if($this->getRequest()->isPost()):
			$form = $this->getRequest()->getPost();
			if(isset($form['run_monthly_close']) && (int)$form['run_monthly_close'] === 1):
				$monthlyCloseRequested = true;
				$region = (isset($form['region']) && $form['region'] !== '') ? (int)$form['region'] : -1;
				$location = (isset($form['location']) && $form['location'] !== '') ? (int)$form['location'] : -1;
				$periodStart = isset($form['period_start']) ? $form['period_start'] : date('Y-m-01');
				$periodEnd = isset($form['period_end']) ? $form['period_end'] : date('Y-m-t');
				$forceReclose = (isset($form['force_reclose']) && (int)$form['force_reclose'] === 1) ? 1 : 0;
				$closeNote = isset($form['close_note']) ? trim((string)$form['close_note']) : '';
				$tier = 4;
				$activity = ($location > 0) ? $location : -1;
				$start_date = $periodStart;
				$end_date = $periodEnd;
			else:
				$tier = 4;
				$activity = $form['location'];
				$region = $form['region'];
	            $location = $form['location'];
				$start_date = $form['start_date'];
				$end_date = $form['end_date'];
			endif;
		else:
			$tier=4;
			$activity='-1';
			$region = '-1';
			$location = '-1';
			$start_date  = date('Y-01-01');
			$end_date   = date('Y-m-d');
		endif;
		$data = array(
		    'tier'     => $tier,
			'activity' => $activity,
			'region' => $region,
			'location' => $location,
			'start_date' => $start_date,
			'end_date'  => $end_date,
		);
		$scope = $this->resolveReportScope($region, $location, $activity);
		$activity = $scope['activity'];
		$region = $scope['region'];
		$location = $scope['location'];
		$regions = $scope['regions'];
		$locations = $scope['locations'];
		$data['activity'] = $activity;
		$data['region'] = $region;
		$data['location'] = $location;
		$monthlyCloseAccess = $this->getMonthlyCloseAccess($region, $location);
		$canRunMonthlyClose = $monthlyCloseAccess['allowed'];
		$canForceReclose = ($canRunMonthlyClose && $this->hasPrivilegedMonthlyCloseRole());
		if($monthlyCloseRequested):
			if($canRunMonthlyClose):
				$this->runMonthlyCloseSnapshot($periodStart, $periodEnd, $region, $location, $forceReclose, $closeNote);
			else:
				$reason = ($monthlyCloseAccess['reason'] !== '') ? $monthlyCloseAccess['reason'] : 'You do not have permission to run monthly close for this scope.';
				$this->flashMessenger()->addMessage('Failed^ '.$reason);
			endif;
		endif;
		$appSettings = $this->getDefinedTable(Administration\AppSettingTable::class)->getSettings();
		$incomeTaxRate = isset($appSettings['income_tax_rate']) ? (float)$appSettings['income_tax_rate'] : 30.0;
		$includeComprehensiveInNet = !empty($appSettings['include_oci_in_net']);
		$existingPeriodSnapshot = $this->getDefinedTable(Accounts\PeriodsnapshotTable::class)->getByPeriod($end_date, $region, $location);
		$hasExistingCloseSnapshot = !empty($existingPeriodSnapshot);
		$ViewModel = new ViewModel(array(
			'classObj' => $this->getDefinedTable(Accounts\ClassTable::class),
			'groupObj' => $this->getDefinedTable(Accounts\GroupTable::class),
			'headObj' => $this->getDefinedTable(Accounts\HeadTable::class),
			'subheadObj' => $this->getDefinedTable(Accounts\SubheadTable::class),
			'transactiondetailObj' => $this->getDefinedTable(Accounts\TransactiondetailTable::class),
			'data' => $data,
			'region' => $regions,
			'locations' => $locations,
			'minDate' => $this->getDefinedTable(Accounts\TransactionTable::class)->getMin('voucher_date'),
			'activityObj' => $this->getDefinedTable(Administration\ActivityTable::class),
			'locationObj' => $this->getDefinedTable(Administration\LocationTable::class),
			'regionObj' => $this->getDefinedTable(Administration\RegionTable::class),
			'canRunMonthlyClose' => $canRunMonthlyClose,
			'canForceReclose' => $canForceReclose,
			'hasExistingCloseSnapshot' => $hasExistingCloseSnapshot,
			'incomeTaxRate' => $incomeTaxRate,
			'includeComprehensiveInNet' => $includeComprehensiveInNet,
			'allowAllRegion' => $scope['allow_all_region'],
			'allowAllLocation' => $scope['allow_all_location'],
			'userID' => $this->_author,
		));
		return $ViewModel;
	}
	/**PROFIT LOSS FOR AUDIT ACTION-------------------------------------------------------------------------------------------------------------*/
	/*public function plauditAction(){
		$this->init();
		if($this->getRequest()->isPost()):
			$form = $this->getRequest()->getPost();
			$tier = $form['tier'];
			$activity = $form['activity'];
			$region = $form['region'];
            $location = $form['location'];
			$start_date = $form['start_date'];
			$end_date = $form['end_date'];
		else:
			$tier=0;
			$activity=0;
			$region = 0;
			$location = 0;
			$start_date  = date('Y-01-01');
			$end_date   = date('Y-m-d');
		endif;
		$data = array(
		    'tier'     => $tier,
			'activity' => $activity,
			'region' => $region,
			'location' => $location,
			'start_date' => $start_date,
			'end_date'  => $end_date,
		);
		$ViewModel = new ViewModel(array(
			'classObj' => $this->getDefinedTable(Accounts\ClassTable::class),
			'groupObj' => $this->getDefinedTable(Accounts\GroupTable::class),
			'headObj' => $this->getDefinedTable(Accounts\HeadTable::class),
			'subheadObj' => $this->getDefinedTable(Accounts\SubheadTable::class),
			'transactiondetailObj' => $this->getDefinedTable(Accounts\TransactiondetailTable::class),
			'headauditObj' => $this->getDefinedTable(Accounts\HeadAuditTable::class),
			'data' => $data,
			'minDate' => $this->getDefinedTable(Accounts\TransactionTable::class)->getMin('voucher_date'),
			'activityObj' => $this->getDefinedTable(Administration\ActivityTable::class),
			'locationObj' => $this->getDefinedTable(Administration\LocationTable::class),
			'regionObj' => $this->getDefinedTable(Administration\RegionTable::class),
            //'userRoleObj'  => $this->getDefinedTable(Acl\UserroleTable::class),
			'userID' => $this->_author,
		));
		return $ViewModel;
	}*/
	/**GET CASH ACCOUNT ACTION*******************************************************************************************************/
	public function getcashaccountAction()
	{
		$this->init();
		$lc='';
		$form = $this->getRequest()->getPost();
		$location_id = $form['location'];
		$cashaccount = $this->getDefinedTable(Accounts\CashaccountTable::class)->getca($location_id);
		
		$lc.="<option value=''></option><option value='-1'>All</option>";
		foreach($cashaccount as $ca):
			$lc.= "<option value='".$ca['id']."'>".$ca['cash_account_code'].'-'.$ca['cash_account_name']."</option>";
		endforeach;
		echo json_encode(array(
			'ca' => $lc,
		));
		exit;
	}
	/**CASH BOOK ACTION-----------------------------------------------------------------------------------------------------------------*/
	public function cashbookAction(){
		$this->init();
		if($this->getRequest()->isPost()){
			$form = $this->getRequest()->getPost();
			$location = $form['location'];
			$cashaccount = isset($form['cashaccount']) ? $form['cashaccount'] : '';
			$start_date = $form['start_date'];
			$end_date = $form['end_date'];
			$cash_details = isset($form['cash_details']) ? $form['cash_details'] : 0;
		}else{
			$location = 0;
			$cashaccount = '';
			$start_date = date('Y-m-d');
			$end_date  = date('Y-m-d');
			$cash_details =0;
		}
		$loc = $this->getDefinedTable(Administration\LocationTable::class)->getColumn($location, 'location');
		return new ViewModel(array(
			'title' => "Cash Book for -".$loc." from ".$start_date." till ".$end_date,
			'location_id' => $location,
			'cashaccount_id' => $cashaccount,
			'cashAccounts' => $this->getDefinedTable(Accounts\CashaccountTable::class)->getca($location),
			'start_date'=> $start_date,
			'end_date' => $end_date,
			'cash_detail' => $cash_details,
			'locationObj' => $this->getDefinedTable(Administration\LocationTable::class),
			'transactionObj' => $this->getDefinedTable(Accounts\TransactionTable::class),
			'transactiondetailObj'=> $this->getDefinedTable(Accounts\TransactiondetailTable::class),
			'journalObj' => $this->getDefinedTable(Accounts\JournalTable::class),
			'headObj'=> $this->getDefinedTable(Accounts\HeadTable::class),
			'subheadObj'=> $this->getDefinedTable(Accounts\SubheadTable::class),
			'cashAccountObj'  => $this->getDefinedTable(Accounts\CashaccountTable::class),
			'closingObj'  => $this->getDefinedTable(Accounts\ClosingbalanceTable::class),
			//'userRoleObj'  => $this->getDefinedTable(Acl\UserroleTable::class),
			'userID' => $this->_author,
		));
	}
	/**GET BANK ACCOUNT ACTION *****************************************************************************************************/
	public function getbankAccAction()
	{
		$this->init();
		$lc='';
		$form = $this->getRequest()->getPost();
		$location_id = $form['location'];
		$bankacc = $this->getDefinedTable(Accounts\BankaccountTable::class)->getba(array('location' => $location_id));
		$lc.="<option value='-1'>All</option>";
		foreach($bankacc as $ba):
			$lc .= "<option value='" . $ba['id'] . "'>" . $ba['code'] . "-" . $ba['account'] . "</option>";
		endforeach;
		echo json_encode(array(
			'ba' => $lc,
		));
		exit;
	}
	/**BANK BOOK ACTION0---------------------------------------------------------------------------------------------------------------- */
	public function bankbookAction(){
		$this->init();
		if($this->getRequest()->isPost()):
			$form = $this->getRequest()->getPost();
			$bank_account = $form['bank_account'];
			$basubhead=$this->getDefinedTable(Accounts\SubheadTable::class)->getColumn(['ref_id'=>$bank_account,'type'=>3],'id');
			//echo '<pre>';print_r($basubhead);exit;
			$start_date = $form['start_date'];
			$end_date = $form['end_date'];
		else:
			$basubhead = '-1';
		    $start_date = date('Y-m-d');
			$end_date  = date('Y-m-d');
		endif;
		$data = array(
			'bank_account' => $basubhead,
			'start_date' => $start_date,
			'end_date'  => $end_date,
		);
		//echo '<pre>';print_r($bank_account);
		//echo '<pre>';print_r($data);exit;
		return new ViewModel(array(
			'title' => "Bank Book",
			'data' => $data,
			'bank_account' =>$basubhead,
			'locationObj' => $this->getDefinedTable(Administration\LocationTable::class),
			'transactionObj' => $this->getDefinedTable(Accounts\TransactionTable::class),
			'transactiondetailObj'=> $this->getDefinedTable(Accounts\TransactiondetailTable::class),
			'subheadObj'=> $this->getDefinedTable(Accounts\SubheadTable::class),
			'bankaccObj'=> $this->getDefinedTable(Accounts\BankaccountTable::class),
			'closingObj'  => $this->getDefinedTable(Accounts\ClosingbalanceTable::class),
			// 'userRoleObj'  => $this->getDefinedTable(Acl\UserroleTable::class),
			'userID' => $this->_author,
		));
	}
	 /**
	 * get bank account by location
	 */
	public function getbankaccountAction(){
		$this->init();
		$baccs='';
		$form = $this->getRequest()->getpost();			
		$locationID =$form['location_id'];
				
		$baccs.="<option value=''></option>";
		$baccounts = $this->getDefinedTable(Accounts\BankaccountTable::class)->get(array('ba.location'=>$locationID));
		
		foreach($baccounts as $ba):
			$baccs .="<option value='".$ba['id']."'>".$ba['account']."</option>";
		endforeach;			
		echo json_encode(array(
			'bankacc' => $baccs,
		));
	    exit;
	}
	/**GET LEDGER & SUB_LEDGER ACTION--------------------------------------------------------------------------------------------- */
	public function ledgerAction()
	{
		$this->init();
		if($this->getRequest()->isPost()):
			$form = $this->getRequest()->getPost();
			$location = $form['location'];
			$activity = $form['location'];//$form['activity'];
			$head = $form['head'];
			$sub_head = $form['sub_head'];
			$start_date = $form['start_date'];
			$end_date = $form['end_date'];
            $location_details = $form['location_details'];
		else:
			$location='-1';
			$activity='-1';
			$head='-1';
			$sub_head='-1';
			$location_details = '';
			$start_date = date('Y-m-d');
			$end_date  = date('Y-m-d');
		endif;
		$data = array(
			'location' => $location,
			'activity' => $activity,
			'head' => $head,
			'sub_head' => $sub_head,
			'start_date' => $start_date,
			'end_date' => $end_date,
		);
		$group_id = $this->getDefinedTable(Accounts\HeadTable::class)->getColumn($head,'group');
		$class_id = $this->getDefinedTable(Accounts\GroupTable::class)->getColumn($group_id,'class');
		$role=explode(",", (string) ($this->_login_role ?? ''));//Multiple Role
		$user_region= $this->getDefinedTable(Administration\UsersTable::class)->getColumn($this->_login_id,'region');
		if(in_array($this->_login_role,array(100,99,8,6,2,17,5,7))):
		   $regions=$this->getDefinedTable(Administration\RegionTable::class)->getAll();
		else:
		   $regions=$this->getDefinedTable(Administration\RegionTable::class)->get(array('id'=>$user_region));
		endif;
		$ViewModel =  new ViewModel(array(
			'title' => "Ledger & Sub-Ledger",
			'data' => $data,
			'region' => $regions,
			'class' => $class_id,
			'location_details' => $location_details,
			'locationObj' => $this->getDefinedTable(Administration\LocationTable::class),
			'activityObj' => $this->getDefinedTable(Administration\ActivityTable::class),
			'headObj' => $this->getDefinedTable(Accounts\HeadTable::class),
			'subheadObj' => $this->getDefinedTable(Accounts\SubheadTable::class),
			'transactionObj' => $this->getDefinedTable(Accounts\TransactionTable::class),
			'journalObj' => $this->getDefinedTable(Accounts\JournalTable::class),
			'transactiondetailObj'=> $this->getDefinedTable(Accounts\TransactiondetailTable::class),
			'closingbalanceObj'=> $this->getDefinedTable(Accounts\ClosingbalanceTable::class),
		    //'userRoleObj'  => $this->getDefinedTable(Acl\UserroleTable::class),
			'userID' => $this->_author,
		));
		return $ViewModel; 
	}
	
    /**GENERAL LEDGER -ANNEXTURE------------------------------------------------------------------------------------------------------ */
	public function annextureAction()
	{   
	    $this->init();
        $location_details = '';
		if($this->getRequest()->isPost()):
			$form = $this->getRequest()->getPost();
			$head = $form['head'];
			$start_date = $form['start_date'];
			$end_date = $form['end_date'];
			$location = $form['location'];
            $location_details = $form['location_details'];
		else:
			$head = '-1';
			$location = '-1';
			$start_date = date('Y-m-d');
			$end_date  = date('Y-m-d');
		endif;
		$data = array(
			'head' => $head,
			'start_date' => $start_date,
			'end_date' => $end_date,
			'location' =>$location,
		);
		$group_id = $this->getDefinedTable(Accounts\HeadTable::class)->getColumn($head,'group');
		$class_id = $this->getDefinedTable(Accounts\GroupTable::class)->getColumn($group_id,'class');
		$role=explode(",", (string) ($this->_login_role ?? ''));//Multiple Role
		$user_region= $this->getDefinedTable(Administration\UsersTable::class)->getColumn($this->_login_id,'region');
		if(in_array($this->_login_role,array(100,99,8,6,2,17,5,7))):
		   $regions=$this->getDefinedTable(Administration\RegionTable::class)->getAll();
		else:
		   $regions=$this->getDefinedTable(Administration\RegionTable::class)->get(array('id'=>$user_region));
		endif;
		$ViewModel =  new ViewModel(array(
            'location_details' => $location_details,
			'headObj'    => $this->getDefinedTable(Accounts\HeadTable::class),
			'subheadObj' => $this->getDefinedTable(Accounts\SubheadTable::class),
			'data'       => $data,
			'region'       =>$regions,
			'class'      => $class_id,
			'transactiondetailObj' => $this->getDefinedTable(Accounts\TransactiondetailTable::class),
            'locationObj' => $this->getDefinedTable(Administration\LocationTable::class),
			'userID'     => $this->_author,
		));
		return $ViewModel; 
	}
	
    /**CASH & BANK ------------------------------------------------------------------------------------------------------ */
	public function cashbankAction()
	{   
	    $this->init();
        $location_details = '';
		if($this->getRequest()->isPost()):
			$form = $this->getRequest()->getPost();
			$start_date = $form['start_date'];
			$end_date = $form['end_date'];
			$location = $form['location'];
            $location_details = $form['location_details'];
		else:
			$location = '-1';
			$start_date = date('Y-m-d');
			$end_date  = date('Y-m-d');
		endif;
		$data = array(
			'start_date' => $start_date,
			'end_date' => $end_date,
			'location' =>$location,
		);
		$role=explode(",", (string) ($this->_login_role ?? ''));//Multiple Role
		$user_region= $this->getDefinedTable(Administration\UsersTable::class)->getColumn($this->_login_id,'region');
		if(in_array($this->_login_role,array(100,99,8,6,2,17,5,7))):
		   $regions=$this->getDefinedTable(Administration\RegionTable::class)->getAll();
		else:
		   $regions=$this->getDefinedTable(Administration\RegionTable::class)->get(array('id'=>$user_region));
		endif;
		$ViewModel =  new ViewModel(array(
            'location_details' => $location_details,
			'headObj'    => $this->getDefinedTable(Accounts\HeadTable::class),
			'subheadObj' => $this->getDefinedTable(Accounts\SubheadTable::class),
			'data'       => $data,
			'region'       =>$regions,
			'transactiondetailObj' => $this->getDefinedTable(Accounts\TransactiondetailTable::class),
            'locationObj' => $this->getDefinedTable(Administration\LocationTable::class),
			'userID'     => $this->_author,
		));
		return $ViewModel; 
	}
    /** VOUCHER CHECK ACTION--------------------------------------------------------------------------------------------------------- */
	public function vouchercheckAction()
	{
		$this->init();
		if($this->getRequest()->isPost()):
			$form = $this->getRequest()->getPost();
			$location = $form['location'];
			$start_date = $form['start_date'];
			$end_date = $form['end_date'];
			$vouchertypes = $form['vouchertype'];
		else:
		    $vouchertypes='-1';
			$location = '-1';
			$start_date = date('Y-m-d');
			$end_date  = date('Y-m-d');
		endif;
		$data = array(
		    'journal' =>$vouchertypes,
			'location' => $location,
			'start_date' => date('Y-m-d',strtotime($start_date)),
			'end_date' => date('Y-m-d',strtotime($end_date)),
		);
		$role=explode(",", (string) ($this->_login_role ?? ''));//Multiple Role
		$user_region= $this->getDefinedTable(Administration\UsersTable::class)->getColumn($this->_login_id,'region');
		if(in_array($this->_login_role,array(100,99,8,6,2,17,5,7))):
		   $regions=$this->getDefinedTable(Administration\RegionTable::class)->getAll();
		else:
		   $regions=$this->getDefinedTable(Administration\RegionTable::class)->get(array('id'=>$user_region));
		endif;
		$ViewModel =  new ViewModel(array(
			'title'          => "Voucher Checking",
			'data'           => $data,
			'region'         => $regions,
			'locationObj'    => $this->getDefinedTable(Administration\LocationTable::class),
			'transactionObj' => $this->getDefinedTable(Accounts\TransactionTable::class),
			'journalObj'     => $this->getDefinedTable(Accounts\JournalTable::class),
		));
		return $ViewModel; 
	}
	/**GET SUBHEAD ACCORDING TO HEAD****************************************************************************************************/
	public function getsubheadAction()
	{
		$this->init();
		$form = $this->getRequest()->getPost();
		$head_id = $form['head'];
		$subheads = $this->getDefinedTable(Accounts\SubheadTable::class)->get(array('head'=>$head_id));
		$sub_heads = "<option value='-1'>All</option>";
		foreach($subheads as $subhead):
			$sub_heads .="<option value='".$subhead['id']."'>".AccountLabel::html($subhead['name'], $subhead['code'])."</option>";
		endforeach;
		echo json_encode(array(
			'subhead' => $sub_heads,
		));
		exit;
	}
	/** RECONCILATION-----------------------------------------------------------------------------------------------------*/
	public function addreconcilationAction(){
		$this->init();
		$year=0;
		$min_year=0;
		if($this->getRequest()->isPost()):
			$form = $this->getRequest()->getPost();
			$transdtls_id = $form['transactiondtl_id'];
			$trans_date = $form['reconcile_date'];
			$trans_subhead = $form['sub_head'];
			if(!empty($form['reconcile'])){
				$reconcile =1;
			}else{
				$reconcile =0;
			}
			$data = array(
			   'id'  => $transdtls_id,
			   'reconcile' =>$reconcile,
			   'reconcile_date' => $trans_date[$i],
			   'author' =>$this->_author,					
			   'modified' =>$this->_modified,
			);
			$result = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->save($data);			
			if($result > 0 ){
				$this->flashMessenger()->addMessage('success^ Successfully Reconciled !');
				$this->redirect()->toRoute('report', array('action' => 'reconcilationlist'));	
			}	
		endif; 
		$today_date   = date('Y-m-d');
		return new ViewModel(array(
			'title'  => ' Bank Reconcilation',
			'todaydate'=>$today_date,
			'transObj' => $this->getDefinedTable(Accounts\TransactiondetailTable::class),
			'subheadObj' => $this->getDefinedTable(Accounts\SubheadTable::class),
			'headObj' => $this->getDefinedTable(Accounts\HeadTable::class),	

   		));
	}
	/**-- TRANSACTIONS NOT RECONCILED----------------------------------------------------------------------*/
	public function transactionlistAction()
	{
		$this->init();		
		$param = explode('-', (string) $this->_id);
		$head = $param['0'];
		$login_id=$this->_login_id;
		$user_location = $this->getDefinedTable(Administration\UsersTable::class)->getColumn($login_id,'admin_location');
		$ViewModel = new ViewModel(array(
			'head'       => $head,
			'user_location'  =>$user_location,
			'subheadObj' => $this->getDefinedTable(Accounts\SubheadTable::class),
			'headObj' => $this->getDefinedTable(Accounts\HeadTable::class),	
			'transactiondetailsObj' => $this->getDefinedTable(Accounts\TransactiondetailTable::class),	
			'cashaccountObj' => $this->getDefinedTable(Accounts\CashaccountTable::class),			
			'headObj' => $this->getDefinedTable(Accounts\HeadTable::class),	
			'bankaccountObj' => $this->getDefinedTable(Accounts\BankaccountTable::class),
			'partyObj'  => $this->getDefinedTable(Accounts\PartyTable::class),
		));
		$ViewModel->setTerminal(True);
		return $ViewModel;
	}
	/**---------------------------RECONCILATION------------------------------------------------------------- */
	/**  reconcilation action */
	public function reconcilationlistAction()
	{
		$this->init();
		$start_date = $this->params()->fromRoute('start_date', date('Y-m-d')); // Default to today's date
        $end_date = $this->params()->fromRoute('end_date', date('Y-m-d')); // Default to today's date
        $bank = $this->params()->fromRoute('bank', '-1'); // Default to -1 (All banks)
		if($this->getRequest()->isPost()){
			$form = $this->getRequest()->getPost();
			$start_date = $form['start_date'];
			$end_date = $form['end_date'];
			$reconcile =$form['reconcile'];
			if($form['bank']!='-1'){
			    $bank_ref=$this->getDefinedTable(Accounts\SubheadTable::class)->getbanks(array('ref_id'=>$form['bank'],'type'=>3));
				foreach($bank_ref as $subheadbank);
				if(!empty($subheadbank)){
				   $bank=$subheadbank['id'];
				}else{
					$bank=$form['bank'];
				}
			}else{
				$bank=$form['bank'];
			}
			$banksel=$form['bank'];
		}else{
			$start_date = date('Y-m-d');
			$end_date  = date('Y-m-d');
			$bank = '-1';
			$banksel = '-1';
		}	
		$data = array(
			'start_date' => $start_date,
			'end_date' => $end_date,
            'bank' =>$bank,	
            'bankselected' =>$banksel,				
		);
		$transTable = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->getforreconcile($data);
		$paginator = new \Laminas\Paginator\Paginator(new \Laminas\Paginator\Adapter\ArrayAdapter($transTable));
		$page = 1;
		if ($this->params()->fromRoute('page')) {
			$page = $this->params()->fromRoute('page');
		}
		$paginator->setCurrentPageNumber((int)$page);
		$paginator->setItemCountPerPage(100000);
		$paginator->setPageRange(8);
		$user_id=$this->_login_id;
		$user_role=$this->_login_role;
		$location=$this->getDefinedTable(Administration\UsersTable::class)->getColumn($this->_author,'location');
		//echo '<pre>';print_r($location);exit;
		return new ViewModel(array(
			'title'         =>'RECONCILATION',
			'user'          =>$user_id,
			'role'          =>$user_role,
			'paginator'     =>$paginator,
			'page'          => $page,
			'data'          =>$data,
			'transactiondetailObj'=> $this->getDefinedTable(Accounts\TransactiondetailTable::class),
			'regions' => $this->getDefinedTable(Administration\RegionTable::class)->getAll(),
			'voucherObj'    =>$this->getDefinedTable(Accounts\JournalTable::class),
			'location'       =>$location,
			'regionObj'     =>$this->getDefinedTable(Administration\RegionTable::class),
			'subheadObj'     =>$this->getDefinedTable(Accounts\SubheadTable::class),
			'bankObj'         =>$this->getDefinedTable(Accounts\BankaccountTable::class),
			'transactionObj' =>$this->getDefinedTable(Accounts\TransactionTable::class),
   		));
	}
	/** BANK RECONCILE */
	public function reconcilationAction()
    {
        $this->init();
	    $array_id = explode("_", (string) $this->_id);
		$startDate = isset($array_id[2])?$array_id[2]:'';
		$endDate = isset($array_id[3])?$array_id[3]:'';
		$bank = isset($array_id[4])?$array_id[4]:'';
		//echo '<pre>';print_r($startDate);exit;
        if ($this->getRequest()->isPost()) {
            $form = $this->getRequest()->getPost();
			$date = date('ym',strtotime($form['voucher_dates']));
			if(!empty($form['reconcile'])){
				$reconcile=1;
			}else{
				$reconcile=0;
			}
            $data = array(
                'id' => $this->_id,
				'reconcile' =>$reconcile,
				'reconcile_date' =>$form['voucher_dates'],
				'author' =>$this->_author,					
				'modified' =>$this->_modified,
            );
            //echo '<pre>';print_r($data);exit;
            //$data = $this->_safedataObj->rteSafe($data);
			$result = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->save($data);
			if($result > 0 ){
				$this->flashMessenger()->addMessage('success^ Successfully Reconciled!');
				$this->redirect()->toRoute('report', [
					'action' => 'reconcilationlist',
					'start_date' => $startDate,
					'end_date' => $endDate,
					'bank' => $bank
				]);	
			}
        }
        $ViewModel = new ViewModel(array(
			'title'  => ' Bank Reconcilation',
			'transObj' => $this->getDefinedTable(Accounts\TransactiondetailTable::class),
			'subheadObj' => $this->getDefinedTable(Accounts\SubheadTable::class),
			'headObj' => $this->getDefinedTable(Accounts\HeadTable::class),	
			'headtype' => $this->getDefinedTable(Accounts\HeadtypeTable::class)->getAll(),
			'types'	=> $this->getDefinedTable(Accounts\TypeTable::class)->getAll(),
			'voucherObj'    =>$this->getDefinedTable(Accounts\JournalTable::class),
			'locationObj'   =>$this->getDefinedTable(Administration\LocationTable::class),
			'regionObj'     =>$this->getDefinedTable(Administration\RegionTable::class),
			'subheadObj'     =>$this->getDefinedTable(Accounts\SubheadTable::class),
			'tdetailsObj' => $this->getDefinedTable(Accounts\TransactiondetailTable::class),
			'bankings' => $this->getDefinedTable(Accounts\TransactiondetailTable::class)->get($this->_id),
			'tdetails' => $this->getDefinedTable(Accounts\TransactiondetailTable::class)->get($this->_id),
		));
		$ViewModel->setTerminal(True);
		return $ViewModel;
    }
	/**---------------------------SDR REPORT------------------------------------------------------------- */
	/**  sdr action */
	public function sdrAction()
	{
		$this->init();
		if($this->getRequest()->isPost()){
			$form = $this->getRequest()->getPost();
			$start_date = $form['start_date'];
			$end_date = $form['end_date'];
			$ledger = $form['ledger'];
			$subledger = $form['subledger'];
		}else{
            $start_date =  date('Y-m-d');
			$end_date =  date('Y-m-d');
			$ledger ='-1';
			$subledger ='-1';
		}	
		$data = array(
			'start_date' => $start_date,
			'end_date' => $end_date,
			'ledger' => $ledger,
			'subledger' => $subledger,
		);
		//echo '<pre>';print_r($data);exit;
		$transTable = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->getbyhead($data['ledger'],$data['subledger'],$start_date,$end_date);
		$paginator = new \Laminas\Paginator\Paginator(new \Laminas\Paginator\Adapter\ArrayAdapter($transTable));
		//echo '<pre>';print_r($paginator);exit;
		$page = 1;
		if ($this->params()->fromRoute('page')) $page = $this->params()->fromRoute('page');
		$paginator->setCurrentPageNumber((int)$page);
		$paginator->setItemCountPerPage(10000);
		$paginator->setPageRange(8);
		
		return new ViewModel(array(
			'title'         => 'debit',
			'paginator'     => $paginator,
			'page'          => $page,
			'data'          => $data,
			'headObj'       =>$this->getDefinedTable(Accounts\HeadTable::class),
			'subheadObj'    =>$this->getDefinedTable(Accounts\SubheadTable::class),
   		));
	}
	 /**--VIEW COST PROFIT REPORT POST OFFICE WISE FORECASTING ------------------------------------------------------------------------------------------*/
    public function costprofitAction()
	{
		$this->init();		
		if($this->getRequest()->isPost()):
			$form = $this->getRequest()->getPost();
			$tier = $form['tier'];
			$start_date = $form['start_date'];
			$end_date = $form['end_date'];
			$region = $form['region'];
            $loc = $form['location'];
		else:
		    $tier =0;
			$start_date = date('Y-m-d');
			$end_date = date('Y-m-d');
			$region = '-1';
            $loc = '-1';
		endif;
		$data = array(
		    'tier'   => $tier,
		    'start_date'=>$start_date,
			'end_date'=>$end_date,
			'region' => $region,
			'location' => $loc,
		);
		//echo '<pre>';print_r($data);exit;
		return new ViewModel(array(
			'title'  => 'Cost Profit Report',
			'data' =>$data,
			'tdetailsObj' => $this->getDefinedTable(Accounts\TransactiondetailTable::class),
			'subheadObj' => $this->getDefinedTable(Accounts\SubheadTable::class),
			'headObj' => $this->getDefinedTable(Accounts\HeadTable::class),
			'groupObj' => $this->getDefinedTable(Accounts\GroupTable::class),
			'classObj' => $this->getDefinedTable(Accounts\ClassTable::class), 
			'locationObj' => $this->getDefinedTable(Administration\LocationTable::class),
			'regionObj' => $this->getDefinedTable(Administration\RegionTable::class),
   		));
	} 
	public function fetchreportlevelAction()
	{
		$this->init();
		$form = $this->getRequest()->getPost();
		$report = isset($form['report']) ? $form['report'] : '';
		$level = isset($form['level']) ? $form['level'] : '';
		$parentId = isset($form['parent_id']) ? (int)$form['parent_id'] : 0;
		$headTypeId = isset($form['head_type_id']) ? $form['head_type_id'] : '';
		$response = $this->getResponse();
		$response->getHeaders()->addHeaderLine('Content-Type', 'application/json');

		if (!is_scalar($headTypeId) || ($headTypeId !== '' && !ctype_digit((string)$headTypeId))) {
			$response->setStatusCode(400);
			return $response->setContent(json_encode(array('success' => 0, 'message' => 'Invalid Head Type.', 'rows' => '')));
		}

		$filters = array(
			'activity' => isset($form['activity']) ? $form['activity'] : '-1',
			'region' => isset($form['region']) ? $form['region'] : '-1',
			'location' => isset($form['location']) ? $form['location'] : '-1',
			'start_date' => isset($form['start_date']) ? $form['start_date'] : date('Y-01-01'),
			'end_date' => isset($form['end_date']) ? $form['end_date'] : date('Y-m-d'),
			'head_type_id' => $headTypeId !== '' ? (int)$headTypeId : null,
		);
		$scope = $this->resolveReportScope($filters['region'], $filters['location'], $filters['activity']);
		$filters['activity'] = $scope['activity'];
		$filters['region'] = $scope['region'];
		$filters['location'] = $scope['location'];

		if($parentId <= 0 || empty($report) || empty($level)):
			$response->setStatusCode(400);
			return $response->setContent(json_encode(array(
				'success' => 0,
				'message' => 'Invalid request. Missing report node context.',
				'rows' => '',
			)));
		endif;

		try {
			$rows = '';
			if($level == 'headtype' && in_array($report, array('trialbalance', 'balancesheet', 'profitloss'), true)):
				$rows = $this->buildReportHeadTypeLazyRows($report, $parentId, $filters);
			elseif($report == 'trialbalance'):
				$rows = $this->buildTrialBalanceLazyRows($level, $parentId, $filters);
			elseif($report == 'balancesheet'):
				$rows = $this->buildBalanceSheetLazyRows($level, $parentId, $filters);
			elseif($report == 'profitloss'):
				$rows = $this->buildProfitLossLazyRows($level, $parentId, $filters);
			endif;

			return $response->setContent(json_encode(array('success' => 1, 'rows' => $rows)));
		} catch (\Throwable $e) {
			error_log(sprintf(
				'[ReportController::fetchreportlevelAction] report=%s level=%s parent=%d region=%s location=%s activity=%s error=%s',
				(string)$report,
				(string)$level,
				(int)$parentId,
				isset($filters['region']) ? (string)$filters['region'] : '',
				isset($filters['location']) ? (string)$filters['location'] : '',
				isset($filters['activity']) ? (string)$filters['activity'] : '',
				$e->getMessage()
			));
			$response->setStatusCode(500);
			return $response->setContent(json_encode(array(
				'success' => 0,
				'message' => 'Unable to load the selected level: ' . $e->getMessage(),
				'rows' => '',
			)));
		}
	}

	public function getreportlevelAction()
	{
		return $this->fetchreportlevelAction();
	}

	private function getReportHeads($report, $groupId, $filters)
	{
		$where = new Where();
		$where->equalTo('group', $groupId);
		if ($filters['head_type_id'] !== null) {
			if ($filters['head_type_id'] === 0) {
				$where->nest()->isNull('head_type')->or->equalTo('head_type', 0)->unnest();
			} else {
				$where->equalTo('head_type', $filters['head_type_id']);
			}
		}
		$headObj = $this->getDefinedTable(Accounts\HeadTable::class);
		if ($report === 'balancesheet') {
			return $headObj->getTransactionHeadforBS($filters['activity'], $filters['region'], $filters['location'], $filters['start_date'], $filters['end_date'], $where);
		}
		return $headObj->getTransactionHead($filters['activity'], $filters['region'], $filters['location'], $filters['start_date'], $filters['end_date'], $where);
	}

	private function getReportHeadAmounts($report, $head, $filters)
	{
		$transactiondetailObj = $this->getDefinedTable(Accounts\TransactiondetailTable::class);
		$classId = $this->getDefinedTable(Accounts\GroupTable::class)->getColumn($head['group'], 'class');
		$activity = $filters['activity'];
		$region = $filters['region'];
		$location = $filters['location'];
		$startDate = $filters['start_date'];
		$endDate = $filters['end_date'];
		$id = $head['id'];

		if ($report === 'trialbalance') {
			$amounts = array(
				'debit' => (float)$transactiondetailObj->getSumbyHead($activity,$region,$location,$startDate,$endDate,'debit',$id),
				'credit' => (float)$transactiondetailObj->getSumbyHead($activity,$region,$location,$startDate,$endDate,'credit',$id),
				'opening' => 0.0,
			);
			if ($classId == 1 || $classId == 2) {
				$amounts['opening'] = (float)$transactiondetailObj->getOpeningBalance($activity,$region,$location,$startDate,$endDate,$id,2);
				$amounts['closing'] = (float)$transactiondetailObj->getClosingBalanceAL($activity,$region,$location,$startDate,$endDate,$id,2);
			} else {
				$amounts['closing'] = (float)$transactiondetailObj->getClosingBalanceIE($activity,$region,$location,$startDate,$endDate,$id,2);
			}
			return $amounts;
		}

		list($previousStart, $previousEnd) = $this->previousPeriod($startDate, $endDate);
		if ($report === 'balancesheet') {
			return array(
				'present' => (float)$transactiondetailObj->getClosingBalanceforPresBS($activity,$region,$location,$startDate,$endDate,$id,$classId,2),
				'previous' => (float)$transactiondetailObj->getClosingBalanceforPrevBS($activity,$region,$location,$previousStart,$previousEnd,$id,2),
			);
		}
		return array(
			'present' => (float)$transactiondetailObj->getClosingBalanceforPresPLSCLASS($activity,$region,$location,$startDate,$endDate,$id,$classId,2),
			'previous' => (float)$transactiondetailObj->getClosingBalanceforPrevPLS($activity,$region,$location,$previousStart,$previousEnd,$id,2),
		);
	}

	private function getReportHeadParentNode($groupId, $filters)
	{
		return $filters['head_type_id'] === null
			? 'group-' . $groupId
			: 'headtype-' . $groupId . '_' . $filters['head_type_id'];
	}

	private function buildReportHeadTypeLazyRows($report, $groupId, $filters)
	{
		$types = array();
		foreach ($this->getDefinedTable(Accounts\HeadtypeTable::class)->getAll() as $type) {
			$types[$type['id']] = $type['head_type'];
		}
		$totals = array();
		foreach ($this->getReportHeads($report, $groupId, $filters) as $head) {
			$amounts = $this->getReportHeadAmounts($report, $head, $filters);
			if ($report === 'trialbalance' && !array_filter($amounts)) {
				continue;
			}
			$typeId = (int)$head['head_type'];
			if (!isset($totals[$typeId])) {
				$totals[$typeId] = array_fill_keys(array_keys($amounts), 0.0);
			}
			foreach ($amounts as $column => $amount) {
				$totals[$typeId][$column] += $amount;
			}
		}
		ksort($totals, SORT_NUMERIC);
		$rows = '';
		foreach ($totals as $typeId => $amounts) {
			$node = 'headtype-' . $groupId . '_' . $typeId;
			$name = isset($types[$typeId]) ? $types[$typeId] : 'Unassigned Head Type';
			$rows .= '<tr class="tb-row tb-headtype" data-level="headtype" data-node="'.$node.'" data-parent="group-'.$groupId.'">'
				.'<td style="padding-left:24px"><a href="#" class="tb-toggle" data-node="'.$node.'"><span class="tb-caret">+</span> '.$this->esc($name).'</a></td>';
			if ($report === 'trialbalance') {
				$rows .= '<td style="text-align:right">'.$this->fmt(abs($amounts['opening'])).'</td>'
					.'<td>'.($amounts['opening'] == 0 ? '' : ($amounts['opening'] < 0 ? 'Cr' : 'Dr')).'</td>'
					.'<td style="text-align:right">'.$this->fmt($amounts['debit']).'</td>'
					.'<td style="text-align:right">'.$this->fmt($amounts['credit']).'</td>'
					.'<td style="text-align:right">'.$this->fmt(abs($amounts['closing'])).'</td>'
					.'<td>'.($amounts['closing'] == 0 ? '' : ($amounts['closing'] < 0 ? 'Cr' : 'Dr')).'</td>';
			} else {
				$rows .= '<td style="text-align:right">'.number_format($amounts['present'],2,'.',',').'</td>'
					.'<td style="text-align:right">'.number_format($amounts['previous'],2,'.',',').'</td>';
			}
			$rows .= '</tr>';
		}
		return $rows;
	}

	private function buildTrialBalanceLazyRows($level, $parentId, $filters)
	{
		$rows = '';
		$activity = $filters['activity'];
		$region = $filters['region'];
		$location = $filters['location'];
		$startDate = $filters['start_date'];
		$endDate = $filters['end_date'];

		$transactiondetailObj = $this->getDefinedTable(Accounts\TransactiondetailTable::class);
		$groupObj = $this->getDefinedTable(Accounts\GroupTable::class);
		$headObj = $this->getDefinedTable(Accounts\HeadTable::class);
		$subheadObj = $this->getDefinedTable(Accounts\SubheadTable::class);

		if($level == 'group'):
			foreach($groupObj->getTransactionGroup($activity, $region, $location, $startDate, $endDate, array('class' => $parentId)) as $grouprow):
				$total_debit = $transactiondetailObj->getSumbyGroup($activity,$region,$location,$startDate,$endDate,'debit',$grouprow['id']);
				$total_credit = $transactiondetailObj->getSumbyGroup($activity,$region,$location,$startDate,$endDate,'credit',$grouprow['id']);

				if($grouprow['class'] == '1' || $grouprow['class'] == '2'):
					$opening_balance = $transactiondetailObj->getOpeningBalance($activity,$region,$location,$startDate,$endDate,$grouprow['id'],3);
					$closing_balance = $transactiondetailObj->getClosingBalanceAL($activity,$region,$location,$startDate,$endDate,$grouprow['id'],3);
				else:
					$opening_balance = 0;
					$closing_balance = $transactiondetailObj->getClosingBalanceIE($activity,$region,$location,$startDate,$endDate,$grouprow['id'],3);
				endif;

				$cr_or_dr_ob = ($opening_balance == '' || $opening_balance == '0') ? '' : (($opening_balance < 0) ? 'Cr' : 'Dr');
				$cr_or_dr_cb = ($closing_balance == '' || $closing_balance == '0') ? '' : (($closing_balance < 0) ? 'Cr' : 'Dr');
				if($opening_balance < 0): $opening_balance = -$opening_balance; endif;
				if($closing_balance < 0): $closing_balance = -$closing_balance; endif;

				if(($opening_balance!=0) || ($closing_balance!=0) || ($total_debit!=0) || ($total_credit!=0)):
					$rows .= '<tr class="info tb-row tb-group" data-level="group" data-node="group-'.$grouprow['id'].'" data-parent="class-'.$parentId.'">'
						.'<td>&nbsp;&nbsp;&nbsp;<a href="#" class="tb-toggle" data-node="group-'.$grouprow['id'].'"><span class="tb-caret">+</span> '.$this->esc($grouprow['name']).'</a></td>'
						.'<td style="text-align:right">'.$this->fmt($opening_balance).'</td>'
						.'<td>'.$cr_or_dr_ob.'</td>'
						.'<td style="text-align:right">'.$this->fmt($total_debit).'</td>'
						.'<td style="text-align:right">'.$this->fmt($total_credit).'</td>'
						.'<td style="text-align:right">'.$this->fmt($closing_balance).'</td>'
						.'<td>'.$cr_or_dr_cb.'</td>'
						.'</tr>';
				endif;
			endforeach;
		elseif($level == 'head'):
			foreach($this->getReportHeads('trialbalance', $parentId, $filters) as $headrow):
				$amounts = $this->getReportHeadAmounts('trialbalance', $headrow, $filters);
				$total_debit = $amounts['debit'];
				$total_credit = $amounts['credit'];
				$opening_balance = $amounts['opening'];
				$closing_balance = $amounts['closing'];

				$cr_or_dr_ob = ($opening_balance == '' || $opening_balance == '0') ? '' : (($opening_balance < 0) ? 'Cr' : 'Dr');
				$cr_or_dr_cb = ($closing_balance == '' || $closing_balance == '0') ? '' : (($closing_balance < 0) ? 'Cr' : 'Dr');
				if($opening_balance < 0): $opening_balance = -$opening_balance; endif;
				if($closing_balance < 0): $closing_balance = -$closing_balance; endif;

				if(($opening_balance!=0) || ($closing_balance!=0) || ($total_debit!=0) || ($total_credit!=0)):
					$rows .= '<tr class="success tb-row tb-head" data-level="head" data-node="head-'.$headrow['id'].'" data-parent="'.$this->getReportHeadParentNode($parentId, $filters).'">'
						.'<td style="padding-left:36px"><a href="#" class="tb-toggle" data-node="head-'.$headrow['id'].'"><span class="tb-caret">+</span> '.AccountLabel::html($headrow['name'], $headrow['code']).'</a></td>'
						.'<td style="text-align:right">'.$this->fmt($opening_balance).'</td>'
						.'<td>'.$cr_or_dr_ob.'</td>'
						.'<td style="text-align:right">'.$this->fmt($total_debit).'</td>'
						.'<td style="text-align:right">'.$this->fmt($total_credit).'</td>'
						.'<td style="text-align:right">'.$this->fmt($closing_balance).'</td>'
						.'<td>'.$cr_or_dr_cb.'</td>'
						.'</tr>';
				endif;
			endforeach;
		elseif($level == 'subhead'):
			foreach($subheadObj->getTransactionSubhead($activity,$region,$location,$startDate,$endDate,array('head'=>$parentId)) as $subheadrow):
				$total_debit = $transactiondetailObj->getSumbySubhead($activity,$region,$location,$startDate,$endDate,'debit',$subheadrow['id']);
				$total_credit = $transactiondetailObj->getSumbySubhead($activity,$region,$location,$startDate,$endDate,'credit',$subheadrow['id']);
				$head_id = $subheadObj->getColumn($subheadrow['id'],'head');
				$group_id = $headObj->getColumn($head_id,'group');
				$class_id = $groupObj->getColumn($group_id,'class');

				if($class_id == '1' || $class_id == '2'):
					$opening_balance = $transactiondetailObj->getOpeningBalance($activity,$region,$location,$startDate,$endDate,$subheadrow['id'],1);
					$closing_balance = $transactiondetailObj->getClosingBalanceAL($activity,$region,$location,$startDate,$endDate,$subheadrow['id'],1);
				else:
					$opening_balance = 0;
					$closing_balance = $transactiondetailObj->getClosingBalanceIE($activity,$region,$location,$startDate,$endDate,$subheadrow['id'],1);
				endif;

				$cr_or_dr_ob = ($opening_balance == '' || $opening_balance == '0') ? '' : (($opening_balance < 0) ? 'Cr' : 'Dr');
				$cr_or_dr_cb = ($closing_balance == '' || $closing_balance == '0') ? '' : (($closing_balance < 0) ? 'Cr' : 'Dr');
				if($opening_balance < 0): $opening_balance = -$opening_balance; endif;
				if($closing_balance < 0): $closing_balance = -$closing_balance; endif;

				if(($opening_balance!=0) || ($closing_balance!=0) || ($total_debit!=0) || ($total_credit!=0)):
					$rows .= '<tr class="subheadrow tb-row tb-subhead" data-level="subhead" data-parent="head-'.$parentId.'">'
						.'<td style="padding-left:48px">'.$this->esc($subheadrow['code'].'-'.$subheadrow['name']).'</td>'
						.'<td style="text-align:right">'.$this->fmt($opening_balance).'</td>'
						.'<td>'.$cr_or_dr_ob.'</td>'
						.'<td style="text-align:right">'.$this->fmt($total_debit).'</td>'
						.'<td style="text-align:right">'.$this->fmt($total_credit).'</td>'
						.'<td style="text-align:right">'.$this->fmt($closing_balance).'</td>'
						.'<td>'.$cr_or_dr_cb.'</td>'
						.'</tr>';
				endif;
			endforeach;
		endif;

		return $rows;
	}

	private function buildBalanceSheetLazyRows($level, $parentId, $filters)
	{
		$rows = '';
		$activity = $filters['activity'];
		$region = $filters['region'];
		$location = $filters['location'];
		$startDate = $filters['start_date'];
		$endDate = $filters['end_date'];
		list($pre_starting_date, $pre_ending_date) = $this->previousPeriod($startDate, $endDate);

		$transactiondetailObj = $this->getDefinedTable(Accounts\TransactiondetailTable::class);
		$groupObj = $this->getDefinedTable(Accounts\GroupTable::class);
		$headObj = $this->getDefinedTable(Accounts\HeadTable::class);
		$subheadObj = $this->getDefinedTable(Accounts\SubheadTable::class);

		if($level == 'group'):
			foreach($groupObj->getTransactionGroupforBS($activity,$region,$location,$startDate,$endDate,array('class'=>$parentId)) as $grouprow):
				$class_id = $groupObj->getColumn($grouprow['id'],'class');
				$pres = $transactiondetailObj->getClosingBalanceforPresBS($activity,$region,$location,$startDate,$endDate,$grouprow['id'],$class_id,3);
				$prev = $transactiondetailObj->getClosingBalanceforPrevBS($activity,$region,$location,$pre_starting_date,$pre_ending_date,$grouprow['id'],$class_id,3);
				$rows .= '<tr class="info grouprow tb-row tb-group" data-level="group" data-node="group-'.$grouprow['id'].'" data-parent="class-'.$parentId.'">'
					.'<td class="text-success"><strong><a href="#" class="tb-toggle" data-node="group-'.$grouprow['id'].'"><span class="tb-caret">+</span> '.$this->esc($grouprow['name']).'</a></strong></td>'
					.'<td class="text-success" style="text-align:right"><strong>'.number_format((float)$pres,2,'.',',').'</strong></td>'
					.'<td class="text-success" style="text-align:right"><strong>'.number_format((float)$prev,2,'.',',').'</strong></td>'
					.'</tr>';
			endforeach;
		elseif($level == 'head'):
			foreach($this->getReportHeads('balancesheet', $parentId, $filters) as $headrow):
				$amounts = $this->getReportHeadAmounts('balancesheet', $headrow, $filters);
				$pres = $amounts['present'];
				$prev = $amounts['previous'];
				$rows .= '<tr class="success headrow tb-row tb-head" data-level="head" data-node="head-'.$headrow['id'].'" data-parent="'.$this->getReportHeadParentNode($parentId, $filters).'">'
					.'<td class="text-secondary" style="padding-left:36px"><strong><a href="#" class="tb-toggle" data-node="head-'.$headrow['id'].'"><span class="tb-caret">+</span> '.AccountLabel::html($headrow['name'], $headrow['code']).'</a></strong></td>'
					.'<td style="text-align:right"><strong>'.number_format((float)$pres,2,'.',',').'</strong></td>'
					.'<td style="text-align:right"><strong>'.number_format((float)$prev,2,'.',',').'</strong></td>'
					.'</tr>';
			endforeach;
		elseif($level == 'subhead'):
			foreach($subheadObj->getTransactionSubheadforBS($activity,$region,$location,$startDate,$endDate,array('head'=>$parentId)) as $subheadrow):
				$head_id = $subheadObj->getColumn($subheadrow['id'],'head');
				$group_id = $headObj->getColumn($head_id,'group');
				$class_id = $groupObj->getColumn($group_id,'class');
				$pres = $transactiondetailObj->getClosingBalanceforPresBS($activity,$region,$location,$startDate,$endDate,$subheadrow['id'],$class_id,1);
				$prev = $transactiondetailObj->getClosingBalanceforPrevBS($activity,$region,$location,$pre_starting_date,$pre_ending_date,$subheadrow['id'],1);
				if((float)$pres == 0.0 && (float)$prev == 0.0):
					continue;
				endif;
				$rows .= '<tr class="subheadrow tb-row tb-subhead" data-level="subhead" data-parent="head-'.$parentId.'">'
					.'<td style="padding-left:48px">'.$this->esc($subheadrow['code'].'-'.$subheadrow['name']).'</td>'
					.'<td style="text-align:right">'.number_format((float)$pres,2,'.',',').'</td>'
					.'<td style="text-align:right">'.number_format((float)$prev,2,'.',',').'</td>'
					.'</tr>';
			endforeach;
		endif;

		return $rows;
	}

	private function buildProfitLossLazyRows($level, $parentId, $filters)
	{
		$rows = '';
		$activity = $filters['activity'];
		$region = $filters['region'];
		$location = $filters['location'];
		$startDate = $filters['start_date'];
		$endDate = $filters['end_date'];
		list($pre_starting_date, $pre_ending_date) = $this->previousPeriod($startDate, $endDate);

		$transactiondetailObj = $this->getDefinedTable(Accounts\TransactiondetailTable::class);
		$groupObj = $this->getDefinedTable(Accounts\GroupTable::class);
		$headObj = $this->getDefinedTable(Accounts\HeadTable::class);
		$subheadObj = $this->getDefinedTable(Accounts\SubheadTable::class);

		if($level == 'group'):
			foreach($groupObj->getTransactionGroup($activity,$region,$location,$startDate,$endDate,array('class'=>$parentId)) as $grouprow):
				$class_id = $groupObj->getColumn($grouprow['id'],'class');
				$pres = $transactiondetailObj->getClosingBalanceforPresPLSCLASS($activity,$region,$location,$startDate,$endDate,$grouprow['id'],$class_id,3);
				$prev = $transactiondetailObj->getClosingBalanceforPrevPLS($activity,$region,$location,$pre_starting_date,$pre_ending_date,$grouprow['id'],3);
				$rows .= '<tr class="info tb-row tb-group" data-level="group" data-node="group-'.$grouprow['id'].'" data-parent="class-'.$parentId.'">'
					.'<td class="text-danger">&nbsp;<a href="#" class="tb-toggle" data-node="group-'.$grouprow['id'].'"><span class="tb-caret">+</span> '.$this->esc($grouprow['name']).'</a></td>'
					.'<td style="text-align:right">'.number_format((float)$pres,2,'.',',').'</td>'
					.'<td style="text-align:right">'.number_format((float)$prev,2,'.',',').'</td>'
					.'</tr>';
			endforeach;
		elseif($level == 'head'):
			foreach($this->getReportHeads('profitloss', $parentId, $filters) as $headrow):
				$amounts = $this->getReportHeadAmounts('profitloss', $headrow, $filters);
				$pres = $amounts['present'];
				$prev = $amounts['previous'];
				$rows .= '<tr class="success tb-row tb-head" data-level="head" data-node="head-'.$headrow['id'].'" data-parent="'.$this->getReportHeadParentNode($parentId, $filters).'">'
					.'<td class="text-success" style="padding-left:36px"><a href="#" class="tb-toggle" data-node="head-'.$headrow['id'].'"><span class="tb-caret">+</span> '.AccountLabel::html($headrow['name'], $headrow['code']).'</a></td>'
					.'<td style="text-align:right">'.number_format((float)$pres,2,'.',',').'</td>'
					.'<td style="text-align:right">'.number_format((float)$prev,2,'.',',').'</td>'
					.'</tr>';
			endforeach;
		elseif($level == 'subhead'):
			foreach($subheadObj->getTransactionSubhead($activity,$region,$location,$startDate,$endDate,array('head'=>$parentId)) as $subheadrow):
				$head_id = $subheadObj->getColumn($subheadrow['id'],'head');
				$group_id = $headObj->getColumn($head_id,'group');
				$class_id = $groupObj->getColumn($group_id,'class');
				$pres = $transactiondetailObj->getClosingBalanceforPresPLSCLASS($activity,$region,$location,$startDate,$endDate,$subheadrow['id'],$class_id,1);
				$prev = $transactiondetailObj->getClosingBalanceforPrevPLS($activity,$region,$location,$pre_starting_date,$pre_ending_date,$subheadrow['id'],1);
				$rows .= '<tr class="subheadrow tb-row tb-subhead" data-level="subhead" data-parent="head-'.$parentId.'">'
					.'<td style="padding-left:48px">'.$this->esc($subheadrow['code'].'-'.$subheadrow['name']).'</td>'
					.'<td style="text-align:right">'.number_format((float)$pres,2,'.',',').'</td>'
					.'<td style="text-align:right">'.number_format((float)$prev,2,'.',',').'</td>'
					.'</tr>';
			endforeach;
		endif;

		return $rows;
	}

	private function previousPeriod($startDate, $endDate)
	{
		$pre_starting_date = date('Y-m-d', strtotime('-1 year', strtotime($startDate)));
		$pre_ending_date = date('Y-m-d', strtotime('-1 year', strtotime($endDate)));
		return array($pre_starting_date, $pre_ending_date);
	}

	private function fmt($number)
	{
		return number_format((float)$number, 2, '.', ',');
	}

	private function esc($value)
	{
		return AccountLabel::html($value);
	}

	/**GET TDS REPORT ACTION *****************************************************************************************************/
	public function tdsreportAction()
	{   
	    $this->init();
        $location_details = '';
		$taxationTypeId = $this->getTaxationTypeIdForReport();
		$taxationOptions = array();
		$defaultSubHead = '';
		$taxationRows = $this->getDefinedTable(Accounts\TaxationTable::class)->getAll();
		foreach($taxationRows as $taxationRow):
			$taxationSubheads = $this->getDefinedTable(Accounts\SubheadTable::class)->get(array('sh.ref_id' => $taxationRow['id'], 'sh.type' => $taxationTypeId));
			if(empty($taxationSubheads)):
				continue;
			endif;
			$taxationSubHeadId = (string)$taxationSubheads[0]['id'];
			$taxationCode = isset($taxationRow['class']) ? trim((string)$taxationRow['class']) : '';
			$taxationName = isset($taxationRow['taxation']) ? trim((string)$taxationRow['taxation']) : '';
			$taxationLabel = ($taxationCode !== '') ? ($taxationName.' ('.$taxationCode.')') : $taxationName;
			$reportMode = (strtoupper($taxationCode) === 'CA-TDS') ? 'all' : 'standard';
			$taxationOptions[] = array(
				'sub_head' => $taxationSubHeadId,
				'taxation' => $taxationName,
				'code' => $taxationCode,
				'label' => $taxationLabel,
				'report_mode' => $reportMode,
			);
			if($defaultSubHead === ''):
				$defaultSubHead = $taxationSubHeadId;
			endif;
		endforeach;
		if($this->getRequest()->isPost()):
			$form = $this->getRequest()->getPost();
			$start_date = $form['start_date'];
			$end_date = $form['end_date'];
            $subhead = $form['sub_head'];
		else:
			$subhead = $defaultSubHead;
			$start_date = date('Y-m-d');
			$end_date  = date('Y-m-d');
		endif;
		$selectedTaxationLabel = '';
		$selectedTaxationMode = 'standard';
		$taxationSubheadMap = array();
		foreach($taxationOptions as $taxationOption):
			$taxationSubheadMap[$taxationOption['sub_head']] = $taxationOption;
		endforeach;
		$isTaxationSelection = isset($taxationSubheadMap[(string)$subhead]);
		if(!$isTaxationSelection && $defaultSubHead !== '' && isset($taxationSubheadMap[$defaultSubHead])):
			$subhead = $defaultSubHead;
			$isTaxationSelection = true;
		endif;
		if($isTaxationSelection):
			$selectedTaxationLabel = $taxationSubheadMap[(string)$subhead]['label'];
			$selectedTaxationMode = $taxationSubheadMap[(string)$subhead]['report_mode'];
		endif;
		$data = array(
			'start_date' => $start_date,
			'end_date' => $end_date,
			'subhead' =>$subhead,
			'taxation_mode' => $selectedTaxationMode,
		);
		//echo '<pre>';print_r($data);exit;
		$ViewModel =  new ViewModel(array(
            'sub_head' => $subhead,
			'taxationOptions' => $taxationOptions,
			'isTaxationSelection' => $isTaxationSelection,
			'selectedTaxationLabel' => $selectedTaxationLabel,
			'selectedTaxationMode' => $selectedTaxationMode,
			'headObj'    => $this->getDefinedTable(Accounts\HeadTable::class),
			'partyObj'   => $this->getDefinedTable(Accounts\PartyTable::class),
			'subheadObj' => $this->getDefinedTable(Accounts\SubheadTable::class),
			'data'       => $data,
			'transactiondetailObj' => $this->getDefinedTable(Accounts\TransactiondetailTable::class),
			'transactionObj' => $this->getDefinedTable(Accounts\TransactionTable::class),
            'locationObj' => $this->getDefinedTable(Administration\LocationTable::class),
			'userID'     => $this->_author,
			'partyroleObj'=>$this->getDefinedTable(Accounts\PartyroleTable::class),
		));
		return $ViewModel; 
	}

	private function getTaxationTypeIdForReport()
	{
		$typeRows = $this->getDefinedTable(Accounts\TypeTable::class)->get(array('type' => 'Taxation'));
		if(!empty($typeRows) && isset($typeRows[0]['id'])):
			return (int)$typeRows[0]['id'];
		endif;
		return 0;
	}

	private function getNetProfitForPeriod($activity, $region, $location, $startDate, $endDate, $includeComprehensiveInNet = false)
	{
		$bucketTotals = array(
			'income' => 0.0,
			'expense' => 0.0,
			'tax' => 0.0,
			'comprehensive' => 0.0,
		);
		$profitLossClasses = $this->getDefinedTable(Accounts\ClassTable::class)->getProfitlossClass($activity, $region, $location, $startDate, $endDate);
		foreach($profitLossClasses as $classRow):
			$classId = isset($classRow['id']) ? $classRow['id'] : 0;
			if($classId > 0):
				$classAmount = (float)$this->getDefinedTable(Accounts\TransactiondetailTable::class)->getClosingBalanceforPresPLSCLASS(
					$activity,
					$region,
					$location,
					$startDate,
					$endDate,
					$classId,
					$classId,
					4
				);
				$bucket = $this->resolveProfitLossClassBucket($classRow);
				if($bucket !== null):
					$bucketTotals[$bucket] += $classAmount;
				endif;
			endif;
		endforeach;
		$netProfit = $bucketTotals['income'] - $bucketTotals['expense'] - $bucketTotals['tax'];
		if($includeComprehensiveInNet):
			$netProfit += $bucketTotals['comprehensive'];
		endif;
		return $netProfit;
	}

	private function resolveProfitLossClassBucket($classRow)
	{
		$classId = isset($classRow['id']) ? (int)$classRow['id'] : 0;
		if($classId === 3):
			return 'income';
		elseif($classId === 4):
			return 'expense';
		elseif($classId === 9):
			return 'tax';
		elseif($classId === 5):
			return 'comprehensive';
		endif;

		$className = strtolower(trim((string)($classRow['class'] ?? '')));
		if($className === ''):
			return null;
		endif;
		if(strpos($className, 'income') !== false && strpos($className, 'tax') === false):
			return 'income';
		elseif(strpos($className, 'expense') !== false):
			return 'expense';
		elseif(strpos($className, 'tax') !== false):
			return 'tax';
		elseif(strpos($className, 'comprehensive') !== false || strpos($className, 'oci') !== false):
			return 'comprehensive';
		endif;
		return null;
	}

	private function includeComprehensiveInNet()
	{
		$appSettings = $this->getDefinedTable(Administration\AppSettingTable::class)->getSettings();
		return !empty($appSettings['include_oci_in_net']);
	}

	private function resolveReportScope($region, $location, $activity = -1)
	{
		$region = (int)$region;
		$location = (int)$location;
		$activity = (int)$activity;
		$permissionObj = $this->PermissionPlugin();
		$regions = $permissionObj->getregion();
		$allowedRegionIds = array();
		foreach($regions as $regionRow):
			if(isset($regionRow['id'])):
				$allowedRegionIds[] = (int)$regionRow['id'];
			endif;
		endforeach;
		$allowedRegionIds = array_values(array_unique($allowedRegionIds));
		if($region > 0 && !in_array($region, $allowedRegionIds, true)):
			$region = -1;
		endif;
		if($region === -1 && sizeof($allowedRegionIds) === 1):
			$region = (int)$allowedRegionIds[0];
		endif;

		$locations = $permissionObj->getlocation(($region > 0) ? $region : 0);
		$allowedLocationIds = array();
		foreach($locations as $locationRow):
			if(isset($locationRow['id'])):
				$allowedLocationIds[] = (int)$locationRow['id'];
			endif;
		endforeach;
		$allowedLocationIds = array_values(array_unique($allowedLocationIds));
		if($location > 0 && !in_array($location, $allowedLocationIds, true)):
			$location = -1;
		endif;
		if($location === -1 && sizeof($allowedLocationIds) === 1):
			$location = (int)$allowedLocationIds[0];
		endif;

		if($location > 0):
			$activity = $location;
		elseif($activity > 0):
			$activity = -1;
		endif;

		return array(
			'activity' => $activity,
			'region' => $region,
			'location' => $location,
			'regions' => $regions,
			'locations' => $locations,
			'allow_all_region' => (sizeof($allowedRegionIds) > 1),
			'allow_all_location' => (sizeof($allowedLocationIds) > 1),
		);
	}

	private function getRetainedEarningsValue($activity, $region, $location, $startDate, $endDate)
	{
		$periodSnapshot = $this->getDefinedTable(Accounts\PeriodsnapshotTable::class)->getByPeriod($endDate, $region, $location);
		if(!empty($periodSnapshot) && isset($periodSnapshot['retained_earnings'])):
			return (float)$periodSnapshot['retained_earnings'];
		endif;
		$openingRetained = 0.0;
		$latestSnapshot = $this->getDefinedTable(Accounts\PeriodsnapshotTable::class)->getLatestBefore($startDate, $region, $location);
		if(!empty($latestSnapshot) && isset($latestSnapshot['retained_earnings'])):
			$openingRetained = (float)$latestSnapshot['retained_earnings'];
		endif;
		$periodNetProfit = $this->getNetProfitForPeriod(
			$activity,
			$region,
			$location,
			$startDate,
			$endDate,
			$this->includeComprehensiveInNet()
		);
		return $openingRetained + $periodNetProfit;
	}

	private function getCurrentRoleIds()
	{
		$roles = array();
		foreach (explode(',', (string)$this->_login_role) as $role) {
			$role = trim($role);
			if (ctype_digit($role) && (int)$role > 0) {
				$roles[] = (int)$role;
			}
		}
		if (empty($roles) && ctype_digit((string)$this->_login_role) && (int)$this->_login_role > 0) {
			$roles[] = (int)$this->_login_role;
		}
		return array_values(array_unique($roles));
	}

	private function getIdentityIdList($field)
	{
		$rawValue = '';
		if (in_array($field, array('admin_location', 'admin_activity'), true)) {
			$rawValue = (string)$this->getDefinedTable(Administration\UsersTable::class)->getColumn((int)$this->_login_id, $field);
		}
		if ($rawValue === '') {
			$identity = $this->identity();
			if ($identity && isset($identity->{$field})) {
				$rawValue = (string)$identity->{$field};
			}
		}

		$ids = array();
		foreach (explode(',', $rawValue) as $value) {
			$value = trim($value);
			if (ctype_digit($value) && (int)$value > 0) {
				$ids[] = (int)$value;
			}
		}
		return array_values(array_unique($ids));
	}

	private function getMonthlyCloseProcessId()
	{
		$processes = $this->getDefinedTable(Acl\ProcessTable::class)->getAll();
		foreach($processes as $process):
			$processId = isset($process['id']) ? (int)$process['id'] : 0;
			if($processId <= 0):
				continue;
			endif;
			$name = strtolower(trim((string)($process['process'] ?? '')));
			$description = strtolower(trim((string)($process['description'] ?? '')));
			$tableName = strtolower(trim((string)($process['table_name'] ?? '')));
			if(
				$tableName === 'fa_period_snapshot'
				|| strpos($name, 'monthly close') !== false
				|| strpos($description, 'monthly close') !== false
				|| strpos($description, 'period snapshot') !== false
			):
				return $processId;
			endif;
		endforeach;
		return 0;
	}

	private function getMonthlyCloseAccess($region, $location)
	{
		$processId = $this->getMonthlyCloseProcessId();
		if($processId <= 0):
			return array(
				'allowed' => false,
				'reason' => 'Monthly close process is not configured. Please create a Functional Process for monthly close and assign role access.',
			);
		endif;

		$currentRoles = $this->getCurrentRoleIds();
		if(empty($currentRoles)):
			return array(
				'allowed' => false,
				'reason' => 'No active role found for monthly close authorization.',
			);
		endif;

		$rows = $this->getDefinedTable(Acl\RoleprocessTable::class)->get(array(
			'process' => $processId,
			'status' => 1,
		));
		$applicableRows = array();
		foreach($rows as $row):
			$rowRole = isset($row['role']) ? (int)$row['role'] : 0;
			if(in_array($rowRole, $currentRoles, true)):
				$applicableRows[] = $row;
			endif;
		endforeach;
		if(empty($applicableRows)):
			return array(
				'allowed' => false,
				'reason' => 'Your role is not mapped to the monthly close functional process.',
			);
		endif;

		$requiresLocation = false;
		$requiresActivity = false;
		foreach($applicableRows as $row):
			if(isset($row['location']) && (int)$row['location'] === 1):
				$requiresLocation = true;
			endif;
			if(isset($row['activity']) && (int)$row['activity'] === 1):
				$requiresActivity = true;
			endif;
		endforeach;

		$location = (int)$location;
		$region = (int)$region;
		if(($requiresLocation || $requiresActivity) && $location <= 0):
			return array(
				'allowed' => false,
				'reason' => 'Monthly close for your role requires a specific location scope.',
			);
		endif;

		if($location > 0):
			$allowedLocations = $this->getIdentityIdList('admin_location');
			if(!empty($allowedLocations) && !in_array($location, $allowedLocations, true)):
				return array(
					'allowed' => false,
					'reason' => 'Selected location is outside your permitted scope.',
				);
			endif;
		endif;

		if($region > 0):
			$allowedRegionIds = array();
			foreach($this->PermissionPlugin()->getregion() as $regionRow):
				if(isset($regionRow['id'])):
					$allowedRegionIds[] = (int)$regionRow['id'];
				endif;
			endforeach;
			$allowedRegionIds = array_values(array_unique($allowedRegionIds));
			if(!empty($allowedRegionIds) && !in_array($region, $allowedRegionIds, true)):
				return array(
					'allowed' => false,
					'reason' => 'Selected region is outside your permitted scope.',
				);
			endif;
		endif;

		return array(
			'allowed' => true,
			'reason' => '',
		);
	}

	private function hasPrivilegedMonthlyCloseRole()
	{
		$access = $this->getMonthlyCloseAccess(-1, -1);
		return !empty($access['allowed']);
	}

	private function runMonthlyCloseSnapshot($periodStart, $periodEnd, $region, $location, $forceReclose = 0, $closeNote = '')
	{
		$activity = ($location > 0) ? $location : -1;
		$existingSnapshot = $this->getDefinedTable(Accounts\PeriodsnapshotTable::class)->getByPeriod($periodEnd, $region, $location);
		$closeAccess = $this->getMonthlyCloseAccess($region, $location);
		$canRunClose = !empty($closeAccess['allowed']);
		$canForceReclose = ($canRunClose && $this->hasPrivilegedMonthlyCloseRole());
		$accessReason = isset($closeAccess['reason']) ? (string)$closeAccess['reason'] : '';

		if(!$canRunClose):
			$this->flashMessenger()->addMessage('Failed^ '.($accessReason !== '' ? $accessReason : 'You do not have permission to run monthly close.'));
			return;
		endif;

		if(!empty($existingSnapshot) && (int)$forceReclose !== 1):
			$closedBy = isset($existingSnapshot['author']) ? (int)$existingSnapshot['author'] : 0;
			$closedOn = '';
			if(!empty($existingSnapshot['modified'])):
				$closedOn = (string)$existingSnapshot['modified'];
			elseif(!empty($existingSnapshot['created'])):
				$closedOn = (string)$existingSnapshot['created'];
			endif;
			$lockMessage = 'This period and scope is already closed.';
			if($closedBy > 0):
				$lockMessage .= ' Closed by user ID '.$closedBy;
			endif;
			if($closedOn !== ''):
				$lockMessage .= ' on '.$closedOn;
			endif;
			$lockMessage .= '. Use Force Re-close (authorized role) to recompute.';
			$this->flashMessenger()->addMessage('Failed^ '.$lockMessage);
			return;
		endif;
		if((int)$forceReclose === 1 && !$canForceReclose):
			$this->flashMessenger()->addMessage('Failed^ You do not have permission to force re-close.');
			return;
		endif;
		if((int)$forceReclose === 1 && trim((string)$closeNote) === ''):
			$this->flashMessenger()->addMessage('Failed^ Force re-close requires a reason note.');
			return;
		endif;

		$connection = $this->_container->get('Laminas\Db\Adapter\Adapter')->getDriver()->getConnection();
		$transactionStarted = false;
		try{
			try{
				$connection->beginTransaction();
				$transactionStarted = true;
			}catch(\Throwable $transactionException){
				$transactionStarted = false;
				error_log('[ReportController::runMonthlyCloseSnapshot] beginTransaction unavailable, continuing without explicit transaction: '.$transactionException->getMessage());
			}
			$openingRetained = 0.0;
			$latestSnapshot = $this->getDefinedTable(Accounts\PeriodsnapshotTable::class)->getLatestBefore($periodStart, $region, $location);
			if(!empty($latestSnapshot) && isset($latestSnapshot['retained_earnings'])):
				$openingRetained = (float)$latestSnapshot['retained_earnings'];
			endif;
			$periodNetProfit = $this->getNetProfitForPeriod(
				$activity,
				$region,
				$location,
				$periodStart,
				$periodEnd,
				$this->includeComprehensiveInNet()
			);
			$retainedEarnings = $openingRetained + $periodNetProfit;
			$snapshotData = array(
				'period_end' => $periodEnd,
				'start_date' => $periodStart,
				'region' => $region,
				'location' => $location,
				'net_profit' => $periodNetProfit,
				'retained_earnings' => $retainedEarnings,
				'author' => $this->_author,
				'modified' => $this->_modified,
			);
			if(!empty($existingSnapshot) && isset($existingSnapshot['id'])):
				$snapshotData['id'] = $existingSnapshot['id'];
			else:
				$snapshotData['created'] = $this->_created;
			endif;
			$result = $this->getDefinedTable(Accounts\PeriodsnapshotTable::class)->save($snapshotData);
			if($result > 0):
				$runNo = $this->getDefinedTable(Accounts\PeriodsnapshotlogTable::class)->getMaxRunNo($periodEnd, $region, $location) + 1;
				$logData = array(
					'period_end' => $periodEnd,
					'start_date' => $periodStart,
					'region' => $region,
					'location' => $location,
					'action' => (!empty($existingSnapshot) ? 'RECLOSE' : 'CLOSE'),
					'run_no' => $runNo,
					'force_reclose' => (int)$forceReclose,
					'note' => $closeNote,
					'net_profit' => $periodNetProfit,
					'retained_earnings' => $retainedEarnings,
					'author' => $this->_author,
					'created' => $this->_created,
				);
				$this->getDefinedTable(Accounts\PeriodsnapshotlogTable::class)->save($logData);
				if($transactionStarted):
					try{
						$connection->commit();
					}catch(\Throwable $commitException){
						if(stripos($commitException->getMessage(), 'no active transaction') === false):
							throw $commitException;
						endif;
						error_log('[ReportController::runMonthlyCloseSnapshot] commit skipped: '.$commitException->getMessage());
					}
					$transactionStarted = false;
				endif;
				$this->flashMessenger()->addMessage('success^ Monthly close snapshot saved successfully.');
			else:
				if($transactionStarted):
					try{
						$connection->rollback();
					}catch(\Throwable $rollbackException){
						error_log('[ReportController::runMonthlyCloseSnapshot] rollback failed in save-failure path: '.$rollbackException->getMessage());
					}
					$transactionStarted = false;
				endif;
				$this->flashMessenger()->addMessage('Failed^ Failed to save monthly close snapshot.');
			endif;
		}catch(\Throwable $e){
			if($transactionStarted):
				try{
					$connection->rollback();
				}catch(\Throwable $rollbackException){
					error_log('[ReportController::runMonthlyCloseSnapshot] rollback failed: '.$rollbackException->getMessage());
				}
			endif;
			error_log('[ReportController::runMonthlyCloseSnapshot] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
			$this->flashMessenger()->addMessage('Failed^ Monthly close failed: '.$e->getMessage());
			return;
		}
	}
}
