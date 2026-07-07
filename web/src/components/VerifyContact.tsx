import { useState } from 'react';
import { api } from '../lib/api';

// Send an OTP to the user's preferred contact and verify it (required before
// self-scheduling). Shared by the logged-in home and the profile page.
export function VerifyContact({ channel, onVerified }: { channel: 'email' | 'sms'; onVerified: () => void }) {
  const [step, setStep] = useState<'start' | 'code'>('start');
  const [sentTo, setSentTo] = useState('');
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const label = channel === 'email' ? 'email' : 'phone';

  async function send() {
    setBusy(true); setError(null);
    try { const r = await api.meSendContactCode(); setSentTo(r.sent_to); setStep('code'); }
    catch (e) { setError(String((e as Error).message ?? e)); }
    finally { setBusy(false); }
  }
  async function verify(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true); setError(null);
    try { await api.meVerifyContact(code.trim()); onVerified(); }
    catch (e) { setError(String((e as Error).message ?? e)); }
    finally { setBusy(false); }
  }

  return (
    <div className="card">
      <div className="name">Verify your {label} to book</div>
      <div className="sub" style={{ marginBottom: 10 }}>We confirm your {label} once before you can schedule.</div>
      {step === 'start' ? (
        <button className="btn primary block" disabled={busy} onClick={send}>{busy ? 'Sending…' : 'Send me a code'}</button>
      ) : (
        <form onSubmit={verify}>
          <div className="sub" style={{ marginBottom: 8 }}>Code sent to {sentTo}.</div>
          <label htmlFor="vcode">Code</label>
          <input id="vcode" inputMode="numeric" maxLength={6} value={code} onChange={(e) => setCode(e.target.value)} autoFocus />
          <button className="btn primary block" style={{ marginTop: 12 }} disabled={busy}>{busy ? 'Checking…' : 'Verify'}</button>
        </form>
      )}
      {error && <p className="error" style={{ marginTop: 10 }}>{error}</p>}
    </div>
  );
}
