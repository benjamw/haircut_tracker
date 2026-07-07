<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Support\Json;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Admin view of booked appointments. */
final class AppointmentController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /**
     * GET /admin/appointments
     * Returns two groups:
     *  - upcoming: confirmed, still in the future.
     *  - to_record: confirmed appointments whose time has passed and haven't
     *    been logged as a haircut yet (barber records amount paid + notes).
     */
    public function list(Request $request, Response $response): Response
    {
        $upcoming = $this->db->query(
            "SELECT a.*, p.display_name AS person_name
             FROM appointments a LEFT JOIN users p ON p.user_id = a.user_id
             WHERE a.status = 'confirmed' AND a.slot_start >= NOW()
             ORDER BY a.slot_start ASC"
        )->fetchAll();

        $toRecord = $this->db->query(
            "SELECT a.*, p.display_name AS person_name
             FROM appointments a LEFT JOIN users p ON p.user_id = a.user_id
             WHERE a.status = 'confirmed' AND a.slot_start < NOW()
             ORDER BY a.slot_start DESC"
        )->fetchAll();

        return Json::write($response, [
            'upcoming'  => array_map([$this, 'shape'], $upcoming),
            'to_record' => array_map([$this, 'shape'], $toRecord),
        ]);
    }

    /**
     * POST /admin/appointments/{id}/record { amount_cents, notes }
     * Turns a past appointment into a haircut record and marks it completed.
     */
    public function record(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $stmt = $this->db->prepare("SELECT * FROM appointments WHERE appointment_id = ?");
        $stmt->execute([$id]);
        $appt = $stmt->fetch();

        if ($appt === false || $appt['status'] !== 'confirmed') {
            return Json::error($response, 'Appointment not found', 404);
        }
        if ($appt['user_id'] === null) {
            return Json::error($response, 'Appointment has no linked client to record against', 422);
        }

        $b = (array) $request->getParsedBody();
        $amount = (int) ($b['amount_cents'] ?? 0);
        $notes  = trim((string) ($b['notes'] ?? ''));

        $this->db->prepare(
            'INSERT INTO haircuts (user_id, haircut_date, haircut_time, amount_cents, notes, created_by)
             VALUES (:pid, DATE(:start), TIME(:start2), :amt, :notes, :by)'
        )->execute([
            'pid' => (int) $appt['user_id'],
            'start' => $appt['slot_start'],
            'start2' => $appt['slot_start'],
            'amt' => $amount,
            'notes' => $notes !== '' ? $notes : null,
            'by' => 'admin',
        ]);
        $haircutId = (int) $this->db->lastInsertId(); // capture before the UPDATE resets it

        $this->db->prepare("UPDATE appointments SET status = 'completed' WHERE appointment_id = ?")->execute([$id]);

        return Json::write($response, ['ok' => true, 'haircut_id' => $haircutId], 201);
    }

    /** POST /admin/appointments/{id}/cancel */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $stmt = $this->db->prepare(
            "UPDATE appointments SET status = 'cancelled', hold_expires_at = NULL WHERE appointment_id = ?"
        );
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            return Json::error($response, 'Appointment not found', 404);
        }
        return Json::write($response, ['ok' => true]);
    }

    private function shape(array $a): array
    {
        return [
            'appointment_id' => (int) $a['appointment_id'],
            'slot_start'    => $a['slot_start'],
            'slot_end'      => $a['slot_end'],
            'status'        => $a['status'],
            'user_id'       => $a['user_id'] !== null ? (int) $a['user_id'] : null,
            'person_name'   => $a['person_name'] ?? $a['contact_name'],
            'contact_name'  => $a['contact_name'],
            'contact_email' => $a['contact_email'],
            'contact_phone' => $a['contact_phone'],
            'notify_channel' => $a['notify_channel'],
        ];
    }
}
