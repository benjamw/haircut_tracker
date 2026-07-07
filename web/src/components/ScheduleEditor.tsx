import { useEffect, useState } from 'react';
import { api, ApiError, type Window, type ScheduleException } from '../lib/api';
import { dateWithDay, slotLen, timeAmPm } from '../lib/format';

interface Conflict { appointment_id: number; slot_start: string; who: string; notify_channel: string; }

function apptWhen(iso: string): string {
  return new Date(iso.replace(' ', 'T')).toLocaleString(undefined, {
    weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
  });
}

const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

export function ScheduleEditor() {
  const [windows, setWindows] = useState<Window[]>([]);
  const [exceptions, setExceptions] = useState<ScheduleException[]>([]);
  const [error, setError] = useState<string | null>(null);

  function load() {
    setError(null);
    api.windows().then((r) => setWindows(r.windows)).catch((e) => setError(String(e.message ?? e)));
    api.exceptions().then((r) => setExceptions(r.exceptions)).catch(() => {});
  }
  useEffect(load, []);

  return (
    <>
      {error && <div className="error">{error}</div>}

      <div className="section-title">Weekly hours</div>
      <div className="muted sub" style={{ marginBottom: 4 }}>Recurring availability. Each block generates hourly slots.</div>
      <div className="list">
        {DAYS.map((label, wd) => (
          <WeekdayRow key={wd} weekday={wd} label={label} windows={windows.filter((w) => w.weekday === wd)} onChanged={load} />
        ))}
      </div>

      <div className="section-title" style={{ marginTop: 18 }}>Time off & custom days</div>
      <ExceptionForm onAdded={load} />
      <div className="list" style={{ marginTop: 10 }}>
        {exceptions.length === 0 && <div className="card muted center">No upcoming blocks or custom days.</div>}
        {exceptions.map((e) => (
          <div key={e.schedule_exception_id} className="card">
            <div className="row">
              <div>
                <div className="name" style={{ fontSize: '.98rem' }}>
                  {e.kind === 'block' ? '🚫 Blocked' : '🕒 Custom hours'}
                </div>
                <div className="sub">
                  {dateWithDay(e.start_date)}{e.end_date !== e.start_date ? ` → ${dateWithDay(e.end_date)}` : ''}
                  {e.kind === 'block'
                    ? (e.all_day ? ' · all day' : ` · ${timeAmPm(e.start_time)}–${timeAmPm(e.end_time)}`)
                    : ` · ${timeAmPm(e.start_time)}–${timeAmPm(e.end_time)}`}
                  {e.note ? ` · ${e.note}` : ''}
                </div>
              </div>
              <button className="btn small danger" onClick={() => api.deleteException(e.schedule_exception_id).then(load)}>✕</button>
            </div>
          </div>
        ))}
      </div>
    </>
  );
}

function WeekdayRow({ weekday, label, windows, onChanged }: {
  weekday: number; label: string; windows: Window[]; onChanged: () => void;
}) {
  const [adding, setAdding] = useState(false);
  const [start, setStart] = useState('10:00');
  const [end, setEnd] = useState('16:00');
  const [slot, setSlot] = useState(60);

  async function add() {
    await api.createWindow({ weekday, start_time: start, end_time: end, slot_minutes: slot });
    setAdding(false);
    onChanged();
  }

  return (
    <div className="card">
      <div className="row">
        <div className="name" style={{ fontSize: '.98rem' }}>{label}</div>
        <button className="btn small" onClick={() => setAdding((v) => !v)}>{adding ? 'Close' : '+ Hours'}</button>
      </div>
      {windows.length === 0 && !adding && <div className="sub">Closed</div>}
      {windows.map((w) => (
        <div key={w.availability_id} className="row" style={{ marginTop: 6 }}>
          <span>{timeAmPm(w.start_time)}–{timeAmPm(w.end_time)} · {slotLen(w.slot_minutes)} slots</span>
          <button className="btn small danger" onClick={() => api.deleteWindow(w.availability_id).then(onChanged)}>✕</button>
        </div>
      ))}
      {adding && (
        <div style={{ marginTop: 8 }}>
          <div className="btn-row">
            <span style={{ flex: 1 }}>
              <label>Open</label>
              <input type="time" value={start} onChange={(e) => setStart(e.target.value)} />
            </span>
            <span style={{ flex: 1 }}>
              <label>Close</label>
              <input type="time" value={end} onChange={(e) => setEnd(e.target.value)} />
            </span>
          </div>
          <label>Slot length (minutes)</label>
          <input inputMode="numeric" value={slot} onChange={(e) => setSlot(Number(e.target.value) || 60)} />
          <button className="btn primary block" style={{ marginTop: 10 }} onClick={add}>Add hours</button>
        </div>
      )}
    </div>
  );
}

