// Display helpers. Money is cents in the API; format as dollars here.

export function money(cents: number): string {
  return `$${(cents / 100).toFixed(2)}`;
}

// Slot length: "1h", "2h", "1h 30m", or "45m" when under an hour.
export function slotLen(minutes: number): string {
  if (minutes < 60) return `${minutes}m`;
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return m === 0 ? `${h}h` : `${h}h ${m}m`;
}

// "5555550111" -> "(555) 555-0111"; "15555550111" -> "+1 (555) 555-0111"
export function phone(raw: string | null): string {
  if (!raw) return '';
  const d = raw.replace(/\D/g, '');
  if (d.length === 10) return `(${d.slice(0, 3)}) ${d.slice(3, 6)}-${d.slice(6)}`;
  if (d.length === 11 && d[0] === '1') return `+1 (${d.slice(1, 4)}) ${d.slice(4, 7)}-${d.slice(7)}`;
  return raw; // unknown format — leave as-is
}

// "10:00" -> "10:00 AM", "16:00" -> "4:00 PM"
export function timeAmPm(hhmm: string | null): string {
  if (!hhmm) return '';
  const [h, m] = hhmm.split(':').map(Number);
  const ampm = h < 12 ? 'AM' : 'PM';
  const h12 = h % 12 === 0 ? 12 : h % 12;
  return `${h12}:${String(m).padStart(2, '0')} ${ampm}`;
}

// All dates include the weekday: "Sun, Jun 12, 2026"
export function shortDate(iso: string | null): string {
  if (!iso) return '—';
  const d = new Date(iso + (iso.length === 10 ? 'T00:00:00' : ''));
  return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
}

// Alias kept for callers that opted into the weekday explicitly.
export const dateWithDay = shortDate;

// Human phrasing for the "overdue_by_days" field.
// >= 0 => overdue by N; < 0 => due in N.
export function dueLabel(overdueByDays: number | null): string {
  if (overdueByDays === null) return 'No cadence yet';
  if (overdueByDays === 0) return 'Due today';
  if (overdueByDays > 0) return `Overdue ${overdueByDays}d`;
  return `Due in ${Math.abs(overdueByDays)}d`;
}

export function dueTone(overdueByDays: number | null): 'none' | 'soon' | 'due' | 'over' {
  if (overdueByDays === null) return 'none';
  if (overdueByDays >= 3) return 'over';
  if (overdueByDays >= 0) return 'due';
  return 'soon';
}
