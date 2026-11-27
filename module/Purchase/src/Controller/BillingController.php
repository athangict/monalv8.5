<?php
namespace Purchase\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\Authentication\AuthenticationService;
use Interop\Container\ContainerInterface;
use Asset\Model As Asset;
use Accounts\Model As Accounts;
use Acl\Model As Acl;
use Administration\Model As Administration;
use Purchase\Model As Purchase;
use Stock\Model As Stock;
use Laminas\EventManager\EventManagerInterface;
class BillingController extends AbstractActionController
{   
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
		
		$this->_id = $this->params()->fromRoute('id');
		
	    $this->_created = date('Y-m-d H:i:s');
		$this->_modified = date('Y-m-d H:i:s');
		$this->_safedataObj = $this->safedata();
		$this->_connection = $this->_container->get('Laminas\Db\Adapter\Adapter')->getDriver()->getConnection();


	}
	protected function hasTableColumn($table, $column)
	{
		$table = trim((string)$table);
		$column = trim((string)$column);
		if($table === '' || $column === ''):
			return false;
		endif;
		$adapter = $this->_container->get('Laminas\Db\Adapter\Adapter');
		$result = $adapter->query(
			"SHOW COLUMNS FROM `".$table."` LIKE '".$column."'",
			$adapter::QUERY_MODE_EXECUTE
		)->toArray();
		return (sizeof($result) > 0);
	}
	protected function getGstRateSubheadColumn()
	{
		if($this->hasTableColumn('st_gst_rate', 'gst_subhead')):
			return 'gst_subhead';
		endif;
		if($this->hasTableColumn('st_gst_rate', 'subhead')):
			return 'subhead';
		endif;
		return null;
	}
	protected function getBillGstSubheadDetails($purchaseReceiptId)
	{
		$gstSubheadColumn = $this->getGstRateSubheadColumn();
		if($gstSubheadColumn === null):
			return array('head' => 0, 'sub_head' => 0);
		endif;
		$gstRateTable = $this->getDefinedTable(Stock\GstRateTable::class);
		$itemTable = $this->getDefinedTable(Stock\ItemTable::class);
		$subheadTable = $this->getDefinedTable(Accounts\SubheadTable::class);
		$itemGstRateCache = array();
		$prDetails = $this->getDefinedTable(Purchase\PRDetailsTable::class)->get(array('purchase_receipt' => $purchaseReceiptId));
		foreach($prDetails as $detail):
			$itemId = isset($detail['item']) ? (int)$detail['item'] : 0;
			if($itemId <= 0):
				continue;
			endif;
			if(!isset($itemGstRateCache[$itemId])):
				$itemGstRate = 0;
				$itemRows = $itemTable->get(array('i.id' => $itemId));
				foreach($itemRows as $itemRow):
					if(isset($itemRow['gst']) && is_numeric($itemRow['gst'])):
						$itemGstRate = (float)$itemRow['gst'];
					elseif(isset($itemRow['bst']) && is_numeric($itemRow['bst'])):
						$itemGstRate = (float)$itemRow['bst'];
					endif;
					break;
				endforeach;
				$itemGstRateCache[$itemId] = $itemGstRate;
			endif;
			$itemGstRate = $itemGstRateCache[$itemId];
			if($itemGstRate <= 0):
				continue;
			endif;
			$rateCandidates = array_unique(array(
				(string)$itemGstRate,
				(string)((int)round($itemGstRate)),
				number_format($itemGstRate, 2, '.', ''),
			));
			foreach($rateCandidates as $rateCandidate):
				$gstRates = $gstRateTable->get(array('rate' => $rateCandidate));
				foreach($gstRates as $gstRateRow):
					$gstSubheadId = isset($gstRateRow[$gstSubheadColumn]) ? (int)$gstRateRow[$gstSubheadColumn] : 0;
					if($gstSubheadId <= 0):
						continue;
					endif;
					$gstHeadId = (int)$subheadTable->getColumn($gstSubheadId, 'head');
					if($gstHeadId > 0):
						return array('head' => $gstHeadId, 'sub_head' => $gstSubheadId);
					endif;
				endforeach;
			endforeach;
		endforeach;
		return array('head' => 0, 'sub_head' => 0);
	}
	
	/**
	 * index Action
	 */
	public function indexAction()
	{
		$this->init();
		$year = '';
		$month = '';
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
		}
		$month = ($month == 0)? date('m'):$month;
		$year = ($year == 0)? date('Y'):$year;
		
		$data = array(
				'year' => $year,
				'month' => $month,
		);
		$payments = $this->getDefinedTable(Purchase\PaymentTable::class)->getDateWise('billing_date',$year,$month);
		return new ViewModel(array(
                'title'     => 'Payment',
				'payments'   => $payments,
				'minYear' => $this->getDefinedTable(Purchase\PaymentTable::class)->getMin('billing_date'),
				'data'    => $data,
				'partyObj'    => $this->getDefinedTable(Accounts\PartyTable::class),
				'userID'          => $this->_author,
		));
	}
	
	/**
	 * addpayment Action
	 */
	public function addbillAction()
	{
		$this->init();
		$admin_locs = $this->getDefinedTable(Administration\UsersTable::class)->getColumn($this->_author,'admin_location');
		$admin_loc_array = explode(',',$admin_locs);
		$source_locs = $this->getDefinedTable(Administration\UsersTable::class)->getColumn($this->_author,'location');
		$source_loc_array = array_values(array_filter(array_map('trim', explode(',', (string) $source_locs))));
		$default_source_loc = isset($source_loc_array[0]) ? $source_loc_array[0] : '';
		if($this->getRequest()->isPost()){
			$form = $this->getRequest()->getPost();
				$billItemName = isset($form['bill_item']) ? trim((string)$form['bill_item']) : '';
				$location_prefix = $this->getDefinedTable(Administration\LocationTable::class)->getcolumn($form['location'],'prefix');
				$date = date('ym',strtotime($form['billing_date']));
				$machine_no = '0';
				$date = date('ym',strtotime($form['billing_date']));
				$tmp_PONo = $location_prefix."BL".$machine_no.$date;
				$results = $this->getDefinedTable(Purchase\PaymentTable::class)->getMonthlyPayment($tmp_PONo);	   
				if(sizeof($results) < 1 ):
				    $next_serial = "0001";
			    else:
				$sheet_no_list = array();
				foreach($results as $result):
						array_push($sheet_no_list, substr($result['bill_no'], -3));
				endforeach;
				$next_serial = max($sheet_no_list) + 1;
			    endif;
			  
			  switch(strlen($next_serial)){
				case 1: $next_py_serial = "000".$next_serial; break;
				case 2: $next_py_serial = "00".$next_serial;  break;
				case 3: $next_py_serial = "0".$next_serial;   break;
				default: $next_py_serial = $next_serial;      break;
			  }		  	
		   	$payment_no = $tmp_PONo.$next_py_serial;
			
		   	$payment = array(
			   		'bill_no'	=> $payment_no,
			   		'billing_date'	=> $form['billing_date'],
			   		'location'		=> $form['location'],
					'prn_no'		=> $form['prn_no'],
			   		'supplier'	    => $form['supplier'],	
		   			'net_amount'    => str_replace( ",", "",$form['net']),	
					'total'    		=> str_replace( ",", "",$form['sup_total']),	
					'supplier_gst'    		=> $form['sup_gst'],
					'tot_amount'    		=> $form['tot_amount'],
					'grand_total'	=> $form['grand_tot'],	
					'total_gst'	=> $form['tot_gst'],	
			   		'discount'		=> $form['discount'],
		   			'receipt_amt'	=>str_replace( ",", "",$form['receipt_amt']) ,
					'ref_no'        =>$form['ref_no'],
			   		'note'			=> $form['note'],
		   			'status'		=> 2,
		   			'author'		=> $this->_author,
		   			'created'		=> $this->_created,
		   			'modified'		=> $this->_modified,
		   	);
			if($this->hasTableColumn('pur_payment', 'item_name')):
				$payment['item_name'] = $billItemName;
			endif;
			if($this->hasTableColumn('pur_payment', 'item')):
				$payment['item'] = ($billItemName !== '') ? 0 : null;
			endif;
		   	$payment = $this->_safedataObj->rteSafe($payment);
			$this->_connection->beginTransaction();
		   	$result = $this->getDefinedTable(Purchase\PaymentTable::class)->save($payment);		   	
		   	 
		   if($result>0):
				$party=$form['sub_head'];
				$amount=$form['amount'];
				$gst_amount=$form['gst_amount'];
				$ptotal=$form['ptotal'];
				$pref_no=$form['pref_no'];
				$remark=$form['remark'];
				
					for($i=0;$i<sizeof($party);$i++){
						if(isset($party[$i]) && is_numeric($party[$i])){
						$paymentd = array(
						'payment'	=> $result,
						'party'	=> $party[$i],
						'amount'		=> $amount[$i],
						'gst_amount'		=> $gst_amount[$i],
						'total'	    => $ptotal[$i],	
						'ref_no'    => $pref_no[$i],	
						'remarks'    		=> $remark[$i],	
						);
						$paymentd = $this->_safedataObj->rteSafe($paymentd);
						$resultd = $this->getDefinedTable(Purchase\PaymentDtlTable::class)->save($paymentd);	
					}
					}
				
					$this->_connection->commit();
				$this->flashMessenger()->addMessage("success^ New Bill successfully added");
				else:
				$this->_connection->rollback();
					$this->flashMessenger()->addMessage("error^ Failed to add new Bill");
			endif;
			return $this->redirect()->toRoute('billing',array('action' => 'viewbilling','id'=>$result));	
		}
		$source_locs	= $this->getDefinedTable(Administration\UsersTable::class)->getColumn($this->_author,'location'); 
		return new ViewModel(array(
				'title'			 => 'Add Bill',
				'regions'     	 => $this->getDefinedTable(Administration\RegionTable::class)->getAll(),
				'locationObj' 	 => $this->getDefinedTable(Administration\LocationTable::class),
				'source_locs'		=> $source_locs,
				'default_source_loc' => $default_source_loc,
				'admin_location' => $admin_loc_array,
				'partyObj'  	    	=> $this->getDefinedTable(Accounts\PartyTable::class),
				'userID'          => $this->_author,
				'userTable'      => $this->getDefinedTable(Administration\UsersTable::class),
				'parties'      	=> $this->getDefinedTable(Accounts\PartyTable::class)->getAll(),
				'purreceipt' => $this->getDefinedTable(Purchase\PurchaseReceiptTable::class)->getpr(array('r.location'=>$default_source_loc,'r.status'=>4)),
		));
	}
	/**
	 * Edit Bill Action                                                                                                                                                      
	 */
	public function editbillAction()
	{
		$this->init();
		$admin_locs = $this->getDefinedTable(Administration\UsersTable::class)->getColumn($this->_author,'admin_location');
		$admin_loc_array = explode(',',$admin_locs);
		if($this->getRequest()->isPost()):
			$form = $this->getRequest()->getPost(); 
			$billItemName = isset($form['bill_item']) ? trim((string)$form['bill_item']) : '';
			$payment = array(
					'id'			  => $this->_id,				
			   		'billing_date'	=> $form['billing_date'],
			   		'location'		=> $form['location'],
					'prn_no'		=> $form['prn_no'],
			   		'supplier'	    => $form['supplier'],	
		   			'net_amount'    => str_replace( ",", "",$form['net']),	
					'total'    		=> str_replace( ",", "",$form['sup_total']),	
					'supplier_gst'    		=> $form['sup_gst'],
					'tot_amount'    		=> $form['tot_amount'],
					'grand_total'	=> $form['grand_tot'],	
					'total_gst'	=> $form['tot_gst'],	
			   		'discount'		=> $form['discount'],
		   			'receipt_amt'	=>str_replace( ",", "",$form['receipt_amt']) ,
					'ref_no'        =>$form['ref_no'],
			   		'note'			=> $form['note'],
		   			'status'		=> 2,
		   			'author'		=> $this->_author,
		   			'created'		=> $this->_created,
		   			'modified'		=> $this->_modified,
			);		
			if($this->hasTableColumn('pur_payment', 'item_name')):
				$payment['item_name'] = $billItemName;
			endif;
			if($this->hasTableColumn('pur_payment', 'item')):
				$payment['item'] = ($billItemName !== '') ? 0 : null;
			endif;
			$payment = $this->_safedataObj->rteSafe($payment);
			$this->_connection->beginTransaction();
			$result = $this->getDefinedTable(Purchase\PaymentTable::class)->save($payment);
			if($result > 0):
				$id=$form['id'];
				
				//print_r($id);
				$party=$form['sub_head'];
				$amount=$form['amount'];
				$gst_amount=$form['gst_amount'];
				$ptotal=$form['ptotal'];
				$pref_no=$form['pref_no'];
				$remark=$form['remark'];
				if(sizeof($id)>0){
					for($i=0;$i<sizeof($id);$i++){
						if(isset($party[$i]) && is_numeric($party[$i])){
						$paymentd = array(
							'id'=>$id[$i],
							'payment'	=> $result,
							'party'	=> $party[$i],
							'amount'		=> $amount[$i],
							'gst_amount'		=> $gst_amount[$i],
							'total'	    => $ptotal[$i],	
							'ref_no'    => $pref_no[$i],	
							'remarks'    		=> $remark[$i],	
							);
						$paymentd = $this->_safedataObj->rteSafe($paymentd);
						$resultd = $this->getDefinedTable(Purchase\PaymentDtlTable::class)->save($paymentd);	
					}
				}
				if(sizeof($id)<sizeof($party)){
					for($i=sizeof($id);$i<sizeof($party);$i++){
						if(isset($party[$i]) && is_numeric($party[$i])){
						$paymentd = array(
							'payment'	=> $result,
							'party'	=> $party[$i],
							'amount'		=> $amount[$i],
							'gst_amount'		=> $gst_amount[$i],
							'total'	    => $ptotal[$i],	
							'ref_no'    => $pref_no[$i],	
							'remarks'    		=> $remark[$i],	
							);
						$paymentd = $this->_safedataObj->rteSafe($paymentd);
						$resultd = $this->getDefinedTable(Purchase\PaymentDtlTable::class)->save($paymentd);	
						}
						}
					}
				}
				else{
					for($i=0;$i<sizeof($party);$i++){
						if(isset($party[$i]) && is_numeric($party[$i])){
						$paymentd = array(
							'payment'	=> $result,
							'party'	=> $party[$i],
							'amount'		=> $amount[$i],
							'gst_amount'		=> $gst_amount[$i],
							'total'	    => $ptotal[$i],	
							'ref_no'    => $pref_no[$i],	
							'remarks'    		=> $remark[$i],	
							);
						$paymentd = $this->_safedataObj->rteSafe($paymentd);
						$resultd = $this->getDefinedTable(Purchase\PaymentDtlTable::class)->save($paymentd);	
					}
				}
				}
				
					$this->_connection->commit();
				$this->flashMessenger()->addMessage("success^ New Bill successfully added");
				else:
				$this->_connection->rollback();
					$this->flashMessenger()->addMessage("error^ Failed to add new Bill");
			endif;
		return $this->redirect()->toRoute('billing',array('action' => 'viewbilling','id'=>$result));	
		endif;
		$source_locs	= $this->getDefinedTable(Administration\UsersTable::class)->getColumn($this->_author,'location');
		return new ViewModel(array(
				'title'		   		=> 'Edit Bill Details',
				'bills'				=> $this->getDefinedTable(Purchase\PaymentTable::class)->get($this->_id),
				'paymentd'				=> $this->getDefinedTable(Purchase\PaymentDtlTable::class)->get(array('payment'=>$this->_id)),
				'regions'     	    => $this->getDefinedTable(Administration\RegionTable::class)->getAll(),
				'recipt'     	    => $this->getDefinedTable(Purchase\PurchaseReceiptTable::class)->getAll(),
				'locationObj' 	    => $this->getDefinedTable(Administration\LocationTable::class),
				'source_locs'		=> $this->getDefinedTable(Administration\UsersTable::class)->getColumn($this->_author,'location'),
				'suppliers'  	    => $this->getDefinedTable(Accounts\PartyTable::class)->get(array('p.role'=>1)),
				'parties'      	=> $this->getDefinedTable(Accounts\PartyTable::class)->getAll(),
				'payment_types'   	=> $this->getDefinedTable(Purchase\PaymentTypeTable::class)->getAll(),
				'bankaccounts'      => $this->getDefinedTable(Accounts\BankaccountTable::class)->getAll(),
				'shead'      	=> $this->getDefinedTable(Accounts\SubheadTable::class)->get(array('head'=>[36])),
				'purreceipt' => $this->getDefinedTable(Purchase\PurchaseReceiptTable::class)->getpr(array('r.location'=>$source_locs,'r.status'=>4)),
				'admin_location' => $admin_loc_array,
				
		));
	}
	/**
	 * view individual supplier payment
	 */
	public function viewbillingAction()
	{
		$this->init();
		$params = explode("-", $this->_id);
		if (isset($params['1']) && $params['1'] == '1' && isset($params['2']) && $params['2'] > 0) {
			$flag = $this->getDefinedTable(Acl\NotifyTable::class)->getColumn($params['2'], 'flag'); 
				if($flag == "0") {
					$notify = array('id' => $params['2'], 'flag'=>'1');
					$this->getDefinedTable(Acl\NotifyTable::class)->save($notify); 	
				}			 	
		}
        $bill = $params['0'];
		return new ViewModel(array(
				'payment'	       => $this->getDefinedTable(Purchase\PaymentTable::class)->get($bill),
				'paymentd'	       => $this->getDefinedTable(Purchase\PaymentDtlTable::class)->get(array('payment'=>$bill)),
				'userTable'        	=> $this->getDefinedTable(Administration\UsersTable::class),
				'partyObj'	=> $this->getDefinedTable(Accounts\PartyTable::class),
				'subheadObj'	=> $this->getDefinedTable(Accounts\SubheadTable::class), 
				'prObj'	=> $this->getDefinedTable(Purchase\PurchaseReceiptTable::class),
                'locationObj' => $this->getDefinedTable(Administration\LocationTable::class),
				'userID'          => $this->_author,
				'userTable'      => $this->getDefinedTable(Administration\UsersTable::class),
				'user_locs'		=> $this->getDefinedTable(Administration\UsersTable::class)->getColumn($this->_author,'location'),
		));
	}
	/**
	 * Process Bill Action
	 *
	 */
	public function processbillAction()
	{
		$this->init();	
		if($this->getRequest()->isPost()):		
			$form = $this->getRequest()->getPost()->toArray();		
			$this->_connection->beginTransaction(); //***Transaction begins here***//
			/*Get users under destination location with sub role Depoy Manager*/
			$sourceLocation = $this->getDefinedTable(Purchase\PaymentTable::class)->getColumn($form['bill_id'], 'location');
			foreach($this->getDefinedTable(Purchase\PaymentTable::class)->get($form['bill_id']) as $bill);

			$loc = $this->getDefinedTable(Administration\LocationTable::class)->getcolumn($bill['location'], 'prefix');
			$prefix = $this->getDefinedTable(Accounts\JournalTable::class)->getcolumn(11,'prefix');
			$date = date('ym',strtotime($bill['billing_date']));
			$tmp_VCNo = $loc.'-'.$prefix.$date;
			
			$results = $this->getDefinedTable(Accounts\TransactionTable::class)->getSerial($tmp_VCNo);
			
			$pltp_no_list = array();
			foreach($results as $result):
				array_push($pltp_no_list, substr($result['voucher_no'], 13));
			endforeach;
			$next_serial = (sizeof($pltp_no_list) > 0) ? (max($pltp_no_list) + 1) : 1;
				
			switch(strlen($next_serial)){
				case 1: $next_dc_serial = "0000".$next_serial; break;
				case 2: $next_dc_serial = "000".$next_serial;  break;
				case 3: $next_dc_serial = "00".$next_serial;   break;
				case 4: $next_dc_serial = "0".$next_serial;    break;
				default: $next_dc_serial = $next_serial;       break;
				}	
		$voucher_no = $tmp_VCNo.$next_dc_serial;
		$region=$this->getDefinedTable(Administration\LocationTable::class)->getColumn($bill['location'],'region');
			if($form['action'] == "1")  
			    {    /* Send bill */
					$data = array(
						'id'			=> $form['bill_id'],
						'remarks'		=> $form['remarks'],
						'status' 		=> 3,
						'author'	    => $this->_author,
						'modified'      => $this->_modified,
				    );
					if(!empty($bill['transaction'])){
						$data1 = array(
							'id'				=> $bill['transaction'],
							'voucher_date' 		=> $bill['billing_date'],
							'voucher_type' 		=> 11,
							'region'   			=>$region,
							'doc_id'   			=>"billing",
							'against'			=>0,
							'voucher_no' 		=> $voucher_no,
							'voucher_amount' 	=> str_replace( ",", "",$bill['grand_total']),
							'status' 			=> 3, // status initiated 
							'remark'			=> $bill['note'],
							'author' 			=>$this->_author,
							'created' 			=>$this->_created,  
							'modified' 			=>$this->_modified,
						);
					}
					else{
						$data1 = array(
							'voucher_date' 		=> $bill['billing_date'],
							'voucher_type' 		=> 11,
							'region'   			=>$region,
							'doc_id'   			=>"billing",
							'voucher_no' 		=> $voucher_no,
							'against'			=>0,
							'voucher_amount' 	=> str_replace( ",", "",$bill['net_amount']),
							'status' 			=> 3, // status initiated 
							'remark'			=> $bill['note'],
							'author' 			=>$this->_author,
							'created' 			=>$this->_created,  
							'modified' 			=>$this->_modified,
						);	
					}
					$resultt = $this->getDefinedTable(Accounts\TransactionTable::class)->save($data1);
					
					if($resultt >0){
						$flowData=array(
							'process'			=> 9001,
							'flow' 				=> 2,
							'application' 		=> $resultt,
							'activity'			=>$bill['location'],
							'actor'   			=>5,
							'action' 			=> "3|4",
							'routing' 			=> 2,
							'status' 			=> 3, // status initiated 
							'routing_status'	=>2,
							'action_performed'	=>0,
							'description'		=>"expense",
							'author' 			=>$this->_author,
							'created' 			=>$this->_created,  
							'modified' 			=>$this->_modified,
						);
						$flowResult=$this->getDefinedTable(Administration\FlowTransactionTable::class)->save($flowData);
						$pr=$this->getDefinedTable(Purchase\PaymentTable::class)->getColumn($form['bill_id'],'prn_no');
						$po=$this->getDefinedTable(Purchase\PurchaseReceiptTable::class)->getColumn($pr,'purchase_order');
						foreach($this->getDefinedTable(Purchase\PODetailsTable::class)->get(array('purchase_order'=>$po)) as $pod);
						$activity = isset($bill['location']) ? $bill['location'] : 0;
						$tdetailsdata = array(
							'transaction' => $resultt,
							'voucher_dates' => $bill['billing_date'],
							'voucher_types' => 11,
							'location' => $bill['location'],
							'head' => $this->getDefinedTable(Accounts\SubheadTable::class)->getColumn($pod['subhead'],'head'),
							'sub_head' =>$pod['subhead'],
							'bank_ref_type' => '',
							'debit' =>$bill['tot_amount'],
							'credit' =>'0.00',
							'against' =>'0',
							'reconcile'=>'0',
							'ref_no'=> '', 
							'type' => '1',//user inputted  data  
							'status' => 3, // status appied
							'activity'=>$activity,
							'author' =>$this->_author,
							'created' =>$this->_created,
							'modified' =>$this->_modified,
						);
						$tdetailsdata = $this->_safedataObj->rteSafe($tdetailsdata);
						$result1 = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->save($tdetailsdata);
						/** 
						 * Transaction for GST amount if applicable
						 */
						if($bill['total_gst']>0){
							$gstSubheadDetails = $this->getBillGstSubheadDetails($bill['prn_no']);
							if($gstSubheadDetails['head'] <= 0 || $gstSubheadDetails['sub_head'] <= 0):
								$this->_connection->rollback();
								$this->flashMessenger()->addMessage("error^ Failed to process bill: GST subhead is not configured in GST rate table.");
								return $this->redirect()->toRoute('billing', array('action' => 'viewbilling', 'id' => $form['bill_id']));
							endif;
							$tdetailsdata = array(
							'transaction' => $resultt,
							'voucher_dates' => $bill['billing_date'],
							'voucher_types' => 11,
							'location' => $bill['location'],
							'head' => $gstSubheadDetails['head'],
							'sub_head' =>$gstSubheadDetails['sub_head'],
							'bank_ref_type' => '',
							'debit' =>$bill['total_gst'],
							'credit' =>'0.00',
							'against' =>'0',
							'reconcile'=>'0',
							'ref_no'=> '', 
							'type' => '1',//user inputted  data  
							'status' => 3, // status appied
							'activity'=>$activity,
							'author' =>$this->_author,
							'created' =>$this->_created,
							'modified' =>$this->_modified,
						);
						$tdetailsdata = $this->_safedataObj->rteSafe($tdetailsdata);
						$result1 = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->save($tdetailsdata);
						}
						$subhead = $this->getDefinedTable(Accounts\SubheadTable::class)->getColumn(array('ref_id'=>$bill['supplier'],'type'=>2),'id');
						$head = $this->getDefinedTable(Accounts\SubheadTable::class)->getColumn(array('ref_id'=>$bill['supplier'],'type'=>2),'head');
						$tdetailsdata = array(
							'transaction' => $resultt,
							'voucher_dates' => $bill['billing_date'],
							'voucher_types' => 11,
							'location' => $bill['location'],
							'head' =>$head,
							'sub_head' =>$subhead,
							'bank_ref_type' => '',
							'debit' =>'0.00',
							'against' =>'0',
							'credit' =>$bill['total'],
							'ref_no'=> $bill['ref_no'],
							'reconcile'=>'0', 
							'type' => '1',//user inputted  data
							'status' => 6, // status applied
							'activity'=>$activity,
							'author' =>$this->_author,
							'created' =>$this->_created,
							'modified' =>$this->_modified,
						);
						$tdetailsdata = $this->_safedataObj->rteSafe($tdetailsdata);
						$result1 = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->save($tdetailsdata);
					}
					//Transaction for freight charges
					$paymentd=$this->getDefinedTable(Purchase\PaymentDtlTable::class)->get(array('payment'=>$bill['id']));
					if(sizeof($paymentd)>0){
						foreach($paymentd as $paymentds){
					$partyRefId = isset($paymentds['party']) ? (int)$paymentds['party'] : 0;
					$partySubheadId = 0;
					$partyHeadId = 0;
					$linkedPartySubheads = $this->getDefinedTable(Accounts\SubheadTable::class)->get(array('sh.ref_id'=>$partyRefId,'sh.type'=>2));
					foreach($linkedPartySubheads as $linkedPartySubhead):
						$partySubheadId = isset($linkedPartySubhead['id']) ? (int)$linkedPartySubhead['id'] : 0;
						$partyHeadId = isset($linkedPartySubhead['head_id']) ? (int)$linkedPartySubhead['head_id'] : 0;
						break;
					endforeach;
					if($partySubheadId <= 0):
						$directSubheads = $this->getDefinedTable(Accounts\SubheadTable::class)->get($partyRefId);
						foreach($directSubheads as $directSubhead):
							$partySubheadId = isset($directSubhead['id']) ? (int)$directSubhead['id'] : 0;
							$partyHeadId = isset($directSubhead['head_id']) ? (int)$directSubhead['head_id'] : 0;
							break;
						endforeach;
					endif;
					if($partySubheadId <= 0 || $partyHeadId <= 0):
						$partyName = $this->getDefinedTable(Accounts\PartyTable::class)->getColumn($partyRefId, 'name');
						$this->_connection->rollback();
						$this->flashMessenger()->addMessage("error^ Failed to process bill: linked subhead not found for party ". $partyName);
						return $this->redirect()->toRoute('billing', array('action' => 'viewbilling', 'id' => $form['bill_id']));
					endif;
					$tdetailsdata = array(
						'transaction' => $resultt,
						'voucher_dates' => $bill['billing_date'],
						'voucher_types' => 11,
						'location' => $bill['location'],
						'head' =>$partyHeadId,
						'sub_head' => $partySubheadId,
						'bank_ref_type' => '',
						'debit' =>'0.00',
						'against' =>'0',
						'reconcile'=>'0',
						'credit' =>$paymentds['total'],
						'ref_no'=> $paymentds['ref_no'], 
						'type' => '1',//user inputted  data
						'status' => 6, // status applied
						'activity'=>$activity,
						'author' =>$this->_author,
						'created' =>$this->_created,
						'modified' =>$this->_modified,
					);
					$tdetailsdata = $this->_safedataObj->rteSafe($tdetailsdata);
					$result1 = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->save($tdetailsdata);
						}
					}
					$data2 = array(
						'id'			=> $form['bill_id'],
						'transaction'	=> $resultt,
					);
					$results = $this->getDefinedTable(Purchase\PaymentTable::class)->save($data2);
					$purchase_order_id = $this->getDefinedTable(Purchase\PurchaseReceiptTable::class)->getColumn($bill['prn_no'],'purchase_order');
					$tot_q = 0;
					$pur_receipt_details = $this->getDefinedTable(Purchase\PRDetailsTable::class)->get(array('purchase_receipt'=>$bill['prn_no']));
					foreach($pur_receipt_details as $prDetail):
						$tot_q += isset($prDetail['accept_qty']) ? (float)$prDetail['accept_qty'] : 0;
					endforeach;
					$tot_amt = 0;
					$paymentDetails = $this->getDefinedTable(Purchase\PaymentDtlTable::class)->get(array('payment'=>$bill['id']));
					foreach($paymentDetails as $paymentDetail):
						$tot_amt += isset($paymentDetail['amount']) ? (float)$paymentDetail['amount'] : 0;
					endforeach;
					$amt_perq = ($tot_q > 0) ? ($tot_amt / $tot_q) : 0;
					$podetails = null;
					foreach($this->getDefinedTable(Purchase\PODetailsTable::class)->get(array('purchase_order'=>$purchase_order_id)) as $podetailRow):
						$podetails = $podetailRow;
						break;
					endforeach;
					$location=$this->getDefinedTable(Purchase\PurchaseReceiptTable::class)->getColumn($bill['prn_no'],'location');
					foreach($this->getDefinedTable(Purchase\PurchaseReceiptTable::class)->get($bill['prn_no']) as $pr);
					if($podetails && isset($podetails['item_class']) && $podetails['item_class']==1){
						$asst = null;
						foreach($this->getDefinedTable(Asset\AssettypeTable::class)->get(array('subhead'=>$podetails['subhead'])) as $assetTypeRow):
							$asst = $assetTypeRow;
							break;
						endforeach;
						if(!$asst || !isset($asst['id'])):
							$asst = array('id' => 0);
						endif;

					$prefix = ($asst['id'] > 0) ? $this->getDefinedTable(Asset\AssettypeTable::class)->getcolumn($asst['id'],'code') : '';
					$prefix = ($prefix !== '') ? $prefix : 'AST';
						$year = date('Y',strtotime($bill['billing_date']));
						$tmp_VCNo = $year.'/'.$prefix;
					
						$results = $this->getDefinedTable(Asset\AssetmanagementTable::class)->getSerial($tmp_VCNo);
						
						$pltp_no_list = array();
						foreach($results as $result):
							array_push($pltp_no_list, substr($result['assetid'], -3));
						endforeach;
						$next_serial = (sizeof($pltp_no_list) > 0) ? (max($pltp_no_list) + 1) : 1;
							
						switch(strlen($next_serial)){
							case 1: $next_dc_serial = "000".$next_serial; break;
							case 2: $next_dc_serial = "00".$next_serial;  break;
							case 3: $next_dc_serial = "0".$next_serial;   break;
							default: $next_dc_serial = $next_serial;       break;
							}	
							$assetid = $tmp_VCNo.$next_dc_serial;
							foreach($pur_receipt_details as $row){
									for($i=0;$i<$row['accept_qty'];$i++){
										$asset=array(
										'name'					=> $row['item'],
										'code'					=> $row['item'],
										'purchase_date'				=> $pr['prn_date'],
										'asset_type'			=> $asst['id'],
										'assetid'				=> $assetid,
										'asset_value'			=> $row['rate']+$amt_perq,
										'region'				=> $this->getDefinedTable(Administration\LocationTable::class)->getColumn($location,'region'),
										'location'				=> $location,
										'status'				=> 1, 
										'author'	    		=> $this->_author,
										'created'      		=> $this->_created,
										'modified'      		=> $this->_modified,
						
									);
									$asset=$this->_safedataObj->rteSafe($asset);
									$asset = $this->getDefinedTable(Asset\AssetmanagementTable::class)->save($asset);
								}
								
							}
								
						}
					else{
						foreach($pur_receipt_details as $row){
							if($row['accept_qty']>0){
								$opening=$this->getDefinedTable(Stock\OpeningStockDtlsTable::class)->get(array('item'=>$row['item'],'location'=>$location));
								foreach($opening as $op);
								/*
									Calculate Weighted Moving Average value
								*/
									$tot_qty=$this->getDefinedTable(Stock\OpeningStockDtlsTable::class)->getSMSUM(array('item'=>$row['item']), 'quantity');
									$priceid=$this->getDefinedTable(Stock\PriceTable::class)->getMax('id',array('opening'=>$op['opening_stock']));
									foreach($this->getDefinedTable(Stock\PriceTable::class)->get($priceid)as $pd);
									$wma=(($tot_qty*$pd['weighted_price'])+($row['accept_qty']*($row['rate']+$amt_perq)))/($tot_qty+$row['accept_qty']);
									$count=$this->getDefinedTable(Stock\PriceTable::class)->getByDate($row['item'],$pr['prn_date']);
									if(in_array($podetails['item_class'],[2,3])){
										$selling_price=$wma;
									}
									else{
										$selling_price=$pd['selling_price'];
									}
									if(sizeof($count)>0){
										$cost_price=$pd['cost_price'];
									}
									else{
										$cost_price=$pd['weighted_price'];
									}
									
								//	print_r($selling_price);exit;
									$dataprice=array(
										'opening'			=> $op['opening_stock'],
										'item'				=> $row['item'],
										'uom'				=> $row['uom'],
										'selling_price'		=> $selling_price, 
										'cost_price'		=> $cost_price, 
										'weighted_price'	=> $wma,
										'date'				=> $pr['prn_date'],
										'author'	    => $this->_author,
									);
									//echo '<pre>';print_r($dataprice);exit;
									$dataprice=$this->_safedataObj->rteSafe($dataprice);
									$dataprice = $this->getDefinedTable(Stock\PriceTable::class)->save($dataprice);
									
										foreach($opening as $opening){
											$quantity=$opening['quantity']+$row['accept_qty'];
											$opening_data=array(
												'id'			=>$opening['id'],
												'quantity'		=>$quantity,
												'cost_price'	=>$wma,
												'selling_price'	=>$selling_price,
												'author'	    => $this->_author,
												'modified'      => $this->_modified,
								
											);
											//echo '<pre>';print_r($opening);exit;
											$opening_data=$this->_safedataObj->rteSafe($opening_data);
											$opening_data = $this->getDefinedTable(Stock\OpeningStockDtlsTable::class)->save($opening_data);
											$opening_stock=array(
												'id'			=>$opening['opening_stock'],
												//'quantity'		=>$quantity,
												'cost_price'	=>$wma,
												'selling_price'	=>$selling_price,
												//'author'	    => $this->_author,
												'modified'      => $this->_modified,
								
											);
											//echo '<pre>';print_r($opening);exit;
											$opening_stock=$this->_safedataObj->rteSafe($opening_stock);
											$opening_stock = $this->getDefinedTable(Stock\OpeningStockTable::class)->save($opening_stock);
									}
								}
							}
						}
					}
					$message = "Successfully Send Bill";
					$br = "New Bill send"; 
			//print_r($data);exit;	
			$result = $this->getDefinedTable(Purchase\PaymentTable::class)->save($data);	
			$bill_no = $this->getDefinedTable(Purchase\PaymentTable::class)->getColumn($form['bill_id'], 'bill_no');		
		
			if($result):
			    	$notification_data = array(
					    'route'         => 'transaction',
						'action'        => 'viewcredit',
						'key' 		    => $resultt,
						'description'   => $br,
						'author'	    => $this->_author,
						'created'       => $this->_created,
						'modified'      => $this->_modified,   
					);
					//print_r($notification_data);exit;
					$notificationResult = $this->getDefinedTable(Acl\NotificationTable::class)->save($notification_data);
					//echo $notificationResult; exit;
					if($notificationResult > 0 ){	
						$recipientRole = isset($flowData['actor']) ? (int)$flowData['actor'] : 0;
						$users = $this->getDefinedTable(Administration\UsersTable::class)->get(array('status' => 1));
						foreach($users as $row):
							$userRoles = array_values(array_filter(array_map('trim', explode(',', (string)$row['role']))));
							if(!in_array((string)$recipientRole, $userRoles, true)):
								continue;
							endif;
							if($recipientRole === 2 && (int)$row['id'] !== (int)$bill['author']):
								continue;
							endif;
							if($recipientRole === 3 && (int)$row['region'] !== (int)$region):
								continue;
							endif;
							$notify_data = array(
								'notification' => $notificationResult,
								'user'    	   => $row['id'],
								'flag'    	 => '0',
								'desc'    	 => $br,
								'author'	 => $this->_author,
								'created'    => $this->_created,
								'modified'   => $this->_modified,  
							);
							$notifyResult = $this->getDefinedTable(Acl\NotifyTable::class)->save($notify_data);
						endforeach;
					}
				$this->_connection->commit(); // commit transaction over success
				$this->flashMessenger()->addMessage("success^".$message);
			else:
			    $this->flashMessenger()->addMessage("error^ Cannot send good request");			  
			endif;
			return $this->redirect()->toRoute('billing',array('action'=>'viewbilling','id'=>$form['bill_id']));
		endif; 		
		$viewModel =  new ViewModel(array(
			'title' => 'Billing',
			'id'  => $this->_id,
			'userID' => $this->_author,
		));	
		$viewModel->setTerminal('false');
        return $viewModel;	
	}
	
	
	/**
	 * Action for getting Purchase Receipt No
	 */
	public function getprnAction()
	{
		$form = $this->getRequest()->getPost();
		$location =$form['loc'];
			$receipt = $this->getDefinedTable(Purchase\PurchaseReceiptTable::class)->getpr(array('r.location'=>$location,'r.status'=>4));
			$receiptopt ="<option value=''></option>";
			foreach($receipt as $receipt):
				$receiptopt .="<option value='".$receipt['id']."'>".$receipt['prn_no']."</option>";
			endforeach;
		echo json_encode(array(
				'prn' => $receiptopt,
		));
		exit;
	}
	/**
	 * Action for getting Purchase Receipt No
	 */
	public function getsupplierAction()
	{
		$form = $this->getRequest()->getPost();
		$prn_no = isset($form['prn_no']) ? (int) $form['prn_no'] : 0;
		$supplier = "<option value=''></option>";
		$partyOptions = "<option value=''></option>";
		$itemOptions = "<option value=''></option>";
		$sum = 0;
		$gstSum = 0;
		$parties = $this->getDefinedTable(Accounts\PartyTable::class)->getAll();
		foreach($parties as $partyRow):
			$partyOptions .= "<option value='" . $partyRow['id'] . "'>" . $partyRow['name'] . "</option>";
		endforeach;

		if($prn_no > 0):
			$pr = $this->getDefinedTable(Purchase\PurchaseReceiptTable::class)->get($prn_no);
			if(!empty($pr)):
				$selectedsup = $pr[0]['supplier'];
				$sum = $this->getDefinedTable(Purchase\PRDetailsTable::class)->getSum('rate','accept_qty',array('purchase_receipt'=>$prn_no));
				$itemGstCache = array();
				$itemNameMap = array();
				$prDetails = $this->getDefinedTable(Purchase\PRDetailsTable::class)->get(array('purchase_receipt'=>$prn_no));
				foreach($prDetails as $detail):
					$itemId = isset($detail['item']) ? (int) $detail['item'] : 0;
					$itemRawValue = isset($detail['item']) ? trim((string)$detail['item']) : '';
					$itemDisplayName = '';
					if($itemId === 0 && !empty($detail['item_name'])):
						$itemDisplayName = trim((string)$detail['item_name']);
					elseif($itemId < 0 && $itemRawValue != ''):
						$itemDisplayName = $itemRawValue;
					elseif($itemRawValue != '' && !is_numeric($itemRawValue)):
						$itemDisplayName = $itemRawValue;
					elseif($itemId <= 0 && !empty($detail['item_name'])):
						$itemDisplayName = trim((string)$detail['item_name']);
					endif;
					if($itemId <= 0):
						if($itemDisplayName != ''):
							$itemNameMap[$itemDisplayName] = $itemDisplayName;
						endif;
						continue;
					endif;
					if(!isset($itemGstCache[$itemId])):
						$itemGstRate = 0;
						$itemRows = $this->getDefinedTable(Stock\ItemTable::class)->get(array('i.id' => $itemId));
						foreach($itemRows as $itemRow):
							if(isset($itemRow['name']) && trim((string)$itemRow['name']) != ''):
								$itemDisplayName = trim((string)$itemRow['name']);
							endif;
							if(isset($itemRow['gst']) && is_numeric($itemRow['gst'])):
								$itemGstRate = (float) $itemRow['gst'];
							elseif(isset($itemRow['bst']) && is_numeric($itemRow['bst'])):
								$itemGstRate = (float) $itemRow['bst'];
							endif;
							break;
						endforeach;
						$itemGstCache[$itemId] = $itemGstRate;
					endif;
					if($itemDisplayName == '' && !empty($detail['item_name'])):
						$itemDisplayName = trim((string)$detail['item_name']);
					endif;
					if($itemDisplayName != ''):
						$itemNameMap[$itemDisplayName] = $itemDisplayName;
					endif;
					$lineRate = isset($detail['rate']) ? (float) str_replace(',', '', (string) $detail['rate']) : 0;
					$lineQty = isset($detail['accept_qty']) ? (float) str_replace(',', '', (string) $detail['accept_qty']) : 0;
					$lineTotal = $lineRate * $lineQty;
					$lineGstRate = $itemGstCache[$itemId];
					if($lineGstRate > 0 && $lineTotal > 0):
						$gstSum += ($lineTotal * $lineGstRate) / 100;
					endif;
				endforeach;
				$sup = $this->getDefinedTable(Accounts\PartyTable::class)->get($selectedsup);
				foreach ($sup as $sup):
					$isSelected = ($sup['id'] == $selectedsup) ? ' selected' : '';
					$supplier .= "<option value='" . $sup['id'] . "'" . $isSelected . ">" . $sup['name'] . "</option>";
				endforeach;
				foreach($itemNameMap as $itemName):
					$itemOptions .= "<option value='" . htmlspecialchars($itemName, ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($itemName, ENT_QUOTES, 'UTF-8') . "</option>";
				endforeach;
			endif;
		endif;

		echo json_encode(array(
			'supplier' => $supplier,
			'sum'=> $sum,
			'gst_sum' => round($gstSum, 2),
			'party_options' => $partyOptions,
			'item_options' => $itemOptions,
		));
		exit;
	}
	/**
	 * Action to delete  Bill
	 */
	public function deletebillAction()
	{
		$this->init();	
		$bill_no = $this->_id;
		
		$tranid = $this->getDefinedTable(Accounts\TransactionTable::class)->getColumn(array('remark'=>$bill_no,'doc_id'=>"billing"),'id');
		foreach($this->getDefinedTable(Accounts\TransactiondetailTable::class)->get(array('transaction'=>$tranid)) as $td):
			$reult1=$this->getDefinedTable(Accounts\TransactiondetailTable::class)->remove($td['id']);
		endforeach;
		$result2= $this->getDefinedTable(Accounts\TransactionTable::class)->remove($tranid);
		$result3=$this->getDefinedTable(Purchase\PaymentTable::class)->remove($bill_no);
		if($result3 > 0):

			$this->flashMessenger()->addMessage("success^ Bill deleted successfully");
		else:
			$this->_connection->rollback(); // rollback transaction over failure
			$this->flashMessenger()->addMessage("error^ Failed to delete Bill");
		endif;
		//end			
	
		return $this->redirect()->toRoute('billing',array('action' => 'index'));	
	}
}

