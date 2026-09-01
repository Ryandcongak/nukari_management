<?php
/**
 * Payroll & Workforce Helper - Nukari ERP
 *
 * Fungsi inti untuk menghubungkan karyawan internal yang dialokasikan ke project
 * dengan payroll bulanan. Pekerja internal tetap dibayar lewat Payroll, tetapi
 * biaya harian/rate project tetap dibebankan ke project agar profit project akurat.
 */

if (!function_exists('payroll_table_has_column')) {
    function payroll_table_has_column(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :column");
            $stmt->execute([':column' => $column]);
            $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }
}

if (!function_exists('payroll_workforce_schema_ready')) {
    function payroll_workforce_schema_ready(PDO $pdo): bool
    {
        try {
            $pdo->query("SELECT 1 FROM payroll_project_allocations LIMIT 1");
            return payroll_table_has_column($pdo, 'payrolls', 'workshop_days')
                && payroll_table_has_column($pdo, 'payrolls', 'project_work_days')
                && payroll_table_has_column($pdo, 'payrolls', 'project_worker_amount');
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('payroll_count_work_days_between')) {
    /**
     * Hitung hari kerja dengan dukungan kalender berbeda.
     *
     * Default/workshop:
     * - Senin-Jumat = 1 hari
     * - Sabtu/Minggu = 0 hari
     *
     * Project saturday_half:
     * - Senin-Jumat = 1 hari
     * - Sabtu = 0.5 hari
     * - Minggu = 0 hari
     */
    function payroll_count_work_days_between(?string $startDate, ?string $endDate, array $schedule = []): float
    {
        if (!$startDate || !$endDate || strtotime($endDate) < strtotime($startDate)) {
            return 0;
        }

        $type = $schedule['work_schedule_type'] ?? $schedule['schedule_type'] ?? 'workshop';
        $satValue = isset($schedule['saturday_work_value']) ? (float)$schedule['saturday_work_value'] : 0;
        $sunValue = isset($schedule['sunday_work_value']) ? (float)$schedule['sunday_work_value'] : 0;

        if ($type === 'project_saturday_half' || $type === 'project') {
            $satValue = $satValue > 0 ? $satValue : 0.5;
            $sunValue = 0;
        } elseif ($type === 'workshop' || $type === 'default') {
            $satValue = 0;
            $sunValue = 0;
        }

        $count = 0.0;
        $current = strtotime($startDate);
        $end = strtotime($endDate);

        while ($current <= $end) {
            $dayName = (int)date('N', $current); // 1=Senin, 6=Sabtu, 7=Minggu

            if ($dayName >= 1 && $dayName <= 5) {
                $count += 1;
            } elseif ($dayName === 6) {
                $count += max(0, min(1, $satValue));
            } elseif ($dayName === 7) {
                $count += max(0, min(1, $sunValue));
            }

            $current = strtotime('+1 day', $current);
        }

        return $count;
    }
}

if (!function_exists('payroll_get_project_schedule')) {
    function payroll_get_project_schedule(array $projectRow): array
    {
        return [
            'work_schedule_type' => $projectRow['work_schedule_type'] ?? 'project_saturday_half',
            'saturday_work_value' => isset($projectRow['saturday_work_value']) ? (float)$projectRow['saturday_work_value'] : 0.5,
            'sunday_work_value' => isset($projectRow['sunday_work_value']) ? (float)$projectRow['sunday_work_value'] : 0,
        ];
    }
}

if (!function_exists('payroll_get_project_worker_allocations')) {
    function payroll_get_project_worker_allocations(PDO $pdo, int $employeeId, string $periodStart, string $periodEnd): array
    {
        if ($employeeId <= 0) {
            return [];
        }

        $stmt = $pdo->prepare("\n            SELECT\n                w.*,\n                p.project_name,\n                p.project_code,\n                p.work_schedule_type,\n                p.saturday_work_value,\n                p.sunday_work_value,\n                c.client_name\n            FROM project_workers w\n            INNER JOIN projects p ON p.id = w.project_id\n            LEFT JOIN clients c ON c.id = p.client_id\n            WHERE w.worker_type = 'employee'\n              AND w.employee_id = :employee_id\n              AND w.start_date IS NOT NULL\n              AND w.end_date IS NOT NULL\n              AND w.start_date <= :period_end\n              AND w.end_date >= :period_start\n              AND COALESCE(w.payment_status, 'unpaid') <> 'cancelled'\n            ORDER BY w.start_date ASC, p.project_name ASC, w.id ASC\n        ");
        $stmt->execute([
            ':employee_id' => $employeeId,
            ':period_start' => $periodStart,
            ':period_end' => $periodEnd,
        ]);

        $allocations = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $start = max(strtotime($row['start_date']), strtotime($periodStart));
            $end = min(strtotime($row['end_date']), strtotime($periodEnd));
            $overlapStart = date('Y-m-d', $start);
            $overlapEnd = date('Y-m-d', $end);
            $schedule = payroll_get_project_schedule($row);
            $days = payroll_count_work_days_between($overlapStart, $overlapEnd, $schedule);

            if ($days <= 0) {
                continue;
            }

            $rate = (float)($row['rate'] ?? 0);
            $paymentType = $row['payment_type'] ?? 'daily';
            $amount = $paymentType === 'borongan' ? $rate : ($days * $rate);

            $allocations[] = [
                'project_worker_id' => (int)$row['id'],
                'project_id' => (int)$row['project_id'],
                'project_name' => $row['project_name'] ?? '-',
                'project_code' => $row['project_code'] ?? '-',
                'client_name' => $row['client_name'] ?? '-',
                'period_start' => $overlapStart,
                'period_end' => $overlapEnd,
                'work_days' => $days,
                'rate' => $rate,
                'amount' => $amount,
                'payment_type' => $paymentType,
                'notes' => $row['notes'] ?? null,
            ];
        }

        return $allocations;
    }
}

if (!function_exists('payroll_summarize_project_allocations')) {
    function payroll_summarize_project_allocations(array $allocations): array
    {
        $days = 0;
        $amount = 0;
        $projectIds = [];
        foreach ($allocations as $allocation) {
            $days += (float)($allocation['work_days'] ?? 0);
            $amount += (float)($allocation['amount'] ?? 0);
            if (!empty($allocation['project_id'])) {
                $projectIds[(int)$allocation['project_id']] = true;
            }
        }
        return [
            'project_work_days' => $days,
            'project_worker_amount' => $amount,
            'project_count' => count($projectIds),
        ];
    }
}

if (!function_exists('payroll_sync_project_allocations')) {
    function payroll_sync_project_allocations(PDO $pdo, int $payrollId, int $employeeId, array $allocations): void
    {
        if ($payrollId <= 0 || !payroll_workforce_schema_ready($pdo)) {
            return;
        }

        $delete = $pdo->prepare("DELETE FROM payroll_project_allocations WHERE payroll_id = :payroll_id");
        $delete->execute([':payroll_id' => $payrollId]);

        $insert = $pdo->prepare("\n            INSERT INTO payroll_project_allocations\n            (payroll_id, employee_id, project_id, project_worker_id, period_start, period_end, work_days, rate, amount, notes, created_at)\n            VALUES\n            (:payroll_id, :employee_id, :project_id, :project_worker_id, :period_start, :period_end, :work_days, :rate, :amount, :notes, NOW())\n        ");

        foreach ($allocations as $allocation) {
            $insert->execute([
                ':payroll_id' => $payrollId,
                ':employee_id' => $employeeId,
                ':project_id' => (int)$allocation['project_id'],
                ':project_worker_id' => (int)$allocation['project_worker_id'],
                ':period_start' => $allocation['period_start'],
                ':period_end' => $allocation['period_end'],
                ':work_days' => (float)$allocation['work_days'],
                ':rate' => (float)$allocation['rate'],
                ':amount' => (float)$allocation['amount'],
                ':notes' => $allocation['notes'] ?: null,
            ]);
        }
    }
}

if (!function_exists('payroll_project_allocation_text')) {
    function payroll_project_allocation_text(array $allocations): string
    {
        if (!$allocations) {
            return '';
        }
        $parts = [];
        foreach ($allocations as $a) {
            $parts[] = ($a['project_name'] ?? '-') . ' ' . rtrim(rtrim(number_format((float)$a['work_days'], 2, ',', '.'), '0'), ',') . ' hari x Rp ' . number_format((float)$a['rate'], 0, ',', '.');
        }
        return implode('; ', $parts);
    }
}
