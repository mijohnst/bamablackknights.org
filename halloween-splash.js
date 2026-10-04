// Halloween splash for the homepage — shows only during October (visitor's
// local date), at most once per day per browser, and removes itself on
// close. Nothing to turn off afterward: from November 1 it never shows.
// To reuse next year, leave the <script> tag in index.html as-is.
(function () {
  var now = new Date();
  if (now.getMonth() !== 9) return; // October only (months are 0-based)

  var seenKey = 'wppc-halloween-' + now.getFullYear() + '-' + now.getDate();
  try { if (localStorage.getItem(seenKey)) return; } catch (e) { /* storage blocked — just show it */ }

  var css = ''
    + '.hw-splash{position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;padding:16px;'
    + 'background:rgba(0,0,0,.78);opacity:0;transition:opacity .35s ease}'
    + '.hw-splash.on{opacity:1}'
    + '.hw-card{position:relative;max-width:440px;width:100%;text-align:center;background:#0b0b0b;color:#f5f5f0;'
    + 'border:2px solid #D4BF91;border-radius:14px;padding:2.25rem 1.75rem 1.75rem;box-shadow:0 0 0 6px rgba(232,119,34,.25),0 18px 50px rgba(0,0,0,.6);'
    + 'transform:translateY(12px) scale(.97);transition:transform .35s ease}'
    + '.hw-splash.on .hw-card{transform:none}'
    + '.hw-pumpkin{font-size:4.25rem;line-height:1;display:block;margin-bottom:.5rem;animation:hw-bob 2.4s ease-in-out infinite}'
    + '.hw-card h2{font-family:"Cinzel",serif;font-size:1.65rem;letter-spacing:.04em;color:#E87722;margin:0 0 .5rem}'
    + '.hw-card p{font-family:"Source Sans 3",sans-serif;font-size:1.02rem;line-height:1.55;margin:0 0 1.4rem;color:#e9e4d6}'
    + '.hw-card .hw-gold{color:#D4BF91;font-weight:600}'
    + '.hw-btn{font-family:"Source Sans 3",sans-serif;font-weight:700;font-size:1rem;letter-spacing:.03em;cursor:pointer;'
    + 'background:#D4BF91;color:#000;border:0;border-radius:6px;padding:.75rem 1.6rem}'
    + '.hw-btn:hover,.hw-btn:focus-visible{background:#E87722;color:#000;outline:2px solid #fff;outline-offset:2px}'
    + '.hw-x{position:absolute;top:.5rem;right:.6rem;background:none;border:0;color:#D4BF91;font-size:1.6rem;line-height:1;cursor:pointer;padding:.25rem .5rem}'
    + '.hw-bat{position:fixed;top:0;left:0;font-size:2rem;pointer-events:none;z-index:10001;animation:hw-fly linear forwards}'
    + '@keyframes hw-bob{0%,100%{transform:translateY(0) rotate(-4deg)}50%{transform:translateY(-8px) rotate(4deg)}}'
    + '@keyframes hw-fly{from{transform:translate(-10vw,var(--y0)) rotate(-8deg)}to{transform:translate(110vw,var(--y1)) rotate(8deg)}}'
    + '@media (prefers-reduced-motion:reduce){.hw-splash,.hw-card{transition:none}.hw-pumpkin{animation:none}.hw-bat{display:none}}';

  var style = document.createElement('style');
  style.textContent = css;
  document.head.appendChild(style);

  var overlay = document.createElement('div');
  overlay.className = 'hw-splash';
  overlay.setAttribute('role', 'dialog');
  overlay.setAttribute('aria-modal', 'true');
  overlay.setAttribute('aria-labelledby', 'hw-title');
  overlay.innerHTML = ''
    + '<div class="hw-card">'
    +   '<button type="button" class="hw-x" aria-label="Close">&times;</button>'
    +   '<span class="hw-pumpkin" aria-hidden="true">🎃</span>'
    +   '<h2 id="hw-title">Happy Halloween!</h2>'
    +   '<p>From all of us at the <span class="hw-gold">West Point Parents Club of Alabama</span>, '
    +   'have a spook-tacular October. Don\'t forget a little Halloween boodle for your cadet!</p>'
    +   '<button type="button" class="hw-btn">Enter Site</button>'
    + '</div>';

  var lastFocus = null;
  function close() {
    try { localStorage.setItem(seenKey, '1'); } catch (e) {}
    overlay.classList.remove('on');
    document.removeEventListener('keydown', onKey);
    setTimeout(function () { overlay.remove(); }, 350);
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  function onKey(e) {
    if (e.key === 'Escape') close();
    // Keep Tab inside the two buttons while the dialog is open.
    if (e.key === 'Tab') {
      var btns = overlay.querySelectorAll('button');
      var first = btns[0], last = btns[btns.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  }

  function show() {
    lastFocus = document.activeElement;
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    overlay.querySelector('.hw-btn').addEventListener('click', close);
    overlay.querySelector('.hw-x').addEventListener('click', close);
    document.addEventListener('keydown', onKey);
    requestAnimationFrame(function () { overlay.classList.add('on'); overlay.querySelector('.hw-btn').focus(); });

    // A few bats drift across the screen (pointer-events:none, so they never block a click).
    for (var i = 0; i < 4; i++) {
      var bat = document.createElement('span');
      bat.className = 'hw-bat';
      bat.setAttribute('aria-hidden', 'true');
      bat.textContent = '🦇';
      bat.style.setProperty('--y0', (10 + Math.random() * 60) + 'vh');
      bat.style.setProperty('--y1', (5 + Math.random() * 60) + 'vh');
      bat.style.animationDuration = (5 + Math.random() * 4) + 's';
      bat.style.animationDelay = (i * 0.9) + 's';
      document.body.appendChild(bat);
      bat.addEventListener('animationend', function () { this.remove(); });
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', show);
  else show();
})();
