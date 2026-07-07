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
 * The headline feature: who is due / overdue for their usual next cut.
 *
 * This is barber-facing only — the app does NOT message the customer here.
 * It surfaces the list (with contact info + preferred channel) so the barber
 * can text people personally from his own phone.
 */
final class DueController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /**
     * GET /admin/due?within=7
     *
     * The barber's home list: people who need a reach-out, ordered by
     * LONGEST since their last cut. Hides anyone who:
     *   - has already been contacted this cycle (last_contacted_at >= last_cut), or
     *   - has an upcoming appointment on the books.
     * `within` includes people coming due within N days (0 = due now only).
     */
    public function list(Request $request, Response $response): Response
    {
        $within = (int) ($request->getQueryParams()['within'] ?? 0); // days ahead to include

        // NOTE: loads the full roster + all haircuts into memory and computes
        // cadence in PHP. Fine for one barber's client list; if this ever grows
        // to many barbers, push the date aggregation into SQL.
        $persons = $this->db
            ->query('SELECT * FROM users WHERE merged_into_id IS NULL')
            ->fetchAll();

        // Gather haircut dates per person in one query.
        $datesByPerson = [];
        $rows = $this->db
            ->query('SELECT user_id, haircut_date FROM haircuts')
            ->fetchAll();
        foreach ($rows as $r) {
            $datesByPerson[(int) $r['user_id']][] = $r['haircut_date'];
        }

        // People with an upcoming appointment -> hidden from the reach-out list.
        $scheduled = [];
        $apptRows = $this->db
            ->query("SELECT DISTINCT user_id FROM appointments
                     WHERE user_id IS NOT NULL
                       AND status IN ('held','pending_verify','confirmed')
                       AND slot_start >= NOW()")
            ->fetchAll();
        foreach ($apptRows as $r) {
            $scheduled[(int) $r['user_id']] = true;
        }

        $due = [];
        foreach ($persons as $p) {
            $pid = (int) $p['user_id'];

            if ((int) $p['inactive'] === 1) {
                continue; // permanently hidden — no longer comes in
            }

            $override = $p['usual_cadence_days'] !== null ? (int) $p['usual_cadence_days'] : null;
            $stats = Cadence::compute($datesByPerson[$pid] ?? [], $override);

            if ($stats['overdue_by_days'] === null) {
                continue; // no cadence yet (< 2 cuts and no override)
            }
            if ($stats['overdue_by_days'] < -$within) {
                continue; // not due yet (outside the window)
            }
            if (isset($scheduled[$pid])) {
                continue; // already has an appointment booked
            }
            if ($this->contactedRecently($p['last_contacted_at'], $stats['last_cut'])) {
                continue; // reached out within the grace window — give them time to reply
            }

            $due[] = [
                'user_id'            => $pid,
                'display_name'       => $p['display_name'],
                'phone'              => $p['phone'],
                'email'              => $p['email'],
                'preferred_channel'  => $p['preferred_channel'],
                'notify_opt_out'     => (bool) $p['notify_opt_out'],
                'last_contacted_at'  => $p['last_contacted_at'],
                'usual_cadence_days' => $stats['usual_cadence_days'],
                'days_since_last'    => $stats['days_since_last'],
                'overdue_by_days'    => $stats['overdue_by_days'],
                'last_cut'           => $stats['last_cut'],
            ];
        }

        // Longest since last cut first.
        usort($due, static fn($a, $b) => ($b['days_since_last'] ?? 0) <=> ($a['days_since_last'] ?? 0));

        return Json::write($response, ['due' => $due, 'within_days' => $within]);
    }

    /** Days to suppress someone after the barber reaches out, before re-adding them. */
    private const CONTACT_GRACE_DAYS = 7;

    /**
     * True if the barber reached out within the grace window (this cycle).
     * After the grace period, if they still haven't come in ("left on read"),
     * they re-appear on the list. A new cut resets everything via cadence.
     */
    private function contactedRecently(?string $lastContactedAt, ?string $lastCut): bool
    {
        if ($lastContactedAt === null) {
            return false;
        }
        $contactedTs = strtotime($lastContactedAt);

        // An old contact from before their last cut belongs to a previous cycle.
        if ($lastCut !== null && $contactedTs < strtotime($lastCut)) {
            return false;
        }

        return (time() - $contactedTs) < self::CONTACT_GRACE_DAYS * 86400;
    }

    /** POST /admin/persons/{id}/mark-contacted — barber texted them himself. */
    public function markContacted(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        // Check existence explicitly — relying on rowCount() would 404 on a
        // harmless double-click within the same second (NOW() unchanged).
        $exists = $this->db->prepare('SELECT 1 FROM users WHERE user_id = ?');
        $exists->execute([$id]);
        if (!$exists->fetchColumn()) {
            return Json::error($response, 'Person not found', 404);
        }

        $this->db->prepare('UPDATE users SET last_contacted_at = NOW() WHERE user_id = ?')->execute([$id]);
        return Json::write($response, ['ok' => true, 'contacted_at' => date('c')]);
    }
}
