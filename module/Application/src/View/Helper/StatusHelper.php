<?php
/*
 * Helper -- Status using label
 * chophel@athang.com
 */
namespace Application\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Acl\Model\StatusTable;

class StatusHelper extends AbstractHelper
{
	private $statusTable;
	
	public function __construct(StatusTable $statusTable)
	{
		$this->statusTable = $statusTable;
	}
	
	public function __invoke($status,$style=NULL,$size=NULL,$customLabel=NULL)
	{
		$row = NULL;
		foreach($this->statusTable->get($status) as $statusRow):
			$row = $statusRow;
		endforeach;
		switch($status):
			case 0:
				$labelText = ($customLabel !== NULL && $customLabel !== '') ? $customLabel : 'Blocked';
				echo "<span class='label label-danger'>".$labelText."</span>";
				break;
			default:
				if($row):
					$statusText = ($customLabel !== NULL && $customLabel !== '') ? $customLabel : $row['status'];
					echo "<span class='label label-".$row['label']."'>".$statusText."</span>";
				endif;
				break;
		endswitch;
	}
}
