<?php
if (!defined('ABSPATH')) exit;

/**
 * Date logic. Everything counts from the purchase date:
 *  - warranty end = purchase date + warranty years (default 12)
 *  - Service milestones every N years (default 3): years 3, 6, 9 (the 12th year is the expiry itself)
 *  - each milestone has reminder steps (default -60, -30, 0, +30 days) and a lock step (default +45)
 */
final class TW_Schedule {

    public static function add_years($date, $years) {
        return (new DateTimeImmutable($date))->modify(sprintf('%+d years', (int) $years))->format('Y-m-d');
    }

    public static function add_days($date, $days) {
        $d = (int) $days;
        return (new DateTimeImmutable($date))->modify(($d >= 0 ? '+' : '') . $d . ' days')->format('Y-m-d');
    }

    /** Whole days from $from to $to (positive when $to is later). */
    public static function diff_days($from, $to) {
        $a = new DateTimeImmutable($from);
        $b = new DateTimeImmutable($to);
        return (int) $a->diff($b)->format('%r%a');
    }

    public static function today() {
        return current_time('Y-m-d');
    }

    public static function warranty_end($purchase_date) {
        return self::add_years($purchase_date, (int) TW_Settings::get('warranty_years'));
    }

    /**
     * @return array[] each: key, type (service|expiry), no, years, due
     */
    public static function milestones($purchase_date, $warranty_end = null) {
        $years = max(1, (int) TW_Settings::get('warranty_years'));
        $interval = (int) TW_Settings::get('service_interval_years');
        $out = [];
        if ($interval > 0) {
            for ($n = 1; $n * $interval < $years; $n++) {
                $out[] = [
                    'key' => 'service_' . $n,
                    'type' => 'service',
                    'no' => $n,
                    'years' => $n * $interval,
                    'due' => self::add_years($purchase_date, $n * $interval),
                ];
            }
        }
        $out[] = [
            'key' => 'expiry',
            'type' => 'expiry',
            'no' => 0,
            'years' => $years,
            'due' => $warranty_end ?: self::add_years($purchase_date, $years),
        ];
        return $out;
    }

    /** Which template stage an offset belongs to. */
    public static function stage($offset) {
        $lock = (int) TW_Settings::get('lock_offset');
        if ($offset >= $lock) return 'locked';
        if ($offset < 0) return 'before';
        if ($offset === 0) return 'today';
        return 'after';
    }

    /** Steps (offsets) of a milestone including the lock step, ascending. */
    public static function steps() {
        $steps = TW_Settings::reminder_offsets();
        $steps[] = (int) TW_Settings::get('lock_offset');
        return $steps;
    }

    /**
     * Human-readable list of the Service dates for emails.
     */
    public static function service_schedule_text($purchase_date) {
        $lines = [];
        foreach (self::milestones($purchase_date) as $m) {
            if ($m['type'] === 'service') {
                $lines[] = sprintf(tw__('- %1$s Service: %2$s (%3$d χρόνια από την αγορά)'), TW_I18n::ordinal($m['no']), TW_Util::date_label($m['due']), $m['years']);
            }
        }
        return $lines ? implode("\n", $lines) : '-';
    }
}
