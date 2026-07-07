import { useEffect, useState } from 'react';
import { api, ApiError, clearUserToken, getUserToken, type MeResponse } from './lib/api';
import { enrollPasskey, passkeysSupported } from './lib/webauthn';
import { DueList } from './components/DueList';
import { Roster } from './components/Roster';
import { PersonDetail } from './components/PersonDetail';
import { PersonForm } from './components/PersonForm';
import { ScheduleEditor } from './components/ScheduleEditor';
import { AppointmentsView } from './components/AppointmentsView';

type Tab = 'due' | 'people' | 'appts' | 'schedule';

export default function AdminApp() {
  const [me, setMe] = useState<MeResponse | null>(null);
  const [loading, setLoading] = useState(getUserToken() !== '');

  const [tab, setTab] = useState<Tab>('due');
  const [openId, setOpenId] = useState<number | null>(null);
  const [adding, setAdding] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);

  function loadMe() {
    // No login UI on /admin — the login lives on /. Anyone who isn't a
    // confirmed admin is sent there.
    if (getUserToken() === '') { window.location.href = '/'; return; }
    setLoading(true);
    api.me()
      .then((m) => {
        if (m.user.role === 'admin') { setMe(m); }
        else { window.location.href = '/'; }
      })
      .catch((e) => {
        if (e instanceof ApiError && e.status === 401) clearUserToken();
        window.location.href = '/';
      })
      .finally(() => setLoading(false));
  }
  useEffect(loadMe, []);

  function logout() { clearUserToken(); window.location.href = '/'; }

  // While loading or redirecting, render a neutral placeholder (no login here).
  if (loading || !me) {
    return <div className="app"><div className="topbar"><h1>💈 HeadAhhBlendz 💈</h1></div><div className="content"><div className="card muted center">…</div></div></div>;
  }

  const bump = () => setReloadKey((k) => k + 1);
  const open = (id: number) => setOpenId(id);
  const overlay = openId !== null || adding;

  return (
    <div className="app">
      <div className="topbar">
        <h1>💈 HeadAhhBlendz — Admin 💈</h1>
        <button className="link" onClick={logout}>Log out</button>
      </div>

      <div className="content">
        {!overlay && !me.user.has_passkey && passkeysSupported() && <AdminPasskeySetup />}

        {openId !== null ? (
          <PersonDetail personId={openId} onBack={() => setOpenId(null)} onChanged={bump} />
        ) : adding ? (
          <PersonForm onSaved={(p) => { setAdding(false); bump(); setOpenId(p.user_id); }} onCancel={() => setAdding(false)} />
        ) : tab === 'due' ? (
          <DueList key={reloadKey} onOpenPerson={open} />
        ) : tab === 'people' ? (
          <Roster reloadKey={reloadKey} onOpenPerson={open} onAddPerson={() => setAdding(true)} />
        ) : tab === 'appts' ? (
          <AppointmentsView key={reloadKey} onOpenPerson={open} />
        ) : (
          <ScheduleEditor />
        )}
      </div>

      {!overlay && (
        <nav className="tabbar">
          <button className={tab === 'due' ? 'active' : ''} onClick={() => setTab('due')}><span className="tab-icon">🔔</span>Reach out</button>
          <button className={tab === 'people' ? 'active' : ''} onClick={() => setTab('people')}><span className="tab-icon">👥</span>People</button>
          <button className={tab === 'appts' ? 'active' : ''} onClick={() => setTab('appts')}><span className="tab-icon">📅</span>Appts</button>
          <button className={tab === 'schedule' ? 'active' : ''} onClick={() => setTab('schedule')}><span className="tab-icon">⚙️</span>Hours</button>
        </nav>
      )}
    </div>
  );
}

// Optional passkey enrollment for the barber's device.
function AdminPasskeySetup() {
  const [state, setState] = useState<'idle' | 'busy' | 'done' | 'error'>('idle');
  const [msg, setMsg] = useState<string | null>(null);

  if (state === 'done') return null;

  async function setup() {
    setState('busy'); setMsg(null);
    try { await enrollPasskey(); setState('done'); }
    catch (e) { setState('error'); setMsg(String((e as Error).message ?? e)); }
  }

  return (
    <div className="card">
      <div className="row">
        <div><div className="name" style={{ fontSize: '.95rem' }}>Faster admin login</div>
          <div className="sub">Set up a passkey on this device.</div></div>
        <button className="btn small primary" disabled={state === 'busy'} onClick={setup}>
          {state === 'busy' ? 'Setting up…' : '🔑 Set up'}
        </button>
      </div>
      {msg && <p className="error" style={{ marginTop: 10 }}>{msg}</p>}
    </div>
  );
}
