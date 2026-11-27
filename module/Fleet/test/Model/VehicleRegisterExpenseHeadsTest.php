<?php

namespace FleetTest\Model;

use Fleet\Model\VehicleRegisterTable;
use Laminas\Db\Adapter\Adapter;
use PHPUnit\Framework\TestCase;

final class VehicleRegisterExpenseHeadsTest extends TestCase
{
    public function testReturnsDistinctRegisteredExpenseHeads(): void
    {
        $adapter = new Adapter(['driver' => 'Pdo_Sqlite', 'database' => ':memory:']);
        foreach ([
            'CREATE TABLE fa_class (id INTEGER, name TEXT)',
            'CREATE TABLE fa_group (id INTEGER, "class" INTEGER)',
            'CREATE TABLE fa_head (id INTEGER, code TEXT, name TEXT, "group" INTEGER)',
            'CREATE TABLE tp_vehicle_register (head INTEGER, location INTEGER, pol INTEGER)',
            "INSERT INTO fa_class VALUES (1, 'EXPENSE'), (2, 'INCOME')",
            'INSERT INTO fa_group VALUES (1, 1), (2, 2)',
            "INSERT INTO fa_head VALUES (1, 'A', 'Fuel', 1), (2, 'B', 'Repairs', 1), (3, 'C', 'Income', 2), (4, 'D', 'Unregistered', 1)",
            'INSERT INTO tp_vehicle_register VALUES (1, 1, 1), (1, 2, 0), (2, 1, 0), (3, 1, 1)',
        ] as $statement) {
            $adapter->query($statement, Adapter::QUERY_MODE_EXECUTE);
        }

        $table = new VehicleRegisterTable($adapter);
        self::assertSame(['A', 'B'], array_column($table->getExpenseHeads(), 'code'));

        $adapter->query('DELETE FROM tp_vehicle_register', Adapter::QUERY_MODE_EXECUTE);
        self::assertSame([], $table->getExpenseHeads());
    }
}
