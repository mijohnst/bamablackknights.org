// Halloween splash for the homepage — a small animated night scene behind a
// greeting card. Shows only during October (visitor's local date), at most
// once per day per browser, and removes itself completely on close. From
// November 1 it never shows; leave the <script> tag in index.html and it
// comes back next October.
//
// Preview anytime (ignores the date and the once-a-day limit):
//   https://bamablackknights.org/?halloween-preview
(function () {
  var preview = /[?&]halloween-preview\b/.test(location.search);
  var now = new Date();
  if (!preview && now.getMonth() !== 9) return; // October only (months are 0-based)

  var seenKey = 'wppc-halloween-' + now.getFullYear() + '-' + (now.getMonth() + 1) + '-' + now.getDate();
  if (!preview) {
    try { if (localStorage.getItem(seenKey)) return; } catch (e) { /* storage blocked — just show it */ }
  }

  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var ORANGE = '#E87722', GOLD = '#D4BF91';

  // ── Styles ────────────────────────────────────────────────────────────
  var css = ''
    // Lets the spider's thread length animate smoothly (browsers without
    // @property just step between lengths — still works, less smooth).
    + '@property --len{syntax:"<length>";inherits:true;initial-value:10px}'
    // Overlay + night sky
    + '.hw-splash{position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;padding:16px;overflow:hidden;'
    + 'background:radial-gradient(ellipse at 50% 120%,#3a1a4a 0%,#160a22 45%,#050308 100%);opacity:0;transition:opacity .5s ease}'
    + '.hw-splash.on{opacity:1}'
    + '.hw-sky{position:absolute;inset:0;pointer-events:none}'
    // Stars
    + '.hw-star{position:absolute;width:2px;height:2px;border-radius:50%;background:#fff;opacity:.2;animation:hw-twinkle 3s ease-in-out infinite}'
    + '@keyframes hw-twinkle{0%,100%{opacity:.15;transform:scale(1)}50%{opacity:1;transform:scale(1.8)}}'
    // Moon
    + '.hw-moon{position:absolute;top:7%;right:9%;width:clamp(70px,13vw,130px);aspect-ratio:1;border-radius:50%;'
    + 'background:radial-gradient(circle at 38% 35%,#fffbe8 0%,#f3e3a6 55%,#d9c27a 100%);'
    + 'box-shadow:0 0 40px 12px rgba(255,236,170,.35),0 0 120px 40px rgba(255,200,120,.15);animation:hw-moonglow 5s ease-in-out infinite}'
    + '.hw-moon::before,.hw-moon::after{content:"";position:absolute;border-radius:50%;background:rgba(180,150,80,.25)}'
    + '.hw-moon::before{width:22%;height:22%;top:28%;left:52%}.hw-moon::after{width:14%;height:14%;top:60%;left:30%}'
    + '@keyframes hw-moonglow{0%,100%{box-shadow:0 0 40px 12px rgba(255,236,170,.35),0 0 120px 40px rgba(255,200,120,.15)}'
    + '50%{box-shadow:0 0 55px 18px rgba(255,236,170,.45),0 0 150px 55px rgba(255,200,120,.22)}}'
    // Bats: outer span flies the path, inner span flaps
    + '.hw-bat{position:absolute;left:0;top:0;font-size:var(--s);will-change:transform;animation:hw-fly var(--d) linear infinite;animation-delay:var(--dl)}'
    + '.hw-bat>span{display:inline-block;animation:hw-flap .28s ease-in-out infinite alternate}'
    + '@keyframes hw-flap{from{transform:scaleY(1) scaleX(1)}to{transform:scaleY(.55) scaleX(1.12)}}'
    + '@keyframes hw-fly{0%{transform:translate(-12vw,var(--y0))}25%{transform:translate(20vw,calc(var(--y0) - 8vh))}'
    + '50%{transform:translate(50vw,calc(var(--y1) + 6vh))}75%{transform:translate(80vw,calc(var(--y1) - 7vh))}100%{transform:translate(112vw,var(--y0))}}'
    // Ghosts
    + '.hw-ghost{position:absolute;bottom:-12vh;font-size:var(--s);opacity:0;will-change:transform,opacity;animation:hw-rise var(--d) ease-in infinite;animation-delay:var(--dl)}'
    + '.hw-ghost>span{display:inline-block;animation:hw-sway 2.6s ease-in-out infinite alternate}'
    + '@keyframes hw-rise{0%{transform:translateY(0);opacity:0}15%{opacity:.85}80%{opacity:.6}100%{transform:translateY(-115vh);opacity:0}}'
    + '@keyframes hw-sway{from{transform:translateX(-18px) rotate(-8deg)}to{transform:translateX(18px) rotate(8deg)}}'
    // Falling leaves
    + '.hw-leaf{position:absolute;top:-8vh;font-size:var(--s);will-change:transform;animation:hw-fall var(--d) linear infinite;animation-delay:var(--dl)}'
    + '.hw-leaf>span{display:inline-block;animation:hw-spin var(--sp) linear infinite}'
    + '@keyframes hw-fall{0%{transform:translate(0,0)}25%{transform:translate(4vw,28vh)}50%{transform:translate(-3vw,56vh)}75%{transform:translate(5vw,84vh)}100%{transform:translate(0,116vh)}}'
    + '@keyframes hw-spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}'
    // Fog
    + '.hw-fog{position:absolute;left:-50%;bottom:-6vh;width:200%;height:28vh;pointer-events:none;'
    + 'background:radial-gradient(ellipse at 20% 60%,rgba(220,210,240,.16),transparent 60%),radial-gradient(ellipse at 60% 70%,rgba(220,210,240,.13),transparent 55%),radial-gradient(ellipse at 85% 55%,rgba(220,210,240,.15),transparent 60%);'
    + 'filter:blur(6px);animation:hw-drift 22s linear infinite}'
    + '.hw-fog.b{bottom:-10vh;opacity:.8;animation-duration:34s;animation-direction:reverse}'
    + '@keyframes hw-drift{from{transform:translateX(0)}to{transform:translateX(25%)}}'
    // Card
    + '.hw-card{position:relative;z-index:2;box-sizing:border-box;max-width:min(460px,100%);width:100%;min-width:0;text-align:center;color:#f5f5f0;'
    + 'background:linear-gradient(180deg,rgba(20,12,26,.96),rgba(8,6,10,.97));border:2px solid ' + GOLD + ';border-radius:16px;'
    + 'padding:2.6rem 1.75rem 1.9rem;box-shadow:0 0 0 6px rgba(232,119,34,.22),0 0 60px rgba(232,119,34,.25),0 24px 60px rgba(0,0,0,.7);'
    + 'transform:translateY(30px) scale(.92);opacity:0;transition:transform .6s cubic-bezier(.2,1.4,.4,1),opacity .5s ease}'
    + '.hw-splash.on .hw-card{transform:none;opacity:1}'
    // Cobweb (corner)
    + '.hw-web{position:absolute;top:-2px;left:-2px;width:86px;height:86px;opacity:.55;pointer-events:none}'
    // Spider on a thread
    + '.hw-spider{position:absolute;top:0;right:2.2rem;width:2px;pointer-events:none;transform-origin:top center;animation:hw-dangle 7s ease-in-out infinite}'
    + '.hw-spider::before{content:"";position:absolute;left:0;top:0;width:1px;height:var(--len,60px);background:rgba(255,255,255,.5)}'
    + '.hw-spider span{position:absolute;left:-.62em;top:var(--len,60px);font-size:1.35rem;animation:hw-wiggle 1.4s ease-in-out infinite alternate}'
    + '@keyframes hw-dangle{0%,100%{--len:10px;transform:rotate(0)}30%{--len:70px;transform:rotate(4deg)}55%{--len:58px;transform:rotate(-4deg)}75%{--len:72px;transform:rotate(2deg)}}'
    + '@keyframes hw-wiggle{from{transform:rotate(-12deg)}to{transform:rotate(12deg)}}'
    // Pumpkin
    + '.hw-pumpkin{position:relative;display:inline-block;font-size:4.6rem;line-height:1;margin-bottom:.4rem;cursor:pointer;'
    + 'animation:hw-bob 2.6s ease-in-out infinite;filter:drop-shadow(0 0 14px rgba(232,119,34,.75))}'
    + '.hw-pumpkin::after{content:"";position:absolute;inset:18% 15% 12%;border-radius:50%;background:radial-gradient(circle,rgba(255,200,60,.55),transparent 70%);'
    + 'mix-blend-mode:screen;animation:hw-flicker 1.7s steps(1) infinite;pointer-events:none}'
    + '.hw-pumpkin.boo{animation:hw-boo .6s ease}'
    + '@keyframes hw-bob{0%,100%{transform:translateY(0) rotate(-5deg)}50%{transform:translateY(-10px) rotate(5deg)}}'
    + '@keyframes hw-flicker{0%{opacity:.9}12%{opacity:.4}20%{opacity:1}34%{opacity:.6}48%{opacity:.95}61%{opacity:.35}70%{opacity:.85}86%{opacity:.55}}'
    + '@keyframes hw-boo{0%{transform:scale(1)}35%{transform:scale(1.35) rotate(-10deg)}65%{transform:scale(.9) rotate(8deg)}100%{transform:scale(1)}}'
    // Title with waving, glowing letters
    + '.hw-card h2{font-family:"Cinzel",serif;font-size:clamp(1.45rem,5.5vw,1.9rem);letter-spacing:.05em;color:' + ORANGE + ';margin:0 0 .6rem}.hw-card h2 .w{display:inline-block;white-space:nowrap}'
    + '.hw-card h2 span:not(.w){display:inline-block;animation:hw-wave 2.2s ease-in-out infinite,hw-glow 3s ease-in-out infinite;animation-delay:calc(var(--i) * .08s),calc(var(--i) * .11s)}'
    + '@keyframes hw-wave{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-7px)}}'
    + '@keyframes hw-glow{0%,100%{text-shadow:0 0 6px rgba(232,119,34,.5)}50%{text-shadow:0 0 14px rgba(255,160,60,.95),0 0 28px rgba(232,119,34,.6)}}'
    + '.hw-card p{font-family:"Source Sans 3",sans-serif;font-size:1.03rem;line-height:1.55;margin:0 0 1.5rem;color:#e9e4d6}'
    + '.hw-card .hw-gold{color:' + GOLD + ';font-weight:600}'
    // Buttons
    + '.hw-btn{font-family:"Source Sans 3",sans-serif;font-weight:700;font-size:1rem;letter-spacing:.04em;cursor:pointer;'
    + 'background:' + GOLD + ';color:#000;border:0;border-radius:7px;padding:.8rem 1.8rem;animation:hw-pulse 2.2s ease-in-out infinite}'
    + '.hw-btn:hover{background:' + ORANGE + '}.hw-btn:focus-visible{outline:2px solid #fff;outline-offset:3px}'
    + '@keyframes hw-pulse{0%,100%{box-shadow:0 0 0 0 rgba(212,191,145,.55)}50%{box-shadow:0 0 0 10px rgba(212,191,145,0)}}'
    + '.hw-x{position:absolute;top:.45rem;right:.55rem;z-index:3;background:none;border:0;color:' + GOLD + ';font-size:1.7rem;line-height:1;cursor:pointer;padding:.25rem .5rem}'
    + '.hw-x:focus-visible{outline:2px solid #fff;border-radius:4px}'
    // Calm version for reduced motion: keep the scene, drop the movement
    + '@media (prefers-reduced-motion:reduce){.hw-splash,.hw-card{transition:none}'
    + '.hw-star,.hw-moon,.hw-fog,.hw-pumpkin,.hw-pumpkin::after,.hw-card h2 span,.hw-btn,.hw-spider,.hw-spider span{animation:none}'
    + '.hw-bat,.hw-ghost,.hw-leaf{display:none}.hw-spider{--len:46px}}';

  var style = document.createElement('style');
  style.textContent = css;
  document.head.appendChild(style);

  // ── Scene ─────────────────────────────────────────────────────────────
  function rand(a, b) { return a + Math.random() * (b - a); }
  function el(tag, cls, html) { var n = document.createElement(tag); if (cls) n.className = cls; if (html != null) n.innerHTML = html; return n; }

  var overlay = el('div', 'hw-splash');
  overlay.setAttribute('role', 'dialog');
  overlay.setAttribute('aria-modal', 'true');
  overlay.setAttribute('aria-labelledby', 'hw-title');
  overlay.setAttribute('aria-describedby', 'hw-msg');

  var sky = el('div', 'hw-sky');
  sky.setAttribute('aria-hidden', 'true');
  for (var s = 0; s < 40; s++) {
    var star = el('span', 'hw-star');
    star.style.left = rand(0, 100) + '%';
    star.style.top = rand(0, 65) + '%';
    star.style.animationDelay = rand(0, 3) + 's';
    star.style.animationDuration = rand(2, 4.5) + 's';
    sky.appendChild(star);
  }
  sky.appendChild(el('div', 'hw-moon'));

  if (!reduceMotion) {
    for (var b = 0; b < 7; b++) {
      var bat = el('span', 'hw-bat', '<span>🦇</span>');
      bat.style.setProperty('--s', rand(1.3, 2.6) + 'rem');
      bat.style.setProperty('--y0', rand(5, 45) + 'vh');
      bat.style.setProperty('--y1', rand(5, 45) + 'vh');
      bat.style.setProperty('--d', rand(7, 13) + 's');
      bat.style.setProperty('--dl', (-rand(0, 12)) + 's'); // negative delay = already mid-flight
      sky.appendChild(bat);
    }
    for (var g = 0; g < 3; g++) {
      var ghost = el('span', 'hw-ghost', '<span>👻</span>');
      ghost.style.left = rand(5, 90) + '%';
      ghost.style.setProperty('--s', rand(1.8, 3) + 'rem');
      ghost.style.setProperty('--d', rand(9, 14) + 's');
      ghost.style.setProperty('--dl', (g * 3.5 + rand(0, 2)) + 's');
      sky.appendChild(ghost);
    }
    var leaves = ['🍂', '🍂', '🍬', '🍂', '🍂', '🍬', '🍂', '🍂', '🍬', '🍂'];
    for (var l = 0; l < leaves.length; l++) {
      var leaf = el('span', 'hw-leaf', '<span>' + leaves[l] + '</span>');
      leaf.style.left = rand(0, 98) + '%';
      leaf.style.setProperty('--s', rand(1, 1.8) + 'rem');
      leaf.style.setProperty('--d', rand(9, 16) + 's');
      leaf.style.setProperty('--dl', (-rand(0, 16)) + 's');
      leaf.style.setProperty('--sp', rand(3, 7) + 's');
      sky.appendChild(leaf);
    }
  }
  sky.appendChild(el('div', 'hw-fog'));
  sky.appendChild(el('div', 'hw-fog b'));
  overlay.appendChild(sky);

  // Title split into letters for the wave/glow
  var title = 'Happy Halloween!';
  var letters = '', idx = 0;
  title.split(' ').forEach(function (word, w) {
    if (w) letters += ' '; // wrap point between words
    letters += '<span class="w">';
    for (var i = 0; i < word.length; i++) letters += '<span style="--i:' + (idx++) + '">' + word[i] + '</span>';
    letters += '</span>';
    idx++;
  });

  var web = '<svg class="hw-web" viewBox="0 0 100 100" aria-hidden="true" fill="none" stroke="#fff" stroke-width="1">'
    + '<path d="M0 0 L100 100 M0 0 L100 45 M0 0 L45 100 M0 0 L100 0 M0 0 L0 100"/>'
    + '<path d="M20 0 Q16 16 0 20 M40 0 Q34 34 0 40 M62 0 Q52 52 0 62 M84 0 Q70 70 0 84"/></svg>';

  var card = el('div', 'hw-card',
      web
    + '<div class="hw-spider" aria-hidden="true"><span>🕷️</span></div>'
    + '<button type="button" class="hw-x" aria-label="Close">&times;</button>'
    + '<span class="hw-pumpkin" aria-hidden="true" title="Boo!">🎃</span>'
    + '<h2 id="hw-title" aria-label="' + title + '">' + letters + '</h2>'
    + '<p id="hw-msg">From all of us at the <span class="hw-gold">West Point Parents Club of Alabama</span>, '
    + 'have a spook-tacular October. Don\'t forget a little Halloween boodle for your cadet!</p>'
    + '<button type="button" class="hw-btn">Enter Site</button>');
  // Screen readers read the aria-label on the title, not the letter spans.
  card.querySelector('#hw-title').querySelectorAll('.w').forEach(function (n) { n.setAttribute('aria-hidden', 'true'); });
  overlay.appendChild(card);

  // ── Behavior ──────────────────────────────────────────────────────────
  var lastFocus = null;
  function close() {
    if (!preview) { try { localStorage.setItem(seenKey, '1'); } catch (e) {} }
    overlay.classList.remove('on');
    document.removeEventListener('keydown', onKey);
    document.documentElement.style.overflow = prevOverflow;
    setTimeout(function () { overlay.remove(); style.remove(); }, 500);
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  function onKey(e) {
    if (e.key === 'Escape') { close(); return; }
    if (e.key === 'Tab') { // keep focus on the two buttons while open
      var btns = card.querySelectorAll('button');
      var first = btns[0], last = btns[btns.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  }

  var prevOverflow = '';
  function show() {
    lastFocus = document.activeElement;
    prevOverflow = document.documentElement.style.overflow;
    document.documentElement.style.overflow = 'hidden'; // no page scroll behind the splash
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function (e) { if (e.target === overlay || e.target === sky) close(); });
    card.querySelector('.hw-btn').addEventListener('click', close);
    card.querySelector('.hw-x').addEventListener('click', close);
    var pumpkin = card.querySelector('.hw-pumpkin');
    pumpkin.addEventListener('click', function () {
      pumpkin.classList.remove('boo'); void pumpkin.offsetWidth; pumpkin.classList.add('boo');
    });
    pumpkin.addEventListener('animationend', function (e) { if (e.animationName === 'hw-boo') pumpkin.classList.remove('boo'); });
    document.addEventListener('keydown', onKey);
    requestAnimationFrame(function () {
      overlay.classList.add('on');
      card.querySelector('.hw-btn').focus({ preventScroll: true });
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', show);
  else show();
})();
