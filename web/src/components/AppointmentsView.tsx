import { useEffect, useState } from 'react';
import { api, type Appointment } from '../lib/api';
import { phone, shortDate } from '../lib/format';

function timeLabel(iso: string): string {
  return new Date(iso.replace(' ', 'T')).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

export function AppointmentsView({ onOpenPerson }: { onOpenPerson: (id: number) => void }) {
  const [upcoming, setUpcoming] = useState<Appointment[]>([]);
  const [toRecord, setToRecord] = useState<Appointment[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [recording, setRecording] = useState<Appointment | null>(null);

  function load() {
    setError(null);
    api.appointments()
      .then((r) => { setUpcoming(r.upcoming); setToRecord(r.to_record); })
      .catch((e) => setError(String(e.message ?? e)));
  }
  useEffect(load, []);

  async function cancel(id: number) {
    if (!confirm('Cancel this appointment?')) return;
    await api.cancelAppointment(id);
    load();
  }

  if (recording) {
    return <RecordForm appt={recording} onDone={() => { setRecording(null); load(); }} onCancel={() => setRecording(null)} />;
  }

  return (
    <>
      {error && <div className="error">{error}</div>}

      {/* Past appointments awaiting a recorded amount */}
      {toRecord.length > 0 && (
        <>
          <div className="section-title">Needs recording</div>
          <div className="muted sub" style={{ marginBottom: 6 }}>Past appointments — log what was paid.</div>
          <div className="list">
            {toRecord.map((a) => (
              <div key={a.appointment_id} className="card tappable" onClick={() => setRecording(a)}>
                <div className="row">
                  <div>
                    <div className="name">{a.person_name ?? a.contact_name}</div>
                    <div className="sub">{shortDate(a.slot_start.slice(0, 10))} · {timeLabel(a.slot_start)}</div>
                  </div>
                  <span className="badge due">Record →</span>
                </div>
              </div>
            ))}
          </div>
        </>
      )}

      <div className="section-title" style={{ marginTop: toRecord.length ? 18 : 0 }}>Upcoming appointments</div>
      {upcoming.length === 0 && <div className="card center muted">No upcoming appointments.</div>}
      <div className="list">
        {upcoming.map((a) => (
          <div key={a.appointment_id} className="card">
            <div className="row">
              <div onClick={() => a.user_id && onOpenPerson(a.user_id)} style={{ cursor: a.user_id ? 'pointer' : 'default' }}>
                <div className="name">{shortDate(a.slot_start.slice(0, 10))} · {timeLabel(a.slot_start)}</div>
                <div className="sub">
                  {a.person_name ?? a.contact_name}
                  {' · '}{a.notify_channel === 'sms' ? `📱 ${phone(a.contact_phone)}` : `✉️ ${a.contact_email ?? ''}`}
                </div>
              </div>
              <button className="btn small danger" onClick={() => cancel(a.appointment_id)}>Cancel</button>
            </div>
          </div>
        ))}
      </div>
    </>
  );
}

// Record a completed appointment as a haircut (amount paid + notes), like "add cut".
function RecordForm({ appt, onDone, onCancel }: { appt: Appointment; onDone: () => void; onCancel: () => void }) {
  const [amount, setAmount] = useState('');
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await api.recordAppointment(appt.appointment_id, {
        amount_cents: Math.round(parseFloat(amount || '0') * 100),
        notes: notes || null,
      });
      onDone();
    } catch (err) {
      setError(String((err as Error).message ?? err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="card" onSubmit={save}>
      <button type="button" className="back" onClick={onCancel}>‹ Back</button>
      <div className="name">Record cut — {appt.person_name ?? appt.contact_name}</div>
      <div className="sub">{shortDate(appt.slot_start.slice(0, 10))} · {timeLabel(appt.slot_start)}</div>

      <label htmlFor="ra">Amount paid ($)</label>
      <input id="ra" inputMode="decimal" placeholder="30.00" value={amount} onChange={(e) => setAmount(e.target.value)} autoFocus />
      <label htmlFor="rn">Notes</label>
      <input id="rn" value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Fade, line-up…" />

      {error && <p className="error" style={{ marginTop: 10 }}>{error}</p>}
      <button className="btn primary block" style={{ marginTop: 14 }} disabled={busy}>
        {busy ? 'Saving…' : 'Save cut'}
      </button>
    </form>
  );
}
