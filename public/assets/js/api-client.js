window.FokusApi = (() => {
  let csrfToken = null;

  async function csrf() {
    if (csrfToken) return csrfToken;
    const response = await fetch('/api/csrf-token', { credentials: 'same-origin' });
    csrfToken = (await response.json()).token;
    return csrfToken;
  }

  async function request(path, options = {}) {
    const method = (options.method || 'GET').toUpperCase();
    const headers = { Accept: 'application/json', ...(options.headers || {}) };
    if (method !== 'GET') headers['X-CSRF-TOKEN'] = await csrf();
    let body = options.body;
    if (body && typeof body === 'object' && !(body instanceof FormData)) body = JSON.stringify(body);
    if (body && !headers['Content-Type'] && !(body instanceof FormData)) headers['Content-Type'] = 'application/json';
    const response = await fetch(`/api${path}`, { ...options, body, method, headers, credentials: 'same-origin', cache: 'no-store' });
    const responseBody = response.status === 204 ? '' : await response.text();
    let payload = null;
    if (responseBody) {
      try {
        payload = JSON.parse(responseBody);
      } catch (_) {
        payload = null;
      }
    }
    if (!response.ok) {
      const validationMessage = payload?.errors
        ? Object.values(payload.errors).flat().find(Boolean)
        : null;
      const fallback = response.status
        ? `Não foi possível concluir a solicitação (HTTP ${response.status}).`
        : 'Não foi possível concluir a solicitação.';
      const error = new Error(validationMessage || payload?.message || fallback);
      error.status = response.status;
      error.errors = payload?.errors || {};
      error.payload = payload;
      throw error;
    }
    return payload;
  }

  // During a platform support session /api/auth/me answers 401 like a guest, so
  // the portal cannot read the customer identity. The platform identity probe is
  // denied with an explicit support code instead, which is the only signal used
  // to offer the support exit; it exposes no customer data.
  async function supportSessionActive() {
    try {
      await request('/backoffice/auth/me');
      return false;
    } catch (error) {
      return error.status === 403 && error.payload?.code === 'support_customer_api_denied';
    }
  }

  return { request, supportSessionActive };
})();
