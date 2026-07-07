import { useState } from 'react';
import { api, setToken } from '../lib/api';

// Temporary gate for the Phase A admin token. Replaced by passkey login in Phase C.
export function TokenGate({ onUnlocked }: { onUnlocked: () => void }) {
  const [value, setValue] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setToken(value.trim());
    try {
      await api.listPersons(); // verifies the token
      onUnlocked();
    } catch {
      setError('That token was rejected. Check ADMIN_TOKEN.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="app">
      <div className="topbar"><h1>💈 HeadAhhBlendz — Admin 💈</h1></div>
      <div className="content">
        <div className="card">
          <p className="muted" style={{ marginTop: 0 }}>
            Enter the admin token to continue. (Dev default: <code>dev-admin-token</code>)
          </p>
          <form onSubmit={submit}>
            <label htmlFor="tok">Admin token</label>
            <input
              id="tok"
              type="password"
              value={value}
              onChange={(e) => setValue(e.target.value)}
              autoFocus
            />
            {error && <p className="error" style={{ marginTop: 10 }}>{error}</p>}
            <button className="btn primary block" style={{ marginTop: 14 }} disabled={busy}>
              {busy ? 'Checking…' : 'Unlock'}
            </button>
          </form>
        </div>
      </div>
    </div>
  );
}
