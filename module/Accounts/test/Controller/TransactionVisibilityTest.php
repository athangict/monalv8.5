<?php
namespace AccountsTest\Controller;

use Accounts\Controller\TransactionController;
use Accounts\Model\TransactionTable;
use Administration\Model\FlowTransactionTable;
use Administration\Model\LocationTable;
use Administration\Model\RegionTable;
use Administration\Model\UsersTable;
use Interop\Container\ContainerInterface;
use Laminas\View\Helper\AbstractHelper;
use Laminas\View\Model\ViewModel;
use Laminas\View\Renderer\PhpRenderer;
use Laminas\View\Resolver\TemplateMapResolver;
use PHPUnit\Framework\TestCase;

final class TransactionVisibilityTest extends TestCase
{
	public function testCreatorCanPerformEachStepOnlyWithItsAssignedRoleRegardlessOfRoleOrder(): void
	{
		foreach (['2', '2,3', '3,2', '2,5', '2,3,5', '5,3,2'] as $roles) {
			$roleIds = explode(',', $roles);
			$transactions = $this->createMock(TransactionTable::class);
			$transactions->method('get')->with(44)->willReturn([['id' => 44, 'author' => 9]]);
			$users = $this->createMock(UsersTable::class);
			$users->method('getColumn')->with(9, 'region')->willReturn(1);
			$users->method('get')->willReturnCallback(static function (array $where) use ($roles): array {
				self::assertSame(1, $where['status']);
				if (isset($where['region'])) {
					self::assertSame(1, $where['region']);
				}
				if (isset($where['id'])) {
					return [['id' => 9, 'role' => $roles]];
				}
				return [['id' => 9, 'role' => $roles], ['id' => 6, 'role' => '3'], ['id' => 8, 'role' => '5']];
			});
			$flow = ['id' => 100, 'application' => 44, 'flow' => 2, 'actor' => 2,
				'role_id' => null, 'action_performed' => 0, 'process' => 9001];
			$flows = $this->createMock(FlowTransactionTable::class);
			$flows->method('get')->willReturnCallback(static function () use (&$flow): array {
				return [$flow];
			});
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturnMap([
				[TransactionTable::class, $transactions], [UsersTable::class, $users],
				[FlowTransactionTable::class, $flows],
			]);
			$controller = new TransactionVisibilityController($container);
			$controller->login(9, $roles);
			self::assertTrue($controller->canAct(100, 44), 'Creator can apply');
			$flow['actor'] = 3;
			self::assertSame(in_array('3', $roleIds, true), $controller->canAct(100, 44), $roles);
			$checkerIds = array_column($controller->expenseRecipients(44, 3), 'id');
			self::assertContains(6, $checkerIds, 'Separate checker remains eligible');
			self::assertSame(in_array('3', $roleIds, true), in_array(9, $checkerIds, true));
			$flow['action_performed'] = 1;
			self::assertFalse($controller->canAct(100, 44), 'Completed step cannot be repeated');
			$flow['action_performed'] = 0;
			$flow['actor'] = 5;
			$canApprove = in_array('5', $roleIds, true);
			self::assertSame($canApprove, $controller->canAct(100, 44), 'Approval requires the approver role');
			self::assertSame($canApprove ? [9, 8] : [8], array_column($controller->expenseRecipients(44, 5), 'id'));
			$flow['action_performed'] = 1;
			self::assertFalse($controller->canAct(100, 44), 'Completed approval cannot be repeated');
		}
	}

