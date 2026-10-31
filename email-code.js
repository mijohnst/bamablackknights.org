/**
 * Emailed verification code step for the public lookup forms.
 * ─────────────────────────────────────────────────────────────────────────────
 * Pairs with admin/email-code.php. The lookup button POSTs the form's fields
 * to its endpoint, which emails a 3-character code to the address typed and
 * returns a challengeId. This script then swaps the lookup fields for a code
 * box; a correct code POSTs { action: 'verify', challengeId, code } to the
 * same endpoint, whose response goes to onVerified exactly as the old
 * one-step lookup's did.
 *
 * EmailCode.attach({
 *   endpoint:   'update-lookup.php',
 *   button:     the lookup button,
 *   fields:     wrapper around the lookup inputs + button (hidden during the code step),
 *   status:     the lookup status box (uses .error / .success classes),
 *   collect:    function () -> { payload, email } or { error: 'message' },
 *   onVerified: function (result) — the verified lookup response,
 *   contact:    email address to suggest when something goes wrong
 * });
 * ─────────────────────────────────────────────────────────────────────────────
 */
(function () {
  function el(tag, attrs, text) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
    if (text) e.textContent = text;
    return e;
  }

  function attach(opts) {
    var challengeId = '';
    var buttonLabel = opts.button.textContent;

    function showStatus(text, kind) {
      opts.status.textContent = text;
      opts.status.className = kind || '';
      opts.status.style.display = text ? 'block' : 'none';
    }

    async function post(body) {
      var resp = await fetch(opts.endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      });
      return resp.json();
    }

    // ── Code step markup, inserted right after the lookup fields ──────────
    var step = el('div', { 'class': 'email-code-step' });
    step.hidden = true;
    var intro = el('p', { style: 'color:var(--gray);font-size:0.95rem;margin-bottom:1.25rem' });
    var sentTo = el('strong');
    intro.append("If that information matches a record on file, we've sent a 3-character code (letters and numbers) to ", sentTo,
      ". It expires in 10 minutes. Don't see it? Check your spam or junk folder.");
    var group = el('div', { 'class': 'form-group', style: 'max-width:14rem' });
    var inputId = 'emailCode-' + Math.random().toString(36).slice(2);
    var input = el('input', { type: 'text', id: inputId, autocomplete: 'one-time-code', maxlength: '3',
      autocapitalize: 'characters', autocorrect: 'off', spellcheck: 'false',
      style: 'text-transform:uppercase;letter-spacing:.3em;font-size:1.25rem' });
    group.append(el('label', { 'for': inputId }, 'Verification Code *'), input);
    var verifyBtn = el('button', { type: 'button', 'class': 'cta-button' }, 'Verify & Continue');
    var links = el('p', { style: 'font-size:.85rem;margin-top:.85rem' });
    var resend = el('a', { href: '#', style: 'color:var(--blue)' }, 'Send a new code');
    var change = el('a', { href: '#', style: 'color:var(--blue)' }, 'Use different information');
    links.append(resend, ' · ', change);
    step.append(intro, group, verifyBtn, links);
    opts.fields.parentNode.insertBefore(step, opts.fields.nextSibling);

    // ── Step 1: send the code ─────────────────────────────────────────────
    var sending = false;
    async function requestCode(isResend) {
      if (sending) return;
      showStatus('');
      var c = opts.collect();
      if (c.error) { showStatus(c.error, 'error'); return; }

      sending = true;
      opts.button.textContent = 'Sending…';
      opts.button.disabled = true;
      try {
        var result = await post(c.payload);
        if (result.success) {
          challengeId = result.challengeId;
          sentTo.textContent = c.email;
          input.value = '';
          opts.fields.hidden = true;
          step.hidden = false;
          input.focus();
          if (isResend) showStatus('A new code is on its way. Only the newest code will work.', 'success');
        } else {
          showStatus(result.error || "We couldn't send a code. Please try again.", 'error');
        }
      } catch (err) {
        showStatus('Error sending your code. Please try again or contact ' + opts.contact, 'error');
      } finally {
        sending = false;
        opts.button.textContent = buttonLabel;
        opts.button.disabled = false;
      }
    }

    opts.button.addEventListener('click', function () { requestCode(false); });
    resend.addEventListener('click', function (e) { e.preventDefault(); requestCode(true); });
    change.addEventListener('click', function (e) {
      e.preventDefault();
      challengeId = '';
      showStatus('');
      step.hidden = true;
      opts.fields.hidden = false;
    });

    // ── Step 2: verify it ─────────────────────────────────────────────────
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); verifyBtn.click(); }
    });

    verifyBtn.addEventListener('click', async function () {
      var code = input.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
      showStatus('');
      if (code.length !== 3) {
        showStatus('Please enter the 3-character code from the email.', 'error');
        return;
      }
      verifyBtn.textContent = 'Verifying…';
      verifyBtn.disabled = true;
      try {
        var result = await post({ action: 'verify', challengeId: challengeId, code: code });
        if (result.success) {
          opts.onVerified(result);
        } else {
          showStatus(result.error || "That code didn't work. Please try again.", 'error');
        }
      } catch (err) {
        showStatus('Error verifying your code. Please try again or contact ' + opts.contact, 'error');
      } finally {
        verifyBtn.textContent = 'Verify & Continue';
        verifyBtn.disabled = false;
      }
    });
  }

  window.EmailCode = { attach: attach };
})();
