import { useEffect, useState } from 'react';
import { api, type Person } from '../lib/api';
import { dueLabel, dueTone, money, phone, shortDate } from '../lib/format';
import { PersonForm } from './PersonForm';

export function PersonDetail({
  personId,
  onBack,
  onChanged,
}: {
  personId: number;
  onBack: () => void;
  onChanged: () => void;
}) {
  const [person, setPerson] = useState<Person | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState(false);
  const [addingCut, setAddingCut] = useState(false);

  function load() {
    setError(null);
    api.getPerson(personId).then(setPerson).catch((e) => setError(String(e.message ?? e)));
  }
  useEffect(load, [personId]);

  async function removePerson() {
    if (!confirm('Delete this person and all their haircuts?')) return;
    await api.deletePerson(personId);
    onChanged();
    onBack();
  }

  async function removeHaircut(hid: number) {
    if (!confirm('Delete this haircut?')) return;
    await api.deleteHaircut(hid);
    load();
    onChanged();
  }

  async function toggleBlock() {
    if (!person?.account) return;
    const next = person.account.status === 'blocked' ? 'active' : 'blocked';
    await api.setUserStatus(person.account.user_id, next);
    load();
  }

  async function removeAccount() {
    if (!person?.account) return;
    if (!confirm(`Remove the login "${person.account.username}"? Their history stays.`)) return;
    await api.deleteUser(person.account.user_id);
    load();
  }

  if (error) return <div className="error">{error}</div>;
  if (!person) return <div className="card muted center">Loading…</div>;

  const s = person.stats;

  return (
    <>
      <button className="back" onClick={onBack}>‹ Back</button>

      {editing ? (
        <PersonForm
          existing={person}
          onSaved={() => { setEditing(false); load(); onChanged(); }}
          onCancel={() => setEditing(false)}
        />
      ) : (
        <div className="card">
          <div className="row">
            <div className="name" style={{ fontSize: '1.25rem' }}>{person.display_name}</div>
            {person.inactive
              ? <span className="badge none">Inactive</span>
              : <span className={`badge ${dueTone(s.overdue_by_days)}`}>{dueLabel(s.overdue_by_days)}</span>}
          </div>
          <div className="sub">
            {person.phone ? `📱 ${phone(person.phone)}` : ''} {person.email ? `· ✉️ ${person.email}` : ''}
            {' · prefers '}{person.preferred_channel === 'sms' ? 'text' : 'email'}
          </div>

          <div className="stat-grid">
            <div className="stat"><div className="k">Cuts</div><div className="v">{s.cut_count}</div></div>
            <div className="stat">
              <div className="k">Usual cadence</div>
              <div className="v">{s.usual_cadence_days ? `${s.usual_cadence_days}d` : '—'}</div>
            </div>
            <div className="stat">
              <div className="k">Since last</div>
              <div className="v">{s.days_since_last !== null ? `${s.days_since_last}d` : '—'}</div>
            </div>
            <div className="stat"><div className="k">Total spent</div><div className="v">{money(person.total_spent_cents)}</div></div>
          </div>
          {s.cadence_source && <div className="sub" style={{ marginTop: 8 }}>Cadence: {s.cadence_source}</div>}
          {person.notes && <div className="sub" style={{ marginTop: 8 }}>📝 {person.notes}</div>}

          <div className="btn-row" style={{ marginTop: 14 }}>
            <button className="btn small" onClick={() => setEditing(true)}>Edit</button>
            <button className="btn small danger" onClick={removePerson}>Delete</button>
          </div>
        </div>
      )}

      {/* Linked login account (block / remove) */}
      {person.account && (
        <div className="card">
          <div className="section-title">Account</div>
          <div className="row">
            <div>
              <div className="name" style={{ fontSize: '.98rem' }}>@{person.account.username}</div>
              <div className="sub">{person.account.role} · {person.account.status}</div>
            </div>
            <div className="btn-row">
              <button className="btn small" onClick={toggleBlock}>
                {person.account.status === 'blocked' ? 'Unblock' : 'Block'}
              </button>
              <button className="btn small danger" onClick={removeAccount}>Remove</button>
            </div>
          </div>
        </div>
      )}

      {/* Merge this person into another */}
      <MergeControl personId={personId} onMerged={() => { onChanged(); onBack(); }} />

      <div className="row" style={{ marginTop: 4 }}>
        <div className="section-title">Haircut history</div>
        <button className="btn small primary" onClick={() => setAddingCut((v) => !v)}>
          {addingCut ? 'Close' : '+ Add cut'}
        </button>
      </div>

      {addingCut && (
        <HaircutForm
          personId={personId}
          onSaved={() => { setAddingCut(false); load(); onChanged(); }}
        />
      )}

      <div className="card">
        {person.haircuts && person.haircuts.length > 0 ? (
          person.haircuts.map((h) => (
            <div key={h.haircut_id} className="haircut">
              <div>
                <div>{shortDate(h.haircut_date)}{h.haircut_time ? ` · ${h.haircut_time.slice(0, 5)}` : ''}</div>
                {h.notes && <div className="sub">{h.notes}</div>}
              </div>
              <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                <span>{money(h.amount_cents)}</span>
                <button className="btn small danger" onClick={() => removeHaircut(h.haircut_id)}>✕</button>
              </div>
            </div>
          ))
        ) : (
          <div className="muted center">No haircuts recorded yet.</div>
        )}
      </div>
    </>
  );
}