	public function testViewingExpenseNotificationSavesEnumLabelWithoutChangingPriority(): void
	{
		$source = file_get_contents(dirname(__DIR__, 2) . '\src\Controller\TransactionController.php');
		$start = strpos($source, '// Keep the notification in the inbox');
		$end = strpos($source, '/*end--*/', $start);
		$fragment = substr($source, $start, $end - $start);
		$notifications = $this->createMock(\Acl\Model\NotifyTable::class);
		$notifications->method('get')->with([
			'n.id' => 9, 'n.user' => 7, 'f.key' => 47, 'f.route' => 'transaction', 'f.action' => 'viewcredit',
		])->willReturn([['id' => 9, 'flag' => '0']]);
		$notifications->expects(self::once())->method('save')->with(['id' => 9, 'flag' => '1'])->willReturn(9);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([[\Acl\Model\NotifyTable::class, $notifications]]);
		$controller = new TransactionVisibilityController($container);
		$controller->login(7, '3');
		$file = tempnam(sys_get_temp_dir(), 'expense-notification-');
		try {
			file_put_contents($file, '<?php use Acl\Model as Acl; ' . $fragment);
			$execute = \Closure::bind(function () use ($file): void {
				$params = ['47', '1', '9'];
				$application_id = 47;
				include $file;
			}, $controller, TransactionController::class);
			$execute();
		} finally {
			unlink($file);
		}
	}

