<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Support\Json;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin management of registered user accounts (block / remove). Blocking is
 * enforced immediately: UserAuth rejects any account whose status != active.
 * Removing deletes the account but keeps the person + their haircut history.
 */
final class UserAdminController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /** GET /admin/users */
    public function list(Request $request, Response $response): Response
    {
        // Only rows that actually have a login.
        $rows = $this->db->query(
            "SELECT user_id, username, status, role, display_name
             FROM users WHERE username IS NOT NULL ORDER BY username"
        )->fetchAll();

        return Json::write($response, ['users' => array_map([$this, 'shape'], $rows)]);
    }

    /** PATCH /admin/users/{id} { status: active|blocked } */
    public function updateStatus(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $status = ((array) $request->getParsedBody())['status'] ?? '';
        if (!in_array($status, ['active', 'blocked'], true)) {
            return Json::error($response, 'status must be active or blocked', 422);
        }

        // Never let the last active admin be blocked/locked out.
        if ($status === 'blocked' && $this->isLastActiveAdmin($id)) {
            return Json::error($response, 'Cannot block the last active admin', 409);
        }

        $stmt = $this->db->prepare('UPDATE users SET status = :s WHERE user_id = :id');
        $stmt->execute(['s' => $status, 'id' => $id]);
        if ($stmt->rowCount() === 0) {
            // status may already match; confirm the user exists
            if (!$this->exists($id)) {
                return Json::error($response, 'User not found', 404);
            }
        }
        return Json::write($response, ['ok' => true, 'status' => $status]);
    }

    /**
     * DELETE /admin/users/{id} — remove the LOGIN, keep the person + history.
     * (One table now, so we strip credentials rather than delete the row.)
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if (!$this->exists($id)) {
            return Json::error($response, 'User not found', 404);
        }
        if ($this->isLastActiveAdmin($id)) {
            return Json::error($response, 'Cannot remove the last active admin', 409);
        }
        // Drop passkeys, then clear the login fields (row + history stay).
        $this->db->prepare('DELETE FROM credentials WHERE user_id = ?')->execute([$id]);
        $this->db->prepare(
            "UPDATE users SET username = NULL, password_hash = NULL, webauthn_challenge = NULL,
                              role = 'user', status = 'active'
             WHERE user_id = ?"
        )->execute([$id]);
        return $response->withStatus(204);
    }

    private function isLastActiveAdmin(int $id): bool
    {
        $r = $this->db->prepare("SELECT role, status FROM users WHERE user_id = ?");
        $r->execute([$id]);
        $u = $r->fetch();
        if ($u === false || $u['role'] !== 'admin' || $u['status'] !== 'active') {
            return false;
        }
        $count = (int) $this->db->query("SELECT COUNT(*) FROM users WHERE role='admin' AND status='active'")->fetchColumn();
        return $count <= 1;
    }

    private function exists(int $id): bool
    {
        $s = $this->db->prepare('SELECT 1 FROM users WHERE user_id = ?');
        $s->execute([$id]);
        return (bool) $s->fetchColumn();
    }

    private function shape(array $u): array
    {
        return [
            'user_id' => (int) $u['user_id'],
            'username' => $u['username'],
            'status' => $u['status'],
            'role' => $u['role'],
            'display_name' => $u['display_name'],
        ];
    }
}
