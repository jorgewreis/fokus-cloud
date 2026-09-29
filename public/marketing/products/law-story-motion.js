(() => {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const sections = [...document.querySelectorAll('.law-section, .law-final-cta')];
  if (!('IntersectionObserver' in window) || reduce) return;
  sections.forEach((section) => section.classList.add('law-story-reveal'));
  const reveal = new IntersectionObserver((entries) => entries.forEach((entry) => {
    if (entry.isIntersecting) { entry.target.classList.add('law-story-visible'); reveal.unobserve(entry.target); }
  }), { threshold: .12 });
  sections.forEach((section) => reveal.observe(section));
  const scene = document.querySelector('.law-contact-visual');
  if (!scene) return;
  scene.dataset.parallaxScene = '';
  let visible = false, queued = false;
  const viewport = new IntersectionObserver(([entry]) => { visible = entry.isIntersecting; if (visible) update(); }, { rootMargin: '80px 0px' });
  viewport.observe(scene);
  const update = () => {
    queued = false;
    if (!visible) return;
    const bounds = scene.getBoundingClientRect();
    const progress = Math.max(-1, Math.min(1, (bounds.top + bounds.height / 2 - innerHeight / 2) / innerHeight));
    scene.style.setProperty('--law-scene-shift', `${progress * -16}px`);
    scene.style.setProperty('--law-stamp-shift', `${progress * 10}px`);
  };
  addEventListener('scroll', () => { if (!queued) { queued = true; requestAnimationFrame(update); } }, { passive: true });
  addEventListener('resize', () => { if (visible && !queued) { queued = true; requestAnimationFrame(update); } }, { passive: true });
})();
