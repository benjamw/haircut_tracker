<?php

declare(strict_types=1);

namespace App\Service;

use App\Database;
use DateInterval;
use DateTimeImmutable;
use PDO;

/**
 * Enumerates bookable slots from the barber's availability windows, minus any
 * slot that's already taken. A slot is "taken" by a confirmed appointment or
 * by a still-live hold (held/pending_verify with hold_expires_at in the future).
 * Expired holds free their slot automatically.
 */
final class SlotService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return list<array{date:string, weekday:int, slots:list<array{start:string,end:string,label:string}>}>
     */
    public function availableDays(int $days = 14): array
    {
        $days = max(1, min($days, 60));

        // Availability windows keyed by weekday (0=Sun..6=Sat).
        $windows = [];
        foreach ($this->db->query('SELECT * FROM availability WHERE active = 1')->fetchAll() as $w) {
            $windows[(int) $w['weekday']][] = $w;
        }

        $liveAppts  = $this->liveAppointments();
        $exceptions = $this->exceptions($days);

        $now   = new DateTimeImmutable('now');
        $today = new DateTimeImmutable('today');
        $out   = [];

        for ($d = 0; $d < $days; $d++) {
            $day     = $today->add(new DateInterval("P{$d}D"));
            $dateStr = $day->format('Y-m-d');
            $weekday = (int) $day->format('w');

            [$dayWindows, $blocks] = $this->windowsForDate($dateStr, $windows[$weekday] ?? [], $exceptions);
            if (empty($dayWindows)) {
                continue; // no recurring/custom hours, or a full-day block
            }

            $slots = [];
            foreach ($dayWindows as $w) {
                $step  = (int) ($w['slot_minutes'] ?: 60);
                $start = new DateTimeImmutable($dateStr . ' ' . $w['start_time']);
                $end   = new DateTimeImmutable($dateStr . ' ' . $w['end_time']);

                for ($t = $start; $t < $end; $t = $t->add(new DateInterval("PT{$step}M"))) {
                    if ($t <= $now) {
                        continue; // no past slots
                    }
                    $slotEnd = $t->add(new DateInterval("PT{$step}M"));

                    if ($this->overlapsLive($t, $slotEnd, $liveAppts)) {
                        continue; // a live booking/hold overlaps this interval
                    }
                    if ($this->inBlockedWindow($t, $blocks)) {
                        continue; // inside a timed block exception
                    }
                    $slots[] = [
                        'start' => $t->format('Y-m-d H:i:s'),
                        'end'   => $slotEnd->format('Y-m-d H:i:s'),
                        'label' => $t->format('g:i A'),
                    ];
                }
            }

            if ($slots) {
                $out[] = [
                    'date'    => $day->format('Y-m-d'),
                    'weekday' => $weekday,
                    'slots'   => $slots,
                ];
            }
        }

        return $out;
    }

    /**
     * Return the open slot matching a slot_start (with its computed end), or
     * null if that slot isn't currently bookable. Used by booking to
     * re-validate before creating a hold.
     *
     * @return array{start:string,end:string,label:string}|null
     *
     * NOTE: re-enumerates up to 60 days of slots to validate one — acceptable
     * for this volume; if bookings scale up, validate against a targeted query
     * for the specific date instead.
     */
    public function find(string $slotStart): ?array
    {
        foreach ($this->availableDays(60) as $day) {
            foreach ($day['slots'] as $s) {
                if ($s['start'] === $slotStart) {
                    return $s;
                }
            }
        }
        return null;
    }

    /** Load exceptions overlapping the [today, today+days] range. */
    private function exceptions(int $days): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM schedule_exceptions
             WHERE end_date >= CURDATE() AND start_date <= DATE_ADD(CURDATE(), INTERVAL :d DAY)'
        );
        $stmt->execute(['d' => $days]);
        return $stmt->fetchAll();
    }

    /**
     * Resolve the effective windows for a date and collect any timed blocks.
     * A "custom" exception replaces the recurring hours for that date; a
     * full-day "block" clears the day entirely.
     *
     * @return array{0: list<array>, 1: list<array{start:string,end:string}>}
     */
    private function windowsForDate(string $dateStr, array $recurring, array $exceptions): array
    {
        $custom = [];
        $timedBlocks = [];
        $fullDayBlock = false;

        foreach ($exceptions as $e) {
            if ($dateStr < $e['start_date'] || $dateStr > $e['end_date']) {
                continue;
            }
            if ($e['kind'] === 'custom') {
                $custom[] = [
                    'start_time'   => $e['start_time'] ?? '10:00:00',
                    'end_time'     => $e['end_time'] ?? '16:00:00',
                    'slot_minutes' => $e['slot_minutes'] ?? 60,
                ];
            } elseif ($e['kind'] === 'block') {
                if ((int) $e['all_day'] === 1 || !$e['start_time'] || !$e['end_time']) {
                    $fullDayBlock = true;
                } else {
                    $timedBlocks[] = [
                        'start' => $dateStr . ' ' . $e['start_time'],
                        'end'   => $dateStr . ' ' . $e['end_time'],
                    ];
                }
            }
        }

        if ($fullDayBlock) {
            return [[], []];
        }

        // Custom hours override the recurring schedule for this date.
        $windows = $custom !== [] ? $custom : $recurring;
        return [$windows, $timedBlocks];
    }

    /** @param list<array{start:string,end:string}> $blocks */
    private function inBlockedWindow(DateTimeImmutable $slotStart, array $blocks): bool
    {
        foreach ($blocks as $b) {
            $bStart = new DateTimeImmutable($b['start']);
            $bEnd   = new DateTimeImmutable($b['end']);
            if ($slotStart >= $bStart && $slotStart < $bEnd) {
                return true;
            }
        }
        return false;
    }

    /**
     * Live appointments as [start,end) intervals — a confirmed booking or a
     * hold that hasn't expired. Returned as DateTimeImmutable pairs so slots
     * are blocked by interval OVERLAP, not slot_start equality (which breaks
     * when the slot grid changes, e.g. 60→45 min).
     *
     * @return list<array{start:DateTimeImmutable,end:DateTimeImmutable}>
     */
    private function liveAppointments(): array
    {
        $rows = $this->db->query(
            "SELECT slot_start, slot_end FROM appointments
             WHERE status = 'confirmed'
                OR (status IN ('held','pending_verify') AND hold_expires_at > NOW())"
        )->fetchAll();

        return array_map(static fn($r) => [
            'start' => new DateTimeImmutable($r['slot_start']),
            'end'   => new DateTimeImmutable($r['slot_end']),
        ], $rows);
    }

    /** @param list<array{start:DateTimeImmutable,end:DateTimeImmutable}> $live */
    private function overlapsLive(DateTimeImmutable $slotStart, DateTimeImmutable $slotEnd, array $live): bool
    {
        foreach ($live as $a) {
            if ($slotStart < $a['end'] && $slotEnd > $a['start']) {
                return true;
            }
        }
        return false;
    }
}
