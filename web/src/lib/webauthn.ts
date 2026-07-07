// WebAuthn ceremony helpers. The API (lbuchs/webauthn) sends challenge/id
// fields as base64url strings; the browser needs ArrayBuffers, and vice-versa.
import { api, setUserToken, type AuthResult } from './api';

function b64urlToBuf(s: string): ArrayBuffer {
  const pad = '='.repeat((4 - (s.length % 4)) % 4);
  const b64 = (s + pad).replace(/-/g, '+').replace(/_/g, '/');
  const bin = atob(b64);
  const bytes = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  return bytes.buffer;
}

function bufToB64url(buf: ArrayBuffer): string {
  const bytes = new Uint8Array(buf);
  let bin = '';
  for (const b of bytes) bin += String.fromCharCode(b);
  return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export function passkeysSupported(): boolean {
  return typeof window !== 'undefined' && !!window.PublicKeyCredential;
}

/** Enroll a passkey for the logged-in user (Bearer token already set). */
export async function enrollPasskey(): Promise<void> {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const opts: any = await api.passkeyRegisterOptions();
  const pub = opts.publicKey;
  pub.challenge = b64urlToBuf(pub.challenge);
  pub.user.id = b64urlToBuf(pub.user.id);
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  (pub.excludeCredentials ?? []).forEach((c: any) => { c.id = b64urlToBuf(c.id); });

  const cred = (await navigator.credentials.create({ publicKey: pub })) as PublicKeyCredential | null;
  if (!cred) throw new Error('Passkey setup was cancelled');
  const resp = cred.response as AuthenticatorAttestationResponse;

  await api.passkeyRegisterVerify({
    clientDataJSON: bufToB64url(resp.clientDataJSON),
    attestationObject: bufToB64url(resp.attestationObject),
  });
}

/** Log in with a passkey (username-first). Sets the session token on success. */
export async function loginWithPasskey(username: string): Promise<AuthResult> {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const opts: any = await api.passkeyLoginOptions(username);
  const pub = opts.publicKey;
  pub.challenge = b64urlToBuf(pub.challenge);
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  (pub.allowCredentials ?? []).forEach((c: any) => { c.id = b64urlToBuf(c.id); });

  const cred = (await navigator.credentials.get({ publicKey: pub })) as PublicKeyCredential | null;
  if (!cred) throw new Error('Passkey login was cancelled');
  const resp = cred.response as AuthenticatorAssertionResponse;

  const result = await api.passkeyLoginVerify({
    username,
    id: cred.id, // already base64url
    clientDataJSON: bufToB64url(resp.clientDataJSON),
    authenticatorData: bufToB64url(resp.authenticatorData),
    signature: bufToB64url(resp.signature),
  });
  setUserToken(result.token);
  return result;
}
