// API client. Sends the temporary admin token (Phase A/B) as a header.
// Base URL comes from Vite env (VITE_API_BASE).

const API_BASE = import.meta.env.VITE_API_BASE ?? 'http://localhost:8080';
const TOKEN_KEY = 'ht_admin_token';
const USER_TOKEN_KEY = 'ht_user_token';

export function getToken(): string {
  return localStorage.getItem(TOKEN_KEY) ?? '';
}

export function setToken(token: string): void {
  localStorage.setItem(TOKEN_KEY, token);
}

export function clearToken(): void {
  localStorage.removeItem(TOKEN_KEY);
}

// Logged-in customer session (Bearer JWT), separate from the admin token.
export function getUserToken(): string {
  return localStorage.getItem(USER_TOKEN_KEY) ?? '';
}
export function setUserToken(token: string): void {
  localStorage.setItem(USER_TOKEN_KEY, token);
}
export function clearUserToken(): void {
  localStorage.removeItem(USER_TOKEN_KEY);
}

// Remembered username (non-sensitive login hint) — lets returning passkey users
// tap "Log in with a passkey" without retyping their username.
const USERNAME_COOKIE = 'ht_last_username';
export function rememberUsername(username: string): void {
  document.cookie = `${USERNAME_COOKIE}=${encodeURIComponent(username)}; path=/; max-age=${60 * 60 * 24 * 365}; SameSite=Lax`;
}
export function getRememberedUsername(): string {
  const m = document.cookie.match(new RegExp('(?:^|; )' + USERNAME_COOKIE + '=([^;]*)'));
  return m ? decodeURIComponent(m[1]) : '';
}

export class ApiError extends Error {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  constructor(public status: number, message: string, public payload?: any) {
    super(message);
  }
}

async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    'X-Admin-Token': getToken(),
  };
  const userToken = getUserToken();
  if (userToken) headers['Authorization'] = `Bearer ${userToken}`;

  const res = await fetch(`${API_BASE}${path}`, {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  });

  if (res.status === 204) return undefined as T;

  const data = await res.json().catch(() => null);
  if (!res.ok) {
    throw new ApiError(res.status, data?.error ?? `Request failed (${res.status})`, data);
  }
  return data as T;
}

// ---- Types ----

export interface CadenceStats {
  cut_count: number;
  first_cut: string | null;
  last_cut: string | null;
  days_since_last: number | null;
  avg_gap_days: number | null;
  median_gap_days: number | null;
  usual_cadence_days: number | null;
  cadence_source: 'override' | 'computed' | null;
  due: boolean;
  overdue_by_days: number | null;
}

export interface Haircut {
  haircut_id: number;
  haircut_date: string;
  haircut_time: string | null;
  amount_cents: number;
  notes: string | null;
  created_by: string | null;
  created_at: string;
}

export interface Person {
  user_id: number;
  display_name: string;
  email: string | null;
  phone: string | null;
  carrier_id: number | null;
  usual_cadence_days: number | null;
  preferred_channel: 'email' | 'sms';
  notify_opt_out: boolean;
  inactive: boolean;
  last_contacted_at: string | null;
  notes: string | null;
  created_at: string;
  stats: CadenceStats;
  total_spent_cents: number;
  is_admin?: boolean;
  haircuts?: Haircut[];
  account?: { user_id: number; username: string; status: 'active' | 'blocked'; role: string } | null;
}

export interface DueItem {
  user_id: number;
  display_name: string;
  phone: string | null;
  email: string | null;
  preferred_channel: 'email' | 'sms';
  notify_opt_out: boolean;
  last_contacted_at: string | null;
  usual_cadence_days: number | null;
  days_since_last: number | null;
  overdue_by_days: number | null;
  last_cut: string | null;
}

export interface PersonInput {
  display_name?: string;
  email?: string | null;
  phone?: string | null;
  carrier_id?: number | null;
  usual_cadence_days?: number | null;
  preferred_channel?: 'email' | 'sms';
  notify_opt_out?: boolean;
  inactive?: boolean;
  notes?: string | null;
}

