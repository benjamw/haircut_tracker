import { useEffect, useState } from 'react';
import { api, ApiError, getUserToken, clearUserToken, type SlotDay, type Slot, type Carrier, type MeResponse } from './lib/api';
import { dateWithDay } from './lib/format';
import { AuthScreen } from './components/AuthScreen';
import { LoggedInHome } from './components/LoggedInHome';
import { ProfilePage } from './components/ProfilePage';
import { Turnstile, turnstileEnabled } from './components/Turnstile';

type Step = 'pick' | 'details' | 'code' | 'done';

const HEADLINES = ['Need a cut?', 'Need a line-up?', 'Time for a fresh fade?', 'Book your next cut'];

export default function BookingApp() {
  // ---- auth state ----
  const [me, setMe] = useState<MeResponse | null>(null);
  const [loadingMe, setLoadingMe] = useState(getUserToken() !== '');
  const [showAuth, setShowAuth] = useState(false);
  const [profileOpen, setProfileOpen] = useState(false);

  function loadMe() {
    if (getUserToken() === '') { setMe(null); setLoadingMe(false); return; }
    setLoadingMe(true);
    api.me()
      .then((m) => {
        // Admins belong in the admin panel, not the customer booking page.
        if (m.user.role === 'admin') { window.location.href = '/admin'; return; }
        setMe(m);
      })
      .catch((e) => {
        // Only drop the session if the token is actually rejected (401).
        // A transient/network error keeps the stored token so we retry later
        // instead of forcing the user to log in again.
        if (e instanceof ApiError && e.status === 401) {
          clearUserToken();
        }
        setMe(null);
      })
      .finally(() => setLoadingMe(false));
  }
  useEffect(loadMe, []);

  function logout() { clearUserToken(); setMe(null); }

  if (loadingMe) {
    return <div className="app"><div className="topbar"><h1>💈 HeadAhhBlendz 💈</h1></div><div className="content"><div className="card muted center">Loading…</div></div></div>;
  }

  if (me) {
    const unverified = !me.person.preferred_verified;
    return (
      <div className="app">
        <div className="topbar">
          <h1>💈 HeadAhhBlendz 💈</h1>
          <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
            <button className="link" style={{ position: 'relative', fontSize: '1.2rem' }}
              aria-label="Profile" onClick={() => setProfileOpen((v) => !v)}>
              👤
              {unverified && <span style={{ position: 'absolute', top: 0, right: -2, width: 9, height: 9, borderRadius: '50%', background: 'var(--over)', border: '1px solid var(--surface)' }} />}
            </button>
            <button className="link" onClick={logout}>Log out</button>
          </div>
        </div>
        <div className="content">
          {profileOpen
            ? <ProfilePage me={me} onBack={() => setProfileOpen(false)} onChanged={loadMe} />
            : <LoggedInHome me={me} onRefresh={loadMe} onLogout={logout} />}
        </div>
      </div>
    );
  }

  if (showAuth) {
    return (
      <div className="app">
        <div className="topbar"><h1>💈 HeadAhhBlendz 💈</h1></div>
        <div className="content">
          <AuthScreen onAuthed={() => { setShowAuth(false); loadMe(); }} onCancel={() => setShowAuth(false)} />
        </div>
      </div>
    );
  }

  return <AnonBooking onLogin={() => setShowAuth(true)} />;
}

