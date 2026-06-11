<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use App\Libraries\Payroll\OvertimeCalculator;

class OvertimeCalculatorTest extends CIUnitTestCase
{
    public function testCalculatePayableHoursNormalDay()
    {
        // Hari kerja normal: 1 jam pertama = 1.5x, berikutnya = 2x
        $this->assertEquals(1.5, OvertimeCalculator::calculatePayableHours(1.0, false));
        $this->assertEquals(3.5, OvertimeCalculator::calculatePayableHours(2.0, false)); // 1.5 + (1 * 2)
        $this->assertEquals(5.5, OvertimeCalculator::calculatePayableHours(3.0, false)); // 1.5 + (2 * 2)
    }

    public function testCalculatePayableHoursHoliday5DaysWorkweek()
    {
        // Hari libur (5 hari kerja): 
        // 8 jam pertama = 2x
        // Jam ke-9 = 3x
        // Jam ke-10 dst = 4x
        
        // 7 jam -> 7 * 2 = 14
        $this->assertEquals(14.0, OvertimeCalculator::calculatePayableHours(7.0, true, true));
        
        // 8 jam -> 8 * 2 = 16
        $this->assertEquals(16.0, OvertimeCalculator::calculatePayableHours(8.0, true, true));
        
        // 9 jam -> (8 * 2) + (1 * 3) = 19
        $this->assertEquals(19.0, OvertimeCalculator::calculatePayableHours(9.0, true, true));
        
        // 10 jam -> (8 * 2) + (1 * 3) + (1 * 4) = 23
        $this->assertEquals(23.0, OvertimeCalculator::calculatePayableHours(10.0, true, true));
    }
}