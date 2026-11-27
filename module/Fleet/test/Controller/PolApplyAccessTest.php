<?php

namespace FleetTest\Controller;

use Accounts\Model\JournalTable;
use Accounts\Model\SubheadTable;
use Accounts\Model\TransactiondetailTable;
use Accounts\Model\TransactionTable;
use Acl\Model\NotificationTable;
use Acl\Model\RolesTable;
use Administration\Model\FlowTransactionTable;
use Administration\Model\LocationTable;
use Administration\Model\UsersTable;
use Fleet\Controller\PolController;
use Fleet\Model\TransportSanctionOrderTable;
use PHPUnit\Framework\TestCase;

final class PolApplyAccessTest extends TestCase
{
    public function testApplyAccessUsesHighestRoleStatusAndLocation(): void
    {
        $controller = new ApplyPolController();
        foreach ([
            ['150', 1, 9, true],
            ['2, 150', 2, 9, true],
            ['150', 6, 9, false],
            ['2', 1, 5, true],
            ['2', 1, 9, false],
            ['99', 1, 5, false],
        ] as [$role, $status, $location, $allowed]) {
            $controller->setUser($role, 5);
            self::assertSame($allowed, $controller->isAllowed($status, $location));
        }
    }

    public function testAdministratorCanApplyAtAnotherLocation(): void
    {
        $controller = new ApplyPolController();
        $controller->setUser('2, 150', 5);
        $controller->getRequest()->setMethod('POST');
        $controller->getRequest()->getPost()->fromArray(['tp_id' => 42, 'remarks' => 'Approved']);

        $controller->processpolAction();

        self::assertTrue($controller->committed);
        self::assertFalse($controller->rolledBack);
        self::assertSame('success^Successfully Applied', $controller->message);
        self::assertSame(9, $controller->locationLookups[0]);
        self::assertSame(9, $controller->locationLookups[1]);
        self::assertSame(3, $controller->voucher['region']);
        self::assertSame('POL-J' . date('ym') . '00001', $controller->voucher['voucher_no']);
        self::assertSame([9, 9], array_column($controller->details, 'location'));
        self::assertSame([9, 9], array_column($controller->details, 'activity'));
    }

    public function testDeniedOrAlreadyAppliedPolIsNotChanged(): void
    {
        foreach ([['2', 1], ['150', 6]] as [$role, $status]) {
            $controller = new ApplyPolController();
            $controller->setUser($role, 5);
            $controller->pol['status'] = $status;
            $controller->getRequest()->setMethod('POST');
            $controller->getRequest()->getPost()->fromArray(['tp_id' => 42, 'remarks' => 'Test']);

            $controller->processpolAction();

            self::assertSame([], $controller->sanctionUpdates);
            self::assertFalse($controller->committed);
            self::assertStringContainsString('error^', $controller->message);
        }
    }

    public function testFailedVoucherRollsBackWithoutCreatingDetails(): void
    {
        $controller = new ApplyPolController();
        $controller->setUser('150', 5);
        $controller->voucherResult = 0;
        $controller->getRequest()->setMethod('POST');
        $controller->getRequest()->getPost()->fromArray(['tp_id' => 42, 'remarks' => 'Test']);

        $controller->processpolAction();

        self::assertTrue($controller->rolledBack);
        self::assertFalse($controller->committed);
        self::assertSame([], $controller->details);
        self::assertStringContainsString('error^', $controller->message);
    }
}

class ApplyPolController extends PolController
{
    public $pol = [
        'id' => 42, 'status' => 1, 'location_id' => 9, 'location' => 'POL location',
        'sanction_order_date' => '2026-11-24', 'head' => 10, 'subhead' => 11,
        'party' => 12, 'amount' => 500, 'ref_no' => 'REF',
    ];
    public $sanctionUpdates = [];
    public $locationLookups = [];
    public $details = [];
    public $voucher;
    public $message;
    public $voucherResult = 77;
    public $committed = false;
    public $rolledBack = false;

    public function __construct()
    {
        $this->_author = 1;
        $this->_created = '2026-11-24';
        $this->_modified = '2026-11-24';
        $this->_safedataObj = new class {
            public function rteSafe($data) { return $data; }
        };
        $this->_connection = new class($this) {
            private $controller;
            public function __construct($controller) { $this->controller = $controller; }
            public function beginTransaction() {}
            public function commit() { $this->controller->committed = true; }
            public function rollback() { $this->controller->rolledBack = true; }
        };
    }

    public function init() {}
    public function setUser($role, $location) { $this->_login_role = $role; $this->_userloc = $location; }
    public function isAllowed($status, $location) { return $this->canApplyPol(['status' => $status, 'location_id' => $location]); }
    public function flashMessenger() { return $this; }
    public function addMessage($message) { $this->message = $message; }
    public function redirect() { return $this; }
    public function toRoute($route, $params = []) { return $route; }

    public function getDefinedTable($table)
    {
        return new class($this, $table) {
            private $controller;
            private $table;
            public function __construct($controller, $table) { $this->controller = $controller; $this->table = $table; }
            public function getMax($column) { return 150; }
            public function get($param) {
                if ($this->table === TransportSanctionOrderTable::class) {
                    return [$this->controller->pol];
                }
                return [];
            }
            public function getColumn($param, $column) {
                if ($this->table === LocationTable::class) {
                    $this->controller->locationLookups[] = $param;
                    return $column === 'region' ? 3 : 'POL';
                }
                if ($this->table === TransportSanctionOrderTable::class) { return 9; }
                if ($this->table === JournalTable::class) { return 'J'; }
                if ($this->table === SubheadTable::class) { return 13; }
                return null;
            }
            public function getSerial($prefix) { return []; }
            public function save($data) {
                if ($this->table === TransportSanctionOrderTable::class) {
                    $this->controller->sanctionUpdates[] = $data;
                    return $data['id'];
                }
                if ($this->table === TransactionTable::class) {
                    $this->controller->voucher = $data;
                    return $this->controller->voucherResult;
                }
                if ($this->table === TransactiondetailTable::class) {
                    $this->controller->details[] = $data;
                    return count($this->controller->details);
                }
                if ($this->table === FlowTransactionTable::class || $this->table === NotificationTable::class) {
                    return 1;
                }
                return 1;
            }
        };
    }
}
