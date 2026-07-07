import { useEffect, useState } from 'react';
import { api, type MeResponse, type Carrier } from '../lib/api';
import { VerifyContact } from './VerifyContact';

export function ProfilePage({ me, onBack, onChanged }: {
  me: MeResponse;
  onBack: () => void;
  onChanged: () => void;
}) {
  const p = me.person;
  const [carriers, setCarriers] = useState<Carrier[]>([]);
  const [name, setName] = useState(p.display_name);
  const [channel, setChannel] = useState<'email' | 'sms'>(p.preferred_channel);
  const [email, setEmail] = useState(p.email ?? '');
  const [phone, setPhone] = useState(p.phone ?? '');
  const [carrierId, setCarrierId] = useState<number | ''>(p.carrier_id ?? '');
  const [msg, setMsg] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => { api.carriers().then((r) => setCarriers(r.carriers)).catch(() => {}); }, []);

  async function saveProfile(e: React.FormEvent) {
    e.preventDefault();
    setError(null); setMsg(null);
    try {
      await api.meUpdateProfile({
        display_name: name.trim(),
        preferred_channel: channel,
        email: email.trim() || null,
        phone: phone.trim() || null,
        carrier_id: carrierId === '' ? null : Number(carrierId),
      });
      setMsg('Saved.');
      onChanged();
    } catch (e) {
      setError(String((e as Error).message ?? e));
    }
  }

  return (
    <>
      <button className="back" onClick={onBack}>‹ Back</button>

      {/* Notification: what still needs verifying + quick CTA */}
      {!p.preferred_verified && (
        <>
          <div className="error">
            Your {p.preferred_channel === 'email' ? 'email' : 'phone'} isn't verified yet — verify it to book appointments.
          </div>
          <VerifyContact channel={p.preferred_channel} onVerified={onChanged} />
        </>
      )}

      {/* Profile fields */}
      <form className="card" onSubmit={saveProfile}>
        <div className="name" style={{ fontSize: '1.15rem' }}>Your profile</div>

        <label htmlFor="pn">Name</label>
        <input id="pn" value={name} onChange={(e) => setName(e.target.value)} />

        <label htmlFor="pe">Email {p.email && (p.email_verified ? '✅' : '⚠️ unverified')}</label>
        <input id="pe" type="email" inputMode="email" value={email} onChange={(e) => setEmail(e.target.value)} />

        <label htmlFor="pp">Phone {p.phone && (p.phone_verified ? '✅' : '⚠️ unverified')}</label>
        <input id="pp" inputMode="tel" value={phone} onChange={(e) => setPhone(e.target.value)} />

        <label htmlFor="pcar">Carrier (for texts)</label>
        <select id="pcar" value={carrierId} onChange={(e) => setCarrierId(e.target.value === '' ? '' : Number(e.target.value))}>
          <option value="">—</option>
          {carriers.map((c) => <option key={c.carrier_id} value={c.carrier_id}>{c.name}</option>)}
        </select>

        <label htmlFor="pch">Preferred contact</label>
        <select id="pch" value={channel} onChange={(e) => setChannel(e.target.value as 'email' | 'sms')}>
          <option value="sms">Text (SMS)</option>
          <option value="email">Email</option>
        </select>

        <p className="sub" style={{ marginTop: 8 }}>Changing your email or phone requires verifying it again.</p>
        {error && <p className="error" style={{ marginTop: 10 }}>{error}</p>}
        {msg && <p className="sub" style={{ marginTop: 10, color: 'var(--soon)' }}>{msg}</p>}
        <button className="btn primary block" style={{ marginTop: 12 }}>Save</button>
      </form>

      <ChangePassword />
    </>
  );
}

function ChangePassword() {
  const [cur, setCur] = useState('');
  const [next, setNext] = useState('');
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true); setError(null); setMsg(null);
    try {
      await api.meChangePassword(cur, next);
      setMsg('Password changed.');
      setCur(''); setNext('');
    } catch (e) {
      setError(String((e as Error).message ?? e));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="card" onSubmit={submit}>
      <div className="name" style={{ fontSize: '1.05rem' }}>Change password</div>
      <label htmlFor="cpc">Current password</label>
      <input id="cpc" type="password" value={cur} onChange={(e) => setCur(e.target.value)} />
      <label htmlFor="cpn">New password</label>
      <input id="cpn" type="password" value={next} onChange={(e) => setNext(e.target.value)} />
      {error && <p className="error" style={{ marginTop: 10 }}>{error}</p>}
      {msg && <p className="sub" style={{ marginTop: 10, color: 'var(--soon)' }}>{msg}</p>}
      <button className="btn block" style={{ marginTop: 12 }} disabled={busy}>{busy ? 'Saving…' : 'Update password'}</button>
    </form>
  );
}
