<?php
require_once __DIR__ . '/auth.php';
require_login();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Club Portal — Staff Guide · USAFA Parents Club of Alabama</title>
<link rel="icon" type="image/png" href="../logo01.png" />
<style>
/* ── Tokens ── */
:root {
  --navy:    #002554;
  --blue:    #003594;
  --crimson: #A6192E;
  --silver:  #8A8D8F;
  --bg:           #FFFFFF;
  --bg-alt:       #EDF2F9;
  --text:         #0D1B2A;
  --text-muted:   #4A5A6B;
  --border:       #C5D4E8;
  --heading:      #002554;
  --card-bg:      #F3F7FD;
  --df: Georgia,'Palatino Linotype',Palatino,serif;
  --ds: 'Segoe UI','Helvetica Neue',Arial,sans-serif;
}
@media(prefers-color-scheme:dark){:root{
  --bg:#0C1623;--bg-alt:#142035;--text:#DCE8F5;--text-muted:#7A9AB8;
  --border:#1E3250;--heading:#A8C4E0;--card-bg:#142035;
}}
:root[data-theme="light"]{
  --bg:#FFFFFF;--bg-alt:#EDF2F9;--text:#0D1B2A;--text-muted:#4A5A6B;
  --border:#C5D4E8;--heading:#002554;--card-bg:#F3F7FD;
}
:root[data-theme="dark"]{
  --bg:#0C1623;--bg-alt:#142035;--text:#DCE8F5;--text-muted:#7A9AB8;
  --border:#1E3250;--heading:#A8C4E0;--card-bg:#142035;
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;overflow:hidden;background:var(--navy);font-family:var(--ds)}

/* ── Deck & slide ── */
.deck{position:relative;width:100vw;height:calc(100vh - 3.25rem);overflow:hidden}
.slide{
  position:absolute;inset:0;display:flex;flex-direction:column;
  background:var(--bg);color:var(--text);
  transform:translateX(100%);
  transition:transform .4s cubic-bezier(.4,0,.2,1);
  overflow:hidden;
}
.slide.active{transform:translateX(0)}
.slide.exit  {transform:translateX(-100%)}

/* ── Slide: Title ── */
.s-title{
  background:var(--navy);color:#fff;justify-content:center;padding:4.5rem 6rem;
  position:relative;overflow:hidden;
}
.s-title::after{
  content:'';position:absolute;top:0;right:0;bottom:0;width:42%;
  background:repeating-linear-gradient(-45deg,transparent,transparent 14px,rgba(255,255,255,.025) 14px,rgba(255,255,255,.025) 28px);
  pointer-events:none;
}
.s-title::before{
  content:'';position:absolute;bottom:0;right:0;
  width:38%;height:60%;
  background:linear-gradient(135deg,transparent 40%,rgba(162,25,46,.18));
  pointer-events:none;
}
.s-title .pre{font-size:.65rem;letter-spacing:.35em;text-transform:uppercase;color:rgba(255,255,255,.35);margin-bottom:2rem}
.s-title h1{font-family:var(--df);font-size:clamp(2.6rem,5vw,4.2rem);font-weight:700;line-height:1.05;margin-bottom:.5rem;max-width:16ch;text-wrap:balance}
.s-title .sub{font-size:1.05rem;color:rgba(255,255,255,.5);font-weight:300;letter-spacing:.02em;margin-bottom:2.25rem}
.s-title .url{font-size:.8rem;color:rgba(255,255,255,.3);font-family:'Courier New',monospace;letter-spacing:.08em}
.accent-bar{width:3.5rem;height:3px;background:var(--crimson);margin:1.5rem 0}

/* ── Slide: Section divider ── */
.s-section{
  background:var(--navy);color:#fff;justify-content:center;padding:5rem 6rem;position:relative;overflow:hidden;
}
.s-section::before{
  content:'';position:absolute;top:0;right:0;bottom:0;width:45%;
  background:repeating-linear-gradient(-45deg,transparent,transparent 14px,rgba(255,255,255,.03) 14px,rgba(255,255,255,.03) 28px);
}
.s-section .eye{font-size:.65rem;letter-spacing:.28em;text-transform:uppercase;color:var(--crimson);margin-bottom:1rem;font-weight:700}
.s-section h1{font-family:var(--df);font-size:clamp(2.2rem,4.5vw,3.5rem);font-weight:700;line-height:1.1;max-width:20ch;text-wrap:balance;margin-bottom:1rem}
.s-section .desc{font-size:1rem;color:rgba(255,255,255,.55);font-weight:300;max-width:36ch;line-height:1.6}

/* ── Slide: Content ── */
.s-content{background:var(--bg);color:var(--text);padding:3rem 4.5rem}
.s-content .label{font-size:.62rem;letter-spacing:.22em;text-transform:uppercase;color:var(--crimson);font-weight:700;margin-bottom:.6rem}
.s-content h2{font-family:var(--df);font-size:clamp(1.5rem,2.8vw,2.1rem);font-weight:700;color:var(--heading);line-height:1.15;text-wrap:balance}
.rule{width:2rem;height:2px;background:var(--crimson);margin:.75rem 0 1.25rem}
.body-text{font-size:.88rem;line-height:1.65;color:var(--text-muted);max-width:64ch}

/* ── Bullets ── */
.bullets{list-style:none;display:flex;flex-direction:column;gap:.6rem;margin-top:.25rem}
.bullets li{display:flex;gap:.8rem;align-items:flex-start;font-size:.88rem;line-height:1.5;color:var(--text)}
.bullets li::before{content:'';width:6px;height:6px;border-radius:50%;background:var(--crimson);flex-shrink:0;margin-top:.55rem}
.bullets .dim{color:var(--text-muted);font-size:.82rem}

/* ── Grids ── */
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start}
.three-col{display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem}

/* ── Cards ── */
.card{background:var(--card-bg);border:1px solid var(--border);padding:1.1rem 1.25rem;border-left:3px solid var(--crimson)}
.card h3{font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--heading);margin-bottom:.55rem}
.card p,.card li{font-size:.8rem;line-height:1.55;color:var(--text-muted)}
.card ul{list-style:none;display:flex;flex-direction:column;gap:.3rem}
.card ul li::before{content:'·  ';color:var(--crimson)}

