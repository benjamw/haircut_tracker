import { useState } from 'react';
import { api, type Person, type PersonInput } from '../lib/api';

// Create or edit a person.
export function PersonForm({
  existing,
  onSaved,
  onCancel,
}: {
  existing?: Person;
  onSaved: (p: Person) => void;
  onCancel: () => void;
}) {
  const [f, setF] = useState<PersonInput>({
    display_name: existing?.display_name ?? '',
    phone: existing?.phone ?? '',
    email: existing?.email ?? '',
    preferred_channel: existing?.preferred_channel ?? 'sms',
    usual_cadence_days: existing?.usual_cadence_days ?? null,
    notify_opt_out: existing?.notify_opt_out ?? false,
    inactive: existing?.inactive ?? false,
    notes: existing?.notes ?? '',
  });
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  function set<K extends keyof PersonInput>(k: K, v: PersonInput[K]) {
    setF((prev) => ({ ...prev, [k]: v }));
  }

  async function save(e: React.FormEvent) {
    e.preventDefault();
    if (!f.display_name?.trim()) { setError('Name is required'); return; }
    setBusy(true);
    setError(null);
    try {
      const saved = existing
        ? await api.updatePerson(existing.user_id, f)
        : await api.createPerson(f);
      onSaved(saved);
    } catch (err) {
      setError(String((err as Error).message ?? err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="card" onSubmit={save}>
      <div className="name">{existing ? 'Edit person' : 'Add person'}</div>

      <label htmlFor="pn">Name</label>
      <input id="pn" value={f.display_name ?? ''} onChange={(e) => set('display_name', e.target.value)} autoFocus />

      <label htmlFor="pp">Phone</label>
      <input id="pp" inputMode="tel" value={f.phone ?? ''} onChange={(e) => set('phone', e.target.value)} />

      <label htmlFor="pe">Email</label>
      <input id="pe" inputMode="email" value={f.email ?? ''} onChange={(e) => set('email', e.target.value)} />

      <label htmlFor="pc">Preferred channel</label>
      <select id="pc" value={f.preferred_channel} onChange={(e) => set('preferred_channel', e.target.value as 'email' | 'sms')}>
        <option value="sms">Text (SMS)</option>
        <option value="email">Email</option>
      </select>

      <label htmlFor="pcad">Usual cadence override (days) — leave blank to auto-compute</label>
      <input
        id="pcad"
        inputMode="numeric"
        value={f.usual_cadence_days ?? ''}
        onChange={(e) => set('usual_cadence_days', e.target.value === '' ? null : Number(e.target.value))}
      />

      <label style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 14 }}>
        <input
          type="checkbox"
          style={{ width: 'auto' }}
          checked={f.inactive ?? false}
          onChange={(e) => set('inactive', e.target.checked)}
        />
        No longer comes in (hide from reach-out list)
      </label>

      <label htmlFor="pnotes">Notes</label>
      <textarea id="pnotes" value={f.notes ?? ''} onChange={(e) => set('notes', e.target.value)} />

      {error && <p className="error" style={{ marginTop: 10 }}>{error}</p>}

      <div className="btn-row" style={{ marginTop: 14 }}>
        <button className="btn primary" disabled={busy}>{busy ? 'Saving…' : 'Save'}</button>
        <button type="button" className="btn" onClick={onCancel}>Cancel</button>
      </div>
    </form>
  );
}