function AnonBooking({ onLogin }: { onLogin: () => void }) {
  const [days, setDays] = useState<SlotDay[] | null>(null);
  const [carriers, setCarriers] = useState<Carrier[]>([]);
  const [error, setError] = useState<string | null>(null);

  const [step, setStep] = useState<Step>('pick');
  const [slot, setSlot] = useState<Slot | null>(null);

  const [name, setName] = useState('');
  const [channel, setChannel] = useState<'sms' | 'email'>('sms');
  const [phone, setPhone] = useState('');
  const [carrierId, setCarrierId] = useState<number | ''>('');
  const [email, setEmail] = useState('');
  const [website, setWebsite] = useState(''); // honeypot
  const [turnstileToken, setTurnstileToken] = useState('');

  const [holdId, setHoldId] = useState<number | null>(null);
  const [sentTo, setSentTo] = useState('');
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  const [confirmed, setConfirmed] = useState<Slot | null>(null);

  const headline = HEADLINES[0];

  useEffect(() => {
    api.slots(21).then((r) => setDays(r.days)).catch((e) => setError(String(e.message ?? e)));
    api.carriers().then((r) => setCarriers(r.carriers)).catch(() => {});
  }, []);

  async function startBooking(e: React.FormEvent) {
    e.preventDefault();
    if (!slot) return;
    setBusy(true);
    setError(null);
    try {
      const res = await api.bookStart({
        slot_start: slot.start,
        name: name.trim(),
        channel,
        email: channel === 'email' ? email.trim() : undefined,
        phone: channel === 'sms' ? phone.trim() : undefined,
        carrier_id: channel === 'sms' && carrierId !== '' ? Number(carrierId) : undefined,
        website,
        turnstile_token: turnstileToken,
      });
      setHoldId(res.hold_id);
      setSentTo(res.sent_to);
      setStep('code');
    } catch (e) {
      setError(String((e as Error).message ?? e));
    } finally {
      setBusy(false);
    }
  }

  async function verify(e: React.FormEvent) {
    e.preventDefault();
    if (holdId === null) return;
    setBusy(true);
    setError(null);
    try {
      await api.bookVerify(holdId, code.trim());
      setConfirmed(slot);
      setStep('done');
    } catch (e) {
      setError(String((e as Error).message ?? e));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="app">
      <div className="topbar"><h1>💈 HeadAhhBlendz 💈</h1><button className="link" onClick={onLogin}>Log in</button></div>
      <div className="content">
        {error && <div className="error">{error}</div>}

        {step === 'pick' && (
          <>
            <h2 style={{ margin: '4px 0 2px' }}>{headline}</h2>
            <div className="muted sub">Pick a time that works for you.</div>
            {days && days.length === 0 && <div className="card center muted">No open times right now — check back soon.</div>}
            {days?.map((d) => (
              <div key={d.date} className="card">
                <div className="name" style={{ fontSize: '.98rem', marginBottom: 8 }}>{dateWithDay(d.date)}</div>
                <div className="btn-row">
                  {d.slots.map((s) => (
                    <button key={s.start} className="btn small" onClick={() => { setSlot(s); setStep('details'); }}>
                      {s.label}
                    </button>
                  ))}
                </div>
              </div>
            ))}
          </>
        )}

        {step === 'details' && slot && (
          <form className="card" onSubmit={startBooking}>
            <button type="button" className="back" onClick={() => setStep('pick')}>‹ Change time</button>
            <div className="name">{dateWithDay(slot.start.slice(0, 10))} at {slot.label}</div>

            <label htmlFor="bn">Your name</label>
            <input id="bn" value={name} onChange={(e) => setName(e.target.value)} required />

            <label>How should we send your confirmation code?</label>
            <select value={channel} onChange={(e) => setChannel(e.target.value as 'sms' | 'email')}>
              <option value="sms">Text me</option>
              <option value="email">Email me</option>
            </select>

            {channel === 'sms' ? (
              <>
                <label htmlFor="bp">Mobile number</label>
                <input id="bp" inputMode="tel" value={phone} onChange={(e) => setPhone(e.target.value)} required />
                <label htmlFor="bc">Carrier</label>
                <select id="bc" value={carrierId} onChange={(e) => setCarrierId(e.target.value === '' ? '' : Number(e.target.value))} required>
                  <option value="">Select carrier…</option>
                  {carriers.map((c) => <option key={c.carrier_id} value={c.carrier_id}>{c.name}</option>)}
                </select>
              </>
            ) : (
              <>
                <label htmlFor="be">Email</label>
                <input id="be" inputMode="email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
              </>
            )}

            {/* Honeypot: hidden from real users */}
            <input
              type="text" value={website} onChange={(e) => setWebsite(e.target.value)}
              tabIndex={-1} autoComplete="off"
              style={{ position: 'absolute', left: '-9999px' }} aria-hidden="true"
            />

            {/* Bot check (renders only when a site key is configured) */}
            <Turnstile onToken={setTurnstileToken} />

            <button
              className="btn primary block"
              style={{ marginTop: 14 }}
              disabled={busy || (turnstileEnabled() && !turnstileToken)}
            >
              {busy ? 'Sending…' : 'Send me a code'}
            </button>
          </form>
        )}

        {step === 'code' && (
          <form className="card" onSubmit={verify}>
            <div className="name">Enter your code</div>
            <div className="muted sub">We sent a 6-digit code to {sentTo}.</div>
            <label htmlFor="otp">Code</label>
            <input id="otp" inputMode="numeric" maxLength={6} value={code} onChange={(e) => setCode(e.target.value)} autoFocus />
            <button className="btn primary block" style={{ marginTop: 14 }} disabled={busy}>
              {busy ? 'Checking…' : 'Confirm booking'}
            </button>
            <button type="button" className="btn block" style={{ marginTop: 8 }} onClick={() => setStep('details')}>Back</button>
          </form>
        )}

        {step === 'done' && confirmed && (
          <div className="card center">
            <div style={{ fontSize: '2.5rem' }}>💈</div>
            <h2 style={{ margin: '6px 0' }}>You're booked!</h2>
            <p className="muted">{dateWithDay(confirmed.start.slice(0, 10))} at {confirmed.label}</p>
            <p className="sub">See you then, {name.split(' ')[0]}.</p>
          </div>
        )}
      </div>
    </div>
  );
}