/* ── Role pills ── */
.pill{display:inline-block;font-size:.6rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;padding:.18rem .55rem;margin:.1rem}
.pill-admin    {background:#002554;color:#fff}
.pill-tech     {background:#154077;color:#fff}
.pill-officer  {background:#0D5C8C;color:#fff}
.pill-secretary{background:#1A6B3C;color:#fff}
.pill-treasurer{background:#7B4213;color:#fff}
.pill-member   {background:#505A63;color:#fff}

/* ── Access table ── */
.at{width:100%;border-collapse:collapse;font-size:.75rem;margin-top:.5rem}
.at th{background:var(--navy);color:#fff;padding:.45rem .7rem;text-align:center;font-weight:600;font-size:.65rem;letter-spacing:.07em;text-transform:uppercase}
.at th:first-child{text-align:left}
.at td{padding:.4rem .7rem;border-bottom:1px solid var(--border);text-align:center;vertical-align:middle}
.at td:first-child{text-align:left;font-weight:600;font-size:.78rem;color:var(--text)}
.at tr:nth-child(even) td{background:var(--bg-alt)}
.y{color:#1A7A3C;font-weight:700;font-size:.9rem}
.p{color:#B7770D;font-size:.68rem;font-weight:600}
.n{color:var(--border)}

/* ── Workflow ── */
.flow{display:flex;gap:0;margin-top:.75rem}
.flow-step{flex:1;border:1px solid var(--border);background:var(--card-bg);padding:1rem .85rem;text-align:center;position:relative}
.flow-step+.flow-step::before{
  content:'→';position:absolute;left:-1rem;top:50%;transform:translateY(-50%);
  font-size:1.1rem;color:var(--silver);z-index:1;background:var(--bg);padding:0 .1rem;
}
.flow-step .fs-label{font-size:.58rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--silver);margin-bottom:.3rem}
.flow-step .fs-name{font-size:.9rem;font-weight:700;color:var(--text);margin-bottom:.3rem}
.flow-step .fs-who{font-size:.7rem;color:var(--text-muted)}
.fs-pending   {border-top:3px solid #E67E22}
.fs-approved  {border-top:3px solid #2980B9}
.fs-submitted {border-top:3px solid #8E44AD}
.fs-paid      {border-top:3px solid #27AE60}

/* ── Highlight box ── */
.hbox{background:rgba(162,25,46,.07);border:1px solid rgba(162,25,46,.2);border-left:3px solid var(--crimson);padding:.7rem 1rem;font-size:.8rem;color:var(--text-muted);margin-top:.75rem;line-height:1.55}
.hbox strong{color:var(--text)}

/* ── Closing ── */
.s-close{background:var(--navy);color:#fff;justify-content:center;align-items:center;text-align:center;padding:4rem}
.s-close h1{font-family:var(--df);font-size:clamp(2rem,4vw,3rem);margin-bottom:.5rem}
.s-close .cs{color:rgba(255,255,255,.5);font-size:.95rem;font-weight:300;margin-bottom:2rem}
.s-close .cmail{font-size:.85rem;color:rgba(255,255,255,.35);letter-spacing:.08em;font-family:'Courier New',monospace;margin-top:1rem}

/* ── Nav bar ── */
.nav-bar{
  position:fixed;bottom:0;left:0;right:0;height:3.25rem;
  background:var(--navy);display:flex;align-items:center;
  justify-content:space-between;padding:0 1.5rem;z-index:200;
  border-top:1px solid rgba(255,255,255,.06);
}
.nb-btn{
  background:none;border:1px solid rgba(255,255,255,.18);color:#fff;
  padding:.3rem .9rem;font-size:.75rem;cursor:pointer;
  font-family:var(--ds);letter-spacing:.04em;transition:background .15s;
}
.nb-btn:hover{background:rgba(255,255,255,.1)}
.nb-btn:disabled{opacity:.25;cursor:default}
.nb-exit{margin-left:1.25rem;text-decoration:none;border-color:rgba(166,25,46,.5)}
.nb-exit:hover{background:rgba(166,25,46,.25)}
.nb-progress{display:flex;gap:.35rem;align-items:center;flex:1;justify-content:center;padding:0 1rem}
.nb-dot{width:5px;height:5px;border-radius:50%;background:rgba(255,255,255,.22);cursor:pointer;transition:all .2s;flex-shrink:0}
.nb-dot.on{background:var(--crimson);transform:scale(1.5)}
.nb-ctr{color:rgba(255,255,255,.38);font-size:.7rem;letter-spacing:.1em;min-width:4.5rem;text-align:right;font-variant-numeric:tabular-nums}

/* ── Utilities ── */
.mt1{margin-top:.75rem}.mt2{margin-top:1.5rem}.mt05{margin-top:.4rem}
.gap-sm{gap:.65rem}

/* ── Screenshots ── */
.shot-strip{display:flex;gap:.6rem;flex-wrap:wrap;margin-top:1rem}
.shot-item{display:flex;flex-direction:column;align-items:center}
.shot-thumb{height:100px;border:1px solid var(--border);border-radius:4px;cursor:zoom-in;box-shadow:0 1px 4px rgba(0,0,0,.15);transition:transform .15s;display:block}
.shot-thumb:hover{transform:scale(1.04)}
.shot-cap{font-size:.62rem;color:var(--text-muted);text-align:center;margin-top:.25rem;max-width:150px}
#lightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:9999;align-items:center;justify-content:center;padding:3rem;cursor:zoom-out}
#lightbox.open{display:flex}
#lightbox img{max-width:100%;max-height:100%;border-radius:6px;box-shadow:0 8px 40px rgba(0,0,0,.5)}
</style>
</head>
<body>

<div class="deck" id="deck">

<!-- 0: Title -->
<div class="slide s-title active" id="s0">
  <p class="pre">USAFA Parents Club of Alabama &nbsp;·&nbsp; Staff Orientation</p>
  <h1>Club Portal Guide</h1>
  <p class="sub">Everything you need to know about managing the portal</p>
  <div class="accent-bar"></div>
  <p class="url">alabamafalcons.org/admin</p>
</div>

<!-- 1: What is the Portal -->
<div class="slide s-content" id="s1">
  <p class="label">Overview</p>
  <h2>What Is the Club Portal?</h2>
  <div class="rule"></div>
  <div class="two-col">
    <ul class="bullets">
      <li>A private, password-protected admin website accessible only to club officers and staff</li>
      <li>Manage club members, track dues, handle finances, and update the public website — all in one place</li>
      <li>Every officer sees only the tools their role requires; sensitive data stays protected</li>
      <li>Changes to leadership, events, announcements, and settings update <strong>alabamafalcons.org</strong> instantly</li>
      <li>Access it at <strong>alabamafalcons.org/admin</strong> — bookmark it on your phone and desktop</li>
    </ul>
    <div>
      <div class="card">
        <h3>Logging In</h3>
        <ul>
          <li>Go to <strong>alabamafalcons.org/admin</strong></li>
          <li>Enter your username and password</li>
          <li>If you forget your password, contact the Tech Officer</li>
          <li>Always log out when finished on a shared device</li>
        </ul>
      </div>
      <div class="hbox mt1">
        <strong>First time?</strong> Your login is created by the Tech Officer. You cannot self-register.
      </div>
    </div>
  </div>
</div>

<!-- 2: Roles -->
<div class="slide s-content" id="s2">
  <p class="label">Access Control</p>
  <h2>The Five Portal Roles</h2>
  <div class="rule"></div>
  <div class="three-col">
    <div class="card">
      <h3><span class="pill pill-tech">Tech Support</span></h3>
      <p>Full access to everything — user management, all financial data, system settings, and all member data. Assigned to the Technology Officer.</p>
    </div>
    <div class="card">
      <h3><span class="pill pill-officer">Officer</span></h3>
      <p>Manages members, website content (leadership, events, announcements, gallery), email communications, and can view all finances and helpdesk tickets.</p>
    </div>
    <div class="card">
      <h3><span class="pill pill-secretary">Secretary</span></h3>
      <p>Full member roster access, email compose, website content management, and the club's record-keeping tools — meeting minutes, attendance, correspondence log, and member letters. Can view all purchases and helpdesk tickets, but can only submit/edit their own.</p>
    </div>
    <div class="card">
      <h3><span class="pill pill-treasurer">Treasurer</span></h3>
      <p>Marks dues paid/unpaid, manages all purchases and reimbursements, views financial reports and the document vault.</p>
    </div>
    <div class="card">
      <h3><span class="pill pill-member">Member</span></h3>
      <p>Submits their own purchases and expense receipts, views and responds to their own helpdesk tickets only.</p>
    </div>
  </div>
</div>

<!-- 3: Dashboard -->
<div class="slide s-content" id="s3">
  <p class="label">Section · Dashboard</p>
  <h2>Dashboard — Your Starting Point</h2>
  <div class="rule"></div>
  <div class="two-col">
    <div>
      <p class="body-text">The first screen after login. Gives you a live snapshot of the club without digging into any section.</p>
      <ul class="bullets mt1">
        <li><strong>Member stats</strong> — Total members, paid this year, unpaid, and new this month</li>
        <li><strong>Upcoming birthdays</strong> — Cadet birthdays in the next 30 days with P.O. Box</li>
        <li><strong>Finance summary</strong> — Pending reimbursements, YTD spending (Treasurer/Tech)</li>
        <li><strong>Dues collected</strong> — Annual vs 4-Year totals and dollar amounts (Treasurer)</li>
        <li><strong>Quick links</strong> — Jump to any section from the dashboard cards</li>
      </ul>
    </div>
    <div>
      <div class="card">
        <h3>Who Sees What</h3>
        <ul>
          <li><strong>All roles</strong> — Member counts and birthdays</li>
          <li><strong>Treasurer +</strong> — Finance stats and dues collected</li>
          <li><strong>Tech Support</strong> — Full dashboard including helpdesk ticket management</li>
        </ul>
      </div>
      <div class="hbox mt1">
        Bookmark the dashboard — it's the fastest way to see if anything needs your attention the moment you log in.
      </div>
    </div>
  </div>
  <div class="shot-strip">
    <div class="shot-item"><img class="shot-thumb" src="guide-images/dashboard-secretary.jpg" alt="Secretary dashboard" onclick="openShot(this.src,this.alt)"><span class="shot-cap">Secretary dashboard</span></div>
    <div class="shot-item"><img class="shot-thumb" src="guide-images/dashboard-officer.jpg" alt="Officer dashboard" onclick="openShot(this.src,this.alt)"><span class="shot-cap">Officer dashboard</span></div>
    <div class="shot-item"><img class="shot-thumb" src="guide-images/dashboard-treasurer.jpg" alt="Treasurer dashboard" onclick="openShot(this.src,this.alt)"><span class="shot-cap">Treasurer dashboard</span></div>
    <div class="shot-item"><img class="shot-thumb" src="guide-images/automated-emails.jpg" alt="Automated Emails settings" onclick="openShot(this.src,this.alt)"><span class="shot-cap">Automated Emails</span></div>
  </div>
</div>

<!-- 4: Members -->
<div class="slide s-content" id="s4">
  <p class="label">Section · Members</p>
  <h2>The Member Roster</h2>
  <div class="rule"></div>
  <div class="two-col">
    <div>
      <ul class="bullets">
        <li><strong>Search</strong> by cadet name, parent name, email, or phone number</li>
        <li><strong>Filter</strong> by class year, Alabama region, squadron, or paid/unpaid status</li>
        <li><strong>View archived members</strong> using the Status filter — they stay in the system, just hidden from the active list</li>
        <li><strong>Add a member</strong> manually (Officer/Secretary/Tech)</li>
        <li><strong>Click any member</strong> to see their full profile — cadet info, parents, dues history, squadron assignments</li>
        <li><strong>Archive vs Delete</strong> — Archive is reversible; Delete is permanent</li>
      </ul>
    </div>
    <div>
      <div class="card">
        <h3>Treasurer View</h3>
        <p>The Treasurer sees a simplified row on each member with a <strong>plan dropdown</strong> and a <strong>✓ Paid / ✗ Unpaid</strong> button — no access to edit personal details.</p>
      </div>
      <div class="card mt1">
        <h3>What Officers Can Do</h3>
        <ul>
          <li>Add, edit, archive, restore members</li>
          <li>Mark dues paid or unpaid</li>
          <li>Reset dues for a new year</li>
          <li>Export to mailing list</li>
        </ul>
      </div>
    </div>
  </div>
  <div class="shot-strip">
    <div class="shot-item"><img class="shot-thumb" src="guide-images/members-list.jpg" alt="Member roster list with filters and dues stats" onclick="openShot(this.src,this.alt)"><span class="shot-cap">Member roster</span></div>
  </div>
</div>

<!-- 5: Dues Tracking -->
<div class="slide s-content" id="s5">
  <p class="label">Feature · Dues</p>
  <h2>Dues Tracking — Annual &amp; 4-Year Plans</h2>
  <div class="rule"></div>
  <div class="two-col">
    <div>
      <ul class="bullets">
        <li><strong>Annual plan ($75)</strong> — Renews each dues year. Reset Dues will mark them unpaid for the new year.</li>
        <li><strong>4-Year plan ($275)</strong> — Covers all four years of a cadet's time at USAFA. The system auto-calculates the expiry year (e.g., paid in 2026 → covered through 2029–2030).</li>
        <li>4-Year members are <strong>protected during Reset Dues</strong> — they are never accidentally marked unpaid while still within their coverage window.</li>
        <li>Each member's profile shows their plan type and paid-through date.</li>
        <li>The Treasurer dashboard shows a dollar breakdown: how much collected from each plan type.</li>
      </ul>
    </div>
    <div>
      <div class="card">
        <h3>Marking Dues Paid</h3>
        <ul>
          <li>Select plan: <strong>Annual ($75)</strong> or <strong>4-Year ($275)</strong></li>
          <li>Enter the membership year (e.g., <em>2026-2027</em>)</li>
          <li>Click <strong>✓ Paid</strong> — system fills in the paid-through date automatically</li>
        </ul>
      </div>
      <div class="hbox mt1">
        <strong>Year-end reset:</strong> Use <em>Reset Dues</em> once per year to roll the roster over. 4-Year members are automatically skipped if they still have active coverage.
      </div>
    </div>
  </div>
</div>

<!-- 6: Bulk Actions -->
<div class="slide s-content" id="s6">
  <p class="label">Feature · Members</p>
  <h2>Bulk Actions — Work With Many Members At Once</h2>
  <div class="rule"></div>
  <div class="two-col">
    <div>
      <p class="body-text">Select multiple members at once using the checkboxes on the left of each row. A toolbar appears at the bottom of the screen.</p>
      <ul class="bullets mt1">
        <li><strong>Select All</strong> — checkbox at the top of the list selects everyone currently shown</li>
        <li>Use filters first to narrow down the list, then Select All to work on a specific group</li>
      </ul>
      <div class="hbox mt2">
        <strong>Example:</strong> Filter to Class of 2027, Select All, choose 4-Year plan, click Mark as Paid — updates all of them in one step.
      </div>
    </div>
    <div>
      <div class="card">
        <h3>Available Bulk Actions</h3>
        <ul>
          <li><strong>Mark as Paid</strong> — set plan &amp; membership year first (Treasurer+)</li>
          <li><strong>Mark as Unpaid</strong> — clears dues status (Treasurer+)</li>
          <li><strong>Archive</strong> — hides from active list, fully reversible (Officer+)</li>
          <li><strong>Restore</strong> — shown when viewing archived list (Officer+)</li>
          <li><strong>Delete</strong> — permanent, requires confirmation (Officer+)</li>
          <li><strong>Send Portal Invite</strong> — emails a sign-up link to each selected member's parent(s) (Officer+)</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<!-- 7: Section — Finances -->
<div class="slide s-section" id="s7">
  <p class="eye">Section</p>
  <div class="accent-bar"></div>
  <h1>Purchases &amp; Finance</h1>
  <p class="desc">Tracking club spending, submitting receipts, and managing the reimbursement workflow.</p>
</div>

<!-- 8: Purchases -->
<div class="slide s-content" id="s8">
  <p class="label">Section · Finance</p>
  <h2>Purchases — Tracking Club Spending</h2>
  <div class="rule"></div>
  <div class="two-col">
    <div>
      <ul class="bullets">
        <li><strong>Submit a purchase</strong> — enter vendor, amount (pre-tax, tax, shipping), date, event category, and payment method</li>
        <li><strong>Attach a receipt</strong> — photo from your phone camera or uploaded file (JPG, PNG, PDF up to 10MB)</li>
        <li>Purchases that look similar to a recent entry will trigger a <strong>duplicate warning</strong> before saving</li>
        <li>All purchases start as <strong>Pending</strong> until an Officer reviews and approves them</li>
        <li>Once approved, the Treasurer submits payment (Check/Venmo/PayPal) and marks it <strong>Submitted</strong></li>
        <li>Once the member confirms receipt, the Treasurer marks it <strong>Paid</strong> — final confirmation</li>
        <li>A plain Member can only see and edit their own submissions</li>
        <li>Officers and Secretary can <strong>view</strong> every purchase for oversight, but can still only <strong>edit</strong> their own — Treasurer and Tech Support can edit any</li>
      </ul>
    </div>
    <div>
      <div class="card">
        <h3>Purchase Categories</h3>
        <ul>
          <li>Parents Weekend</li>
          <li>Care Packages</li>
          <li>Appointee Send-off</li>
          <li>Taste of Home</li>
          <li>Birthday / Gift</li>
          <li>General Operations</li>
        </ul>
      </div>
    </div>
  </div>
  <div class="flow mt2">
    <div class="flow-step fs-pending">
      <p class="fs-label">Step 1</p>
      <p class="fs-name">Pending</p>
      <p class="fs-who">Member or Officer submits with receipt</p>
    </div>
    <div class="flow-step fs-approved">
      <p class="fs-label">Step 2</p>
      <p class="fs-name">Approved</p>
      <p class="fs-who">Officer reviews and approves</p>
    </div>
    <div class="flow-step fs-submitted">
      <p class="fs-label">Step 3</p>
      <p class="fs-name">Submitted</p>
      <p class="fs-who">Treasurer submits payment to member</p>
    </div>
    <div class="flow-step fs-paid">
      <p class="fs-label">Step 4</p>
      <p class="fs-name">Paid</p>
      <p class="fs-who">Treasurer confirms payment received</p>
    </div>
  </div>
  <div class="shot-strip">
    <div class="shot-item"><img class="shot-thumb" src="guide-images/add-purchase.jpg" alt="Add Purchase form" onclick="openShot(this.src,this.alt)"><span class="shot-cap">Add Purchase</span></div>
    <div class="shot-item"><img class="shot-thumb" src="guide-images/finance-purchases.jpg" alt="Purchases list" onclick="openShot(this.src,this.alt)"><span class="shot-cap">Purchases list</span></div>
  </div>
</div>

<!-- 9: Section — Website Content -->
<div class="slide s-section" id="s9">
  <p class="eye">Section</p>
  <div class="accent-bar"></div>
  <h1>Website Content</h1>
  <p class="desc">Managing everything that appears on the public site — photos, events, announcements, leadership, and more.</p>
</div>

<!-- 10: Gallery -->
<div class="slide s-content" id="s10">
  <p class="label">Section · Gallery</p>
  <h2>Photo Gallery — Managing the Site Slideshow</h2>
  <div class="rule"></div>
  <div class="two-col">
    <div>
      <ul class="bullets">
        <li><strong>Upload photos</strong> — select multiple photos at once; add a caption that applies to all selected</li>
        <li>Photos appear in the slideshow on the public website immediately after upload</li>
        <li><strong>Sort order</strong> — controls the display sequence; lower numbers appear first</li>
        <li><strong>Show / Hide</strong> — toggle individual photos without deleting them</li>
        <li>Photos <strong>auto-expire after 30 days</strong> — upload fresh photos for active events</li>
        <li><strong>Display limit</strong> — configurable (default 20); oldest photos are trimmed automatically when the limit is reached</li>
      </ul>
    </div>
    <div>
      <div class="card">
        <h3>Bulk Actions (Gallery)</h3>
        <ul>
          <li>Check the box on any photo thumbnail to select it</li>
          <li><strong>Apply Caption</strong> — type once, apply to all selected photos</li>
          <li><strong>Delete Selected</strong> — removes photos from disk and the database</li>
          <li>Select All checkbox above the grid for quick mass operations</li>
        </ul>
      </div>
      <div class="hbox mt1">
        <strong>Upload tip:</strong> Large batches may take 30–60 seconds. Once you click Upload, wait for the page to reload — don't click again.
      </div>
    </div>
  </div>
  <div class="shot-strip">
    <div class="shot-item"><img class="shot-thumb" src="guide-images/gallery.jpg" alt="Photo Gallery upload and management page" onclick="openShot(this.src,this.alt)"><span class="shot-cap">Photo Gallery</span></div>
  </div>
</div>

<!-- 11: Announcements & Events -->
<div class="slide s-content" id="s11">
  <p class="label">Section · Website Content</p>
  <h2>Announcements &amp; Events</h2>
  <div class="rule"></div>
  <div class="two-col">
    <div>
      <div class="card">
        <h3>Announcements</h3>
        <ul>
          <li>Banner messages displayed at the top of the public website</li>
          <li>Set a <strong>start date and expiry date</strong> — announcements disappear automatically</li>
          <li>Good for: dues reminders, event notices, urgent news</li>
          <li>Multiple announcements can be active at once</li>
          <li>Changes appear on the site within minutes</li>
        </ul>
      </div>
    </div>
    <div>
      <div class="card">
        <h3>Events</h3>
        <ul>
          <li>Listed in the Events section on the public website</li>
          <li>Each event has a <strong>title, date, location, description, and optional registration link</strong></li>
          <li>Set <strong>sort order</strong> to control display sequence</li>
          <li>Toggle <strong>visible</strong> to show/hide without deleting</li>
          <li>Past events can be hidden rather than deleted to keep a record</li>
        </ul>
      </div>
    </div>
  </div>
  <div class="hbox mt2">
    Both sections update the public site <strong>immediately</strong> when saved — no publish step required.
  </div>
  <div class="shot-strip">
    <div class="shot-item"><img class="shot-thumb" src="guide-images/announcements.jpg" alt="Site Announcements page" onclick="openShot(this.src,this.alt)"><span class="shot-cap">Announcements</span></div>
  </div>
</div>

<!-- 12: Leadership -->
<div class="slide s-content" id="s12">
  <p class="label">Section · Website Content</p>
  <h2>Leadership — Managing Officer Cards on the Site</h2>
  <div class="rule"></div>
  <div class="two-col">
    <div>
      <ul class="bullets">
        <li>Each entry becomes an <strong>officer card on the public website</strong> — name, title, photo, email, and bio</li>
        <li><strong>Upload a headshot</strong> — square photos work best; JPG or PNG</li>
        <li><strong>Sort order</strong> controls who appears first; President should be 1, VP 2, etc.</li>
        <li>Toggle <strong>Show on website</strong> to hide an officer card temporarily (e.g., vacant position)</li>
        <li><strong>Bio</strong> appears when a visitor hovers over an officer's card</li>
        <li>Email links on each card use the club's official email addresses</li>
      </ul>
    </div>
    <div>
      <div class="card">
        <h3>Updating an Officer</h3>
        <ul>
          <li>Click <strong>Edit</strong> next to the officer in the admin list</li>
          <li>Upload a new photo to replace the existing one</li>
          <li>Change role title, bio, or email as needed</li>
          <li>Click <strong>Save Changes</strong> — site updates instantly</li>
        </ul>
      </div>
      <div class="hbox mt1">
        When an officer transitions out, update the name, title, and photo rather than deleting and re-adding — this preserves the sort order.
      </div>
    </div>
  </div>
  <div class="shot-strip">
    <div class="shot-item"><img class="shot-thumb" src="guide-images/leadership.jpg" alt="Club Leadership admin list" onclick="openShot(this.src,this.alt)"><span class="shot-cap">Club Leadership</span></div>
  </div>
</div>

<!-- 13: Section — Communications -->
<div class="slide s-section" id="s13">
  <p class="eye">Section</p>
  <div class="accent-bar"></div>
  <h1>Communications &amp; Tools</h1>
  <p class="desc">Email the membership, manage support tickets, secure documents, and the member directory.</p>
</div>

<!-- 14: Email -->
<div class="slide s-content" id="s14">
  <p class="label">Section · Email</p>
  <h2>Compose Email — Sending to the Membership</h2>
  <div class="rule"></div>
  <div class="two-col">
    <div>
      <ul class="bullets">
        <li><strong>Filter recipients</strong> by class year, Alabama region, or paid/unpaid status before composing</li>
        <li><strong>Email List</strong> picks whose address to pull — Everyone, Both/either Parent, Cadet, or Board Members Only</li>
        <li>Use the <strong>Reset button</strong> to clear all filters and start fresh</li>
        <li>The recipient count updates live as you adjust filters</li>
        <li><strong>Format the message</strong> with the rich text toolbar — bold, color, size, alignment, lists, links — plus an emoji picker</li>
        <li><strong>Attach files</strong> — images, PDF, Word, Excel, PowerPoint — up to 10MB each, 20MB total</li>
        <li>Choose which officer address to send <strong>From</strong> — Reply-To matches automatically, and a copy always goes to info@alabamafalcons.org for the club's records</li>
        <li>Large sends are automatically split into batches behind the scenes if the recipient count is high — nothing you need to do</li>
        <li>Available to: Officers, Secretary, Tech Support</li>
      </ul>
    </div>
    <div>
      <div class="card">
        <h3>Common Uses</h3>
        <ul>
          <li>Dues renewal reminders (filter: unpaid)</li>
          <li>Class-year-specific announcements (filter: 2027)</li>
          <li>General newsletters to all active members</li>
          <li>Event invitations to specific regions</li>
          <li>Quick note to leadership (Email List: Board Members Only)</li>
        </ul>
      </div>
      <div class="hbox mt1">
        <strong>Important:</strong> Emails are sent immediately and cannot be recalled. Preview the recipient count carefully before sending.
      </div>
    </div>
  </div>
  <div class="shot-strip">
    <div class="shot-item"><img class="shot-thumb" src="guide-images/compose-email-president.jpg" alt="Compose Email as President" onclick="openShot(this.src,this.alt)"><span class="shot-cap">Compose Email</span></div>
    <div class="shot-item"><img class="shot-thumb" src="guide-images/compose-email-secretary.jpg" alt="Compose Email as Secretary, loading Cadet Emails" onclick="openShot(this.src,this.alt)"><span class="shot-cap">Loading recipients</span></div>
  </div>
</div>

<!-- 15: Helpdesk, Vault, Directory -->
<div class="slide s-content" id="s15">
  <p class="label">Sections · Tools</p>
  <h2>Helpdesk · Document Vault · Directory</h2>
  <div class="rule"></div>
  <div class="three-col">
    <div class="card">
      <h3>Helpdesk</h3>
      <ul>
        <li>Internal support ticket system for member inquiries</li>
        <li>Statuses: <strong>Open, In Progress, Resolved</strong></li>
        <li><strong>Tech Support only</strong> can manage tickets — respond as staff, change status, assign, and close</li>
        <li><strong>Officer and Secretary</strong> can view any ticket for oversight, but can only reply on their own</li>
        <li>Treasurer and Member can submit tickets and view their own only</li>
      </ul>
    </div>
    <div class="card">
      <h3>Document Vault</h3>
      <ul>
        <li>Secure storage for club documents — tax forms, meeting minutes, policies, financial records</li>
        <li>Files are <strong>not publicly accessible</strong></li>
        <li>Upload PDF or image files with a title and category</li>
        <li>Access: Treasurer, Officers, Secretary, Tech Support</li>
        <li>Files can be viewed inline or downloaded</li>
      </ul>
    </div>
    <div class="card">
      <h3>Member Directory</h3>
      <ul>
        <li>Searchable, printable roster of active members</li>
        <li>Filter by class year or Alabama region</li>
        <li>Excludes families who <strong>opted out of Directory Listing</strong> on their membership/update form — blank/unanswered still shows</li>
        <li>Access: All logged-in roles, including Members</li>
        <li>Archived members are never listed, opted-in or not</li>
      </ul>
    </div>
  </div>
  <div class="shot-strip">
    <div class="shot-item"><img class="shot-thumb" src="guide-images/support-tickets.jpg" alt="Support Tickets list" onclick="openShot(this.src,this.alt)"><span class="shot-cap">Support Tickets</span></div>
    <div class="shot-item"><img class="shot-thumb" src="guide-images/document-vault.jpg" alt="Document Vault" onclick="openShot(this.src,this.alt)"><span class="shot-cap">Document Vault</span></div>
  </div>
</div>

<!-- 16: Meeting Minutes & Attendance -->
<div class="slide s-content" id="s16">
  <p class="label">Section · Secretary Tools</p>
  <h2>Meeting Minutes &amp; Attendance</h2>
  <div class="rule"></div>
  <div class="two-col">
    <div class="card">
      <h3>Meeting Minutes</h3>
      <ul>
        <li>Log a meeting: date, type (General/Board/Special/Other), title, location, and Zoom/Meet link</li>
        <li>Upload the minutes file (PDF, DOC, or DOCX) once notes are ready</li>
        <li>Uploading automatically mirrors a copy into the <strong>Document Vault</strong> under "Meeting Minutes" — stays in sync if replaced or deleted</li>
        <li><strong>Notify Board</strong> emails every board member a secure link to view or download the minutes — no login required on their end</li>
      </ul>
    </div>
    <div class="card">
      <h3>Attendance</h3>
      <ul>
        <li>Click into any meeting to check off who attended</li>
        <li><strong>Mark All Present</strong> / <strong>Clear All</strong> helpers speed up entry</li>
        <li>Saving replaces the full attendance list for that meeting</li>
        <li>The meeting list shows an attendance rate with a color-coded bar</li>
      </ul>
    </div>
  </div>
  <div class="hbox mt2">
    <strong>Access:</strong> Tech Support, Officer, Secretary, Treasurer. Deleting a meeting also deletes its attendance records and vault copy — no separate cleanup needed.
  </div>
  <div class="shot-strip">
    <div class="shot-item"><img class="shot-thumb" src="guide-images/minutes-list.jpg" alt="Meeting Minutes list" onclick="openShot(this.src,this.alt)"><span class="shot-cap">Meeting Minutes</span></div>
    <div class="shot-item"><img class="shot-thumb" src="guide-images/attendance-take.jpg" alt="Take Attendance checklist" onclick="openShot(this.src,this.alt)"><span class="shot-cap">Take Attendance</span></div>
  </div>
</div>

<!-- 17: Correspondence & Member Letters -->
<div class="slide s-content" id="s17">
  <p class="label">Section · Secretary Tools</p>
  <h2>Correspondence Log &amp; Member Letters</h2>
  <div class="rule"></div>
  <div class="two-col">
    <div class="card">
      <h3>Correspondence Log</h3>
      <ul>
        <li>Log outgoing or incoming club correspondence — letters, emails, calls, and more</li>
        <li>Fields: date, direction (Sent/Received), contact name, organization, method, subject, notes</li>
        <li>Filter by year and direction; export the filtered log to CSV</li>
        <li>Internal record-keeping only — nothing here sends an email itself</li>
      </ul>
    </div>
    <div class="card">
      <h3>Member Status Letters</h3>
      <ul>
        <li>Search for a member by parent or cadet name</li>
        <li><strong>Generate Letter</strong> produces a printable/PDF-able membership confirmation, auto-filled from their record</li>
        <li>Useful for a family that needs proof of membership</li>
        <li>Nothing is emailed or saved automatically — staff prints or saves as PDF manually</li>
      </ul>
    </div>
  </div>
  <div class="hbox mt2">
    <strong>Access to both tools:</strong> Tech Support, Officer, Secretary, Treasurer.
  </div>
</div>

<!-- 18: Site Settings -->
<div class="slide s-content" id="s18">
  <p class="label">Section · Settings</p>
  <h2>Site Settings — Controlling Homepage Content</h2>
  <div class="rule"></div>
  <div class="two-col">
    <div>
      <ul class="bullets">
        <li><strong>Hero section</strong> — the banner text and button on the main page</li>
        <li><strong>Membership section</strong> — dues amount and description shown to visitors</li>
        <li><strong>President's Letter</strong> — the full letter text, name, and title (rich text editor)</li>
        <li><strong>Facebook link</strong> — updates the Facebook button on the site</li>
        <li><strong>Footer Resources</strong> — the USAFA links listed at the bottom of every page (format: <em>Title|URL</em>, one per line)</li>
      </ul>
      <div class="hbox mt2">
        All settings save immediately and update the public website within a few minutes. No publish step required.
      </div>
    </div>
    <div>
      <div class="card">
        <h3>Sponsors</h3>
        <ul>
          <li>Manage the sponsor listings shown on <strong>sponsorship.html</strong></li>
          <li>Add logo, name, location, contribution type, and website link</li>
          <li>Sort order controls display sequence</li>
          <li>Toggle visible to show or hide without deleting</li>
        </ul>
      </div>
      <div class="card mt1">
        <h3>Volunteers</h3>
        <ul>
          <li>Submissions from the <em>Volunteer with Us</em> form on the main site</li>
          <li>View name, contact, availability, and areas of interest</li>
          <li>Tech Support, Officer, and Secretary access</li>
        </ul>
      </div>
    </div>
  </div>
  <div class="shot-strip">
    <div class="shot-item"><img class="shot-thumb" src="guide-images/site-settings.jpg" alt="Site Settings page" onclick="openShot(this.src,this.alt)"><span class="shot-cap">Site Settings</span></div>
    <div class="shot-item"><img class="shot-thumb" src="guide-images/sponsors.jpg" alt="Sponsors admin list" onclick="openShot(this.src,this.alt)"><span class="shot-cap">Sponsors</span></div>
    <div class="shot-item"><img class="shot-thumb" src="guide-images/volunteers.jpg" alt="Volunteer Interest submissions" onclick="openShot(this.src,this.alt)"><span class="shot-cap">Volunteer Interest</span></div>
  </div>
</div>

<!-- 19: Member Support Tools -->
<div class="slide s-content" id="s19">
  <p class="label">Section · Member Support</p>
  <h2>Volunteer Sign-Ups · Event RSVP · Photo Submissions · Committees</h2>
  <div class="rule"></div>
  <div class="three-col">
    <div class="card">
      <h3>Volunteer Opportunities</h3>
      <ul>
        <li>Officers post opportunities with a date, location, and number of spots needed</li>
        <li>Members sign up right from their dashboard — no double sign-ups thanks to a duplicate check</li>
        <li>Officers see a live roster of who's signed up for each opportunity, and can toggle an opportunity inactive or delete it</li>
      </ul>
    </div>
    <div class="card">
      <h3>Event RSVP</h3>
      <ul>
        <li>Members RSVP to upcoming events and note a guest count</li>
        <li>Re-submitting updates their existing RSVP instead of creating a duplicate</li>
        <li>Officers view RSVP counts and a full roster (with guest counts) per event</li>
      </ul>
    </div>
    <div class="card">
      <h3>Photo Submissions</h3>
      <ul>
        <li>Members upload event photos with an optional caption</li>
        <li>Submissions queue for officer review — nothing appears publicly until approved</li>
        <li><strong>Approve</strong> adds the photo to the homepage <strong>Member Photos</strong> slideshow (same place/rules as Gallery uploads — 30-day auto-expiry, display limit); <strong>Reject</strong> leaves it out</li>
        <li>Members can see the status of their own recent submissions (Pending / Approved / Rejected)</li>
      </ul>
    </div>
  </div>
  <div class="hbox mt2">
    <strong>Committee Interest:</strong> Members check off which committees they'd be willing to help with (Fundraising, Events &amp; Socials, Communications, Care Packages, Membership Outreach, Sendoff Support). Officers see everyone's picks grouped by committee, with a mailto link to reach out directly. All four tools are available to every logged-in role from the dashboard; the review/management side is limited to Tech Support, Officer, and Secretary.
  </div>
  <div class="hbox mt1">
    <strong>Getting members into the portal:</strong> Use the <strong>Send Portal Invite</strong> bulk action on the member roster (filter to Paid, Select All, then Send Portal Invite) to email each parent with their own sign-up link — no manual account creation needed. Anyone who already has a login is skipped automatically. If a link expires before they use it, resend it from <strong>Users</strong> (shows an "Invite Pending" badge).
  </div>
  <div class="hbox mt1">
    <strong>My Membership:</strong> Anyone invited via Send Portal Invite is automatically linked to their cadet family record, so their dashboard shows a My Membership tile with their own dues status (Paid/Unpaid, plan, and coverage year) plus a link to pay if unpaid. Accounts with no matching family record (e.g. staff-only logins) simply don't see the tile.
  </div>
</div>

<!-- 20: Role Quick Reference -->
<div class="slide s-content" id="s20" style="padding:2.25rem 4rem">
  <p class="label">Quick Reference</p>
  <h2>Who Can Do What — Role Access Summary</h2>
  <div class="rule"></div>
  <div style="overflow-x:auto">
  <table class="at">
    <thead>
      <tr>
        <th>Section</th>
        <th>Tech Support</th>
        <th>Officer</th>
        <th>Secretary</th>
        <th>Treasurer</th>
        <th>Member</th>
      </tr>
    </thead>
    <tbody>
      <tr><td>Dashboard</td><td class="y">Full</td><td class="y">Full</td><td class="y">Full</td><td class="p">Dues &amp; Finance</td><td class="p">Basic</td></tr>
      <tr><td>Members — View &amp; Edit</td><td class="y">✓</td><td class="y">✓</td><td class="y">✓</td><td class="p">Dues only</td><td class="n">—</td></tr>
      <tr><td>Members — Archive / Delete</td><td class="n">—</td><td class="y">✓</td><td class="y">✓</td><td class="n">—</td><td class="n">—</td></tr>
      <tr><td>Purchases — View</td><td class="y">✓ all</td><td class="y">✓ all</td><td class="y">✓ all</td><td class="y">✓ all</td><td class="p">Own only</td></tr>
      <tr><td>Purchases — Submit / Edit</td><td class="p">Own only</td><td class="p">Submit / Approve</td><td class="p">Own only</td><td class="y">Any</td><td class="p">Own only</td></tr>
      <tr><td>Photo Gallery</td><td class="y">✓</td><td class="y">✓</td><td class="y">✓</td><td class="y">✓</td><td class="n">—</td></tr>
      <tr><td>Announcements</td><td class="y">✓</td><td class="y">✓</td><td class="y">✓</td><td class="n">—</td><td class="n">—</td></tr>
      <tr><td>Events</td><td class="y">✓</td><td class="y">✓</td><td class="y">✓</td><td class="n">—</td><td class="n">—</td></tr>
      <tr><td>Leadership</td><td class="y">✓</td><td class="y">✓</td><td class="n">—</td><td class="n">—</td><td class="n">—</td></tr>
      <tr><td>Email / Compose</td><td class="n">—</td><td class="y">✓</td><td class="y">✓</td><td class="n">—</td><td class="n">—</td></tr>
      <tr><td>Helpdesk — View</td><td class="y">Full</td><td class="p">Any (read-only)</td><td class="p">Any (read-only)</td><td class="p">Own only</td><td class="p">Own only</td></tr>
      <tr><td>Helpdesk — Manage / Reply</td><td class="y">✓</td><td class="p">Own only</td><td class="p">Own only</td><td class="p">Own only</td><td class="p">Own only</td></tr>
      <tr><td>Meeting Minutes &amp; Attendance</td><td class="y">✓</td><td class="y">✓</td><td class="y">✓</td><td class="y">✓</td><td class="n">—</td></tr>
      <tr><td>Correspondence Log</td><td class="y">✓</td><td class="y">✓</td><td class="y">✓</td><td class="y">✓</td><td class="n">—</td></tr>
      <tr><td>Member Letters</td><td class="y">✓</td><td class="y">✓</td><td class="y">✓</td><td class="y">✓</td><td class="n">—</td></tr>
      <tr><td>Document Vault</td><td class="n">—</td><td class="y">✓</td><td class="y">✓</td><td class="y">✓</td><td class="n">—</td></tr>
      <tr><td>Directory (opted-out members hidden)</td><td class="y">✓</td><td class="y">✓</td><td class="y">✓</td><td class="y">✓</td><td class="y">✓</td></tr>
      <tr><td>Volunteer Sign-Ups / RSVP / Photos / Committees — Submit</td><td class="y">✓</td><td class="y">✓</td><td class="y">✓</td><td class="y">✓</td><td class="y">✓</td></tr>
      <tr><td>Volunteer Sign-Ups / RSVP / Photos / Committees — Manage &amp; Review</td><td class="y">✓</td><td class="y">✓</td><td class="y">✓</td><td class="n">—</td><td class="n">—</td></tr>
      <tr><td>Site Settings</td><td class="y">✓</td><td class="y">✓</td><td class="n">—</td><td class="n">—</td><td class="n">—</td></tr>
      <tr><td>User Management</td><td class="y">Tech only</td><td class="n">—</td><td class="n">—</td><td class="n">—</td><td class="n">—</td></tr>
    </tbody>
  </table>
  </div>
</div>

<!-- 21: Tips -->
<div class="slide s-content" id="s21">
  <p class="label">Best Practices</p>
  <h2>Tips for Day-to-Day Use</h2>
  <div class="rule"></div>
  <div class="two-col">
    <ul class="bullets">
      <li><strong>Archive, don't delete</strong> — archived members can always be restored. Only delete when you're certain.</li>
      <li><strong>Use bulk actions for class-year work</strong> — filter first, then Select All to update dozens of members at once</li>
      <li><strong>Gallery photos expire in 30 days</strong> — upload fresh photos after each event to keep the slideshow current</li>
      <li><strong>Check the dashboard weekly</strong> — it shows unpaid members, pending reimbursements, and upcoming cadet birthdays at a glance</li>
      <li><strong>Leadership changes go live immediately</strong> — update officer cards as soon as a transition happens</li>
    </ul>
    <ul class="bullets">
      <li><strong>Dues Reset runs once a year</strong> — 4-Year members with active coverage are automatically protected</li>
      <li><strong>Log out on shared devices</strong> — the session will expire eventually, but logging out is the safe habit</li>
      <li><strong>Contact the Tech Officer</strong> for password resets, new user accounts, or any portal issues</li>
      <li><strong>Email sends immediately</strong> — double-check your recipient filter and count before hitting Send</li>
      <li><strong>The site reflects changes instantly</strong> — announcements, events, leadership, and settings update the public website as soon as you save</li>
      <li><strong>Take attendance right after posting minutes</strong> — the meeting is already in front of you, and it keeps the attendance rate accurate</li>
      <li><strong>Directory listing is opt-in</strong> — if a family wants to appear in the printable directory, they need to select "Yes" on their membership or update form</li>
      <li><strong>My Profile</strong> — every portal user, including members, can add their own profile picture from the My Profile tile; it shows on their dashboard and on the Users page</li>
    </ul>
  </div>
</div>

<!-- 22: Closing -->
<div class="slide s-close" id="s22">
  <div class="accent-bar" style="margin:0 auto 1.5rem"></div>
  <h1>Questions?</h1>
  <p class="cs">USAFA Parents Club of Alabama &nbsp;·&nbsp; Club Portal</p>
  <p class="cmail">mijohnst@alabamafalcons.org</p>
  <p class="cmail" style="margin-top:.5rem">alabamafalcons.org/admin</p>
</div>

</div><!-- /deck -->

<!-- Screenshot lightbox -->
<div id="lightbox" onclick="closeShot()">
  <img id="lightbox-img" src="" alt="">
</div>

<!-- Nav bar -->
<div class="nav-bar">
  <button class="nb-btn" id="btn-prev" onclick="go(-1)">← Prev</button>
  <div class="nb-progress" id="dots"></div>
  <span class="nb-ctr" id="ctr"></span>
  <button class="nb-btn" id="btn-next" onclick="go(1)">Next →</button>
  <a class="nb-btn nb-exit" href="dashboard.php">✕ Exit to Portal</a>
</div>

<script>
const TOTAL = 22;
let cur = 0;
let going = false;

const dots = document.getElementById('dots');
const ctr  = document.getElementById('ctr');
const btnP = document.getElementById('btn-prev');
const btnN = document.getElementById('btn-next');

for (let i = 0; i < TOTAL; i++) {
  const d = document.createElement('span');
  d.className = 'nb-dot' + (i === 0 ? ' on' : '');
  d.onclick = () => jumpTo(i);
  dots.appendChild(d);
}

function slides() { return document.querySelectorAll('.slide'); }

function render() {
  const all = slides();
  all.forEach((s, i) => {
    s.classList.remove('active','exit');
    if (i === cur) s.classList.add('active');
  });
  dots.querySelectorAll('.nb-dot').forEach((d, i) => d.classList.toggle('on', i === cur));
  ctr.textContent = (cur + 1) + ' / ' + TOTAL;
  btnP.disabled = cur === 0;
  btnN.disabled = cur === TOTAL - 1;
}

function go(dir) {
  if (going) return;
  const next = cur + dir;
  if (next < 0 || next >= TOTAL) return;
  going = true;
  const all = slides();
  const prev = cur;
  cur = next;
  all[prev].classList.add('exit');
  all[prev].style.transform = dir > 0 ? 'translateX(-100%)' : 'translateX(100%)';
  all[cur].style.transform = dir > 0 ? 'translateX(100%)' : 'translateX(-100%)';
  all[cur].style.transition = 'none';
  all[cur].classList.add('active');
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      all[cur].style.transition = '';
      all[cur].style.transform = 'translateX(0)';
    });
  });
  setTimeout(() => {
    all[prev].classList.remove('active','exit');
    all[prev].style.transform = '';
    going = false;
    render();
  }, 440);
  dots.querySelectorAll('.nb-dot').forEach((d, i) => d.classList.toggle('on', i === cur));
  ctr.textContent = (cur + 1) + ' / ' + TOTAL;
  btnP.disabled = cur === 0;
  btnN.disabled = cur === TOTAL - 1;
}

function jumpTo(i) {
  if (i === cur || going) return;
  go(i > cur ? 1 : -1);
  if (Math.abs(i - cur) > 1) {
    setTimeout(() => jumpTo(i), 450);
  }
}

document.addEventListener('keydown', e => {
  if (lightbox.classList.contains('open')) {
    if (e.key === 'Escape') closeShot();
    return;
  }
  if (e.key === 'ArrowRight' || e.key === ' ') { e.preventDefault(); go(1); }
  if (e.key === 'ArrowLeft') { e.preventDefault(); go(-1); }
});

// ── Screenshot lightbox ──────────────────────────────────────────────────
const lightbox    = document.getElementById('lightbox');
const lightboxImg = document.getElementById('lightbox-img');
function openShot(src, alt) {
  lightboxImg.src = src;
  lightboxImg.alt = alt || '';
  lightbox.classList.add('open');
}
function closeShot() {
  lightbox.classList.remove('open');
  lightboxImg.src = '';
}

render();
</script>
</body>
</html>