// Fold this person into another (dupes / same client added twice).
function MergeControl({ personId, onMerged }: { personId: number; onMerged: () => void }) {
  const [open, setOpen] = useState(false);
  const [others, setOthers] = useState<Person[]>([]);
  const [targetId, setTargetId] = useState<number | ''>('');
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      api.listPersons()
        .then((r) => setOthers(r.persons.filter((p) => p.user_id !== personId)))
        .catch(() => {});
    }
  }, [open, personId]);

  async function merge() {
    if (targetId === '') return;
    const into = others.find((p) => p.user_id === targetId);
    if (!confirm(`Merge this person INTO ${into?.display_name}? Their cuts and appointments move over.`)) return;
    try {
      await api.mergePersons(personId, Number(targetId));
      onMerged();
    } catch (e) {
      setError(String((e as Error).message ?? e));
    }
  }

  if (!open) {
    return <button className="btn small" style={{ marginTop: 4 }} onClick={() => setOpen(true)}>Merge into another person…</button>;
  }

  return (
    <div className="card">
      <div className="section-title">Merge into</div>
      <select value={targetId} onChange={(e) => setTargetId(e.target.value === '' ? '' : Number(e.target.value))}>
        <option value="">Choose person…</option>
        {others.map((p) => <option key={p.user_id} value={p.user_id}>{p.display_name}</option>)}
      </select>
      {error && <p className="error" style={{ marginTop: 10 }}>{error}</p>}
      <div className="btn-row" style={{ marginTop: 10 }}>
        <button className="btn danger" disabled={targetId === ''} onClick={merge}>Merge</button>
        <button className="btn" onClick={() => setOpen(false)}>Cancel</button>
      </div>
    </div>
  );
}

function HaircutForm({ personId, onSaved }: { personId: number; onSaved: () => void }) {
  const today = new Date().toISOString().slice(0, 10);
  const [date, setDate] = useState(today);
  const [time, setTime] = useState('');
  const [amount, setAmount] = useState('');
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await api.addHaircut(personId, {
        haircut_date: date,
        haircut_time: time || null,
        amount_cents: Math.round(parseFloat(amount || '0') * 100),
        notes: notes || null,
      });
      onSaved();
    } catch (err) {
      setError(String((err as Error).message ?? err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="card" onSubmit={save}>
      <label htmlFor="hd">Date</label>
      <input id="hd" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
      <label htmlFor="ht">Time (optional)</label>
      <input id="ht" type="time" value={time} onChange={(e) => setTime(e.target.value)} />
      <label htmlFor="ha">Amount ($)</label>
      <input id="ha" inputMode="decimal" placeholder="30.00" value={amount} onChange={(e) => setAmount(e.target.value)} />
      <label htmlFor="hn">Notes</label>
      <input id="hn" value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Fade, line-up…" />
      {error && <p className="error" style={{ marginTop: 10 }}>{error}</p>}
      <button className="btn primary block" style={{ marginTop: 14 }} disabled={busy}>
        {busy ? 'Saving…' : 'Save haircut'}
      </button>
    </form>
  );
}