export interface HaircutInput {
  haircut_date?: string;
  haircut_time?: string | null;
  amount_cents?: number;
  notes?: string | null;
}

export interface Window {
  availability_id: number;
  weekday: number;
  start_time: string;
  end_time: string;
  slot_minutes: number;
  active: boolean;
}

export interface ScheduleException {
  schedule_exception_id: number;
  kind: 'block' | 'custom';
  start_date: string;
  end_date: string;
  all_day: boolean;
  start_time: string | null;
  end_time: string | null;
  slot_minutes: number | null;
  note: string | null;
}

export interface Appointment {
  appointment_id: number;
  slot_start: string;
  slot_end: string;
  status: string;
  user_id: number | null;
  person_name: string | null;
  contact_name: string | null;
  contact_email: string | null;
  contact_phone: string | null;
  notify_channel: 'email' | 'sms' | null;
}

// Public booking types
export interface Slot { start: string; end: string; label: string; }
export interface SlotDay { date: string; weekday: number; slots: Slot[]; }
export interface Carrier { carrier_id: number; name: string; }

export interface BookStartInput {
  slot_start: string;
  name: string;
  channel: 'email' | 'sms';
  email?: string;
  phone?: string;
  carrier_id?: number;
  website?: string; // honeypot
  turnstile_token?: string;
}

export interface AuthResult {
  token: string;
  user: { user_id: number; username: string; display_name: string; role: 'user' | 'admin' };
  contact_verification_required?: boolean;
  channel?: 'email' | 'sms';
  sent_to?: string;
}

export interface MeResponse {
  user: { user_id: number; username: string; role: 'user' | 'admin'; has_passkey: boolean };
  person: {
    user_id: number;
    display_name: string;
    email: string | null;
    phone: string | null;
    carrier_id: number | null;
    preferred_channel: 'email' | 'sms';
    email_verified: boolean;
    phone_verified: boolean;
    preferred_verified: boolean;
  };
  stats: CadenceStats;
  next_appointment: { appointment_id: number; slot_start: string; slot_end: string } | null;
  haircuts: { haircut_date: string; haircut_time: string | null; notes: string | null }[];
}

export interface RegisterInput {
  username: string;
  password: string;
  name?: string;
  email?: string;
  phone?: string;
  carrier_id?: number;
  preferred_channel?: 'email' | 'sms';
}

// ---- Endpoints ----

