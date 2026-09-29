<?php

function payroll_ensure_additions_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_additions (
        id INT AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL, payroll_month VARCHAR(7) NOT NULL,
        category VARCHAR(30) NOT NULL DEFAULT 'other', addition_name VARCHAR(150) NOT NULL,
        selected_dates TEXT NOT NULL, date_count INT UNSIGNED NOT NULL DEFAULT 1,
        unit_amount DECIMAL(15,2) NOT NULL DEFAULT 0, total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        notes TEXT NULL, created_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_payroll_additions_period (payroll_month, employee_id),
        CONSTRAINT fk_payroll_additions_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach ([
        'category' => "VARCHAR(30) NOT NULL DEFAULT 'other' AFTER payroll_month",
        'notes' => "TEXT NULL AFTER total_amount",
    ] as $column => $definition) {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payroll_additions' AND COLUMN_NAME=:column LIMIT 1");
        $stmt->execute([':column' => $column]);
        if (!$stmt->fetchColumn()) $pdo->exec("ALTER TABLE payroll_additions ADD COLUMN {$column} {$definition}");
    }
    foreach ([
        'additional_amount' => "DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER overtime_amount",
        'additional_details' => "LONGTEXT NULL AFTER additional_amount",
    ] as $column => $definition) {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payrolls' AND COLUMN_NAME=:column LIMIT 1");
        $stmt->execute([':column' => $column]);
        if (!$stmt->fetchColumn()) $pdo->exec("ALTER TABLE payrolls ADD COLUMN {$column} {$definition}");
    }
}

function payroll_addition_snapshot(PDO $pdo, int $employeeId, string $payrollMonth): array
{
    $stmt = $pdo->prepare("SELECT id,category,addition_name,selected_dates,date_count,unit_amount,total_amount,notes FROM payroll_additions WHERE employee_id=:employee_id AND payroll_month=:payroll_month ORDER BY addition_name,id");
    $stmt->execute([':employee_id'=>$employeeId, ':payroll_month'=>$payrollMonth]);
    $details = [];
    $total = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['dates'] = json_decode((string)$row['selected_dates'], true) ?: [];
        unset($row['selected_dates']);
        $row['date_count'] = (int)$row['date_count'];
        $row['unit_amount'] = (float)$row['unit_amount'];
        $row['total_amount'] = (float)$row['total_amount'];
        $total += $row['total_amount'];
        $details[] = $row;
    }
    return ['total'=>round($total, 2), 'details'=>$details];
}

function payroll_addition_category_label(string $category): string
{
    return ['fuel'=>'Uang Bensin', 'project'=>'Tambahan Project', 'other'=>'Lainnya'][$category] ?? 'Lainnya';
}
