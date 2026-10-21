/* Mapa Wi-Fi v2.0 na página inicial (carregado pelo bloco embed/bloco-home.html).
   Fica num arquivo separado para o WordPress e o cache não alterarem o código.
   - Coloca o Mapa Wi-Fi v2.0 logo depois do título de entrada.
   - Guarda a versão 1.0 (números, filtros e mapa do plugin) atrás do botão "Retomar a versão 1.0".
   - "Descubra o acervo" só desce até o Mapa Wi-Fi v2.0. */
(function () {
  var s = document.getElementById('mapa-wifi') || document.querySelector('.mv-wifi');
  var hero = document.querySelector('.mv3-wrap > .mv3-hero');
  /* "Descubra o acervo" desce até o Mapa Wi-Fi v2.0, logo abaixo do texto de entrada. */
  /* Vale também quando outro elemento invisível está por cima do botão: conta a área do botão na tela. */
  window.addEventListener('click', function (e) {
    if (e.button || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = (e.target.closest && e.target.closest('a.mv3-hero__cta')) || null;
    if (!a) {
      var c = document.querySelector('a.mv3-hero__cta'); if (!c) return;
      var r = c.getBoundingClientRect();
      if (!(r.width && e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom)) return;
      a = c;
    }
    e.preventDefault(); e.stopImmediatePropagation();
    var alvo = document.getElementById('mapa-wifi');
    if (!alvo) { window.location.assign(a.href); return; }
    alvo.scrollIntoView({ behavior: 'smooth', block: 'start' });
    if (history.replaceState) { history.replaceState(null, '', '#mapa-wifi'); }
  }, true);
  (function () { var c = document.querySelector('a.mv3-hero__cta'); if (c) { c.setAttribute('href', '#mapa-wifi'); } })();
  if (!s || !hero) return;
  if (hero.nextElementSibling !== s) hero.parentNode.insertBefore(s, hero.nextSibling);
  s.classList.add('mv-wifi--topo');
  var stats = hero.querySelector('.mv3-hero__stats');
  /* Versão 1.0 (números + filtros + mapa do plugin) guardada atrás de um botão. Fica montado fora de vista,
     com o tamanho real, para abrir já funcionando. */
  var wrap = hero.parentNode, cont = wrap.querySelector('#mv3-map-container');
  if (!cont || wrap.querySelector('.mv-anterior')) return;
  var box = document.createElement('div'); box.className = 'mv-anterior';
  box.innerHTML = '<button type="button" class="mv-anterior__btn" aria-expanded="false" aria-controls="mv-anterior-corpo">' +
    '<span>Retomar a versão 1.0</span><small>O mapa anterior: só monumentos e pontos de acesso, com filtros.</small></button>' +
    '<div class="mv-anterior__corpo" id="mv-anterior-corpo"></div>';
  var corpo = box.lastChild;
  if (stats) { corpo.appendChild(stats); stats.classList.add('mv-stats-v1'); }
  ['.mv3-filters', '#mv3-map-loading', '#mv3-map-error', '#mv3-map-container'].forEach(function (sel) {
    var el = wrap.querySelector(sel); if (el) corpo.appendChild(el);
  });
  s.parentNode.insertBefore(box, s.nextSibling);
  var btn = box.firstChild;
  btn.addEventListener('click', function () {
    var aberto = box.classList.toggle('aberto');
    btn.setAttribute('aria-expanded', aberto ? 'true' : 'false');
    btn.firstChild.textContent = aberto ? 'Guardar a versão 1.0' : 'Retomar a versão 1.0';
    if (aberto) setTimeout(function () { window.dispatchEvent(new Event('resize')); }, 60);
  });
})();
