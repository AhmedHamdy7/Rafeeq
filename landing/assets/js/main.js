(function () {
  var CONFIG = {
    supportEmail: "sahla.aao007@gmail.com",
    privacyEmail: "sahla.aao007@gmail.com",
    legalEmail: "sahla.aao007@gmail.com",
    companyName: "YuGo"
  };

  var AR = window.YUGO_AR || {};
  var root = document.documentElement;
  var isHome = !!document.querySelector(".hero");
  var base = isHome ? "" : "index.html";

  var ICONS = {
    arrow: '<path d="M5 12h14m-7-7 7 7-7 7"/>',
    check: '<path d="M20 6 9 17l-5-5"/>',
    x: '<path d="M18 6 6 18M6 6l12 12"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    shield: '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
    users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    route: '<circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/>',
    wallet: '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
    repeat: '<path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>',
    chat: '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
    star: '<path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/>',
    pin: '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
    id: '<rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="8" cy="11" r="2"/><path d="M13 10h5M13 14h5M5 16c.6-1.4 1.8-2 3-2s2.4.6 3 2"/>',
    heart: '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
    flag: '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><path d="M4 22v-7"/>',
    car: '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>',
    calendar: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    mail: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 5L2 7"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2m-7.07-2.93 1.41-1.41m11.32-11.32 1.41-1.41M2 12h2m16 0h2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41"/>',
    moon: '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
    menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
    trash: '<path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>'
  };

  var style = document.createElement("style");
  style.textContent = "svg.i{fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex:none}";
  document.head.appendChild(style);

  var sprite = '<svg xmlns="http://www.w3.org/2000/svg" style="display:none">';
  Object.keys(ICONS).forEach(function (k) {
    sprite += '<symbol id="i-' + k + '" viewBox="0 0 24 24">' + ICONS[k] + "</symbol>";
  });
  sprite += "</svg>";
  document.body.insertAdjacentHTML("afterbegin", sprite);

  function ic(name, size) {
    return '<svg class="i" width="' + size + '" height="' + size + '"><use href="#i-' + name + '"/></svg>';
  }

  var header = document.getElementById("site-header");
  if (header) {
    header.innerHTML =
      '<header class="nav"><div class="container nav-inner">' +
      '<a class="brand" href="' + (isHome ? "#" : "index.html") + '"><img src="assets/img/icon-256.png" alt="" width="36" height="36"><span>YuGo</span></a>' +
      '<nav class="nav-links" id="nav-links">' +
      '<a href="' + base + '#how" data-i18n="nav.how">How it works</a>' +
      '<a href="' + base + '#features" data-i18n="nav.features">Features</a>' +
      '<a href="' + base + '#safety" data-i18n="nav.safety">Safety</a>' +
      '<a href="' + base + '#faq" data-i18n="nav.faq">FAQ</a></nav>' +
      '<div class="nav-actions">' +
      '<button class="chip" id="lang-btn" aria-label="Language">عربي</button>' +
      '<button class="chip" id="theme-btn" aria-label="Theme">' + ic("sun", 18) + "</button>" +
      '<a class="btn btn-primary" href="contact.html" data-i18n="foot.contact">Contact us</a>' +
      '<button class="chip menu-btn" id="menu-btn" aria-label="Menu">' + ic("menu", 20) + "</button>" +
      "</div></div></header>";
  }

  var footer = document.getElementById("site-footer");
  if (footer) {
    footer.innerHTML =
      '<footer class="footer"><div class="container"><div class="footer-grid">' +
      '<div><a class="brand" href="' + (isHome ? "#" : "index.html") + '"><img src="assets/img/icon-256.png" alt="" width="36" height="36"><span>YuGo</span></a>' +
      '<p data-i18n="foot.tag">Your daily commute, shared with people you trust across Greater Cairo.</p></div>' +
      '<div><h4 data-i18n="foot.product">Product</h4><ul>' +
      '<li><a href="' + base + '#how" data-i18n="nav.how">How it works</a></li>' +
      '<li><a href="' + base + '#features" data-i18n="nav.features">Features</a></li>' +
      '<li><a href="' + base + '#faq" data-i18n="nav.faq">FAQ</a></li></ul></div>' +
      '<div><h4 data-i18n="foot.legal">Legal</h4><ul>' +
      '<li><a href="privacy.html" data-i18n="foot.privacy">Privacy Policy</a></li>' +
      '<li><a href="terms.html" data-i18n="foot.terms">Terms &amp; Conditions</a></li>' +
      '<li><a href="safety.html" data-i18n="foot.safety">Safety &amp; Community Guidelines</a></li></ul></div>' +
      '<div><h4 data-i18n="foot.help">Help</h4><ul>' +
      '<li><a href="contact.html" data-i18n="foot.contact">Contact us</a></li>' +
      '<li><a href="delete-account.html" data-i18n="foot.delete">Delete account &amp; data</a></li></ul></div>' +
      '</div><div class="footer-bottom"><span>© <span id="yr"></span> ' + CONFIG.companyName + '. <span data-i18n="foot.rights">All rights reserved.</span></span>' +
      '<span data-i18n="foot.made">Made in Egypt</span></div></div></footer>';
    document.getElementById("yr").textContent = new Date().getFullYear();
  }

  document.querySelectorAll("[data-cfg]").forEach(function (el) {
    var v = CONFIG[el.dataset.cfg];
    if (!v) return;
    el.textContent = v;
    if (el.tagName === "A") el.href = "mailto:" + v;
  });

  function store(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }

  function translate(key, lang) {
    return lang === "ar" && AR[key] ? AR[key] : null;
  }

  function applyLang(lang) {
    root.lang = lang;
    root.dir = lang === "ar" ? "rtl" : "ltr";
    document.querySelectorAll("[data-i18n]").forEach(function (el) {
      if (el.dataset.en === undefined) el.dataset.en = el.innerHTML;
      var t = translate(el.dataset.i18n, lang);
      el.innerHTML = t !== null ? t : el.dataset.en;
    });
    document.querySelectorAll("[data-i18n-ph]").forEach(function (el) {
      if (el.dataset.enPh === undefined) el.dataset.enPh = el.placeholder;
      var t = translate(el.dataset.i18nPh, lang);
      el.placeholder = t !== null ? t : el.dataset.enPh;
    });
    var btn = document.getElementById("lang-btn");
    if (btn) btn.textContent = lang === "ar" ? "English" : "عربي";
    store("yugo-lang", lang);
  }

  function applyTheme(theme) {
    root.dataset.theme = theme;
    var btn = document.getElementById("theme-btn");
    if (btn) btn.innerHTML = ic(theme === "dark" ? "sun" : "moon", 18);
    store("yugo-theme", theme);
  }

  applyTheme(root.dataset.theme || "light");
  var wanted = null;
  try { wanted = new URLSearchParams(window.location.search).get("lang"); } catch (e) {}
  if (wanted === "ar" || wanted === "en") applyLang(wanted);
  else if (root.lang === "ar") applyLang("ar");

  var langBtn = document.getElementById("lang-btn");
  if (langBtn) langBtn.addEventListener("click", function () { applyLang(root.lang === "ar" ? "en" : "ar"); });
  var themeBtn = document.getElementById("theme-btn");
  if (themeBtn) themeBtn.addEventListener("click", function () { applyTheme(root.dataset.theme === "dark" ? "light" : "dark"); });

  var menuBtn = document.getElementById("menu-btn");
  var links = document.getElementById("nav-links");
  if (menuBtn && links) {
    menuBtn.addEventListener("click", function () { links.classList.toggle("open"); });
    links.addEventListener("click", function (e) { if (e.target.tagName === "A") links.classList.remove("open"); });
  }

  document.querySelectorAll(".faq-q").forEach(function (q) {
    q.addEventListener("click", function () {
      var item = q.parentElement;
      var a = item.querySelector(".faq-a");
      var open = item.classList.toggle("open");
      a.style.maxHeight = open ? a.scrollHeight + "px" : "0";
    });
  });

  document.querySelectorAll(".tab").forEach(function (tab) {
    tab.addEventListener("click", function () {
      document.querySelectorAll(".tab").forEach(function (t) { t.classList.remove("active"); });
      document.querySelectorAll(".panel").forEach(function (p) { p.classList.remove("active"); });
      tab.classList.add("active");
      document.getElementById("panel-" + tab.dataset.tab).classList.add("active");
    });
  });

  if ("IntersectionObserver" in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) { e.target.classList.add("in"); io.unobserve(e.target); }
      });
    }, { threshold: 0.12 });
    document.querySelectorAll(".reveal").forEach(function (el) { io.observe(el); });
  } else {
    document.querySelectorAll(".reveal").forEach(function (el) { el.classList.add("in"); });
  }

})();
