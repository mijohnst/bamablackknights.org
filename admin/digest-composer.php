<?php
require_once __DIR__ . '/auth.php';
require_digest_composer_access();

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf'];

admin_header('Digest Composer');
?>
<style>
.digest-card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:1.5rem;max-width:900px;margin-bottom:1.25rem}
.digest-hint{font-size:.85rem;color:#5a6a7a;margin-bottom:1rem;line-height:1.5}
#raw-input{min-height:260px;font-family:"Segoe UI",Arial,sans-serif}
.digest-actions{display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;margin-top:.9rem}
.digest-status{font-size:.85rem;color:#5a6a7a}
.digest-result{display:none}
.digest-preview{border:1px solid #d0d5dd;border-radius:4px;padding:1.25rem 1.5rem;background:#fbfcfe;margin-top:.75rem}
.digest-preview h3{color:#002554;font-size:1rem;margin:1rem 0 .4rem}
.digest-preview h3:first-child{margin-top:0}
.digest-preview p{margin:0 0 .75rem;line-height:1.5}
.digest-preview ul{margin:0 0 .75rem 1.25rem}
.digest-preview li{margin-bottom:.35rem;line-height:1.5}
.digest-preview a{color:#003594}
.copy-toast{display:none;color:#2e7d32;font-size:.82rem;font-weight:600}
.digest-textout{width:100%;min-height:180px;margin-top:.5rem;font-family:ui-monospace,Consolas,monospace;font-size:.82rem}
</style>

<div class="page-head">
  <h1>Digest Composer</h1>
  <div style="display:flex;gap:.5rem">
    <a href="digest-catalog.php" class="btn btn-secondary">📚 Catalog</a>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>

<div class="digest-card">
  <div class="digest-hint">
    Paste in the emails you've been forwarding to members (copy/paste the whole message, several at once is fine).
    The AI will organize them into one clean digest — group by topic, pull deadlines to the top, and strip the
    signatures/forwarding clutter. <strong>Always proofread the result</strong> — especially dates, dollar amounts,
    and links — before sending. This tool doesn't send anything; it just gives you something to paste into Gmail.
  </div>

  <div class="form-group">
    <label>Pasted Emails</label>
    <textarea id="raw-input" placeholder="Paste one or more forwarded emails here…"></textarea>
  </div>

  <div class="form-group">
    <label>Additional Instructions <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem;color:#9aa5b4">optional</span></label>
    <input type="text" id="instructions-input" maxlength="500" placeholder="e.g. keep it shorter this week, put the fundraiser first">
  </div>

  <div class="digest-actions">
    <button type="button" class="btn btn-primary" id="generate-btn" onclick="generateDigest()">Organize into Digest</button>
    <span class="digest-status" id="digest-status"></span>
    <a href="#" id="cancel-retry-link" style="display:none;font-size:.85rem;color:#c62828" onclick="cancelRetry(); return false;">Stop retrying</a>
  </div>
</div>

<div class="digest-card digest-result" id="digest-result">
  <div class="page-head" style="margin-bottom:.75rem">
    <h2 style="margin:0">Digest Draft</h2>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
      <span class="copy-toast" id="copy-toast">✓ Copied</span>
      <button type="button" class="btn btn-secondary btn-sm" id="copy-plain-btn" onclick="copyPlain()">Copy Plain Text</button>
      <button type="button" class="btn btn-primary btn-sm" id="copy-btn" onclick="copyFormatted()">Copy Formatted (for Gmail)</button>
    </div>
  </div>
  <div class="digest-preview" id="digest-preview"></div>
  <details style="margin-top:.75rem">
    <summary style="cursor:pointer;font-size:.82rem;color:#5a6a7a">Plain text version</summary>
    <textarea class="digest-textout" id="digest-textout" readonly></textarea>
  </details>

  <div style="border-top:1px solid #e1e5eb;margin-top:1.25rem;padding-top:1rem">
    <div class="form-group" style="max-width:400px">
      <label>Save to Catalog As</label>
      <input type="text" id="digest-title" maxlength="200">
    </div>
    <form method="POST" action="digest-catalog.php" id="save-form">
      <input type="hidden" name="csrf" id="save-csrf-input" value="<?= h($csrf_token) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="title" id="save-title-input">
      <input type="hidden" name="html" id="save-html-input">
      <button type="submit" class="btn btn-primary">Save to Catalog</button>
    </form>
  </div>
</div>

<script>
var lastHtml = '', lastText = '';
var csrfToken = '<?= h($csrf_token) ?>';

var retryTimer = null;
var retryAttempt = 0;
var MAX_RETRIES = 20; // with the backoff below, spans roughly 10 minutes

function generateDigest() {
  clearTimeout(retryTimer);
  retryAttempt = 0;
  var raw = document.getElementById('raw-input').value.trim();
  if (!raw) {
    var status = document.getElementById('digest-status');
    status.textContent = 'Paste in some emails first.'; status.style.color = '#c62828';
    return;
  }
  document.getElementById('generate-btn').disabled = true;
  document.getElementById('digest-result').style.display = 'none';
  runGenerateAttempt();
}

function cancelRetry() {
  clearTimeout(retryTimer);
  retryAttempt = 0;
  document.getElementById('generate-btn').disabled = false;
  document.getElementById('cancel-retry-link').style.display = 'none';
  var status = document.getElementById('digest-status');
  status.style.color = '#5a6a7a';
  status.textContent = 'Stopped.';
}

function runGenerateAttempt() {
  var raw = document.getElementById('raw-input').value.trim();
  var status = document.getElementById('digest-status');
  status.style.color = '#5a6a7a';
  status.textContent = retryAttempt === 0
    ? 'Organizing… this can take up to 30 seconds.'
    : 'Still busy — retrying automatically (attempt ' + (retryAttempt + 1) + ')…';

  var form = new FormData();
  form.append('raw', raw);
  form.append('instructions', document.getElementById('instructions-input').value.trim());
  form.append('csrf', csrfToken);

  fetch('digest-generate.php', { method: 'POST', body: form })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.csrf) csrfToken = data.csrf;
      if (data.error) {
        if (data.retryable && retryAttempt < MAX_RETRIES) {
          retryAttempt++;
          document.getElementById('cancel-retry-link').style.display = 'inline';
          var delaySec = Math.min(10 + retryAttempt * 5, 30);
          status.textContent = 'Google\'s AI service is busy — retrying in ' + delaySec + 's (attempt ' + (retryAttempt + 1) + ')…';
          retryTimer = setTimeout(runGenerateAttempt, delaySec * 1000);
          return;
        }
        document.getElementById('generate-btn').disabled = false;
        document.getElementById('cancel-retry-link').style.display = 'none';
        status.style.color = '#c62828';
        status.textContent = data.retryable
          ? 'Still busy after several minutes of retrying — please try again later.'
          : data.error;
        return;
      }
      document.getElementById('generate-btn').disabled = false;
      document.getElementById('cancel-retry-link').style.display = 'none';
      status.textContent = '';
      lastHtml = data.html;
      lastText = data.text;
      document.getElementById('digest-preview').innerHTML = data.html;
      document.getElementById('digest-textout').value = data.text;
      document.getElementById('digest-title').value = 'Digest — ' + new Date().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
      document.getElementById('digest-result').style.display = 'block';
      document.getElementById('digest-result').scrollIntoView({ behavior: 'smooth', block: 'start' });
    })
    .catch(function() {
      document.getElementById('generate-btn').disabled = false;
      document.getElementById('cancel-retry-link').style.display = 'none';
      status.style.color = '#c62828';
      status.textContent = 'Something went wrong contacting the server. Please try again.';
    });
}

function showCopyToast() {
  var t = document.getElementById('copy-toast');
  t.style.display = 'inline';
  setTimeout(function() { t.style.display = 'none'; }, 2000);
}

function copyFormatted() {
  if (!lastHtml) return;
  if (navigator.clipboard && window.ClipboardItem) {
    var htmlBlob = new Blob([lastHtml], { type: 'text/html' });
    var textBlob = new Blob([lastText], { type: 'text/plain' });
    navigator.clipboard.write([new ClipboardItem({ 'text/html': htmlBlob, 'text/plain': textBlob })])
      .then(showCopyToast)
      .catch(function() { copyPlainFallback(); });
  } else {
    copyPlainFallback();
  }
}

function copyPlainFallback() {
  var ta = document.getElementById('digest-textout');
  ta.select();
  document.execCommand('copy');
  showCopyToast();
}

function copyPlain() {
  var ta = document.getElementById('digest-textout');
  ta.select();
  navigator.clipboard && navigator.clipboard.writeText
    ? navigator.clipboard.writeText(lastText).then(showCopyToast)
    : (document.execCommand('copy'), showCopyToast());
}

document.getElementById('save-form').addEventListener('submit', function() {
  document.getElementById('save-title-input').value = document.getElementById('digest-title').value.trim() || 'Untitled Digest';
  document.getElementById('save-html-input').value = lastHtml;
  // csrfToken tracks the session's current token (rotated after every
  // "Organize into Digest" call) — the field rendered at page load goes
  // stale as soon as more than one draft has been generated.
  document.getElementById('save-csrf-input').value = csrfToken;
});
</script>

<?php admin_footer(); ?>
