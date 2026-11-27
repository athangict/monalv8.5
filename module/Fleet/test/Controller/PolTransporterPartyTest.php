<?php

namespace FleetTest\Controller;

use Accounts\Model\PartyTable;
use Accounts\Model\SubheadTable;
use Fleet\Controller\PolController;
use Administration\Model\LocationTable;
use Fleet\Model\TransportCashMemoTable;
use Fleet\Model\TransportSanctionOrderTable;
use Fleet\Model\TransportVehicleLogTable;
use Laminas\Db\Adapter\Adapter;
use PHPUnit\Framework\TestCase;

final class PolTransporterPartyTest extends TestCase
{
    private function controller(): TestPolController
    {
        $adapter = new Adapter(['driver' => 'Pdo_Sqlite', 'database' => ':memory:']);
        foreach ([
            'CREATE TABLE fa_party_role (id INTEGER, role TEXT)',
            'CREATE TABLE fa_party (id INTEGER, name TEXT, role INTEGER)',
            'CREATE TABLE fa_sub_head (id INTEGER, ref_id INTEGER, type INTEGER)',
            "INSERT INTO fa_party_role VALUES (4, 'Transporter'), (5, 'Employee')",
            "INSERT INTO fa_party VALUES (10, 'Mapped', 4), (11, 'Unmapped', 4), (12, 'Multiple', 4), (13, 'Employee', 5)",
            'INSERT INTO fa_sub_head VALUES (77, 10, 2), (78, 12, 2), (79, 12, 2), (80, 10, 1)',
        ] as $statement) {
            $adapter->query($statement, Adapter::QUERY_MODE_EXECUTE);
        }

        return new TestPolController($adapter);
    }

    public function testListsEveryTransporterEvenWithoutAUniqueSubhead(): void
    {
        $view = $this->controller()->addpolAction();

        self::assertSame(['Mapped', 'Unmapped', 'Multiple'], array_column($view->getVariable('party'), 'name'));
    }

    public function testRejectsInvalidOrAmbiguousPartiesBeforeSaving(): void
    {
        foreach ([11, 12, 13, 999, '10invalid'] as $partyId) {
            $controller = $this->controller();
            $controller->getRequest()->setMethod('POST');
            $controller->getRequest()->getPost()->set('party', $partyId);
            $controller->addpolAction();

            self::assertNull($controller->savedData);
            self::assertStringContainsString('error^', $controller->message);
        }
    }

    public function testStoresTheUniqueLinkedSubheadInsteadOfThePartyId(): void
    {
        $controller = $this->controller();
        $controller->getRequest()->setMethod('POST');
        $controller->getRequest()->getPost()->fromArray(array_fill_keys([
            'sanction_order_date', 'head', 'subhead', 'transporter_fcb', 'location',
            'start_date', 'end_date', 'tank_balance', 'tank_closing', 'last_km_reading',
            'current_km_reading', 'total_refuel', 'total_fuel_consumed', 'total_kilometer',
            'amount', 'note', 'ref_no', 'license_plate',
        ], '1') + ['party' => 10]);
        $controller->addpolAction();

        self::assertSame(77, (int) $controller->savedData['party']);
    }

    public function testCreatesSerialsForEmptyAndExistingMonthlyRecords(): void
    {
        foreach ([false, true] as $hasExisting) {
            $controller = $this->controller();
            $memoPrefix = 'LOCCM' . date('ym');
            $controller->monthlySanctionOrders = $hasExisting
                ? [['sanction_order_no' => 'PLSO26110007']]
                : [];
            $controller->memoNumbers = $hasExisting ? [$memoPrefix . '0007'] : [];
            $controller->saveResult = 1;
            $controller->getRequest()->setMethod('POST');
            $post = array_fill_keys([
                'head', 'subhead', 'transporter_fcb', 'start_date', 'end_date',
                'tank_balance', 'tank_closing', 'last_km_reading', 'current_km_reading',
                'total_refuel', 'total_fuel_consumed', 'total_kilometer', 'amount',
                'note', 'ref_no', 'license_plate',
            ], '1');
            $post += array_fill_keys([
                'initial_reading', 'final_reading', 'km_covered', 'opening_tank_balance',
                'refuelling', 'total_fuelling', 'millage', 'fuel_consumed',
                'closing_tank_balance', 'r_rate', 'r_amount',
            ], ['1', '2']);
            $post += ['party' => 10, 'location' => 9, 'sanction_order_date' => '2026-11-24'];
            $controller->getRequest()->getPost()->fromArray($post);

            $controller->addpolAction();

            self::assertSame('PLSO2611' . ($hasExisting ? '0008' : '0001'), $controller->savedData['sanction_order_no']);
            self::assertSame(
                [$memoPrefix . ($hasExisting ? '0008' : '0001'), $memoPrefix . ($hasExisting ? '0009' : '0002')],
                array_column($controller->savedLogs, 'cash_memo_no')
            );
            self::assertSame([9, 9], $controller->locationLookups);
            self::assertTrue($controller->committed);
        }
    }
}

class TestPolController extends PolController
{
    private $tables;
    public $savedData;
    public $message;
    public $monthlySanctionOrders = [['sanction_order_no' => 'PLSO25010001']];
    public $memoNumbers = [];
    public $savedLogs = [];
    public $locationLookups = [];
    public $saveResult = 0;
    public $committed = false;

    public function __construct(Adapter $adapter)
    {
        $this->tables = [
            PartyTable::class => new PartyTable($adapter),
            SubheadTable::class => new SubheadTable($adapter),
        ];
        $this->_safedataObj = new class {
            public function rteSafe($data) { return $data; }
        };
        $this->_connection = new class($this) {
            private $controller;
            public function __construct($controller) { $this->controller = $controller; }
            public function beginTransaction() {}
            public function rollback() {}
            public function commit() { $this->controller->committed = true; }
        };
    }

    public function init() {}

    public function getDefinedTable($table)
    {
        if (isset($this->tables[$table])) {
            return $this->tables[$table];
        }
        if ($table === TransportSanctionOrderTable::class) {
            return new class($this) {
                private $controller;
                public function __construct($controller) { $this->controller = $controller; }
                public function getMonthlySO($prefix) { return $this->controller->monthlySanctionOrders; }
                public function save($data) { $this->controller->savedData = $data; return $this->controller->saveResult; }
            };
        }
        if ($table === TransportCashMemoTable::class || $table === TransportVehicleLogTable::class) {
            return new class($this) {
                private $controller;
                public function __construct($controller) { $this->controller = $controller; }
                public function getMonthlyCM($prefix) {
                    return array_map(function ($number) { return ['cash_memo_no' => $number]; }, $this->controller->memoNumbers);
                }
                public function save($data) {
                    $this->controller->savedLogs[] = $data;
                }
                public function getAll() { return []; }
            };
        }
        if ($table === LocationTable::class) {
            return new class($this) {
                private $controller;
                public function __construct($controller) { $this->controller = $controller; }
                public function getcolumn($id, $column) { $this->controller->locationLookups[] = $id; return 'LOC'; }
                public function getAll() { return []; }
            };
        }
        return new class {
            public function getAll() { return []; }
            public function getAllEmp() { return []; }
            public function getExpenseHeads() { return []; }
        };
    }

    public function flashMessenger() { return $this; }
    public function addMessage($message) { $this->message = $message; }
    public function redirect() { return $this; }
    public function toRoute($route, $params = []) { return $route; }
}
