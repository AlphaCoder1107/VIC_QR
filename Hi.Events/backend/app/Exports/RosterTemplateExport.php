<?php

declare(strict_types=1);

namespace HiEvents\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class RosterTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return ['enrollment_no', 'name', 'email', 'phone'];
    }

    public function array(): array
    {
        return [
            ['23001', 'Rahul Verma', 'rahul@example.com', '9876543210'],
            ['22045', 'Aditya Sharma', 'aditya@example.com', '9876543211'],
            ['21033', 'Bhumika Patel', 'bhumika@example.com', '9876543212'],
        ];
    }
}
