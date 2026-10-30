/* The fetched HTML is both the iframe source and the exact copied source. */
(() => {
  // The real hero preview follows its content height, including responsive wraps.
  const hero = document.querySelector('.showcase-preview iframe');
  let observer;
  const sizeHero = () => {
    const body = hero?.contentDocument?.body;
    if (!body) return;
    observer?.disconnect();
    const resize = () => { hero.style.height = Math.ceil(body.getBoundingClientRect().height) + 2 + 'px'; };
    resize();
    if ('ResizeObserver' in window) { observer = new ResizeObserver(resize); observer.observe(body); }
  };
  hero?.addEventListener('load', sizeHero);
  if (hero?.contentDocument?.readyState === 'complete') sizeHero();
  const panel = document.querySelector('#source-panel');
  if (!panel) return;
  const source = document.querySelector('#example-source');
  const status = document.querySelector('#copy-status');
  const copy = document.querySelector('#copy-source');
  const names = { projects: 'Painel de projetos', onboarding: 'Cadastro e preferências', records: 'Gestão de registros' };
  let opener, request = 0;
  document.querySelectorAll('[data-example]').forEach(button => button.addEventListener('click', async () => {
    const slug = button.dataset.example;
    if (!Object.hasOwn(names, slug)) return;
    const current = ++request;
    opener = button;
    panel.hidden = false;
    copy.disabled = true;
    source.textContent = '';
    status.textContent = 'Carregando o HTML completo…';
    document.querySelector('#source-title').textContent = names[slug] + ' — HTML completo';
    panel.scrollIntoView({ behavior: 'instant', block: 'start' });
    try {
      const response = await fetch('/styles/examples/' + slug + '.html');
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const html = await response.text();
      if (current !== request) return;
      source.textContent = html;
      copy.disabled = false;
      status.textContent = 'Código carregado. A folha CSS está incluída no ZIP dos exemplos.';
      copy.focus({ preventScroll: true });
    } catch {
      if (current !== request) return;
      status.textContent = 'Não foi possível carregar o código. Abra o exemplo ou baixe o ZIP e tente novamente.';
    }
  }));
  document.querySelector('#close-source').addEventListener('click', () => {
    ++request; // Ignore a response arriving after dismissal.
    panel.hidden = true;
    opener?.focus();
  });
  copy.addEventListener('click', async () => {
    const current = request;
    try {
      await navigator.clipboard.writeText(source.textContent);
      if (current !== request || panel.hidden) return;
      status.textContent = 'HTML completo copiado.';
    } catch {
      if (current !== request || panel.hidden) return;
      const range = document.createRange();
      range.selectNodeContents(source);
      const selection = window.getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
      status.textContent = 'Cópia automática indisponível. O código foi selecionado para você copiar manualmente.';
    }
  });
})();
