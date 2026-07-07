import { useEffect, useState } from 'react';
import { api, type Person } from '../lib/api';
import { dueLabel, dueTone, money, shortDate } from '../lib/format';

// Sort order: active clients (A–Z), then the admin/barber, then inactive last.
function rank(p: Person): number {
  if (p.inactive) return 2;
  if (p.is_admin) return 1;
  return 0;
}
function rosterOrder(a: Person, b: Person): number {
  return rank(a) - rank(b) || a.display_name.localeCompare(b.display_name);
}

// People the barber keeps tabs on.
export function Roster({
  onOpenPerson,
  onAddPerson,
  reloadKey,
}: {
  onOpenPerson: (id: number) => void;
  onAddPerson: () => void;
  reloadKey: number;
}) {
  const [people, setPeople] = useState<Person[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    setError(null);
    api.listPersons()
      .then((r) => setPeople([...r.persons].sort(rosterOrder)))
      .catch((e) => setError(String(e.message ?? e)));
  }, [reloadKey]);

  return (
    <>
      <div className="row">
        <div className="section-title">People</div>
        <button className="btn small primary" onClick={onAddPerson}>+ Add</button>
      </div>

      {error && <div className="error">{error}</div>}

      <div className="list">
        {people?.map((p) => (
          <div key={p.user_id} className="card tappable" onClick={() => onOpenPerson(p.user_id)}>
            <div className="row">
              <div>
                <div className="name">{p.display_name}</div>
                <div className="sub">
                  {p.stats.cut_count} cut{p.stats.cut_count === 1 ? '' : 's'} · last {shortDate(p.stats.last_cut)}
                  {' · '}{money(p.total_spent_cents)}
                </div>
              </div>
              {p.inactive
                ? <span className="badge none">Inactive</span>
                : <span className={`badge ${dueTone(p.stats.overdue_by_days)}`}>
                    {dueLabel(p.stats.overdue_by_days)}
                  </span>}
            </div>
          </div>
        ))}
        {people && people.length === 0 && (
          <div className="card center muted">No people yet. Tap “Add”.</div>
        )}
      </div>
    </>
  );
}