function ExceptionForm({ onAdded }: { onAdded: () => void }) {
  const [open, setOpen] = useState(false);
  const [kind, setKind] = useState<'block' | 'custom'>('block');
  const today = new Date().toISOString().slice(0, 10);
  const [startDate, setStartDate] = useState(today);
  const [endDate, setEndDate] = useState(today);
  const [timed, setTimed] = useState(false);
  const [startTime, setStartTime] = useState('12:00');
  const [endTime, setEndTime] = useState('14:00');
  const [note, setNote] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [conflicts, setConflicts] = useState<Conflict[] | null>(null);

  function buildPayload(confirm: boolean) {
    return {
      kind,
      start_date: startDate,
      end_date: endDate,
      note: note || null,
      confirm,
      ...(kind === 'custom' || timed
        ? { start_time: startTime, end_time: endTime, slot_minutes: 60 }
        : {}),
    };
  }

  async function submit(confirm = false) {
    setError(null);
    try {
      await api.createException(buildPayload(confirm));
      setOpen(false);
      setNote('');
      setConflicts(null);
      onAdded();
    } catch (e) {
      if (e instanceof ApiError && e.status === 409 && e.payload?.conflicts) {
        setConflicts(e.payload.conflicts as Conflict[]);
      } else {
        setError(String((e as Error).message ?? e));
      }
    }
  }

  if (!open) {
    return <button className="btn primary block" onClick={() => setOpen(true)}>+ Add time off / custom day</button>;
  }

  return (
    <div className="card">
      <label>Type</label>
      <select value={kind} onChange={(e) => setKind(e.target.value as 'block' | 'custom')}>
        <option value="block">Block off (time off)</option>
        <option value="custom">Custom hours for these dates</option>
      </select>

      <div className="btn-row">
        <span style={{ flex: 1 }}>
          <label>From</label>
          <input type="date" value={startDate} onChange={(e) => { setStartDate(e.target.value); if (e.target.value > endDate) setEndDate(e.target.value); }} />
        </span>
        <span style={{ flex: 1 }}>
          <label>To</label>
          <input type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} />
        </span>
      </div>

      {kind === 'block' && (
        <label style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 10 }}>
          <input type="checkbox" style={{ width: 'auto' }} checked={timed} onChange={(e) => setTimed(e.target.checked)} />
          Only part of the day (otherwise whole day off)
        </label>
      )}

      {(kind === 'custom' || timed) && (
        <div className="btn-row">
          <span style={{ flex: 1 }}>
            <label>{kind === 'custom' ? 'Open' : 'Block from'}</label>
            <input type="time" value={startTime} onChange={(e) => setStartTime(e.target.value)} />
          </span>
          <span style={{ flex: 1 }}>
            <label>{kind === 'custom' ? 'Close' : 'Block until'}</label>
            <input type="time" value={endTime} onChange={(e) => setEndTime(e.target.value)} />
          </span>
        </div>
      )}

      <label>Note (optional)</label>
      <input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Vacation, appointment…" />

      {error && <p className="error" style={{ marginTop: 10 }}>{error}</p>}

      {conflicts && conflicts.length > 0 ? (
        <div style={{ marginTop: 12 }}>
          <div className="error">
            ⚠️ This time off overlaps {conflicts.length} booked appointment{conflicts.length === 1 ? '' : 's'}:
          </div>
          <div className="list" style={{ marginTop: 8 }}>
            {conflicts.map((c) => (
              <div key={c.appointment_id} className="card">
                <div className="name" style={{ fontSize: '.95rem' }}>{c.who}</div>
                <div className="sub">{apptWhen(c.slot_start)} · notify by {c.notify_channel}</div>
              </div>
            ))}
          </div>
          <p className="sub" style={{ marginTop: 8 }}>
            Confirming will cancel these and message each person to reschedule.
          </p>
          <div className="btn-row" style={{ marginTop: 8 }}>
            <button className="btn danger" onClick={() => submit(true)}>Cancel these & block</button>
            <button className="btn" onClick={() => setConflicts(null)}>Never mind</button>
          </div>
        </div>
      ) : (
        <div className="btn-row" style={{ marginTop: 12 }}>
          <button className="btn primary" onClick={() => submit(false)}>Save</button>
          <button className="btn" onClick={() => setOpen(false)}>Cancel</button>
        </div>
      )}
    </div>
  );
}
