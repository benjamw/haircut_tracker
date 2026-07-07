import { useEffect, useState } from 'react';
import { api, type MeResponse, type SlotDay } from '../lib/api';
import { dateWithDay, shortDate } from '../lib/format';
import { enrollPasskey, passkeysSupported } from '../lib/webauthn';
import { VerifyContact } from './VerifyContact';

const CTAS = ['Need a cut?', 'Need a line-up?', 'Time for a fresh fade?'];

function timeOf(iso: string): string {
  return new Date(iso.replace(' ', 'T')).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

export function LoggedInHome({ me, onRefresh, onLogout }: {
  me: MeResponse; onRefresh: () => void; onLogout: () => void;
}) {
  const [days, setDays] = useState<SlotDay[] | null>(null);
  const [picking, setPicking] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => { if (picking) api.slots(21).then((r) => setDays(r.days)).catch((e) => setError(String(e.message ?? e))); }, [picking]);

  const first = me.person.display_name.split(' ')[0];
  const next = me.next_appointment;
  const verified = me.person.preferred_verified;

  async function book(slotStart: string) {
    setBusy(true); setError(null);
    try { await api.meBook(slotStart); setPicking(false); onRefresh(); }
    catch (e) { setError(String((e as Error).message ?? e)); }
    finally { setBusy(false); }
  }

  async function cancel() {
    if (!next || !confirm('Cancel this appointment?')) return;
    await api.meCancel(next.appointment_id);
    onRefresh();
  }

  return (
    <>
      <div className="row">
        <h2 style={{ margin: '2px 0' }}>Hey {first} 👋</h2>
      </div>

      {error && <div className="error">{error}</div>}

      {/* Top card: next appt, or a booking CTA */}
      {next ? (
        <div className="card">
          <div className="section-title">Your next cut</div>
          <div className="name" style={{ fontSize: '1.1rem' }}>{dateWithDay(next.slot_start.slice(0, 10))}</div>
          <div className="sub">at {timeOf(next.slot_start)}</div>
          <div className="btn-row" style={{ marginTop: 12 }}>
            <button className="btn small danger" onClick={cancel}>Cancel</button>
          </div>
        </div>
      ) : !verified ? (
        <VerifyContact channel={me.person.preferred_channel} onVerified={onRefresh} />
      ) : (
        <div className="card center">
          <div style={{ fontSize: '1.3rem', fontWeight: 700, margin: '4px 0' }}>{CTAS[0]}</div>
          {me.stats.usual_cadence_days && me.stats.overdue_by_days !== null && me.stats.overdue_by_days >= 0 && (
            <div className="sub" style={{ marginBottom: 8 }}>You usually come in every ~{me.stats.usual_cadence_days} days — you're due 👀</div>
          )}
          <button className="btn primary block" onClick={() => setPicking(true)}>Book a time</button>
        </div>
      )}

      {/* Instant booking slot picker */}
      {picking && (
        <>
          <div className="row"><div className="section-title">Pick a time</div>
            <button className="btn small" onClick={() => setPicking(false)}>Close</button></div>
          {days?.map((d) => (
            <div key={d.date} className="card">
              <div className="name" style={{ fontSize: '.95rem', marginBottom: 8 }}>{dateWithDay(d.date)}</div>
              <div className="btn-row">
                {d.slots.map((s) => (
                  <button key={s.start} className="btn small" disabled={busy} onClick={() => book(s.start)}>{s.label}</button>
                ))}
              </div>
            </div>
          ))}
          {days && days.length === 0 && <div className="card center muted">No open times right now.</div>}
        </>
      )}

      {/* My history — dates + cadence, no prices */}
      <div className="section-title" style={{ marginTop: 16 }}>Your haircut history</div>
      <div className="card">
        <div className="sub" style={{ marginBottom: 8 }}>
          {me.stats.cut_count} cut{me.stats.cut_count === 1 ? '' : 's'}
          {me.stats.usual_cadence_days ? ` · usually every ~${me.stats.usual_cadence_days} days` : ''}
        </div>
        {me.haircuts.length === 0 && <div className="muted">No cuts on record yet.</div>}
        {me.haircuts.map((h, i) => (
          <div key={i} className="haircut">
            <span>{shortDate(h.haircut_date)}</span>
            <span className="muted">{h.notes ?? ''}</span>
          </div>
        ))}
      </div>

      {!me.user.has_passkey && <PasskeySetup />}

      <button className="btn block" style={{ marginTop: 12 }} onClick={onLogout}>Log out</button>
    </>
  );
}

// One-tap enrollment of a device passkey for faster future logins.
function PasskeySetup() {
  const [state, setState] = useState<'idle' | 'busy' | 'done' | 'error'>('idle');
  const [msg, setMsg] = useState<string | null>(null);

  if (!passkeysSupported()) return null;

  async function setup() {
    setState('busy');
    setMsg(null);
    try {
      await enrollPasskey();
      setState('done');
    } catch (e) {
      setState('error');
      setMsg(String((e as Error).message ?? e));
    }
  }

  return (
    <div className="card" style={{ marginTop: 16 }}>
      <div className="section-title">Faster login</div>
      {state === 'done' ? (
        <div className="sub">✅ Passkey saved to this device. You can log in with your fingerprint/PIN next time.</div>
      ) : (
        <>
          <div className="sub" style={{ marginBottom: 10 }}>Set up a passkey to skip the password on this device.</div>
          <button className="btn small primary" disabled={state === 'busy'} onClick={setup}>
            {state === 'busy' ? 'Setting up…' : '🔑 Set up passkey'}
          </button>
          {msg && <p className="error" style={{ marginTop: 10 }}>{msg}</p>}
        </>
      )}
    </div>
  );
}
