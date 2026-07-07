import { useEffect, useState } from 'react';
import { api, type DueItem } from '../lib/api';
import { dueLabel, dueTone, shortDate } from '../lib/format';

// Headline feature: who's due for their usual next cut.
// Barber-facing only — surfaces contact info so he texts people himself.
export function DueList({ onOpenPerson }: { onOpenPerson: (id: number) => void }) {
  const [items, setItems] = useState<DueItem[] | null>(null);
  const [within, setWithin] = useState(7);
  const [error, setError] = useState<string | null>(null);

  function load(days: number) {
    setError(null);
    api.due(days).then((r) => setItems(r.due)).catch((e) => setError(String(e.message ?? e)));
  }

  useEffect(() => { load(within); }, [within]);

  async function contacted(id: number) {
    await api.markContacted(id);
    load(within);
  }

  function reachOut(p: DueItem) {
    // Prefer SMS on mobile; fall back to email.
    if (p.preferred_channel === 'sms' && p.phone) {
      window.location.href = `sms:${p.phone}`;
    } else if (p.email) {
      window.location.href = `mailto:${p.email}`;
    }
  }

  return (
    <>
      <div className="row">
        <div className="section-title">Reach out — longest since last cut</div>
        <select
          value={within}
          onChange={(e) => setWithin(Number(e.target.value))}
          style={{ width: 'auto' }}
          aria-label="Window"
        >
          <option value={0}>Due now</option>
          <option value={7}>Within 7 days</option>
          <option value={14}>Within 14 days</option>
          <option value={30}>Within 30 days</option>
        </select>
      </div>

      {error && <div className="error">{error}</div>}
      {items && items.length === 0 && (
        <div className="card center muted">All caught up 💈<div className="sub" style={{ marginTop: 6 }}>People you've contacted or who have an appointment are hidden.</div></div>
      )}

      <div className="list">
        {items?.map((p) => (
          <div key={p.user_id} className="card">
            <div className="row">
              <div onClick={() => onOpenPerson(p.user_id)} style={{ cursor: 'pointer' }}>
                <div className="name">{p.display_name}</div>
                <div className="sub">
                  Usual ~{p.usual_cadence_days}d · last {shortDate(p.last_cut)}
                  {p.days_since_last !== null ? ` (${p.days_since_last}d ago)` : ''}
                  {p.last_contacted_at ? ` · pinged ${shortDate(p.last_contacted_at)}` : ''}
                </div>
              </div>
              <span className={`badge ${dueTone(p.overdue_by_days)}`}>{dueLabel(p.overdue_by_days)}</span>
            </div>
            <div className="btn-row" style={{ marginTop: 12 }}>
              <button className="btn small primary" onClick={() => reachOut(p)}>
                {p.preferred_channel === 'sms' ? '💬 Text' : '✉️ Email'}
              </button>
              <button className="btn small" onClick={() => contacted(p.user_id)}>✓ Mark contacted</button>
            </div>
            {p.notify_opt_out && <div className="sub" style={{ marginTop: 8 }}>⚠️ opted out of app messages</div>}
          </div>
        ))}
      </div>
    </>
  );
}
