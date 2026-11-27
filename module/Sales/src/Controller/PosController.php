<?php
namespace Sales\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\Authentication\AuthenticationService;
use Interop\Container\ContainerInterface;
use Accounts\Model As Accounts;
use Acl\Model As Acl;
use Administration\Model As Administration;
use Stock\Model As Stock;
use Sales\Model As Sales;
use Hr\Model As Hr;
class PosController extends AbstractActionController
{   
	private const PARTY_ROLE_BY_SALE_MODE = array(
		'y' => array('value' => 1, 'label' => 'Credit'),
		'1' => array('value' => 2, 'label' => 'Advance'),
	);

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
    protected $_safedataObj; // safedata controller plugin
	protected $_userloc; //location of the current user
	protected $_connection; // DB transaction connection
	

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
		if(!isset($this->_config)) {
			$this->_config = $this->_container->get('Config');
		}
		if(!isset($this->_user)) {
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
		
	
		if(!isset($this->_userloc)){
			$this->_userloc = $this->_user->location;  
		}
		
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

	private function getSaleRow($saleId)
	{
		$rows = $this->getDefinedTable(Sales\SalesTable::class)->get($saleId);
		foreach ($rows as $row) {
			return $row;
		}
		return null;
	}

	private function canAccessSale($saleId)
	{
		$sale = $this->getSaleRow($saleId);
		if ($sale === null) {
			return false;
		}
		if ($this->isPrivilegedUser()) {
			return true;
		}
		return isset($sale['author']) && (int) $sale['author'] === (int) $this->_login_id;
	}

	private function getSalesDetailRow($detailId)
	{
		$rows = $this->getDefinedTable(Sales\SalesDetailsTable::class)->get($detailId);
		foreach ($rows as $row) {
			return $row;
		}
		return null;
	}
	
        //check if eos or not
	public function eoscheckdateAction()
	{
		$this->init();
		$form = $this->getRequest()->getPost();
		
		$dated = $form['date_val'];
		$location = $form['sales_loc'];
		
		$date_add = $this->getDefinedTable(Sales\SalesTable::class)->getMaxTranDate(array('location' => $location),'sales_date');
		$date_add = strtotime($date_add);
		$date_add = strtotime("+1 day", $date_add);
		$date_add = date('Y-m-d', $date_add);
		
		$check = $this->getDefinedTable(Sales\SalesTable::class)->getColumn(array('sales_date' => $dated,'location' => $location),'transaction');
		$date = ($check > 0)?$date_add:$dated;
		$booln = ($check > 0)?'1':'0';
		echo json_encode(array(
					'dated' => $date,
					'booln' => $booln,
			));
		exit;
	}
	/**
	 * Action for getting Accounts
	 */
	public function getptypeAction()
	{
		//$this->init();
		
		$this->init();
		
		$form = $this->getRequest()->getPost();
		$p_type = $form['pt_type'];
		    $customerObj = $this->getDefinedTable(Accounts\PartyTable::class);
			$cus_type_id = $customerObj->getColumn(array('id'=>$p_type,'role'=>8),'p_type');
			$custypeObj = $this->getDefinedTable(Accounts\PartyTypeTable::class);
			$ptypes = $custypeObj->get(array('id'=>$cus_type_id));
			$ptype.="<option value=''></option>";
			foreach($ptypes as $ptype):
				$ptype.="<option value='".$ptype['id']."'>".$ptype['code']."</option>";
			endforeach;
		echo json_encode(array(
				'fp_type' => $ptype,
		));
		exit;
	}
	/**
	 * index action of Sales
	 */
	public function indexAction()
	{
		$this->init();
		$admin_locs = $this->getDefinedTable(Administration\UsersTable::class)->getColumn($this->_author,'admin_location');
		$admin_loc_array = explode(',',$admin_locs);
		if($this->getRequest()->isPost())
		{
			$form = $this->getRequest()->getPost();
			
			$year = $form['year'];
			$month = $form['month'];
			if(strlen($month)==1){
				$month = '0'.$month;
			}
			$location = $form['location'];
		}else{
			$month = date('m');
			$year = date('Y');
			
			$location = $this->_userloc;
			$location = (in_array($location,$admin_loc_array))?$location:'-1'; 
		}
		$minYear = $this->getDefinedTable(Sales\SalesTable::class)->getMin('sales_date');
		$minYear = ($minYear == "")?date('Y-m-d'):$minYear;
		$minYear = date('Y', strtotime($minYear));
		$data = array(
				'year' => $year,
				'month' => $month,
				'minYear' => $minYear,
				'location' => $location,
		);
		$salesrecord = $this->getDefinedTable(Sales\SalesTable::class)->getDateWise('sales_date',$year,$month,$location);
		return new ViewModel(array(
				'title' 	  		=> 'Sales',
				'data'        		=> $data,
				'salesrecord' 		=> $salesrecord,
		        'customerObj' 		=> $this->getDefinedTable(Accounts\PartyTable::class),
				'locationObj' 		=> $this->getDefinedTable(Administration\LocationTable::class),
				'admin_location'	=> $admin_loc_array,
				'account'			=>$this->getDefinedTable(Accounts\BankaccountTable::class),
				'cash'				=>$this->getDefinedTable(Accounts\CashaccountTable::class),
				'admin_role' 		=> $this->getDefinedTable(Administration\UsersTable::class)->getColumn($this->_author,'role'),
				
		));
	}
	/**
	 * Add Sale action of Sales
	 */
	/**
	 * Add Sale action of Sales
	 */
	public function addsalesAction()
	{
		$this->init();
		$admin_locs = $this->getDefinedTable(Administration\UsersTable::class)->getColumn($this->_author,'admin_location');
		$admin_loc_array = explode(',',$admin_locs);
		$sales_no = $this->_id;
		$source_locs = $this->getDefinedTable(Administration\UsersTable::class)->getColumn($this->_author,'location');
		if($sales_no != "" || $sales_no != 0 ){
			$sales = $this->getDefinedTable(Sales\SalesTable::class)->get(array('sales_no'=>$sales_no));
			$sales_dtls = $this->getDefinedTable(Sales\SalesDetailsTable::class)->get(array('sales'=>$sales_no));
			$source_loc = $this->getDefinedTable(Sales\SalesTable::class)->getColumn(array('sales_no'=>$sales_no),'location');
		}
		if($this->getRequest()->isPost()):
    		$form = $this->getRequest()->getPost();
			$customer = $form['customer'] ?? 0;
			$partyRole = $this->getSalesPartyRole($form['credit']);
			if($partyRole !== null && !$this->isEligibleSalesCustomer($customer, $partyRole['value'])){
				$this->flashMessenger()->addMessage("error^ Please select a customer whose Party Role is set to ".$partyRole['label'].".");
				return $this->redirect()->toRoute('pos',array('action' => 'addsales'));
			}
			$source_loc = $form['location']; 
			$location_prefix = $this->getDefinedTable(Administration\LocationTable::class)->getcolumn($form['location'],'prefix');
			$machine_no = '0';
    		$date = date('ym',strtotime($form['sales_date']));
			$tmp_SLNo = $location_prefix."SL".$machine_no.$date;
						$results = $this->getDefinedTable(Sales\SalesTable::class)->getMonthlySL($tmp_SLNo);
					
						if(sizeof($results) < 1 ):
							$next_serial = "0001";
						else:
							$sheet_no_list = array();
							foreach($results as $result):
								array_push($sheet_no_list, substr($result['sales_no'], -3));
							endforeach;
							//print_r(max($sheet_no_list));exit;
							$next_serial = max($sheet_no_list) + 1;
						endif;
						switch(strlen($next_serial)){
							case 1: $next_sl_serial = "0000".$next_serial; break;
							case 2: $next_sl_serial = "000".$next_serial;  break;
							case 3: $next_sl_serial = "00".$next_serial;   break;
							case 4: $next_sl_serial = "0".$next_serial;   break;
							default: $next_sl_serial = $next_serial;      break;
						}            			
						$sales_no = $tmp_SLNo.$next_sl_serial;
						//print_r($sales_no);exit;
			if($form['credit']=="y"){
				$due_date=$form['due_date'];
			}
			else{
				$due_date='0000-00-00';
			}
			//$due_date = ($form['credit']=="y")?$form['due_date']:'0000-00-00';
			//$payment_type = ($form['credit']==0)?$form['payment_type']:'';
			$account_no = ($form['credit'] == "n" && ($form['payment_type'] == 1 || $form['payment_type'] == 2)) ? $form['account_no'] : 0;
			if($form['credit']=="n" && $form['payment_type']==0){
				$this->flashMessenger()->addMessage("error^ Failed to add new Sales. Please select Payment type or credit");
				return $this->redirect()->toRoute('pos',array('action' => 'addsales'));
			}
			if($form['payment_type']==2 && ($form['jrnl_no']=="" || $form['phone']==0)){
				$this->flashMessenger()->addMessage("error^ Failed to add new Sales. Please Enter Phone number or jrnl_no");
				return $this->redirect()->toRoute('pos',array('action' => 'addsales'));
			}
			$headerGstAmount = isset($form['total_gst']) ? (float)$form['total_gst'] : 0;
			$headerTotalAfterDiscount = isset($form['total_after_discount']) ? (float)$form['total_after_discount'] : 0;
			$headerNetAmount = isset($form['net_amount']) ? (float)$form['net_amount'] : ($headerTotalAfterDiscount + $headerGstAmount);
			$headerGstRate = ($headerTotalAfterDiscount > 0) ? (($headerGstAmount / $headerTotalAfterDiscount) * 100) : 0;
			$data = array(			
				'sales_no'=>$sales_no,		
				'sales_date' => $form['sales_date'],
				'location' => $form['location'],
				'credit'=>$form['credit'],
				'customer'=>$customer,
				'payment_type'=>$form['payment_type'],
				'discount'=>$form['discount'],
				'due_date'=>$due_date,
				'account_no'=>$account_no,
				'jrnl_no'=>$form['jrnl_no'],
				'phone'=>$form['phone'],
				'salesperson'=>$form['sales_person'],
				'ref_no'=>$form['ref_no'],
				'gst_rate'=>$headerGstRate,
				'gst_amount'=>$headerGstAmount,
				'payment_amount'	=>$headerNetAmount,
				'status'=>2,
				'author' =>$this->_author,
				'created' =>$this->_created,
				'modified' =>$this->_modified,
		);
		$data = $this->_safedataObj->rteSafe($data);
		$result = $this->getDefinedTable(Sales\SalesTable::class)->save($data);	
		if($result > 0):
			$item=$form['item'];
			$uom=$form['basic_uom'];
			$rate=$form['rate'];
			$quantity=$form['quantity'];
			$basic_qty=$form['stock_qty'];
			$amount=$form['amount'];
			$gst_amount = (isset($form['gst_amount']) && is_array($form['gst_amount'])) ? $form['gst_amount'] : array();
			for($i=0; $i < sizeof($item); $i++):
				if(isset($item[$i]) && is_numeric($item[$i])):
					$data1 = array(
						'sales' => $result,
						'item' => $item[$i],
						'uom' => $uom[$i],
						'rate' => $rate[$i],
						'quantity' => $quantity[$i],
						'basic_quantity' => $basic_qty[$i],
						'scheme_dtls'	=>1,
						'batch'=>1,
						'free_item'		=>0,
						'free_item_uom'	=>1,
						'discount_qty'	=>0,
						'gst_amount'	=> isset($gst_amount[$i]) ? $gst_amount[$i] : 0,
						'author' =>$this->_author,
						'created' =>$this->_created,
						'modified' =>$this->_modified,
					);
					$result1 = $this->getDefinedTable(Sales\SalesDetailsTable::class)->save($data1);
					if($result1 <= 0):
						break;
					endif;
				endif;
			endfor;
			$this->flashMessenger()->addMessage("success^ Sales entry added successfully");
		else:
			$this->flashMessenger()->addMessage("error^ Failed to add sales entry");
		endif;
		return $this->redirect()->toRoute('pos',array('action' => 'viewsales','id'=>$sales_no));			 
			
		endif;
		
		return new ViewModel( array(
			'title'         => 'Sales Entry',
			'locationObj'   => $this->getDefinedTable(Administration\LocationTable::class),
			'employees' 	=> $this->getDefinedTable(Administration\UsersTable::class)->get($this->_author),
			'admin_location' => $admin_loc_array,
			'source_locs'=>$source_locs,
			'group'			=> $this->getDefinedTable(Stock\OpeningStockTable::class),
			'itemgroups' => $this->getDefinedTable(Stock\ItemGroupTable::class)-> getAll(),
			'uomObj'	  => $this->getDefinedTable(Stock\UomTable::class),
			'accountObj' => $this->getDefinedTable(Accounts\BankaccountTable::class),
			'cashObj' => $this->getDefinedTable(Accounts\CashaccountTable::class),
		));
	}
		/**
	 * Edit Sale action of Sales
	 */
	public function editsalesAction()
	{
		$this->init();
		if (!$this->canAccessSale((int) $this->_id)) {
			$this->flashMessenger()->addMessage("error^ You are not allowed to edit this sales record");
			return $this->redirect()->toRoute('pos',array('action' => 'index'));
		}
		$employees='';
		$admin_locs = $this->getDefinedTable(Administration\UsersTable::class)->getColumn($this->_author,'admin_location');
		$admin_loc_array = explode(',',$admin_locs);
		$assigned_act = $this->getDefinedTable(Administration\UsersTable::class)->getColumn($this->_author,'name');//assigned_activity
		$assigned_act_array = explode(',',$assigned_act);
		$employees = $this->getDefinedTable(Administration\UsersTable::class)->get($this->_author);
		
		if($this->getRequest()->isPost()):
    		$form = $this->getRequest()->getPost();
			$partyRole = $this->getSalesPartyRole($form['credit']);
			$customer = $partyRole !== null ? ($form['customer'] ?? 0) : 0;
			if($partyRole !== null && !$this->isEligibleSalesCustomer($customer, $partyRole['value'])){
				$this->flashMessenger()->addMessage("error^ Please select a customer whose Party Role is set to ".$partyRole['label'].".");
				return $this->redirect()->toRoute('pos',array('action' => 'editsales','id'=>$this->_id));
			}
			$due_date = ($form['credit']=="y")?$form['due_date']:'0000-00-00';
			//$payment_type = ($form['credit']==0)?$form['payment_type']:'';
			$account_no = ($form['credit'] == "n" && ($form['payment_type'] == 1 || $form['payment_type'] == 2)) ? $form['account_no'] : 0;

			$quantity=$form['quantity'];
			$basic_qty=$form['stock_qty'];
			
			$ql=0;
			for($q=0;$q<sizeof($basic_qty);$q++):
				if($basic_qty[$q]-$quantity[$q]<0){
					$ql++;
				}
			endfor;
			if($ql>0){
				$this->flashMessenger()->addMessage("error^ Please Check You Quantity and Stock Quantity");
				return $this->redirect()->toRoute('pos',array('action' => 'editsales','id'=>$this->_id));
			}
			$headerGstAmount = isset($form['total_gst']) ? (float)$form['total_gst'] : 0;
			$headerTotalAfterDiscount = isset($form['total_after_discount']) ? (float)$form['total_after_discount'] : 0;
			$headerNetAmount = isset($form['net_amount']) ? (float)$form['net_amount'] : ($headerTotalAfterDiscount + $headerGstAmount);
			$headerGstRate = ($headerTotalAfterDiscount > 0) ? (($headerGstAmount / $headerTotalAfterDiscount) * 100) : 0;
		
			$data = array(	
				'id'			=>$this->_id,		
				'sales_no'		=>$form['sales_no'],		
				'sales_date' 	=>$form['sales_date'],
				'location' 		=>$form['location'],
				'credit'		=>$form['credit'],
				'customer'		=>$customer,
				'payment_type'	=>$form['payment_type'],
				'jrnl_no'		=>$form['jrnl_no'],
				'phone'			=>$form['phone'],
				'due_date'		=>$due_date,
				'account_no'	=>$account_no,
				'salesperson'	=>$form['sales_person'],
				'ref_no'		=>$form['ref_no'],
				'gst_rate'		=>$headerGstRate,
				'gst_amount'	=>$headerGstAmount,
				'payment_amount'	=>$headerNetAmount,
				'status'		=>2,
				'discount'		=>$form['discount'],
				'modified' 		=>$this->_modified,
		);
		$data = $this->_safedataObj->rteSafe($data);
		$result = $this->getDefinedTable(Sales\SalesTable::class)->save($data);	
		if($result > 0):
			$id = (isset($form['id']) && is_array($form['id'])) ? $form['id'] : array();
			$allowedDetailIds = array();
			foreach ($this->getDefinedTable(Sales\SalesDetailsTable::class)->get(array('sales' => $this->_id)) as $detailRow) {
				if (isset($detailRow['id']) && ctype_digit((string) $detailRow['id'])) {
					$allowedDetailIds[(int) $detailRow['id']] = true;
				}
			}
			$item=$form['item'];
			$uom=$form['basic_uom'];
			$rate=$form['rate'];
			$quantity=$form['quantity'];
			$basic_qty=$form['stock_qty'];
			$amount=$form['amount'];
			$gst_amount = (isset($form['gst_amount']) && is_array($form['gst_amount'])) ? $form['gst_amount'] : array();
			for($i=0; $i < sizeof($id); $i++):
				if(isset($item[$i]) && is_numeric($item[$i])):
					$detailId = (int) $id[$i];
					if($detailId > 0 && !isset($allowedDetailIds[$detailId])){
						$this->flashMessenger()->addMessage("error^ Invalid sales detail row selection");
						return $this->redirect()->toRoute('pos',array('action' => 'editsales','id'=>$this->_id));
					}
					$data1 = array(
						'id'				=> $detailId,
						'sales' 			=> $result,
						'item' 				=> $item[$i],
						'uom' 				=> $uom[$i],
						'rate' 				=> $rate[$i],
						'quantity' 			=> $quantity[$i],
						'basic_quantity' 	=> $basic_qty[$i],
						'scheme_dtls'		=>1,
						'batch'				=>1,
						'free_item'			=>0,
						'free_item_uom'		=>1,
						'discount_qty'		=>0,
						'gst_amount'		=> isset($gst_amount[$i]) ? $gst_amount[$i] : 0,
						'author' 			=>$this->_author,
						'created' 			=>$this->_created,
						'modified' 			=>$this->_modified,
					);
					$result1 = $this->getDefinedTable(Sales\SalesDetailsTable::class)->save($data1);
					if($result1 <= 0):
						break;
					endif;
				endif;
			endfor;
			if(sizeof($id)<sizeof($item)){
				for($i=sizeof($id); $i < sizeof($item); $i++):
						$data1 = array(
							'sales' 			=> $result,
							'item' 				=> $item[$i],
							'uom' 				=> $uom[$i],
							'rate' 				=> $rate[$i],
							'quantity' 			=> $quantity[$i],
							'basic_quantity' 	=> $basic_qty[$i],
							'scheme_dtls'		=>1,
							'batch'				=>1,
							'free_item'			=>0,
							'free_item_uom'		=>1,
							'discount_qty'		=>0,
							'gst_amount'		=> isset($gst_amount[$i]) ? $gst_amount[$i] : 0,
							'author' 			=>$this->_author,
							'created' 			=>$this->_created,
							'modified' 			=>$this->_modified,
						);
						$result1 = $this->getDefinedTable(Sales\SalesDetailsTable::class)->save($data1);
					endfor;
			}
			$this->flashMessenger()->addMessage("success^ Sales entry updated successfully");
		else:
			$this->flashMessenger()->addMessage("error^ Failed to update sales entry");
		endif;
		return $this->redirect()->toRoute('pos',array('action' => 'index'));			 
			
		endif;
		
		$sales = $this->getDefinedTable(Sales\SalesTable::class)->get($this->_id);
		$partyRole = $this->getSalesPartyRole($sales[0]['credit'] ?? null);
		return new ViewModel( array(
			'title'         => 'Edit Sales',
			'locationObj'   => $this->getDefinedTable(Administration\LocationTable::class),
			'itemObj' 		=> $this->getDefinedTable(Stock\ItemTable::class),
			'item' 			=> $this->getDefinedTable(Stock\ItemTable::class)->getAll(),
			'uomObj' 		=> $this->getDefinedTable(Stock\UomTable::class),
			'itemuomObj' 	=> $this->getDefinedTable(Stock\ItemUomTable::class),
			'customers'		=> $partyRole !== null
				? $this->getDefinedTable(Accounts\PartyTable::class)->get(array('r.credit' => $partyRole['value']))
				: array(),
			'customerRoleLabel' => $partyRole['label'] ?? null,
			'sales' 		=> $sales,
			'salesdtl' 		=> $this->getDefinedTable(Sales\SalesDetailsTable::class),
			'accounts' 		=> $this->getDefinedTable(Accounts\BankaccountTable::class)->get(array('ba.location'=>[$this->_userloc,1])),
			'cash' 			=> $this->getDefinedTable(Accounts\CashaccountTable::class)->get(array('ca.location'=>[$this->_userloc,1])),
			'employees' 	=> $employees,
			'admin_location' => $admin_loc_array,
			'assigned_act_array' => $assigned_act_array,
			'group'			=> $this->getDefinedTable(Stock\OpeningStockTable::class),
			'itemgroups' => $this->getDefinedTable(Stock\ItemGroupTable::class)-> getAll(),
			'itemgroupsObj' => $this->getDefinedTable(Stock\ItemGroupTable::class),
			'uomObj'	  => $this->getDefinedTable(Stock\UomTable::class),
			'gst' => $this->getDefinedTable(Stock\GstRateTable::class)-> getAll(),
		));
	}
	/**
	 * view sale action
	 */
	public function deleteAction()
	{
		$this->init(); 
		$salesd = $this->getSalesDetailRow((int) $this->_id);
		if (empty($salesd) || !$this->canAccessSale((int) $salesd['sales'])) {
			$this->flashMessenger()->addMessage("error^ Invalid sales detail selection");
			return $this->redirect()->toRoute('pos',array('action' => 'index'));
		}
		foreach($this->getDefinedTable(Sales\SalesTable::Class)->get($salesd['sales']) as $sales);
		$result = $this->getDefinedTable(Sales\SalesDetailsTable::Class)->remove($this->_id);
		if($result > 0):
				$this->flashMessenger()->addMessage("success^ Item deleted successfully");
			else:
				$this->_connection->rollback(); // rollback transaction over failure
				$this->flashMessenger()->addMessage("error^ Failed to delete item");
			endif;
			return $this->redirect()->toRoute('pos',array('action' => 'editsales','id'=>$sales['id']));	
	}
	/**
	 * delete sale details action
	 */
	 public function deletesalesdAction()
	{
		$this->init(); 
		$salesd = $this->getSalesDetailRow((int) $this->_id);
		if (empty($salesd) || !$this->canAccessSale((int) $salesd['sales'])) {
			$this->flashMessenger()->addMessage("error^ Invalid sales detail selection");
			return $this->redirect()->toRoute('pos',array('action' => 'index'));
		}
		foreach($this->getDefinedTable(Sales\SalesTable::Class)->get($salesd['sales']) as $sales);
		$result = $this->getDefinedTable(Sales\SalesDetailsTable::Class)->remove($this->_id);
		if($result > 0):
				$this->flashMessenger()->addMessage("success^ Item deleted successfully");
			else:
				$this->_connection->rollback(); // rollback transaction over failure
				$this->flashMessenger()->addMessage("error^ Failed to delete item");
			endif;
			return $this->redirect()->toRoute('pos',array('action' => 'viewsales','id'=>$sales['sales_no']));	
	}
	/**
	 * view sale action
	 */
	public function viewsalesAction()
	{
		$this->init();
		$locale = 'en_US'; // Change to the desired locale
		$fmt = new \NumberFormatter($locale, \NumberFormatter::SPELLOUT);
		$sales  = $this->getDefinedTable(Sales\SalesTable::class)->get(array('sales_no'=>$this->_id));
		foreach($sales as $sale);
	//	print_r($sale['id']);
		return new ViewModel(array(
				'title' 	  => 'View Sales',
				'wordformat'  =>$fmt,
				'sales' 	  => $this->getDefinedTable(Sales\SalesTable::class)->get(array('sales_no'=>$this->_id)),
				'saledetails' => $this->getDefinedTable(Sales\SalesDetailsTable::class)->get(array('sales'=>$sale['id'])),
				'itemObj' 	  => $this->getDefinedTable(Stock\ItemTable::class),
				'uomObj'	  => $this->getDefinedTable(Stock\UomTable::class),
				'batchObj'	  => $this->getDefinedTable(Stock\BatchTable::class),
		        'locationObj' => $this->getDefinedTable(Administration\LocationTable::class),
		        'customerObj' => $this->getDefinedTable(Accounts\PartyTable::class),
		        'userObj'   => $this->getDefinedTable(Administration\UsersTable::class),
				'userRoleObj'  => $this->getDefinedTable(Acl\RolesTable::class),
				'userID' => $this->_author,
				'employeeObj' => $this->getDefinedTable(Hr\EmployeeTable::class),
				'accountObj' => $this->getDefinedTable(Accounts\BankaccountTable::class),
				'cashObj' => $this->getDefinedTable(Accounts\CashaccountTable::class),
				'partyObj' => $this->getDefinedTable(Accounts\PartyTable::class),
		));
	}
	/**
	 * confirm Sale Action
	 */
	public function confirmAction()
	{
		$this->init();
		$sale_no=$this->_id;
		$sales = $this->getDefinedTable(Sales\SalesTable::class)->get(array('sales_no'=>$sale_no));
		$this->_connection->beginTransaction();
		if($this->getRequest()->isPost())
		{
			$form = $this->getRequest()->getPost();
			$salesen=$this->getDefinedTable(Sales\SalesDetailsTable::class)->get(array('sales'=>$form['sales']));
			foreach($this->getDefinedTable(Sales\SalesTable::class)->get($form['sales']) as $row);
			$computedTotalAmount = 0.000;
			$computedTotGstFromDetails = 0.000;
			$computedCostPrice = 0.000;
			$discountPercent = isset($row['discount']) ? (float)$row['discount'] : 0.000;
			if($discountPercent <= 0 && isset($form['discount'])){
				$discountPercent = (float)$form['discount'];
			}
			foreach($salesen as $saleLine):
				$lineRate = isset($saleLine['rate']) ? (float)$saleLine['rate'] : 0.000;
				$lineQty = isset($saleLine['quantity']) ? (float)$saleLine['quantity'] : 0.000;
				$lineDiscountQty = isset($saleLine['discount_qty']) ? (float)$saleLine['discount_qty'] : 0.000;
				$computedTotalAmount += (($lineRate * $lineQty) - $lineDiscountQty);
				$computedTotGstFromDetails += isset($saleLine['gst_amount']) ? (float)$saleLine['gst_amount'] : 0.000;
			endforeach;
			$headerGstAmount = isset($row['gst_amount']) ? (float)$row['gst_amount'] : 0.000;
			$computedTotGst = ($headerGstAmount > 0) ? $headerGstAmount : $computedTotGstFromDetails;
			$totalAfterDiscount = $computedTotalAmount - (($discountPercent > 0) ? ($computedTotalAmount * ($discountPercent/100)) : 0.000);
			$computedDiscountAmt = $computedTotalAmount - $totalAfterDiscount;
			$computedGrandTotal = $totalAfterDiscount + $computedTotGst;
			//print_r($salesen);exit 
			/**
			 * Generating voucher no
			 */
			$loc = $this->getDefinedTable(Administration\LocationTable::class)->getcolumn($form['location'], 'prefix');
			$prefix = $this->getDefinedTable(Accounts\JournalTable::class)->getcolumn(7,'prefix');
			$date = date('ym',strtotime($row['sales_date']));
				$tmp_VCNo = $loc.'-'.$prefix.$date;
				
				$results = $this->getDefinedTable(Accounts\TransactionTable::class)->getSerial($tmp_VCNo);
				
				$pltp_no_list = array();
				foreach($results as $result):
					array_push($pltp_no_list, substr($result['voucher_no'], -4));
				endforeach;
				if(empty($pltp_no_list)):
					$next_serial = 1;
				else:
					$next_serial = max($pltp_no_list) + 1;
				endif;
					
				switch(strlen($next_serial)){
					case 1: $next_dc_serial = "0000".$next_serial; break;
					case 2: $next_dc_serial = "000".$next_serial;  break;
					case 3: $next_dc_serial = "00".$next_serial;   break;
					case 4: $next_dc_serial = "0".$next_serial;    break;
					default: $next_dc_serial = $next_serial;       break;
				}	
				$voucher_no = $tmp_VCNo.$next_dc_serial;
			//	print_r($voucher_no);exit;
			/**
			 * Generating voucher no ended
			 */
			$region=$this->getDefinedTable(Administration\LocationTable::class)->getColumn($form['location'],'region');

			foreach($salesen as $sale):
				$openingdtls=$this->getDefinedTable(Stock\OpeningStockDtlsTable::class)->get(array('location'=>$form['location'],'opening_stock'=>$sale['batch']));
				$salestable=$this->getDefinedTable(Sales\SalesTable::class)->get($form['sales']);
				foreach($salestable as $salestables);
				foreach($openingdtls as $openingdtl);
						$quantity=$openingdtl['quantity']-$sale['quantity'];
						if($openingdtl['sales']==0||$openingdtl['sales']==""):
							$totalsale=$sale['quantity'];
						else:
							$totalsale=$sale['quantity']+$openingdtl['sales'];
						endif;
						$cost=$sale['quantity']*$openingdtl['cost_price'];
					$computedCostPrice += $cost;
					$cost_price=$cost+$salestables['cost_price'];

					$os=$this->getDefinedTable(Stock\OpeningStockTable::class)->get(array('id'=>$openingdtl['opening_stock']));
					foreach($os as $os);
					$squantity=$os['quantity']-$sale['quantity'];

				$data=array(
					'id'=>$form['sales'],
					'cost_price'=>$cost_price,
					'status'=>4,
					'modified' => $this->_modified,	
				);
				$result = $this->getDefinedTable(Sales\SalesTable::class)->save($data);	
				$data=array(
					'id'=>$openingdtl['id'],
					'sales'=>$totalsale,
					'quantity'=>$quantity,
					'author' => $this->_author,
					'created' => $this->_created,
					'modified' => $this->_modified,	
				);
				$result= $this->getDefinedTable(Stock\OpeningStockDtlsTable::class)->save($data);
				$data=array(
					'id'=>$os['id'],
					'quantity'=>$squantity,
					'author' => $this->_author,
					'created' => $this->_created,
					'modified' => $this->_modified,	
				);
				$result = $this->getDefinedTable(Stock\OpeningStockTable::class)->save($data);
			endforeach;
				foreach($this->getDefinedTable(Sales\SalesTable::class)->get(array('sales_no'=>$form['sales_no'])) as $sales);
				$salesTotalAmount = $computedTotalAmount;
				$salesTotGst = $computedTotGst;
				$salesDiscountAmt = ($discountPercent > 0 && $salesTotalAmount > 0)
					? ($salesTotalAmount * ($discountPercent/100))
					: $computedDiscountAmt;
				$salesGrandTotal = $computedGrandTotal;
				$salesCostPrice = ($computedCostPrice > 0) ? $computedCostPrice : (float)$sales['cost_price'];
				$salesTotalAfterDiscount = $salesTotalAmount - $salesDiscountAmt;
				$salesGstRate = ($salesTotalAfterDiscount > 0) ? (($salesTotGst / $salesTotalAfterDiscount) * 100) : 0;
				$updateSalesTotals = array(
					'id'			=> $form['sales'],
					'gst_amount'	=> $salesTotGst,
					'gst_rate'		=> $salesGstRate,
					'payment_amount'=> $salesGrandTotal,
					'cost_price'	=> $salesCostPrice,
					'modified'		=> $this->_modified,
				);
				$updateSalesTotals = $this->_safedataObj->rteSafe($updateSalesTotals);
				$this->getDefinedTable(Sales\SalesTable::class)->save($updateSalesTotals);
				$data1 = array(
					'voucher_date' => $form['sales_date'],
					'voucher_type' => 7,
					'region'   =>$region,
					'doc_id'   =>"sales",
					'voucher_no' => $voucher_no,
					'remark' => $form['sales_no'],
					'voucher_amount' => $salesTotalAmount + $salesCostPrice + $salesTotGst,
					'status' => 4, // status initiated 
					'author' =>$this->_author,
					'created' =>$this->_created,  
					'modified' =>$this->_modified,
				);
				$resultt = $this->getDefinedTable(Accounts\TransactionTable::class)->save($data1);
				foreach($this->getDefinedTable(Stock\ItemTable::class)->get(array('i.id'=>$sale['item'])) as $itemg);
					$subheadinventory=$this->getDefinedTable(Stock\ItemGroupTable::class)->getColumn($itemg['item_group'],'fn_inventory');
					$subheadincome=$this->getDefinedTable(Stock\ItemGroupTable::class)->getColumn($itemg['item_group'],'fn_income');
					$headincome=$this->getDefinedTable(Accounts\SubheadTable::class)->getColumn($subheadincome,'head');
					$subheadexpense =$this->getDefinedTable(Stock\ItemGroupTable::class)->getColumn($itemg['item_group'],'fn_expense');
					$headexpense =$this->getDefinedTable(Accounts\SubheadTable::class)->getColumn($subheadexpense,'head');
					$subheaddiscount =$this->getDefinedTable(Stock\ItemGroupTable::class)->getColumn($itemg['item_group'],'fn_discount');
					$headdiscount = $subheaddiscount ? $this->getDefinedTable(Accounts\SubheadTable::class)->getColumn($subheaddiscount,'head') : null;
					$subheadgst =$this->getDefinedTable(Stock\ItemGroupTable::class)->getColumn($itemg['item_group'],'fn_gst');
					$headgst = $subheadgst ? $this->getDefinedTable(Accounts\SubheadTable::class)->getColumn($subheadgst,'head') : null;
					//print_r($headexpense);

					if($salesDiscountAmt > 0.0001 && (empty($subheaddiscount) || empty($headdiscount))){
						$this->_connection->rollback();
						$this->flashMessenger()->addMessage("error^ Failed to confirm sale. Please configure Discount on Sales account mapping in Item Group.");
						return $this->redirect()->toRoute('pos',array('action' => 'viewsales','id'=>$form['sales_no']));
					}
					if($salesTotGst > 0 && (empty($subheadgst) || empty($headgst))){
						$this->_connection->rollback();
						$this->flashMessenger()->addMessage("error^ Failed to confirm sale. Please configure GST (Inbound) account mapping in Item Group.");
						return $this->redirect()->toRoute('pos',array('action' => 'viewsales','id'=>$form['sales_no']));
					}

					/* checks if sale entry has discounts or not*/
					if($salesDiscountAmt>0.0001){
						$tdetailsdata2 = array(
						'transaction' => $resultt,
						'voucher_dates' => $form['sales_date'],
						'voucher_types' => 7,
						'location' => $form['location'],
						'head' => $headdiscount,
						'sub_head' =>$subheaddiscount,
						'bank_ref_type' => '',
						'debit' =>$salesDiscountAmt,
						'credit' =>'0.000',
						'ref_no'=> '', 
						'activity'=>$sales['location'],
						'type' => '1',//user inputted  data
						'status' => 4, // status initiated
						'author' =>$this->_author,
						'created' =>$this->_created,
						'modified' =>$this->_modified,
						);
				$tdetailsdata2 = $this->_safedataObj->rteSafe($tdetailsdata2);
				$result2 = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->save($tdetailsdata2);
				}//end discount check

				$tdetailsdata = array(
					'transaction' => $resultt,
					'voucher_dates' => $form['sales_date'],
					'voucher_types' => 7,
					'location' => $form['location'],
					'head' =>$headincome,
					'sub_head' =>$subheadincome,
					'bank_ref_type' => '',
					'debit' =>'0.000',
					'credit' =>$salesTotalAmount,
					'ref_no'=> '', 
					'type' => '1',//user inputted  data
					'status' => 4, // status initiated
					'activity'=>$sales['location'],
					'author' =>$this->_author,
					'created' =>$this->_created,
					'modified' =>$this->_modified,
				);
				$tdetailsdata = $this->_safedataObj->rteSafe($tdetailsdata);
				$result1 = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->save($tdetailsdata);
				if($salesTotGst>0){
					$tdetailsdata3 = array(
					'transaction' => $resultt,
					'voucher_dates' => $form['sales_date'],
					'voucher_types' => 7,
					'location' => $form['location'],
					'head' =>$headgst,
					'sub_head' =>$subheadgst,
					'bank_ref_type' => '',
					'debit' =>'0.000',
					'credit' =>$salesTotGst,
					'ref_no'=> '', 
					'type' => '1',//user inputted  data
					'status' => 4, // status initiated
					'activity'=>$sales['location'],
					'author' =>$this->_author,
					'created' =>$this->_created,
					'modified' =>$this->_modified,
				);
				$tdetailsdata3 = $this->_safedataObj->rteSafe($tdetailsdata3);
				$result2 = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->save($tdetailsdata3);
				}
				
					foreach($this->getDefinedTable(Sales\SalesTable::class)->get(array('sales_no'=>$form['sales_no'])) as $sdd);
					$creditMode = isset($sdd['credit']) ? strtolower(trim((string)$sdd['credit'])) : '';
					$partyId = isset($sdd['customer']) ? (int)$sdd['customer'] : 0;
					$isCreditOrAdvance = in_array($creditMode, array('y', '1', 'yes', 'credit', 'advance'), true);
					$isPartyPosting = ($partyId > 0);
					if($isCreditOrAdvance && !$isPartyPosting){
						$this->_connection->rollback();
						$this->flashMessenger()->addMessage("error^ Failed to confirm sale. Credit/Advance sale requires a selected party.");
						return $this->redirect()->toRoute('pos',array('action' => 'viewsales','id'=>$form['sales_no']));
					}
					if($isPartyPosting){
						$ref = $partyId;
						$subhead = $this->getDefinedTable(Accounts\SubheadTable::class)->getColumn(array('ref_id'=>$ref,'type'=>2),'id');
						$head = $subhead ? $this->getDefinedTable(Accounts\SubheadTable::class)->getColumn($subhead,'head') : null;
						$ref_no = $salestables['ref_no'];
						if(empty($subhead) || empty($head)){
							$this->_connection->rollback();
							$this->flashMessenger()->addMessage("error^ Failed to confirm sale. Selected party does not have a mapped receivable subhead.");
							return $this->redirect()->toRoute('pos',array('action' => 'viewsales','id'=>$form['sales_no']));
						}
					}
					else{
						$ref_no="";
						$ref=$sdd['account_no'];
							if($sdd['payment_type']==1){
								$type=6;
							}
							else if($sdd['payment_type']==2){
								$type=3;
							}
						$subhead = $this->getDefinedTable(Accounts\SubheadTable::class)->getColumn(array('ref_id'=>$ref,'type'=>$type),'id');
						$head = $this->getDefinedTable(Accounts\SubheadTable::class)->getColumn(array('ref_id'=>$ref,'type'=>$type),'head');
						if(empty($subhead) || empty($head)){
							$this->_connection->rollback();
							$this->flashMessenger()->addMessage("error^ Failed to confirm sale. Selected cash/bank account does not have a mapped subhead.");
							return $this->redirect()->toRoute('pos',array('action' => 'viewsales','id'=>$form['sales_no']));
						}
					}
				$tdetailsdata1 = array(
					'transaction' => $resultt,
					'voucher_dates' => $form['sales_date'],
					'voucher_types' => 7,
					'location' => $form['location'],
					'head' => $head,
					'sub_head' => $subhead,
					'bank_ref_type' => '',
					'bank_trans_journal'=>$sales['jrnl_no'],
					'debit' =>$salesGrandTotal,
					'credit' => '0.00',
					'activity'=>$sales['location'],
					'ref_no'=> $ref_no, 
					'against'=>0,
					'type' => '1',//user inputted  data
					'status' => 4, // status initiated
					'author' =>$this->_author,
					'created' =>$this->_created,
					'modified' =>$this->_modified,
				);
				$tdetailsdata1 = $this->_safedataObj->rteSafe($tdetailsdata1);
				$result2 = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->save($tdetailsdata1);
					$tdetailsdata5 = array(
						'transaction' => $resultt,
						'voucher_dates' => $form['sales_date'],
						'voucher_types' => 7,
						'location' => $form['location'],
						'head' =>$headexpense,
						'sub_head' =>$subheadexpense,
						'bank_ref_type' => '',
						'debit' =>$salesCostPrice,
						'credit' =>'0.000',
						'ref_no'=> $ref, 
						'activity'=>$sales['location'],
						'type' => '1',//user inputted  data
						'status' => 4, // status initiated
						'author' =>$this->_author,
						'created' =>$this->_created,
						'modified' =>$this->_modified,
					);
					$tdetailsdata5 = $this->_safedataObj->rteSafe($tdetailsdata5);
				//	print_r($tdetailsdata5 );exit;
					$result2 = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->save($tdetailsdata5);
					$tdetailsdata2 = array(
						'transaction' => $resultt,
						'voucher_dates' => $form['sales_date'],
						'voucher_types' => 7,
						'location' => $form['location'],
						'head' =>$this->getDefinedTable(Accounts\SubheadTable::class)->getColumn($subheadinventory,'head'),
						'sub_head' =>$subheadinventory,
						'bank_ref_type' => '', 
						'debit' =>'0.000',
						'activity'=>$sales['location'],
						'credit' =>$salesCostPrice,
						'ref_no'=> $ref, 
						'type' => '1',//user inputted  data
						'status' => 4, // status initiated
						'author' =>$this->_author,
						'created' =>$this->_created,
						'modified' =>$this->_modified,
					);
					$tdetailsdata2 = $this->_safedataObj->rteSafe($tdetailsdata2);
					$result2 = $this->getDefinedTable(Accounts\TransactiondetailTable::class)->save($tdetailsdata2);
						/**Push the transaction id from Transaction Table */
				$data5 = array(
					'id' 	             => $form['sales'],
					'transaction'     => $resultt,
					
				);
				$data5 =  $this->_safedataObj->rteSafe($data5);
				$result5 = $this->getDefinedTable(Sales\SalesTable::class)->save($data5);
				
			if($result2>0):
			$this->_connection->commit();
			$this->flashMessenger()->addMessage("success^ Confirmed sale successfully ");
			else:
			$this->flashMessenger()->addMessage("Failed^ Failed to Confirm Sale");	
				$this->flashMessenger()->addMessage("error^ Failed to add new Item");
			endif;
			return $this->redirect()->toRoute('pos',array('action' => 'viewsales','id'=>$form['sales_no']));	
		}
		$ViewModel = new ViewModel(array( 
			'title' 	  => 'Confirm Sales',
				'sales'       =>$sales,
		        'stocks' => $this->getDefinedTable(Stock\OpeningStockTable::class),
				'locationObj' => $this->getDefinedTable(Administration\LocationTable::class),
	));

	$ViewModel->setTerminal(True);
	return $ViewModel;
	}
	/**
	 * recipt for the sales made
	 */
	public function salesreciptAction()
	{
	    $this->init();
        
	    $sales_no = $this->_id;
	 
		$ViewModel = new ViewModel(array(
				'title' => 'Sales Receipt',
				'sales' => $this->getDefinedTable(Sales\SalesTable::class)->get(array('sales_no'=> $sales_no)),
				'sales_details' => $this->getDefinedTable(Sales\SalesDetailsTable::class)->get(array('sales'=>$sales_no)),
				'locationObj' => $this->getDefinedTable(Administration\LocationTable::class),
				'itemObj' => $this->getDefinedTable(Stock\ItemTable::class),
		        'uomObj' => $this->getDefinedTable(Stock\UomTable::class),
				'customerObj' => $this->getDefinedTable(Accounts\PartyTable::class),
		));
		$ViewModel->setTerminal(True);
		return $ViewModel;
	}
	
	/**
	 * pos recipt for the sales made
	 */
	public function posreciptAction()
	{
	    $this->init();
        
	    $sales_no = $this->_id;
	 
		return new ViewModel(array(
				'title' => 'Sales Receipt',
				'sales' => $this->getDefinedTable(Sales\SalesTable::class)->get(array('sales_no'=> $sales_no)),
				'sales_details' => $this->getDefinedTable(Sales\SalesDetailsTable::class)->get(array('sales'=>$sales_no)),
				'locationObj' => $this->getDefinedTable(Administration\LocationTable::class),
				'itemObj' => $this->getDefinedTable(Stock\ItemTable::class),
		        'uomObj' => $this->getDefinedTable(Stock\UomTable::class),
		));
	}

	/**
	 * delete sale action
	 */
		public function deletesaleAction()
	{
		
		$this->init();
		if($this->getRequest()->isPost())
		{
		$sales_id = (int)$this->_id;
		foreach($this->getDefinedTable(Sales\SalesTable::Class)->get($this->_id) as $sales);
		foreach($this->getDefinedTable(Sales\SalesDetailsTable::Class)->get(array('sales'=>$this->_id)) as $salesd):
			foreach($this->getDefinedTable(Stock\OpeningStockDtlsTable::Class)->get(array('location'=>$sales['location'],'item'=>$salesd['item'])) as $opening);
			if($sales['status']==4){
				$data=array(
					'id'=>$opening['id'],
					'sales'=>$opening['sales']-$salesd['quantity'],
					'quantity'=>$opening['quantity']+$salesd['quantity'],
					'author' => $this->_author,
					'created' => $this->_created,
					'modified' => $this->_modified,	
				);
				//print_r($data);exit;
				$result = $this->getDefinedTable(Stock\OpeningStockDtlsTable::Class)->save($data);	
			}
			$result1= $this->getDefinedTable(Sales\SalesDetailsTable::Class)->remove($salesd['id']);
			endforeach;

			$transId = isset($sales['transaction']) ? (int)$sales['transaction'] : 0;

			if($transId > 0):
				$linkedSalesTransId = (int)$this->getDefinedTable(Accounts\TransactionTable::Class)->getColumn(
					array('id' => $transId, 'doc_id' => 'sales', 'remark' => $sales['sales_no']),
					'id'
				);
				if($linkedSalesTransId > 0):
					foreach($this->getDefinedTable(Accounts\TransactiondetailTable::Class)->getTransaction(array('transaction' => $linkedSalesTransId)) as $trand):
						$tranDetailId = (int)$trand['id'];
						if($tranDetailId > 0):
							$this->getDefinedTable(Accounts\TransactiondetailTable::Class)->remove($tranDetailId);
						endif;
					endforeach;
					$this->getDefinedTable(Accounts\TransactionTable::Class)->remove($linkedSalesTransId);
				endif;
			endif;

		$result4 = $this->getDefinedTable(Sales\SalesTable::Class)->remove($sales_id);
		if($result4 > 0):
				$this->flashMessenger()->addMessage("success^ sale deleted successfully");
			else:
				
				$this->flashMessenger()->addMessage("error^ Failed to delete sale");
			endif;
			//end			
		
			return $this->redirect()->toRoute('pos',array('action' => 'index'));	
		}
		$ViewModel = new ViewModel(array(
				'title' => 'Delete Sales',
				'salesdetls' => $this->getDefinedTable(Sales\SalesTable::Class)->get($this->_id),
			));	
			$ViewModel->setTerminal(True);
			 return $ViewModel;
	}
	/**
	 * cancel sales
	 */
	public function cancelsalesAction()
	{
		$this->init();
	
		$suspended_sales_no = $this->_id;
	
		$sales_id = $this->getDefinedTable(Sales\SalesTable::class)->getColumn(array('sales_no'=>$suspended_sales_no),'id');
		$sales_dtls = $this->getDefinedTable(Sales\SalesDetailsTable::class)->get(array('sales'=>$suspended_sales_no));
		
		foreach($sales_dtls as $sd):
			$this->getDefinedTable(Sales\SalesDetailsTable::class)->remove($sd['id']);
		endforeach;
		
		$result = $this->getDefinedTable(Sales\SalesTable::class)->remove($sales_id);
		if($result > 0):
			$this->flashMessenger()->addMessage("success^ Successfully deleted Sales No. :". $suspended_sales_no);
			return $this->redirect()->toRoute('pos', array('action' =>'draftsales'));
		else:
			$this->flashMessenger()->addMessage("error^ Unsuccessful to delete Sales No. :". $suspended_sales_no);
			return $this->redirect()->toRoute('pos', array('action' =>'draftsales'));
		endif;
	}
	
	/**
	 * getitem - Get item based on location
	 * **/
	public function getItemChangeAction()
	{
		//echo("Hello");
		$form = $this->getRequest()->getPost();
		$locationId = $form['locationId'];
		$subgroup = $form['item_subgroup'];

		$itemOptions = "<option value=''></option>";
		$allGroupItems = $this->getDefinedTable(Stock\ItemTable::class)->get(array('item_group'=>$subgroup));
		$itemMap = array();
		foreach($allGroupItems as $groupItem):
			if(isset($groupItem['id'])){
				$itemMap[(int)$groupItem['id']] = $groupItem;
			}
		endforeach;
		if($locationId != -1 && $locationId != ''){
			$stockItems = $this->getDefinedTable(Stock\OpeningStockDtlsTable::class)->getitems(array('osd.location'=>$locationId,'i.item_group'=>$subgroup));
			foreach($stockItems as $stockItem):
				if(isset($stockItem['id'])){
					$itemMap[(int)$stockItem['id']] = $stockItem;
				}
			endforeach;
		}
		foreach($itemMap as $item):
			$itemOptions .= "<option value='".$item['id']."'>".$item['name']."</option>";
		endforeach;

		echo json_encode(array(
			'items' => $itemOptions,
		));

		exit;
	}
	/**
	 * getrate - Get rate based on item and location
	 * **/
	public function getrateAction()
	{
		$form = $this->getRequest()->getPost();
		$item =$form['item'];
		$location=$form['location'];
		$rate = 0;
		$quantity = 0;
		$cost_price = 0;
		$gst_rate = 0;
		//console.log($location);
		$itemdetails = $this->getDefinedTable(Stock\OpeningStockDtlsTable::class)->get(array('item'=>$item,'location'=>$location));
		$itemRow = null;
		$itemRows = $this->getDefinedTable(Stock\ItemTable::class)->get(array('i.id' => $item));
		foreach($itemRows as $itemData):
			$itemRow = $itemData;
		endforeach;
		if($itemRow !== null){
			if(isset($itemRow['gst']) && is_numeric($itemRow['gst'])){
				$gst_rate = (float)$itemRow['gst'];
			}else if(isset($itemRow['bst']) && is_numeric($itemRow['bst'])){
				$gst_rate = (float)$itemRow['bst'];
			}
		}
		foreach($itemdetails as $items):
		$itemgroup=$this->getDefinedTable(Stock\ItemTable::class)->getColumn($items['item'],'item_group');
		if($itemgroup==67){
			$rate=$items['cost_price'];
		}
		else{
		$rate=$items['selling_price'];
		}
			$quantity=$items['quantity'];
			$cost_price=$items['cost_price'];
			
		endforeach;
		echo json_encode(array(
			'rate' => $rate,
			'quantity' => $quantity,
			'cost_price' => $cost_price,
			'gst_rate' => $gst_rate,
		));
		exit;
	}
	private function getSalesPartyRole($mode): ?array
	{
		return is_string($mode) || is_int($mode) ? (self::PARTY_ROLE_BY_SALE_MODE[$mode] ?? null) : null;
	}

	private function isEligibleSalesCustomer($customer, int $partyRole): bool
	{
		return is_string($customer) && ctype_digit($customer) && (int)$customer > 0
			&& !empty($this->getDefinedTable(Accounts\PartyTable::class)->get(array(
				'p.id' => (int)$customer,
				'r.credit' => $partyRole,
			)));
	}

	/**
	 * Get customers by configured Party Role for Add/Edit Sales, or by legacy filters for other screens.
	 * **/
	public function getcustomerAction()
	{
		$form = $this->getRequest()->getPost();
		$id = $form['id'];
		$partyRole = in_array($form['source'] ?? '', array('addsales', 'editsales'), true)
			? $this->getSalesPartyRole($id)
			: null;
		$customer_list = array();
		if($partyRole !== null){
			$customer_list = $this->getDefinedTable(Accounts\PartyTable::class)->get(array('r.credit' => $partyRole['value']));
		}
		else if($id=="y"){
			$customer_list = $this->getDefinedTable(Accounts\PartyTable::class)->get(array('p.role'=>[11,15,12],'p.location'=>[282,2]));
		}
		 else if($id=="1"){
			$customer_list = $this->getDefinedTable(Accounts\PartyTable::class)->get(array('p.role'=>[6,8,9,10]));
		}
		$placeholder = 'None';
		if($partyRole !== null){
			$placeholder = $customer_list ? 'Select Customer' : 'No customers have a Party Role set to '.$partyRole['label'];
		}
		$customer = "<option value='-1'>".$placeholder."</option>";
		foreach($customer_list as $customer_lists):
			$customer .= "<option value='".(int)$customer_lists['id']."'>".htmlspecialchars($customer_lists['name'], ENT_QUOTES, 'UTF-8')."</option>";
		endforeach;
		echo json_encode(array(
			'customer' => $customer,
		));
		exit;
	}
	/**
	 * getitem - Get item based on location
	 * **/
	public function getuomAction()
	{
		$form = $this->getRequest()->getPost();
		$itemId =$form['itemId'];
		$item = $this->getDefinedTable(Stock\ItemTable::class)->get(array('i.id'=>$itemId));
		$selectedUomId = $item[0]['uom'];
		$uom = $this->getDefinedTable(Stock\UomTable::class)->get(array('id' => $selectedUomId));
		foreach($item as $items);
		$uom = $this->getDefinedTable(Stock\UomTable::class)->get(array('id'=>$items['uom']));
		foreach($uom as $uoms):
			$buom=$uoms['code'];
		endforeach;
		echo json_encode(array(
				'uom' => $buom,
		));
		exit;
	}
	public function getamountAction()
	{
		$form = $this->getRequest()->getPost();
		$rate =$form['rate'];
		$quantity=$form['quantity'];
		$amount=$rate*$quantity;
		echo json_encode(array(
			'amount' => $amount,
		));
		exit;
	}
	/**
	 * getacount - Get item based on location
	 * **/
	public function getBCAccountAction()
	{
		$form = $this->getRequest()->getPost();
		$payment = (int) ($form['paymentId'] ?? 0);
		$locationID = (int) ($form['locationId'] ?? 0);
		$selectedAccount = isset($form['selectedAccount']) ? (string) $form['selectedAccount'] : '';
		$acc = "<option value='-1'>Select</option>";
		if ($payment == 1) {
			$cashTable = $this->getDefinedTable(Accounts\CashaccountTable::class);
			$accountlist = $locationID > 0 ? $cashTable->get(array('ca.location' => $locationID)) : $cashTable->getAll();
			if (empty($accountlist) && $locationID > 0) {
				$accountlist = $cashTable->getAll();
			}
			if (!empty($accountlist)) {
				$acc = "";
				foreach($accountlist as $index => $accountlists):
					$isSelected = ((string)$accountlists['id'] === $selectedAccount || ($selectedAccount === '' && $index === 0)) ? " selected" : "";
					$acc.="<option value='".$accountlists['id']."'".$isSelected.">".$accountlists['cash_account_name']."</option>";
				endforeach;
			} else {
				$acc = "<option value='-1'>No cash account configured</option>";
			}
		} elseif ($payment == 2) {
			$bankTable = $this->getDefinedTable(Accounts\BankaccountTable::class);
			$accountlist = $locationID > 0 ? $bankTable->get(array('ba.location' => $locationID)) : $bankTable->getAll();
			if (empty($accountlist) && $locationID > 0) {
				$accountlist = $bankTable->getAll();
			}
			if (!empty($accountlist)) {
				foreach($accountlist as $index => $accountlists):
					$isSelected = ((string)$accountlists['id'] === $selectedAccount || ($selectedAccount === '' && $index === 0)) ? " selected" : "";
					$acc.="<option value='".$accountlists['id']."'".$isSelected.">".$accountlists['code'].'-'.$accountlists['account']."</option>";
				endforeach;
			} else {
				$acc = "<option value='-1'>No bank account configured</option>";
			}
		}
		echo json_encode(array(
				'account' => $acc,
		));
		exit;
	}
	/**
	 * getacount - Get item based on location
	 * **/
	public function getUsersAction()
	{
		$form = $this->getRequest()->getPost();
		$location=$form['locationId'];
		$users= $this->getDefinedTable(Administration\UsersTable::class)->get(array('location'=>$location));	
		
		$userlist = "<option value='-1'>All</option>";
		foreach($users as $users):
			$userlist.="<option value='".$users['id']."'>".$users['name']."</option>";
		endforeach;
		echo json_encode(array(
				'users' => $userlist,
		));
		exit;
	}
	/**
	 * getitemsubgroup - Get item based on item class
	 * **/
	public function getItemgroupAction()
	{
		$form = $this->getRequest()->getPost();
		$itemclass=$form['item_class'];
		$itemgroup= $this->getDefinedTable(Stock\ItemGroupTable::class)->get(array('item_class'=>$itemclass));	
		
		$group = "<option value='-1'>All</option>";
		foreach($itemgroup as $itemgroup):
			$group.="<option value='".$itemgroup['id']."'>".$itemgroup['item_group']."</option>";
		endforeach;
		echo json_encode(array(
				'subgroup' => $group,
		));
		exit;
	}
	/*
	* Service Sales  Report 
	*/
	public function salesreportAction()
	{
		{	
			$this->init();
			$array_id = explode("_", (string) ($this->_id ?? ''));
			$user = (sizeof($array_id)>1)?$array_id[0]:'-1';
			$location = (sizeof($array_id)>1)?$array_id[1]:'-1';
			$item = (sizeof($array_id)>1)?$array_id[1]:'-1';
			$item_subgroup = (sizeof($array_id)>1)?$array_id[1]:'-1';
			$item_class = (sizeof($array_id)>1)?$array_id[1]:'-1';
			if($this->getRequest()->isPost())
			{
				$form      	 = $this->getRequest()->getPost();
				$location       = $form['location'];
				$item    = $form['item'];
				$item_subgroup=$form['item_subgroup'];
				$user     	= $form['user'];
				$start_date = $form['start_date'];
				$end_date   = $form['end_date'];
				$item_class   = $form['item_class'];
			}else{
				$location ='-1';
				$item = '-1';
				$user = '-1';
				$item_subgroup='-1';
				$start_date =date('Y-m-d');
				$end_date   = date('Y-m-d');
				$item_class   = '-1';
			}

			$data = array(
				'location'    => $location,
				'item'  => $item,
				'user'  => $user,
				'item_subgroup'=>$item_subgroup,
				'start_date' => $start_date,
				'end_date'   => $end_date,
				'item_class'  => $item_class,
			);
			$userloc = $this->getDefinedTable(Administration\UsersTable::class)->getColumn($this->_author,'location');
			$salesTable = $this->getDefinedTable(Sales\SalesDetailsTable::class)->getSalesReportByUsers($data,$start_date,$end_date);
			
			return new ViewModel(array(
				'title' => 'Sales Report',
				'paginator'       => $salesTable,
				'data'            => $data,
				'item' => $this->getDefinedTable(Stock\ItemTable::class),
				'items' => $this->getDefinedTable(Stock\ItemTable::class)->getAll(),
				'userObj' => $this->getDefinedTable(Administration\UsersTable::class),
				'users' => $this->getDefinedTable(Administration\UsersTable::class)->getUsers(),
				'userLoc'         => $this->getDefinedTable(Administration\UsersTable::class)->get($this->_author),
				'locations'       => $this->getDefinedTable(Administration\LocationTable::class)->getlocation($userloc),
				'locationObj'     => $this->getDefinedTable(Administration\LocationTable::class),
				'statusObj' => $this->getDefinedTable(Acl\StatusTable::class),
				'itemgroups' => $this->getDefinedTable(Stock\ItemGroupTable::class)-> getAll(),
				'itemclass' => $this->getDefinedTable(Stock\ItemClassTable::class)-> getAll(),
			)); 
		} 

		
	}
}
