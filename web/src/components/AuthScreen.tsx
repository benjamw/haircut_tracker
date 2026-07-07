import { useEffect, useState } from 'react';
import { api, setUserToken, rememberUsername, getRememberedUsername, type Carrier, type AuthResult } from '../lib/api';
import { loginWithPasskey, passkeysSupported } from '../lib/webauthn';

// Customer login / sign-up. Sign-up "claims" existing history by matching
// the email or phone the barber already has on file.
export function AuthScreen({ onAuthed, onCancel, loginOnly = false }: {
  onAuthed: (r: AuthResult) => void;
  onCancel?: () => void;
  loginOnly?: boolean;
}) {
  const [mode, setMode] = useState<'login' | 'register'>('login');
  const [username, setUsername] = useState(getRememberedUsername()); // prefill for returning users
  const [password, setPassword] = useState('');
  const [name, setName] = useState('');
  const [channel, setChannel] = useState<'sms' | 'email'>('sms');
  const [phone, setPhone] = useState('');
  const [carrierId, setCarrierId] = useState<number | ''>('');
  const [email, setEmail] = useState('');
  const [carriers, setCarriers] = useState<Carrier[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // Post-registration contact verification (claims history).
  const [verifyResult, setVerifyResult] = useState<AuthResult | null>(null);
  const [code, setCode] = useState('');

  useEffect(() => { api.carriers().then((r) => setCarriers(r.carriers)).catch(() => {}); }, []);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const result = mode === 'login'
        ? await api.login(username.trim(), password)
        : await api.register({
            username: username.trim(),
            password,
            name: name.trim() || undefined,
            preferred_channel: channel,
            email: channel === 'email' ? email.trim() : undefined,
            phone: channel === 'sms' ? phone.trim() : undefined,
            carrier_id: channel === 'sms' && carrierId !== '' ? Number(carrierId) : undefined,
          });
      setUserToken(result.token);
      rememberUsername(result.user.username);
      // Registration requires proving contact ownership before history is claimed.
      if (result.contact_verification_required) {
        setVerifyResult(result);
      } else {
        onAuthed(result);
      }
    } catch (err) {
      setError(String((err as Error).message ?? err));
    } finally {
      setBusy(false);
    }
  }

  async function passkeyLogin() {
    if (!username.trim()) { setError('Enter your username first'); return; }
    setBusy(true);
    setError(null);
    try {
      const result = await loginWithPasskey(username.trim());
      rememberUsername(result.user.username);
      onAuthed(result);
    } catch (err) {
      setError(String((err as Error).message ?? err));
    } finally {
      setBusy(false);
    }
  }

  async function submitCode(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await api.meVerifyContact(code.trim());
      onAuthed(verifyResult!);
    } catch (err) {
      setError(String((err as Error).message ?? err));
    } finally {
      setBusy(false);
    }
  }

  // Step 2: enter the code sent to the registrant's contact.
  if (verifyResult) {
    return (
      <form className="card" onSubmit={submitCode}>
        <div className="name" style={{ fontSize: '1.15rem' }}>Verify your {verifyResult.channel === 'email' ? 'email' : 'number'}</div>
        <p className="muted sub" style={{ marginTop: 2 }}>
          We sent a 6-digit code to {verifyResult.sent_to}. Enter it to finish and pull in any past cuts.
        </p>
        <label htmlFor="vc">Code</label>
        <input id="vc" inputMode="numeric" maxLength={6} value={code} onChange={(e) => setCode(e.target.value)} autoFocus />
        {error && <p className="error" style={{ marginTop: 10 }}>{error}</p>}
        <button className="btn primary block" style={{ marginTop: 14 }} disabled={busy}>
          {busy ? 'Checking…' : 'Verify'}
        </button>
      </form>
    );
  }

  return (
    <form className="card" onSubmit={submit}>
      {onCancel && <button type="button" className="back" onClick={onCancel}>‹ Back</button>}
      <div className="name" style={{ fontSize: '1.15rem' }}>{mode === 'login' ? 'Log in' : 'Create account'}</div>
      {mode === 'register' && (
        <p className="muted sub" style={{ marginTop: 2 }}>
          Use the email or number your barber has, and your past cuts show up automatically.
        </p>
      )}

      <label htmlFor="au">Username</label>
      <input id="au" value={username} onChange={(e) => setUsername(e.target.value)} autoCapitalize="none" required />

      <label htmlFor="ap">Password</label>
      <input id="ap" type="password" value={password} onChange={(e) => setPassword(e.target.value)} required />

      {mode === 'register' && (
        <>
          <label htmlFor="an">Your name</label>
          <input id="an" value={name} onChange={(e) => setName(e.target.value)} />

          <label>Contact method</label>
          <select value={channel} onChange={(e) => setChannel(e.target.value as 'sms' | 'email')}>
            <option value="sms">Text (SMS)</option>
            <option value="email">Email</option>
          </select>

          {channel === 'sms' ? (
            <>
              <label htmlFor="aph">Mobile number</label>
              <input id="aph" inputMode="tel" value={phone} onChange={(e) => setPhone(e.target.value)} />
              <label htmlFor="ac">Carrier</label>
              <select id="ac" value={carrierId} onChange={(e) => setCarrierId(e.target.value === '' ? '' : Number(e.target.value))}>
                <option value="">Select carrier…</option>
                {carriers.map((c) => <option key={c.carrier_id} value={c.carrier_id}>{c.name}</option>)}
              </select>
            </>
          ) : (
            <>
              <label htmlFor="ae">Email</label>
              <input id="ae" type="email" inputMode="email" value={email} onChange={(e) => setEmail(e.target.value)} />
            </>
          )}
        </>
      )}

      {error && <p className="error" style={{ marginTop: 10 }}>{error}</p>}

      {mode === 'login' && passkeysSupported() ? (
        <>
          {/* Passkey is the default when the device supports it. */}
          <button type="button" className="btn primary block" style={{ marginTop: 14 }} disabled={busy} onClick={passkeyLogin}>
            🔑 Log in with a passkey
          </button>
          <button className="btn block" style={{ marginTop: 8 }} disabled={busy}>
            {busy ? 'Please wait…' : 'Use password instead'}
          </button>
        </>
      ) : (
        <button className="btn primary block" style={{ marginTop: 14 }} disabled={busy}>
          {busy ? 'Please wait…' : (mode === 'login' ? 'Log in' : 'Create account')}
        </button>
      )}

      {!loginOnly && (
        <button type="button" className="btn block" style={{ marginTop: 8 }}
          onClick={() => { setMode(mode === 'login' ? 'register' : 'login'); setError(null); }}>
          {mode === 'login' ? 'Need an account? Sign up' : 'Have an account? Log in'}
        </button>
      )}
    </form>
  );
}
