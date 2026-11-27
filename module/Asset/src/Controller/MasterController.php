<?php
namespace Asset\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\Authentication\AuthenticationService;
use Laminas\Stdlib\ArrayObject;
use Laminas\Validator\File\Size;
use Laminas\Validator\File\Extension;
use Interop\Container\ContainerInterface;
use Asset\Model As Asset;
use Acl\Model As Acl;
use Administration\Model As Administration;
use Hr\Model As Hr;
use Accounts\Model As Accounts;

class MasterController extends AbstractActionController
{   
	protected $_connection;
	protected $_highest_role;
	protected $_lowest_role;
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
		if(!isset($this->_highest_role)){
			$this->_highest_role = $this->getDefinedTable(Acl\RolesTable::class)->getMax($column='id');  
		}
		if(!isset($this->_lowest_role)){
			$this->_lowest_role = $this->getDefinedTable(Acl\RolesTable::class)->getMin($column='id'); 
		}
		if(!isset($this->_author)){
			$this->_author = $this->_user->id;  
		}
		
		$this->_id = $this->params()->fromRoute('id');
		
		$this->_created = date('Y-m-d H:i:s');
		$this->_modified = date('Y-m-d H:i:s');
		
		
		$fileManagerDir = $this->_config['file_manager']['dir'];
	
		if(!is_dir($fileManagerDir)) {
			mkdir($fileManagerDir, 0777);
		}			
	
		$this->_dir =realpath($fileManagerDir);
		
