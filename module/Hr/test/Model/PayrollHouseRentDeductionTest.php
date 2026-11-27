<?php

namespace HrTest\Model;

use Hr\Model\PaydetailTable;
use Laminas\Db\Adapter\Adapter;
use PHPUnit\Framework\TestCase;

final class PayrollHouseRentDeductionTest extends TestCase
{
    public function testHouseRentDeductionIsIdentifiedByNameAndDeductionFlag(): void
    {
        $adapter = new Adapter(['driver' => 'Pdo_Sqlite', 'database' => ':memory:']);
        foreach ([
            'CREATE TABLE hr_pay_details (id INTEGER, pay_roll INTEGER, pay_head INTEGER, amount REAL)',
            'CREATE TABLE hr_pay_heads (id INTEGER, payhead_type INTEGER)',
            'CREATE TABLE hr_pay_head_type (id INTEGER, head_type TEXT, deduction INTEGER)',
            'CREATE TABLE hr_payroll (id INTEGER, employee INTEGER, year INTEGER, month INTEGER)',
            'CREATE TABLE hr_employee (id INTEGER, location INTEGER)',
            "INSERT INTO hr_pay_head_type VALUES (77, 'hOuSe ReNt deduction', 1), (88, 'Income Tax', 1), (99, 'House Rent Allowance', 0), (66, 'Monthly RENT', 1), (55, 'Current Liabilities', 1), (44, 'Payment', 1)",
            'INSERT INTO hr_pay_heads VALUES (1, 77), (2, 88), (3, 99), (4, 66), (5, 55), (6, 44)',
            'INSERT INTO hr_employee VALUES (1, 5)',
            'INSERT INTO hr_payroll VALUES (1, 1, 2026, 10)',
            'INSERT INTO hr_pay_details VALUES (1, 1, 1, 100), (2, 1, 2, 200), (3, 1, 3, 300), (4, 1, 4, 400), (5, 1, 5, 500), (6, 1, 6, 600)',
        ] as $sql) {
            $adapter->query($sql, Adapter::QUERY_MODE_EXECUTE);
        }

        $table = new PaydetailTable($adapter);
        $regularDeductions = $table->getDistinctDeduction(
            'ph.payhead_type',
            ['year' => 2026, 'month' => 10],
            ['pht.deduction' => 1]
        );
        $houseRentDeductions = $table->getEmployeeDed('location', ['year' => 2026, 'month' => 10]);
        $houseRentTypeIds = array_column($houseRentDeductions, 'payhead_type_id');
        sort($houseRentTypeIds);
        $houseRentAmounts = array_column($houseRentDeductions, 'amount', 'payhead_type_id');

        $regularDeductionTypeIds = array_column($regularDeductions, 'id');
        sort($regularDeductionTypeIds);
        self::assertSame([44, 55, 88], $regularDeductionTypeIds);
        self::assertCount(2, $houseRentDeductions);
        self::assertSame([66, 77], $houseRentTypeIds);
        self::assertSame(5, $houseRentDeductions[0]['location']);
        self::assertSame(100.0, (float) $houseRentAmounts[77]);
        self::assertSame(400.0, (float) $houseRentAmounts[66]);
    }
}
