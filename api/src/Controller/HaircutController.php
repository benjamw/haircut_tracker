<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Support\Json;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin-facing haircut CRUD (past/current cuts). amount_cents is admin-only.
 */
final class HaircutController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /** POST /admin/persons/{id}/haircuts */
    public function create(Request $request, Response $response, array $args): Response
    {
        $personId = (int) $args['id'];
        if (!$this->personExists($personId)) {
            return Json::error($response, 'Person not found', 404);
        }

        $b = (array) $request->getParsedBody();
        $date = trim((string) ($b['haircut_date'] ?? ''));
        if ($date === '' || !$this->isDate($date)) {
            return Json::error($response, 'haircut_date (YYYY-MM-DD) is required', 422);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO haircuts (user_id, haircut_date, haircut_time, amount_cents, notes, created_by)
             VALUES (:pid, :date, :time, :amount, :notes, :by)'
        );
        $stmt->execute([
            'pid'    => $personId,
            'date'   => $date,
            'time'   => $this->timeOrNull($b['haircut_time'] ?? null),
            'amount' => (int) ($b['amount_cents'] ?? 0),
            'notes'  => $this->strOrNull($b['notes'] ?? null),
            'by'     => 'admin',
        ]);

        return Json::write($response, $this->fetch((int) $this->db->lastInsertId()), 201);
    }

    /** PATCH /admin/haircuts/{hid} */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['hid'];
        if ($this->fetch($id) === null) {
            return Json::error($response, 'Haircut not found', 404);
        }

        $b = (array) $request->getParsedBody();
        $set = [];
        $params = ['id' => $id];

        if (array_key_exists('haircut_date', $b)) {
            if (!$this->isDate((string) $b['haircut_date'])) {
                return Json::error($response, 'Invalid haircut_date', 422);
            }
            $set[] = 'haircut_date = :date';
            $params['date'] = $b['haircut_date'];
        }
        if (array_key_exists('haircut_time', $b)) {
            $set[] = 'haircut_time = :time';
            $params['time'] = $this->timeOrNull($b['haircut_time']);
        }
        if (array_key_exists('amount_cents', $b)) {
            $set[] = 'amount_cents = :amount';
            $params['amount'] = (int) $b['amount_cents'];
        }
        if (array_key_exists('notes', $b)) {
            $set[] = 'notes = :notes';
            $params['notes'] = $this->strOrNull($b['notes']);
        }

        if ($set) {
            $this->db->prepare('UPDATE haircuts SET ' . implode(', ', $set) . ' WHERE haircut_id = :id')
                ->execute($params);
        }

        return Json::write($response, $this->fetch($id));
    }

    /** DELETE /admin/haircuts/{hid} */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['hid'];
        if ($this->fetch($id) === null) {
            return Json::error($response, 'Haircut not found', 404);
        }
        $this->db->prepare('DELETE FROM haircuts WHERE haircut_id = ?')->execute([$id]);
        return $response->withStatus(204);
    }

    // ---- helpers ----

    private function personExists(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM users WHERE user_id = ?');
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }

    private function fetch(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM haircuts WHERE haircut_id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        return [
            'haircut_id'   => (int) $row['haircut_id'],
            'user_id'      => (int) $row['user_id'],
            'haircut_date' => $row['haircut_date'],
            'haircut_time' => $row['haircut_time'],
            'amount_cents' => (int) $row['amount_cents'],
            'notes'        => $row['notes'],
            'created_by'   => $row['created_by'],
            'created_at'   => $row['created_at'],
        ];
    }

    private function isDate(string $d): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
    }

    private function timeOrNull(mixed $t): ?string
    {
        $t = trim((string) $t);
        return $t === '' ? null : $t;
    }

    private function strOrNull(mixed $s): ?string
    {
        $s = trim((string) $s);
        return $s === '' ? null : $s;
    }
}
