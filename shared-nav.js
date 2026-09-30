document.getElementById('hamburger').addEventListener('click', function() {
  document.getElementById('nav-links').classList.toggle('open');
});
document.querySelectorAll('.nav-links > li').forEach(function(item) {
  var toggle = Array.from(item.children).find(function(child) {
    return child.matches('a');
  });
  if (!toggle || toggle.textContent.trim().toLowerCase() !== 'get involved') return;

  item.classList.add('nav-dropdown');
  var menu = Array.from(item.children).find(function(child) {
    return child.matches('.nav-dropdown-menu');
  });
  if (!menu) {
    menu = document.createElement('ul');
    menu.className = 'nav-dropdown-menu';
    item.appendChild(menu);
  }

  menu.replaceChildren();
  [
    ['membership.html', 'Join the Club'],
    ['update.html', 'Update Your Information']
  ].forEach(function(entry) {
    var listItem = document.createElement('li');
    var link = document.createElement('a');
    link.href = entry[0];
    link.textContent = entry[1];
    listItem.appendChild(link);
    menu.appendChild(listItem);
  });
});
var navLinks = document.querySelector('.nav-links');
if (navLinks && !navLinks.querySelector('.nav-admin-login')) {
  var adminItem = document.createElement('li');
  var adminLink = document.createElement('a');
  adminLink.className = 'nav-login-btn nav-admin-login';
  adminLink.href = '/admin/login.php';
  adminLink.textContent = 'Admin Login';
  adminItem.appendChild(adminLink);
  navLinks.appendChild(adminItem);
}
document.querySelectorAll('.nav-links a').forEach(function(link) {
  link.addEventListener('click', function() {
    var isDropdownToggle = link.parentElement.classList.contains('nav-dropdown');
    if (isDropdownToggle && window.innerWidth <= 768) return;
    document.getElementById('nav-links').classList.remove('open');
  });
});
document.querySelectorAll('.nav-dropdown > a').forEach(function(a) {
  a.addEventListener('click', function(e) {
    if (window.innerWidth <= 768) {
      e.preventDefault();
      var parent = a.parentElement;
      parent.classList.toggle('open');
    }
  });
});
// Facebook links marked data-facebook-link follow the "Facebook Group URL"
// site setting; the hardcoded href stays as the fallback if the feed fails.
// (index.html applies the setting itself as part of its own settings load.)
var facebookLinks = document.querySelectorAll('a[data-facebook-link]');
if (facebookLinks.length) {
  fetch('settings-feed.php')
    .then(function(r) { return r.json(); })
    .then(function(data) {
      var url = data && data.settings && data.settings.facebook_url;
      if (!url || !/^https?:\/\//i.test(url)) return;
      facebookLinks.forEach(function(link) { link.href = url; });
    })
    .catch(function() {});
}