		$this->_safedataObj = $this->safedata();
		$this->_connection = $this->_container->get('Laminas\Db\Adapter\Adapter')->getDriver()->getConnection();

	}
	/**
	 * get SUBHEAD by class
	**/
	public function getsubheadAction()
	{  
		$form = $this->getRequest()->getPost();
		
		$class_id = $form['class_id'];
		//$class_id =1;
		$subheads = $this->getDefinedTable(Accounts\SubheadTable::class)->get(array('c.id' => $class_id));
		
		$sc="<option value='-1'>All</option>";
		foreach($subheads as $sbh):
			$sc.= "<option value='".$sbh['id']."'>".$sbh['name']."</option>";
		endforeach;
		echo json_encode(array(
			'shead' => $sc,
		));
		exit;
	}
	/**
	 *  ASSET TYPE 
	 */
	public function assettypeAction()
	{
		$this->init();
		$assetmgtTable = $this->getDefinedTable(Asset\AssettypeTable::class)->getAll();
		$paginator = new \Laminas\Paginator\Paginator(new \Laminas\Paginator\Adapter\ArrayAdapter($assetmgtTable));
			
		$page = 1;
		if ($this->params()->fromRoute('page')) $page = $this->params()->fromRoute('page');
		$paginator->setCurrentPageNumber((int)$page);
		$paginator->setItemCountPerPage(1000);
		$paginator->setPageRange(8);
		return new ViewModel(array(
			'title'       => 'Assest Masters',
			'paginator'   => $paginator,
			'page'        => $page,
			'classObj'    => $this->getDefinedTable(Accounts\ClassTable::class),
			'subheadObj'  => $this->getDefinedTable(Accounts\SubheadTable::class),
		));
	} 
	/**
	 *  ADD ASSET TYPE
	 */
	public function addassettypeAction()
	{
		$this->init();
		if($this->getRequest()->isPost()){
			$form = $this->getRequest()->getPost();
			$data = array(
				'code' 			=> $form['code'],
				'name' 			=> $form['name'],
				'subhead' 		=> $form['subhead'],
				'status' 		=> 1,
				'manual'		=> $form['manual'],
				'dep_subhead'	=> $form['depreciation'],
				'disposal_subhead' => $form['disposal'],
				'writeoff_subhead' => $form['writeoff'],
				'author' 		=>$this->_author,
				'created' 		=>$this->_created,
				'modified' 		=>$this->_modified,
			);
			$data = $this->_safedataObj->rteSafe($data);
			$result = $this->getDefinedTable(Asset\AssettypeTable::class)->save($data);
	
			if($result > 0):
			$this->flashMessenger()->addMessage("success^ New Asset Type successfully added");
			else:
			$this->flashMessenger()->addMessage("Failed^ Failed to add new Asset Type");
			endif;
			return $this->redirect()->toRoute('mastera', array('action'=>'assettype'));
		}
		$expenseSubheads = $this->getDefinedTable(Accounts\SubheadTable::class)->get(array('c.code' => 'EXP'));
		if (empty($expenseSubheads)):
			$expenseSubheads = $this->getDefinedTable(Accounts\SubheadTable::class)->get(array('c.name' => 'EXPENSE'));
		endif;
		$ViewModel = new ViewModel(array(
			'assetSubheads' => $this->getDefinedTable(Accounts\SubheadTable::class)->getAll(),
			'depreSh'      => $expenseSubheads,
		));
		$ViewModel->setTerminal(True);
		return $ViewModel;
	}
	/**
	 *  EDIT ASSET TYPE
	 */
     public function editassettypeAction()
    {
        $this->init();
		$params = explode("_", (string) $this->_id);
		$assettype_id =  $params['0'];
		//echo '<pre>';print_r($params);exit;
		//$test = $this->getDefinedTable(Asset\AssettypeTable::class)->get($assettype_id);
		//echo '<pre>';print_r($test);exit;
        if($this->getRequest()->isPost())
        {
            $form=$this->getRequest()->getPost();
            $data=array(
				'id' => $this->_id,
				'code' => $form['code'],
				'name' => $form['name'],
				'subhead' => $form['subhead'],
				'status' =>1,
				'manual'		=> $form['manual'],
				'dep_subhead'	=> $form['depreciation'],
            	'disposal_subhead' => $form['disposal'],
				'writeoff_subhead' => $form['writeoff'],
				'author' =>$this->_author,
				'created' =>$this->_created,
				'modified' =>$this->_modified,
            );
			//echo '<pre>';print_r($data);exit;
            $data = $this->_safedataObj->rteSafe($data);
			$this->_connection->beginTransaction(); //***Transaction begins here***//
            $result = $this->getDefinedTable(Asset\AssettypeTable::class)->save($data);
            if($result > 0):
				$this->_connection->commit(); // commit transaction on success
                $this->flashMessenger()->addMessage("success^ Asset successfully updated");
            else:
				$this->_connection->rollback(); // rollback transaction over failure
                $this->flashMessenger()->addMessage("Failed^ Failed to update asset type");
            endif;
            return $this->redirect()->toRoute('mastera', array('action'=>'assettype'));
        }  
		$expenseSubheads = $this->getDefinedTable(Accounts\SubheadTable::class)->get(array('c.code' => 'EXP'));
		if (empty($expenseSubheads)):
			$expenseSubheads = $this->getDefinedTable(Accounts\SubheadTable::class)->get(array('c.name' => 'EXPENSES'));
		endif;
		$currentDepSubhead = $this->getDefinedTable(Asset\AssettypeTable::class)->getColumn($assettype_id, 'dep_subhead');
		if (!empty($currentDepSubhead)):
			$present = false;
			foreach($expenseSubheads as $subheadRow):
				if ((int)$subheadRow['id'] === (int)$currentDepSubhead):
					$present = true;
					break;
				endif;
			endforeach;
			if (!$present):
				$currentDepRows = $this->getDefinedTable(Accounts\SubheadTable::class)->get($currentDepSubhead);
				foreach($currentDepRows as $currentDepRow):
					$expenseSubheads[] = $currentDepRow;
				endforeach;
			endif;
		endif;
		$currentDisposalSubhead = $this->getDefinedTable(Asset\AssettypeTable::class)->getColumn($assettype_id, 'disposal_subhead');
		if (!empty($currentDisposalSubhead)):
			$present = false;
			foreach($expenseSubheads as $subheadRow):
				if ((int)$subheadRow['id'] === (int)$currentDisposalSubhead):
					$present = true;
					break;
				endif;
			endforeach;
			if (!$present):
				$currentDisposalRows = $this->getDefinedTable(Accounts\SubheadTable::class)->get($currentDisposalSubhead);
				foreach($currentDisposalRows as $currentDisposalRow):
					$expenseSubheads[] = $currentDisposalRow;
				endforeach;
			endif;
		endif;
		$currentWriteoffSubhead = $this->getDefinedTable(Asset\AssettypeTable::class)->getColumn($assettype_id, 'writeoff_subhead');
		if (!empty($currentWriteoffSubhead)):
			$present = false;
			foreach($expenseSubheads as $subheadRow):
				if ((int)$subheadRow['id'] === (int)$currentWriteoffSubhead):
					$present = true;
					break;
				endif;
			endforeach;
			if (!$present):
				$currentWriteoffRows = $this->getDefinedTable(Accounts\SubheadTable::class)->get($currentWriteoffSubhead);
				foreach($currentWriteoffRows as $currentWriteoffRow):
					$expenseSubheads[] = $currentWriteoffRow;
				endforeach;
			endif;
		endif;
        $ViewModel = new ViewModel(array(
			'title' => 'Edit Asset Type',
			'assettype' => $this->getDefinedTable(Asset\AssettypeTable::class)->get($assettype_id),
			'assetSubheads' => $this->getDefinedTable(Accounts\SubheadTable::class)->getAll(),
			'depreSh'      => $expenseSubheads,
	    ));
		$ViewModel->setTerminal(True);
		return $ViewModel;
    }
	
	
}
