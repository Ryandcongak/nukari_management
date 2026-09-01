<?php
function attendance_time(?string $value): ?string {
    $value = trim((string)$value);
    if ($value === '') return null;
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value)) throw new InvalidArgumentException('Format jam tidak valid.');
    return strlen($value) === 5 ? $value . ':00' : $value;
}
function attendance_minutes(?string $start, ?string $end): int {
    if (!$start || !$end) return 0;
    $a = strtotime('2000-01-01 '.$start); $b = strtotime('2000-01-01 '.$end);
    if ($b <= $a) throw new InvalidArgumentException('Jam keluar harus lebih besar daripada jam masuk.');
    return (int)(($b-$a)/60);
}
function attendance_calculate(array $input): array {
    $times=[];
    foreach (['morning_in','morning_out','afternoon_in','afternoon_out','overtime_in','overtime_out'] as $field) $times[$field]=attendance_time($input[$field] ?? null);
    $work=attendance_minutes($times['morning_in'],$times['morning_out'])+attendance_minutes($times['afternoon_in'],$times['afternoon_out'])+attendance_minutes($times['overtime_in'],$times['overtime_out']);
    $late=$times['morning_in'] && $times['morning_in']>'08:00:00' ? attendance_minutes('08:00:00',$times['morning_in']) : 0;
    $early=$times['afternoon_out'] && $times['afternoon_out']<'17:00:00' ? attendance_minutes($times['afternoon_out'],'17:00:00') : 0;
    $requested=$input['status'] ?? 'present';
    $status=in_array($requested,['permission','sick','absent'],true)?$requested:($late>0?'late':'present');
    if ($status==='absent' && array_filter($times)) throw new InvalidArgumentException('Status Alpha tidak boleh memiliki jam absensi.');
    $checkIn=$times['morning_in'] ?: ($times['afternoon_in'] ?: $times['overtime_in']);
    $checkOut=$times['overtime_out'] ?: ($times['afternoon_out'] ?: $times['morning_out']);
    return $times+['check_in'=>$checkIn,'check_out'=>$checkOut,'late_minutes'=>$late,'early_leave_minutes'=>$early,'work_minutes'=>$work,'status'=>$status];
}
