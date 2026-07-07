import { useEffect, useRef } from 'react';

// Cloudflare Turnstile widget. The site key is public and comes from a build-time
// env var; if it's unset the widget renders nothing (and the API skips the check
// too, since its secret is unset) — so dev works with no config.
const SITE_KEY = import.meta.env.VITE_TURNSTILE_SITE_KEY ?? '';
const SCRIPT_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

// eslint-disable-next-line @typescript-eslint/no-explicit-any
declare global { interface Window { turnstile?: any } }

let scriptPromise: Promise<void> | null = null;
function loadScript(): Promise<void> {
  if (window.turnstile) return Promise.resolve();
  if (!scriptPromise) {
    scriptPromise = new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = SCRIPT_SRC;
      s.async = true;
      s.defer = true;
      s.onload = () => resolve();
      s.onerror = () => reject(new Error('Failed to load Turnstile'));
      document.head.appendChild(s);
    });
  }
  return scriptPromise;
}

export function turnstileEnabled(): boolean {
  return SITE_KEY !== '';
}

/** Renders the challenge and reports the token (empty string when reset/expired). */
export function Turnstile({ onToken }: { onToken: (token: string) => void }) {
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!SITE_KEY) return;
    let widgetId: string | undefined;
    loadScript()
      .then(() => {
        if (ref.current && window.turnstile) {
          widgetId = window.turnstile.render(ref.current, {
            sitekey: SITE_KEY,
            callback: (t: string) => onToken(t),
            'error-callback': () => onToken(''),
            'expired-callback': () => onToken(''),
          });
        }
      })
      .catch(() => { /* fail open in dev */ });
    return () => { if (widgetId && window.turnstile) window.turnstile.remove(widgetId); };
  }, [onToken]);

  if (!SITE_KEY) return null;
  return <div ref={ref} style={{ marginTop: 12 }} />;
}
