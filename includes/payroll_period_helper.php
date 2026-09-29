<?php

/**
 * Menghasilkan periode payroll berdasarkan bulan tanggal tutup buku.
 *
 * Contoh konfigurasi 16-15:
 * payroll September 2026 = 16 Agustus 2026 s.d. 15 September 2026.
 */
function payroll_date_range(string $month, array $setting): array
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        throw new InvalidArgumentException('Bulan payroll tidak valid.');
    }

    $startDay = (int)($setting['start_day'] ?? 16);
    $endDay = (int)($setting['end_day'] ?? 15);
    $monthStart = $month . '-01';

    if ($startDay === 1) {
        $startMonth = $month;
    } else {
        $startMonth = date('Y-m', strtotime($monthStart . ' -1 month'));
    }

    $startDay = min($startDay, (int)date('t', strtotime($startMonth . '-01')));
    $endDay = min($endDay, (int)date('t', strtotime($monthStart)));

    return [
        sprintf('%s-%02d', $startMonth, $startDay),
        sprintf('%s-%02d', $month, $endDay),
    ];
}

/** Pastikan dua periode payroll bulanan berurutan tidak memiliki celah/duplikasi. */
function payroll_period_is_contiguous(int $startDay, int $endDay): bool
{
    return $startDay === 1 ? $endDay === 31 : $startDay === $endDay + 1;
}

/** Daftar bulan payroll yang konsisten di semua form, termasuk browser tanpa input month yang baik. */
function payroll_month_options(string $selectedMonth, int $monthsBack = 24, int $monthsForward = 12): array
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $selectedMonth)) {
        $selectedMonth = date('Y-m');
    }
    $anchor = new DateTimeImmutable($selectedMonth . '-01');
    $options = [];
    for ($offset = -max(0, $monthsBack); $offset <= max(0, $monthsForward); $offset++) {
        $date = $anchor->modify(($offset >= 0 ? '+' : '') . $offset . ' months');
        $value = $date->format('Y-m');
        $options[$value] = date('M Y', $date->getTimestamp());
    }
    return $options;
}