export const api = {
  ping: () => request<{ status: string; db: string }>('GET', '/health'),

  listPersons: () => request<{ persons: Person[] }>('GET', '/admin/persons'),
  getPerson: (id: number) => request<Person>('GET', `/admin/persons/${id}`),
  createPerson: (input: PersonInput) => request<Person>('POST', '/admin/persons', input),
  updatePerson: (id: number, input: PersonInput) =>
    request<Person>('PATCH', `/admin/persons/${id}`, input),
  deletePerson: (id: number) => request<void>('DELETE', `/admin/persons/${id}`),
  mergePersons: (sourceId: number, targetId: number) =>
    request<Person>('POST', '/admin/persons/merge', { source_id: sourceId, target_id: targetId }),

  setUserStatus: (id: number, status: 'active' | 'blocked') =>
    request<{ ok: boolean }>('PATCH', `/admin/users/${id}`, { status }),
  deleteUser: (id: number) => request<void>('DELETE', `/admin/users/${id}`),

  addHaircut: (personId: number, input: HaircutInput) =>
    request<Haircut>('POST', `/admin/persons/${personId}/haircuts`, input),
  updateHaircut: (hid: number, input: HaircutInput) =>
    request<Haircut>('PATCH', `/admin/haircuts/${hid}`, input),
  deleteHaircut: (hid: number) => request<void>('DELETE', `/admin/haircuts/${hid}`),

  due: (within = 7) => request<{ due: DueItem[]; within_days: number }>('GET', `/admin/due?within=${within}`),
  markContacted: (id: number) =>
    request<{ ok: boolean }>('POST', `/admin/persons/${id}/mark-contacted`),

  // Appointments (admin)
  appointments: () => request<{ upcoming: Appointment[]; to_record: Appointment[] }>('GET', '/admin/appointments'),
  cancelAppointment: (id: number) => request<{ ok: boolean }>('POST', `/admin/appointments/${id}/cancel`),
  recordAppointment: (id: number, body: { amount_cents: number; notes: string | null }) =>
    request<{ ok: boolean; haircut_id: number }>('POST', `/admin/appointments/${id}/record`, body),

  // Availability (admin)
  windows: () => request<{ windows: Window[] }>('GET', '/admin/availability'),
  createWindow: (w: Partial<Window>) => request<Window>('POST', '/admin/availability', w),
  updateWindow: (id: number, w: Partial<Window>) => request<Window>('PATCH', `/admin/availability/${id}`, w),
  deleteWindow: (id: number) => request<void>('DELETE', `/admin/availability/${id}`),
  exceptions: () => request<{ exceptions: ScheduleException[] }>('GET', '/admin/exceptions'),
  createException: (e: Partial<ScheduleException> & { confirm?: boolean }) =>
    request<ScheduleException & { cancelled_count?: number }>('POST', '/admin/exceptions', e),
  deleteException: (id: number) => request<void>('DELETE', `/admin/exceptions/${id}`),

  // Public booking (no token needed)
  slots: (days = 14) => request<{ days: SlotDay[] }>('GET', `/slots?days=${days}`),
  carriers: () => request<{ carriers: Carrier[] }>('GET', '/carriers'),
  bookStart: (input: BookStartInput) =>
    request<{ hold_id: number; channel: string; sent_to: string; code_expires_in_minutes: number }>(
      'POST', '/book/start', input),
  bookVerify: (holdId: number, code: string) =>
    request<{ status: string; appointment: { appointment_id: number; slot_start: string; slot_end: string } }>(
      'POST', '/book/verify', { hold_id: holdId, code }),

  // User accounts / logged-in
  register: (input: RegisterInput) => request<AuthResult>('POST', '/auth/register', input),
  login: (username: string, password: string) =>
    request<AuthResult>('POST', '/auth/login', { username, password }),
  me: () => request<MeResponse>('GET', '/me'),
  meBook: (slotStart: string) =>
    request<{ status: string; appointment: { appointment_id: number; slot_start: string; slot_end: string } }>(
      'POST', '/me/book', { slot_start: slotStart }),
  meCancel: (id: number) => request<{ ok: boolean }>('POST', `/me/appointments/${id}/cancel`),
  meVerifyContact: (code: string) =>
    request<{ verified: boolean; claimed: boolean }>('POST', '/me/verify-contact', { code }),
  meSendContactCode: () =>
    request<{ sent_to: string; channel: 'email' | 'sms' }>('POST', '/me/contact/send-code', {}),
  meUpdateProfile: (body: {
    display_name?: string; email?: string | null; phone?: string | null;
    carrier_id?: number | null; preferred_channel?: 'email' | 'sms';
  }) => request<{ ok: boolean }>('PATCH', '/me/profile', body),
  meChangePassword: (currentPassword: string, newPassword: string) =>
    request<{ ok: boolean }>('POST', '/me/password', { current_password: currentPassword, new_password: newPassword }),

  // Passkeys (options are passed straight to the WebAuthn browser API)
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  passkeyRegisterOptions: () => request<any>('POST', '/me/passkey/options', {}),
  passkeyRegisterVerify: (body: { clientDataJSON: string; attestationObject: string }) =>
    request<{ ok: boolean }>('POST', '/me/passkey/verify', body),
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  passkeyLoginOptions: (username: string) => request<any>('POST', '/auth/passkey/options', { username }),
  passkeyLoginVerify: (body: { username: string; id: string; clientDataJSON: string; authenticatorData: string; signature: string }) =>
    request<AuthResult>('POST', '/auth/passkey/verify', body),
};
