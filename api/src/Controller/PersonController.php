<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Service\Cadence;
use App\Support\Json;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin-facing person CRUD. These responses include payment amounts and
 * contact info, so the routes MUST stay behind the admin guard.
 */
final class PersonController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /** GET /admin/persons — roster with cadence stats + totals. */
    public function list(Request $request, Response $response): Response
    {
        $persons = $this->db
            ->query('SELECT * FROM users WHERE merged_into_id IS NULL ORDER BY display_name')
            ->fetchAll();

        $byId = [];
        foreach ($persons as $p) {
            $byId[(int) $p['user_id']] = ['dates' => [], 'total' => 0];
        }

        if ($byId) {
            $ids = implode(',', array_keys($byId));
            $rows = $this->db
                ->query("SELECT user_id, haircut_date, amount_cents FROM haircuts WHERE user_id IN ($ids)")
                ->fetchAll();
            foreach ($rows as $r) {
                $pid = (int) $r['user_id'];
                $byId[$pid]['dates'][] = $r['haircut_date'];
                $byId[$pid]['total']  += (int) $r['amount_cents'];
            }
        }

        $out = [];
        foreach ($persons as $p) {
            $pid = (int) $p['user_id'];
            $out[] = $this->shape($p, [
                'stats'             => Cadence::compute($byId[$pid]['dates'], self::override($p)),
                'total_spent_cents' => $byId[$pid]['total'],
                'is_admin'          => $p['role'] === 'admin',
            ]);
        }

        return Json::write($response, ['persons' => $out]);
    }

    /** GET /admin/persons/{id} — full detail incl. haircut history. */
    public function detail(Request $request, Response $response, array $args): Response
    {
        $person = $this->find((int) $args['id']);
        if ($person === null) {
            return Json::error($response, 'Person not found', 404);
        }

        $stmt = $this->db->prepare(
            'SELECT haircut_id, haircut_date, haircut_time, amount_cents, notes, created_by, created_at
             FROM haircuts WHERE user_id = ? ORDER BY haircut_date DESC, haircut_time DESC'
        );
        $stmt->execute([$person['user_id']]);
        $haircuts = $stmt->fetchAll();

        $dates = array_map(static fn($h) => $h['haircut_date'], $haircuts);
        $total = array_sum(array_map(static fn($h) => (int) $h['amount_cents'], $haircuts));

        // The login account is now the same row — present only if it has a username.
        $account = $person['username'] === null ? null : [
            'user_id'  => (int) $person['user_id'],
            'username' => $person['username'],
            'status'   => $person['status'],
            'role'     => $person['role'],
        ];

        return Json::write($response, $this->shape($person, [
            'stats'             => Cadence::compute($dates, self::override($person)),
            'total_spent_cents' => $total,
            'haircuts'          => array_map([$this, 'shapeHaircut'], $haircuts),
            'account'           => $account,
        ]));
    }

    /** POST /admin/persons */
    public function create(Request $request, Response $response): Response
    {
        $b = (array) $request->getParsedBody();

        $name = trim((string) ($b['display_name'] ?? ''));
        if ($name === '') {
            return Json::error($response, 'display_name is required', 422);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO users (display_name, email, phone, carrier_id, usual_cadence_days,
                                preferred_channel, notify_opt_out, notes)
             VALUES (:name, :email, :phone, :carrier_id, :cadence, :channel, :opt_out, :notes)'
        );
        $stmt->execute($this->bindFields($b, $name));

        $id = (int) $this->db->lastInsertId();
        return $this->detail($request, $response->withStatus(201), ['id' => $id]);
    }

    /** PATCH /admin/persons/{id} */
    public function update(Request $request, Response $response, array $args): Response
    {
        $person = $this->find((int) $args['id']);
        if ($person === null) {
            return Json::error($response, 'Person not found', 404);
        }

        $b = (array) $request->getParsedBody();
        $fields = [
            'display_name', 'email', 'phone', 'carrier_id',
            'usual_cadence_days', 'preferred_channel', 'notify_opt_out', 'inactive', 'notes',
        ];

        $set = [];
        $params = ['id' => $person['user_id']];
        foreach ($fields as $f) {
            if (array_key_exists($f, $b)) {
                $set[] = "$f = :$f";
                $params[$f] = $this->normalize($f, $b[$f]);
            }
        }

        if ($set) {
            $sql = 'UPDATE users SET ' . implode(', ', $set) . ' WHERE user_id = :id';
            $this->db->prepare($sql)->execute($params);
        }

        return $this->detail($request, $response, ['id' => (int) $person['user_id']]);
    }

    /**
     * POST /admin/persons/merge { source_id, target_id }
     * Fold `source` into `target`: move haircuts + appointments (and any user
     * account), backfill missing contact info, and mark source merged. Non-
     * destructive — source stays as a tombstone with merged_into_id set, so it
     * drops out of all merged_into_id IS NULL queries.
     */
    public function merge(Request $request, Response $response): Response
    {
        $b = (array) $request->getParsedBody();
        $sourceId = (int) ($b['source_id'] ?? 0);
        $targetId = (int) ($b['target_id'] ?? 0);

        if ($sourceId === 0 || $targetId === 0 || $sourceId === $targetId) {
            return Json::error($response, 'source_id and target_id must be two different people', 422);
        }
        $source = $this->find($sourceId);
        $target = $this->find($targetId);
        if ($source === null || $target === null) {
            return Json::error($response, 'Person not found', 404);
        }
        if ($source['merged_into_id'] !== null || $target['merged_into_id'] !== null) {
            return Json::error($response, 'Cannot merge an already-merged person', 409);
        }

        try {
            $this->db->beginTransaction();
            // Move the source's data onto the target.
            $this->db->prepare('UPDATE haircuts SET user_id = :t WHERE user_id = :s')
                ->execute(['t' => $targetId, 's' => $sourceId]);
            $this->db->prepare('UPDATE appointments SET user_id = :t WHERE user_id = :s')
                ->execute(['t' => $targetId, 's' => $sourceId]);
            $this->db->prepare('UPDATE reminders SET user_id = :t WHERE user_id = :s')
                ->execute(['t' => $targetId, 's' => $sourceId]);
            // Backfill target contact/notes from source where empty.
            $this->db->prepare(
                'UPDATE users SET
                    email = COALESCE(email, :e), phone = COALESCE(phone, :p),
                    carrier_id = COALESCE(carrier_id, :c), notes = COALESCE(notes, :n)
                 WHERE user_id = :id'
            )->execute([
                'e' => $source['email'], 'p' => $source['phone'],
                'c' => $source['carrier_id'], 'n' => $source['notes'], 'id' => $targetId,
            ]);
            $this->db->prepare('UPDATE users SET merged_into_id = :t WHERE user_id = :s')
                ->execute(['t' => $targetId, 's' => $sourceId]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[persons/merge] failed: ' . $e->getMessage());
            return Json::error($response, 'Merge failed', 500);
        }

        return $this->detail($request, $response, ['id' => $targetId]);
    }

    /** DELETE /admin/persons/{id} (cascades haircuts). */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $person = $this->find((int) $args['id']);
        if ($person === null) {
            return Json::error($response, 'Person not found', 404);
        }
        $this->db->prepare('DELETE FROM users WHERE user_id = ?')->execute([$person['user_id']]);
        return $response->withStatus(204);
    }

    // ---- helpers ----

    private function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE user_id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private static function override(array $p): ?int
    {
        return $p['usual_cadence_days'] !== null ? (int) $p['usual_cadence_days'] : null;
    }

    /** @param array<string,mixed> $extra */
    private function shape(array $p, array $extra = []): array
    {
        return array_merge([
            'user_id'            => (int) $p['user_id'],
            'display_name'       => $p['display_name'],
            'email'              => $p['email'],
            'phone'              => $p['phone'],
            'carrier_id'         => $p['carrier_id'] !== null ? (int) $p['carrier_id'] : null,
            'usual_cadence_days' => $p['usual_cadence_days'] !== null ? (int) $p['usual_cadence_days'] : null,
            'preferred_channel'  => $p['preferred_channel'],
            'notify_opt_out'     => (bool) $p['notify_opt_out'],
            'inactive'           => (bool) $p['inactive'],
            'last_contacted_at'  => $p['last_contacted_at'],
            'notes'              => $p['notes'],
            'created_at'         => $p['created_at'],
        ], $extra);
    }

    private function shapeHaircut(array $h): array
    {
        return [
            'haircut_id'   => (int) $h['haircut_id'],
            'haircut_date' => $h['haircut_date'],
            'haircut_time' => $h['haircut_time'],
            'amount_cents' => (int) $h['amount_cents'],
            'notes'        => $h['notes'],
            'created_by'   => $h['created_by'],
            'created_at'   => $h['created_at'],
        ];
    }

    /** @return array<string,mixed> */
    private function bindFields(array $b, string $name): array
    {
        return [
            'name'       => $name,
            'email'      => $this->normalize('email', $b['email'] ?? null),
            'phone'      => $this->normalize('phone', $b['phone'] ?? null),
            'carrier_id' => $this->normalize('carrier_id', $b['carrier_id'] ?? null),
            'cadence'    => $this->normalize('usual_cadence_days', $b['usual_cadence_days'] ?? null),
            'channel'    => $this->normalize('preferred_channel', $b['preferred_channel'] ?? 'sms'),
            'opt_out'    => $this->normalize('notify_opt_out', $b['notify_opt_out'] ?? false),
            'notes'      => $this->normalize('notes', $b['notes'] ?? null),
        ];
    }

    private function normalize(string $field, mixed $value): mixed
    {
        return match ($field) {
            'carrier_id', 'usual_cadence_days' =>
                ($value === null || $value === '') ? null : (int) $value,
            'notify_opt_out', 'inactive' => (int) (bool) $value,
            'preferred_channel' => in_array($value, ['email', 'sms'], true) ? $value : 'sms',
            'email', 'phone', 'notes', 'display_name' =>
                ($value === null || $value === '') ? null : trim((string) $value),
            default => $value,
        };
    }
}