	public function testNotificationRecipientRetainsReadAccessAfterCheckerStepWithoutActionAccess(): void
	{
		$rows = [['id' => 47, 'author' => 9], ['id' => 48, 'author' => 9]];
		$transactions = $this->createMock(TransactionTable::class);
		$transactions->method('get')->willReturnCallback(static fn ($id) => array_values(array_filter(
			$rows, static fn ($row) => $row['id'] === $id
		)));
		$notifications = $this->createMock(\Acl\Model\NotifyTable::class);
		$notifications->method('get')->with([
			'n.user' => 7, 'f.route' => 'transaction', 'f.action' => 'viewcredit',
		])->willReturn([['key' => '47', 'flag' => 1]]);
		$flows = $this->createMock(FlowTransactionTable::class);
		$flows->method('get')->willReturn([]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[TransactionTable::class, $transactions], [FlowTransactionTable::class, $flows],
			[\Acl\Model\NotifyTable::class, $notifications],
		]);
		$controller = new TransactionVisibilityController($container);
		$controller->login(7, '3');
		self::assertTrue($controller->canView(47));
		self::assertFalse($controller->canView(48));
		self::assertSame([47], array_column($controller->visible($rows), 'id'));
		self::assertFalse($controller->canAct(46529, 47));
	}

	public function testExpenseDetailRendersWorkflowPermissionsAsPlainViewData(): void
	{
		$source = file_get_contents(dirname(__DIR__, 2) . '\view\accounts\transaction\viewcredit.phtml');
		$fragment = substr($source, 0, strpos($source, '<div class="space">'));
		$file = tempnam(sys_get_temp_dir(), 'expense-view-');
		try {
			file_put_contents($file, $fragment . '<?php echo $edit_option; ?>');
			$renderer = new PhpRenderer();
			$renderer->setResolver(new TemplateMapResolver(['expense-permissions' => $file]));
			$transaction = ['id' => 44, 'bank_ref_type' => 1];
			foreach ([
				[[['id' => 100]], [], '0'],
				[[['id' => 100]], [100], '1'],
				[[], [], '0'],
			] as [$flows, $allowedIds, $expected]) {
				$output = $renderer->render('expense-permissions', [
					'transactionrow' => [$transaction], 'application' => [$transaction],
					'expenseFlows' => $flows, 'allowedExpenseFlowIds' => $allowedIds,
				]);
				self::assertSame($expected, trim($output));
			}
		} finally {
			unlink($file);
		}
	}

	public function testEmptyVoucherListDoesNotResolveOrphanExpenseFlows(): void
	{
		$flows = $this->createMock(FlowTransactionTable::class);
		$flows->expects(self::once())->method('get')
			->with(['role_id' => 9, 'action_performed' => 0])->willReturn([]);
		$container = $this->createMock(ContainerInterface::class);
		$notifications = $this->createMock(\Acl\Model\NotifyTable::class);
		$notifications->method('get')->willReturn([]);
		$container->method('get')->willReturnMap([
			[FlowTransactionTable::class, $flows], [\Acl\Model\NotifyTable::class, $notifications],
		]);
		$controller = new TransactionVisibilityController($container);
		$controller->login(9, '2');
		self::assertSame([], $controller->visible([]));
	}

	public function testExpenseNotificationIsDeliveredToRegionalChecker(): void
	{
		$transactions = $this->createMock(TransactionTable::class);
		$transactions->method('get')->with(44)->willReturn([['id' => 44, 'author' => 9, 'voucher_no' => 'ER-44']]);
		$privileges = $this->createMock(\Administration\Model\FlowPrivilegeTable::class);
		$privileges->method('get')->with(14)->willReturn([[
			'flow' => 2, 'description' => 'Applied', 'route_notification_to' => '3', 'notification' => 1,
		]]);
		$users = $this->createMock(UsersTable::class);
		$users->method('getColumn')->with(9, 'region')->willReturn(1);
		$users->method('get')->with(['status' => 1, 'region' => 1])->willReturn([
			['id' => 9, 'role' => '2', 'email' => '', 'name' => 'Maker'],
			['id' => 6, 'role' => '2,3,5', 'email' => '', 'name' => 'Checker'],
		]);
		$notifications = $this->createMock(\Acl\Model\NotificationTable::class);
		$notifications->expects(self::once())->method('save')->with(self::callback(
			static fn (array $data): bool => $data['key'] === 44 && str_contains($data['description'], 'Please check')
		))->willReturn(100);
		$inbox = $this->createMock(\Acl\Model\NotifyTable::class);
		$inbox->expects(self::once())->method('save')->with(self::callback(
			static fn (array $data): bool => $data['user'] === 6 && $data['notification'] === 100 && $data['flag'] === '0'
		))->willReturn(101);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[TransactionTable::class, $transactions], [UsersTable::class, $users],
			[\Administration\Model\FlowPrivilegeTable::class, $privileges],
			[\Acl\Model\NotificationTable::class, $notifications], [\Acl\Model\NotifyTable::class, $inbox],
		]);
		$controller = new TransactionVisibilityController($container);
		$controller->notify(44, 14, 100, 'Please check');
	}

	public function testExpenseHandoffBelongsToCheckerNotPreviousMaker(): void
	{
		$transactions = $this->createMock(TransactionTable::class);
		$transactions->method('get')->with(44)->willReturn([['id' => 44, 'author' => 9]]);
		$users = $this->createMock(UsersTable::class);
		$users->method('getColumn')->with(9, 'region')->willReturn(1);
		$users->method('get')->with(['status' => 1, 'region' => 1])
			->willReturn([['id' => 6, 'role' => '2,3,5']]);
		$flow = ['id' => 100, 'application' => 44, 'flow' => 2, 'actor' => 3,
			'role_id' => 9, 'action_performed' => 0, 'process' => 9001];
		$flows = $this->createMock(FlowTransactionTable::class);
		$flows->method('get')->willReturn([$flow]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[TransactionTable::class, $transactions], [UsersTable::class, $users],
			[FlowTransactionTable::class, $flows],
		]);
		$controller = new TransactionVisibilityController($container);
		$controller->login(9, '2');
		self::assertFalse($controller->canAct(100, 44));
		$controller->login(6, '2,3,5');
		self::assertTrue($controller->canAct(100, 44));
		self::assertSame([6], array_column($controller->expenseRecipients(44, 3), 'id'));
	}

	public function testVoucherHeaderWidthsMatchExpectedContent(): void
	{
		$path = dirname(__DIR__, 2) . '\view\accounts\transaction\\';
		$fields = ['voucher_date', 'voucher_type', 'cheque_no1', 'doc_id', 'doc_type', 'voucher_amount'];
		foreach (['add', 'edit'] as $operation) {
			foreach (['transaction', 'credit', 'debit', 'contra', 'reference', 'againstdebit', 'expense'] as $type) {
				$source = file_get_contents($path . $operation . $type . '.phtml');
				preg_match_all(
					'/<div class="account-entry-header-field( account-entry-(?:document|amount)-field)?">(.*?)<label[^>]*for="([^"]+)"/s',
					$source,
					$matches,
					PREG_SET_ORDER
				);
				self::assertCount(6, $matches, $operation . $type);
				foreach ($matches as $index => $match) {
					self::assertSame($fields[$index], $match[3]);
					$expectedClass = in_array($match[3], ['doc_id', 'doc_type'], true) ? ' account-entry-document-field' : '';
					if ($match[3] === 'voucher_amount') {
						$expectedClass = ' account-entry-amount-field';
					}
					self::assertSame($expectedClass, $match[1]);
				}
			}
		}
	}

	public function testAllVoucherFormsUseUniformDetailColumns(): void
	{
		$path = dirname(__DIR__, 2) . '\view\accounts\transaction\\';
		foreach (['add', 'edit'] as $operation) {
			foreach (['transaction', 'credit', 'debit', 'contra', 'reference', 'againstdebit', 'expense'] as $type) {
				$source = file_get_contents($path . $operation . $type . '.phtml');
				preg_match('/<table id="trdetails_table".*?<\/table>/s', $source, $matches);
				self::assertNotEmpty($matches, $operation . $type);
				$table = preg_replace('/<!--.*?-->/s', '', $matches[0]);
				self::assertStringContainsString('account-entry-details', $table);
				preg_match_all('/<th(?:\s[^>]*)?>/', explode('</thead>', $table)[0], $headers);
				self::assertStringContainsString('<col span="' . (count($headers[0]) - 1) . '">', $table);
				self::assertStringContainsString('<col class="transaction-action-column">', $table);
				self::assertDoesNotMatchRegularExpression('/<td[^>]*col-lg-/', $table);
			}
		}
	}

	public function testEntryLocationsComeOnlyFromAdminLocationAssignments(): void
	{
		foreach (['7' => [7], '7, 12,7,0,invalid' => [7, 12], '' => [], '0' => []] as $assigned => $expectedIds) {
			$users = $this->createMock(UsersTable::class);
			$users->method('getColumn')->with(123, 'admin_location')->willReturn((string) $assigned);
			$locations = $this->createMock(LocationTable::class);
			$rows = array_map(static function ($id) {
				return ['id' => $id, 'region' => $id];
			}, $expectedIds);
			if ($expectedIds) {
				$locations->expects(self::once())->method('get')->with(['id' => $expectedIds])->willReturn($rows);
			} else {
				$locations->expects(self::never())->method('get');
			}
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturnMap([
				[UsersTable::class, $users], [LocationTable::class, $locations],
			]);
			$controller = new TransactionVisibilityController($container);
			$controller->login(123, '100');
			self::assertSame($rows, $controller->entryLocations());
			self::assertFalse($controller->allowsLocations(['999'], $rows));
			self::assertFalse($controller->allowsLocations(['7 OR 1=1'], $rows));
			self::assertFalse($controller->allowsLocations([['7']], $rows));
			self::assertFalse($controller->allowsLocations(null, $rows));
			self::assertFalse($controller->allowsLocations([], $rows));
			if ($expectedIds) {
				self::assertTrue($controller->allowsLocations(array_map('strval', $expectedIds), $rows));
				self::assertTrue($controller->allowsLocations([$expectedIds[0], $expectedIds[0]], $rows));
			} else {
				self::assertFalse($controller->allowsLocations(['7'], $rows));
			}
		}
	}

	public function testCreatorsSeeEveryOwnVoucherRegardlessOfRoleStatusOrRegion(): void
	{
		$transactions = [];
		foreach ([1, 3, 4, 5, 6, 8, 9, 11, 12, 15] as $voucher) {
			foreach ([2, 3, 4, 6, 10, 18] as $status) {
				$transactions[] = [
					'id' => count($transactions) + 1,
					'voucher_id' => $voucher,
					'status' => $status,
					'region' => 42,
					'author' => '123',
				];
			}
		}
		$otherTransaction = [
			'id' => 999, 'voucher_id' => 9, 'status' => 2,
			'region' => 42, 'author' => 456,
		];
		foreach (['2', '8', '17', '2,17', '777'] as $role) {
			$controller = $this->controller($role, $transactions);
			$visible = $controller->visible(array_merge($transactions, [$otherTransaction]));
			self::assertSame(array_column($transactions, 'id'), array_column($visible, 'id'), $role);
			self::assertSame(array_fill(0, count($transactions), true), array_column($visible, 'can_view'));
			foreach ($transactions as $transaction) {
				self::assertTrue($controller->canView($transaction['id']));
			}
			self::assertFalse($controller->canView(999));
		}
	}

	public function testAllAccountsEntryActionsValidateLocationsBeforeSaving(): void
	{
		$source = file_get_contents(dirname(__DIR__, 2) . '\src\Controller\TransactionController.php');
		foreach (['addtransaction', 'edittransaction', 'addcredit', 'editcredit', 'addexpense', 'editexpense',
			'adddebit', 'editdebit', 'addcontra', 'editcontra', 'addagainstcredit', 'editagainst',
			'addagainstdebit', 'editagainstdebit', 'addiwr', 'editiwr', 'addiwrreceipt', 'editiwrreceipt'] as $action) {
			preg_match('/public function ' . $action . 'Action\(\).*?(?=\n\s*public function|\z)/s', $source, $matches);
			self::assertNotEmpty($matches, $action);
			self::assertStringContainsString('$this->entryViewModel(array(', $matches[0], $action);
			self::assertStringContainsString('$this->areEntryLocationsAllowed(', $matches[0], $action);
			self::assertLessThan(
				strpos($matches[0], '$this->_connection->beginTransaction()'),
				strpos($matches[0], '$this->areEntryLocationsAllowed('),
				$action
			);
		}
	}

	public function testEntryViewUsesAssignedLocationsAcrossRegionsInsteadOfRoleBasedOptions(): void
	{
		$assigned = [
			['id' => 7, 'region' => 1, 'location' => 'Assigned One'],
			['id' => 12, 'region' => 2, 'location' => 'Assigned Two'],
		];
		$regions = [['id' => 1, 'region' => 'Region One'], ['id' => 2, 'region' => 'Region Two']];
		$users = $this->createMock(UsersTable::class);
		$users->method('getColumn')->with(123, 'admin_location')->willReturn('7,12');
		$locations = $this->createMock(LocationTable::class);
		$locations->method('get')->with(['id' => [7, 12]])->willReturn($assigned);
		$regionTable = $this->createMock(RegionTable::class);
		$regionTable->method('get')->with(['id' => [1, 2]])->willReturn($regions);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[UsersTable::class, $users], [LocationTable::class, $locations], [RegionTable::class, $regionTable],
		]);
		$controller = new TransactionVisibilityController($container);
		$controller->login(123, '2');
		$model = $controller->entryView([
			'title' => 'Entry', 'locations' => [['id' => 999]], 'regions' => [['id' => 99]],
		]);
		self::assertSame('Entry', $model->getVariable('title'));
		self::assertSame($assigned, $model->getVariable('entryLocations'));
		self::assertSame($assigned, $model->getVariable('locations'));
		self::assertSame($regions, $model->getVariable('regions'));
	}

	public function testEveryLocationDropdownOffersOnlyAssignedLocations(): void
	{
		$path = dirname(__DIR__, 2) . '\view\accounts\transaction\\';
		$templatePath = tempnam(sys_get_temp_dir(), 'accounts-locations-');
		self::assertNotFalse($templatePath);
		$renderer = new PhpRenderer();
		$renderer->setResolver(new TemplateMapResolver(['locations' => $templatePath]));
		try {
			foreach (['add', 'edit'] as $operation) {
				foreach (['transaction', 'credit', 'debit', 'contra', 'reference', 'againstdebit', 'expense'] as $type) {
					$source = file_get_contents($path . $operation . $type . '.phtml');
					preg_match_all('/<select\b[^>]*name="location\[\]"[^>]*>.*?<\/select>/s', $source, $matches);
					self::assertCount(2, $matches[0], $operation . $type);
					file_put_contents($templatePath, str_replace('id="location"', '', implode("\n", $matches[0])));
					foreach ([[], [['id' => 7, 'region' => 1, 'location' => 'Assigned One']],
						[['id' => 7, 'region' => 1, 'location' => 'Assigned One'],
						 ['id' => 12, 'region' => 2, 'location' => 'Assigned Two']]] as $assigned) {
						$model = new ViewModel([
							'entryLocations' => $assigned,
							'regions' => [['id' => 1, 'region' => 'Region One'], ['id' => 2, 'region' => 'Region Two']],
							'location_id' => 7,
							'trans' => ['location_id' => 7, 'location' => 'Assigned One'],
							'tdetail1' => ['location_id' => 7, 'location' => 'Assigned One'],
						]);
						$model->setTemplate('locations');
						$document = new \DOMDocument();
						$document->loadHTML($renderer->render($model));
						foreach ($document->getElementsByTagName('select') as $index => $select) {
							$actual = [];
							$selected = [];
							foreach ($select->getElementsByTagName('option') as $option) {
								if ($option->getAttribute('value') !== '') {
									$actual[] = (int) $option->getAttribute('value');
									if ($option->hasAttribute('selected')) {
										$selected[] = (int) $option->getAttribute('value');
									}
								}
							}
							$expected = array_column($assigned, 'id');
							if ($operation === 'add' && $type === 'expense' && $index === 0) {
								$expected = $assigned ? [7] : [];
							}
							self::assertSame($expected, $actual, "$operation$type/$index");
							if ($assigned && (($operation === 'edit' && $index === 0) || ($operation === 'add' && count($assigned) === 1))
								&& !($operation === 'add' && $type === 'expense' && $index === 0)) {
								self::assertSame([7], $selected, "$operation$type/$index selection");
							}
						}
					}
				}
			}
		} finally {
			unlink($templatePath);
		}
	}

	public function testAdministratorsRetainBroadVisibilityWithMultipleRoles(): void
	{
		$transactions = [['id' => 1, 'author' => 456]];
		foreach (['99', '100', '2,99', '17,100'] as $role) {
			$controller = $this->controller($role, $transactions);
			self::assertSame([1], array_column($controller->visible($transactions), 'id'));
			self::assertTrue($controller->canView(1));
		}
	}

	public function testEveryAccountsListRendersOwnEntriesExactlyOnceForAnyRoleAndStatus(): void
	{
		$renderer = new PhpRenderer();
		$helper = new class extends AbstractHelper {
			public function __invoke(...$arguments): string
			{
				return 'test-helper';
			}
		};
		foreach (['tabs_helper', 'button', 'url', 'status', 'currency'] as $name) {
			$renderer->getHelperPluginManager()->setService($name, $helper);
		}
		$voucherTable = new class {
			public function getColumn($id, $column): int
			{
				return 5;
			}
		};
		$path = dirname(__DIR__, 2) . '\view\accounts\transaction\\';
		foreach (['index' => 4, 'credit' => 11, 'debit' => 3, 'contra' => 1, 'expense' => 8, 'reference' => 9, 'againstdebit' => 12] as $template => $voucher) {
			$renderer->setResolver(new TemplateMapResolver([$template => $path . $template . '.phtml']));
			foreach (['2', '777', '2,17'] as $role) {
				foreach ([2, 3, 4, 6, 10, 18] as $status) {
					$transactions = [
						['id' => 1, 'author' => 123, 'status' => $status, 'voucher_id' => $voucher,
							'voucher_no' => 'CREATOR-VOUCHER', 'voucher_type' => 'Voucher',
							'voucher_amount' => 100, 'voucher_date' => '2026-10-19'],
						['id' => 2, 'author' => 456, 'status' => $status, 'voucher_id' => $voucher,
							'voucher_no' => 'OTHER-VOUCHER', 'voucher_type' => 'Voucher',
							'voucher_amount' => 100, 'voucher_date' => '2026-10-19'],
					];
					$controller = $this->controller($role, $transactions);
					$model = new ViewModel([
						'title' => 'Transactions', 'page' => 1, 'user' => 123, 'role' => $role,
						'data' => ['year' => 2026, 'month' => -1, 'date' => -1, 'status' => -1],
						'maxdate' => 31, 'statuslist' => [], 'voucherObj' => $voucherTable,
						'paginator' => $controller->visible($transactions),
					]);
					$model->setTemplate($template);
					$html = $renderer->render($model);
					self::assertSame(1, substr_count($html, 'CREATOR-VOUCHER'), "$template/$role/$status");
					self::assertStringNotContainsString('OTHER-VOUCHER', $html);
				}
			}
		}
	}

	public function testEveryAccountsListAndDirectViewUsesTheSharedVisibilityRules(): void
	{
		$source = file_get_contents(dirname(__DIR__, 2) . '\src\Controller\TransactionController.php');
		foreach (['index', 'credit', 'expense', 'debit', 'contra', 'against', 'againstdebit', 'iwr', 'iwrreceipt'] as $action) {
			preg_match('/public function ' . $action . 'Action\(\).*?(?=\n\s*public function|\z)/s', $source, $matches);
			self::assertNotEmpty($matches, $action);
			self::assertStringContainsString('$this->filterVisibleTransactions($transTable)', $matches[0], $action);
			self::assertStringNotContainsString('$date,$user_region)', $matches[0], $action);
			self::assertLessThan(
				strpos($matches[0], 'new \Laminas\Paginator\Paginator'),
				strpos($matches[0], '$this->filterVisibleTransactions($transTable)'),
				$action
			);
		}
		foreach (['transaction', 'details', 'credit', 'expense', 'debit', 'contra', 'against', 'againstdebit', 'iwr', 'iwrreceipt'] as $view) {
			preg_match('/public function view' . $view . 'Action\(\).*?(?=\n\s*public function|\z)/s', $source, $matches);
			self::assertNotEmpty($matches, $view);
			self::assertStringContainsString('$this->canViewTransaction((int) $this->_id)', $matches[0], $view);
		}
	}

	private function controller(string $role, array $transactions): TransactionVisibilityController
	{
		$table = $this->createMock(TransactionTable::class);
		$table->method('get')->willReturnCallback(static function ($id) use ($transactions) {
			return array_values(array_filter($transactions, static function ($transaction) use ($id) {
				return $transaction['id'] === $id;
			}));
		});
		$flows = $this->createMock(FlowTransactionTable::class);
		$flows->method('get')->willReturn([]);
		$notifications = $this->createMock(\Acl\Model\NotifyTable::class);
		$notifications->method('get')->willReturn([]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[TransactionTable::class, $table],
			[FlowTransactionTable::class, $flows],
			[\Acl\Model\NotifyTable::class, $notifications],
		]);
		$controller = new TransactionVisibilityController($container);
		$controller->login(123, $role);
		return $controller;
	}
}

final class TransactionVisibilityController extends TransactionController
{
	public function canAct(int $flowId, int $applicationId): bool
	{
		return $this->canActOnWorkflow($flowId, $applicationId);
	}

	public function expenseRecipients(int $applicationId, int $role): array
	{
		return $this->expenseWorkflowRecipients($applicationId, $role);
	}

	public function entryView(array $data): ViewModel
	{
		return $this->entryViewModel($data);
	}

	public function entryLocations(): array
	{
		return $this->getEntryLocations();
	}

	public function allowsLocations($submitted, array $locations): bool
	{
		return $this->areEntryLocationsAllowed($submitted, $locations);
	}

	public function login(int $id, string $role): void
	{
		$this->_login_id = $id;
		$this->_login_role = $role;
	}

	public function visible(array $transactions): array
	{
		return $this->filterVisibleTransactions($transactions);
	}

	public function canView(int $id): bool
	{
		return $this->canViewTransaction($id);
	}
}
