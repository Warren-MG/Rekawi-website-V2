/* =========================================================================
   Rekawi Company Limited — app.js
   No dependencies. One handler per form. Modals are focus-trapped.
   ========================================================================= */
(function () {
  'use strict';

  var $  = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------------------------------------------------------------------
     Hero video — reveal only when playable, fall back to the poster
     --------------------------------------------------------------------- */
  function initHeroVideo() {
    var v = $('#hero-video');
    if (!v) return;

    // Honour reduced-motion and save-data by leaving the poster in place.
    var saveData = navigator.connection && navigator.connection.saveData;
    if (reduceMotion || saveData) { v.remove(); return; }

    var settled = false;
    function ready() {
      if (settled) return;
      settled = true;
      v.dataset.ready = 'true';
    }
    function fail() {
      if (settled) return;
      settled = true;
      v.remove();
    }

    v.addEventListener('canplay', ready, { once: true });
    v.addEventListener('error', fail, { once: true });
    $$('source', v).forEach(function (src) {
      src.addEventListener('error', function () {
        if (!v.querySelector('source + source')) fail();
      });
    });

    var p = v.play();
    if (p && typeof p.catch === 'function') { p.catch(function () { /* autoplay blocked; poster stays */ }); }

    // If nothing has loaded in 6s, keep the poster rather than a black box.
    setTimeout(function () { if (v.readyState < 3) fail(); }, 6000);
  }

  /* ---------------------------------------------------------------------
     Toasts
     --------------------------------------------------------------------- */
  var TOAST_OK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
  var TOAST_ERR = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/></svg>';

  function toast(kind, msg) {
    var stack = $('#toast-stack');
    if (!stack) return;
    var el = document.createElement('div');
    el.className = 'toast toast--' + (kind === 'ok' ? 'ok' : 'err');
    el.setAttribute('role', kind === 'ok' ? 'status' : 'alert');
    el.innerHTML = (kind === 'ok' ? TOAST_OK : TOAST_ERR) + '<span>' + msg + '</span>';
    stack.appendChild(el);
    setTimeout(function () {
      el.dataset.leaving = 'true';
      setTimeout(function () { el.remove(); }, 320);
    }, 6000);
  }

  /* ---------------------------------------------------------------------
     Header: solid state on scroll + active section tracking
     --------------------------------------------------------------------- */
  function initHeader() {
    var header = $('#site-header');
    if (!header) return;
    var ticking = false;

    function onScroll() {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(function () {
        header.classList.toggle('is-solid', window.scrollY > 40);
        ticking = false;
      });
    }
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });

    // Active link via IntersectionObserver
    var links = $$('.nav__link[data-section]');
    if (!links.length || !('IntersectionObserver' in window)) return;
    var map = {};
    links.forEach(function (l) { map[l.getAttribute('data-section')] = l; });

    var sections = Object.keys(map)
      .map(function (id) { return document.getElementById(id); })
      .filter(Boolean);

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        var link = map[e.target.id];
        if (!link) return;
        if (e.isIntersecting) {
          links.forEach(function (l) { l.removeAttribute('aria-current'); });
          link.setAttribute('aria-current', 'true');
        }
      });
    }, { rootMargin: '-45% 0px -50% 0px', threshold: 0 });

    sections.forEach(function (s) { io.observe(s); });
  }

  /* ---------------------------------------------------------------------
     Mobile drawer
     --------------------------------------------------------------------- */
  function initDrawer() {
    var burger = $('#burger');
    var drawer = $('#drawer');
    if (!burger || !drawer) return;

    function setOpen(open) {
      document.body.classList.toggle('nav-open', open);
      document.body.style.overflow = open ? 'hidden' : '';
      burger.setAttribute('aria-expanded', String(open));
      drawer.setAttribute('aria-hidden', String(!open));
      if (open) {
        var first = drawer.querySelector('a, button');
        if (first) first.focus();
      }
    }

    burger.addEventListener('click', function () {
      setOpen(!document.body.classList.contains('nav-open'));
    });

    drawer.addEventListener('click', function (e) {
      if (e.target.closest('a')) setOpen(false);
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && document.body.classList.contains('nav-open')) {
        setOpen(false);
        burger.focus();
      }
    });

    window.addEventListener('resize', function () {
      if (window.innerWidth >= 1024 && document.body.classList.contains('nav-open')) setOpen(false);
    });
  }

  /* ---------------------------------------------------------------------
     Desktop dropdown — hover + keyboard
     --------------------------------------------------------------------- */
  function initDropdown() {
    $$('.nav__item--has-menu').forEach(function (item) {
      var trigger = $('.nav__link', item);
      var panel = $('.nav__panel', item);
      if (!trigger || !panel) return;
      var timer;

      function open(v) {
        clearTimeout(timer);
        item.setAttribute('aria-expanded', String(v));
        trigger.setAttribute('aria-expanded', String(v));
      }

      item.addEventListener('mouseenter', function () { open(true); });
      item.addEventListener('mouseleave', function () { timer = setTimeout(function () { open(false); }, 140); });
      item.addEventListener('focusin', function () { open(true); });
      item.addEventListener('focusout', function () {
        if (!item.contains(document.activeElement)) open(false);
      });
      trigger.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { open(false); trigger.focus(); }
      });
    });
  }

  /* ---------------------------------------------------------------------
     About slideshow — guarded, pauses when offscreen
     --------------------------------------------------------------------- */
  function initSlideshow() {
    var root = $('#about-slides');
    if (!root) return;
    var slides = $$('img', root);
    var dots = $$('button', $('#about-dots') || root);
    if (slides.length < 2) { if (slides[0]) slides[0].classList.add('is-active'); return; }

    var i = 0, timer = null, DELAY = 5000;

    function show(n) {
      i = (n + slides.length) % slides.length;
      slides.forEach(function (s, k) { s.classList.toggle('is-active', k === i); });
      dots.forEach(function (d, k) {
        if (k === i) d.setAttribute('aria-current', 'true');
        else d.removeAttribute('aria-current');
      });
    }
    function start() { if (!timer && !reduceMotion) timer = setInterval(function () { show(i + 1); }, DELAY); }
    function stop() { clearInterval(timer); timer = null; }

    dots.forEach(function (d, k) {
      d.addEventListener('click', function () { stop(); show(k); start(); });
    });

    root.addEventListener('mouseenter', stop);
    root.addEventListener('mouseleave', start);

    show(0);

    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (es) {
        es[0].isIntersecting ? start() : stop();
      }, { threshold: 0.15 }).observe(root);
    } else { start(); }

    document.addEventListener('visibilitychange', function () {
      document.hidden ? stop() : start();
    });
  }

  /* ---------------------------------------------------------------------
     Product tabs — roving tabindex, arrow keys
     --------------------------------------------------------------------- */
  function initTabs() {
    var list = $('#product-tabs');
    if (!list) return;
    var tabs = $$('[role="tab"]', list);

    function select(tab) {
      tabs.forEach(function (t) {
        var on = t === tab;
        t.setAttribute('aria-selected', String(on));
        t.tabIndex = on ? 0 : -1;
        var p = document.getElementById(t.getAttribute('aria-controls'));
        if (p) p.hidden = !on;
      });
    }

    tabs.forEach(function (tab, idx) {
      tab.addEventListener('click', function () { select(tab); });
      tab.addEventListener('keydown', function (e) {
        var d = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
        if (!d) return;
        e.preventDefault();
        var next = tabs[(idx + d + tabs.length) % tabs.length];
        next.focus(); select(next);
      });
    });
  }

  /* ---------------------------------------------------------------------
     Modals — focus trap, scroll lock, restore focus
     --------------------------------------------------------------------- */
  var Modal = (function () {
    var openEl = null, lastFocus = null;
    var SEL = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    function open(el) {
      if (!el) return;
      lastFocus = document.activeElement;
      openEl = el;
      el.classList.add('is-open');
      el.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';

      // Always start at the top, however the dialog was last left.
      $$('.modal__box, .qform__body, .scroll-y', el).forEach(function (n) {
        if (n.scrollTop) n.scrollTop = 0;
      });

      var f = el.querySelector(SEL);
      if (f) setTimeout(function () { f.focus(); }, 60);
    }

    function close() {
      if (!openEl) return;
      openEl.classList.remove('is-open');
      openEl.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      if (lastFocus) lastFocus.focus();
      openEl = null;
    }

    document.addEventListener('keydown', function (e) {
      if (!openEl) return;
      if (e.key === 'Escape') { close(); return; }
      if (e.key !== 'Tab') return;
      var items = $$(SEL, openEl).filter(function (n) { return n.offsetParent !== null; });
      if (!items.length) return;
      var first = items[0], last = items[items.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });

    document.addEventListener('click', function (e) {
      if (e.target.closest('[data-modal-close]') || e.target.classList.contains('modal__scrim')) close();
    });

    return { open: open, close: close };
  })();

  function initModalTriggers() {
    $$('[data-modal-open]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        Modal.open(document.getElementById(btn.getAttribute('data-modal-open')));
      });
    });

    // Detail modals populated from inline JSON
    var detailEl = $('#detail-modal');
    var dataEl = $('#detail-data');
    if (!detailEl || !dataEl) return;

    var DATA = {};
    try { DATA = JSON.parse(dataEl.textContent); } catch (err) { DATA = {}; }

    var carTimer = null;

    function stopCarousel() { clearInterval(carTimer); carTimer = null; }

    function buildCarousel(images, alts, title) {
      if (!images || !images.length) return '';
      var slides = images.map(function (src, i) {
        var alt = (alts && alts[i]) ? alts[i] : (title + ' \u2014 image ' + (i + 1));
        return '<img src="' + src + '" alt="' + alt + '"' + (i === 0 ? ' class="is-active"' : '') +
               ' loading="lazy" decoding="async">';
      }).join('');

      var nav = '';
      if (images.length > 1) {
        nav =
          '<button type="button" class="mcar__btn mcar__btn--prev" aria-label="Previous image">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>' +
          '</button>' +
          '<button type="button" class="mcar__btn mcar__btn--next" aria-label="Next image">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>' +
          '</button>';
      }
      return '<div class="mcar"><div class="mcar__frame">' + slides + nav + '</div></div>';
    }

    function initCarousel(root) {
      var frame = $('.mcar__frame', root);
      if (!frame) return;
      var imgs = $$('img', frame);
      if (imgs.length < 2) return;

      var i = 0;
      function show(n) {
        i = (n + imgs.length) % imgs.length;
        imgs.forEach(function (im, k) { im.classList.toggle('is-active', k === i); });
      }
      function start() { if (!carTimer && !reduceMotion) carTimer = setInterval(function () { show(i + 1); }, 4500); }

      var prev = $('.mcar__btn--prev', root);
      var next = $('.mcar__btn--next', root);
      if (prev) prev.addEventListener('click', function () { stopCarousel(); show(i - 1); start(); });
      if (next) next.addEventListener('click', function () { stopCarousel(); show(i + 1); start(); });
      frame.addEventListener('mouseenter', stopCarousel);
      frame.addEventListener('mouseleave', start);
      start();
    }

    function listBlock(heading, items) {
      if (!items || !items.length) return '';
      return '<div class="detail__block"><h4>' + heading + '</h4><ul>' +
             items.map(function (x) { return '<li>' + x + '</li>'; }).join('') +
             '</ul></div>';
    }

    var SERVICE_KEYS = { building: 1, water: 1, civil: 1, automation: 1 };
    var current = { key: '', title: '', isService: false };

    $$('[data-detail]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var key = btn.getAttribute('data-detail');
        var d = DATA[key];
        if (!d) return;

        current = { key: key, title: d.title || '', isService: !!SERVICE_KEYS[key] };
        stopCarousel();

        var eyebrow = $('#detail-eyebrow');
        if (eyebrow) eyebrow.textContent = current.isService ? 'Our service' : 'Our product';
        $('#detail-title').textContent = d.title || '';

        var media = $('#detail-media');
        var imgs = d.images && d.images.length ? d.images : (d.image ? [d.image] : []);
        if (imgs.length) {
          media.innerHTML = buildCarousel(imgs, d.alts, d.title || '');
          media.hidden = false;
        } else {
          media.hidden = true;
          media.innerHTML = '';
        }

        var html = '';
        if (d.description) {
          html += '<p class="detail__text" style="margin-bottom:1.4rem">' + d.description + '</p>';
        }
        html += listBlock('Service details', d.services);
        html += listBlock('Features', d.features);
        html += listBlock('Applications', d.applications);
        $('#detail-body').innerHTML = html;

        Modal.open(detailEl);
        var box = $('#detail-scroll');
        if (box) box.scrollTop = 0;
        if (!media.hidden) initCarousel(media);
      });
    });

    // Quote CTA inside the detail modal: routes to service or product form
    var detailQuote = $('#detail-quote');
    if (detailQuote) {
      detailQuote.addEventListener('click', function () {
        stopCarousel();
        var isService = current.isService;
        var label = current.title;
        Modal.close();
        setTimeout(function () {
          if (isService) { openQuoteModal('service', label); }
          else { openQuoteModal('product', label); }
        }, 240);
      });
    }

    detailEl.addEventListener('transitionend', function () {
      if (!detailEl.classList.contains('is-open')) stopCarousel();
    });
  }

  /* ---------------------------------------------------------------------
     Service / product quote modals
     --------------------------------------------------------------------- */
  var QUOTE_TARGETS = {
    service: { modal: 'service-quote-modal', chip: 'sq-chip', input: 'sq-service-name', head: 'sq-h' },
    product: { modal: 'product-quote-modal', chip: 'pq-chip', input: 'pq-product-name', head: 'pq-h' }
  };

  function scrollModalTop(el, smooth) {
    if (!el) return;
    var pane = el.querySelector('.qform__body') || el.querySelector('.modal__box');
    if (!pane) return;
    if (smooth && !reduceMotion && typeof pane.scrollTo === 'function') {
      pane.scrollTo({ top: 0, behavior: 'smooth' });
    } else {
      pane.scrollTop = 0;
    }
  }

  function openQuoteModal(type, label) {
    var t = QUOTE_TARGETS[type];
    if (!t) return;
    var modal = document.getElementById(t.modal);
    if (!modal) return;

    var chip = document.getElementById(t.chip);
    var input = document.getElementById(t.input);
    var head = document.getElementById(t.head);
    if (chip) chip.textContent = label || '';
    if (input) input.value = label || '';
    if (head) head.textContent = label || '';

    scrollModalTop(modal, false);
    var status = modal.querySelector('.status');
    if (status) { status.className = 'status'; status.innerHTML = ''; }

    Modal.open(modal);
  }

  function initQuoteModals() {
    function decode(v) {
      var t = document.createElement('textarea');
      t.innerHTML = v;
      return t.value;
    }
    // Any element carrying these attributes opens the matching form.
    $$('[data-service-quote]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openQuoteModal('service', decode(btn.getAttribute('data-service-quote')));
      });
    });
    $$('[data-product-quote]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openQuoteModal('product', decode(btn.getAttribute('data-product-quote')));
      });
    });
  }

  /* ---------------------------------------------------------------------
     Forms — ONE handler. Validates, posts, reports precisely.
     --------------------------------------------------------------------- */
  var ICON_OK  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
  var ICON_ERR = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/></svg>';

  function setStatus(box, kind, msg) {
    if (!box) return;
    box.className = 'status is-visible status--' + kind;
    box.innerHTML = (kind === 'ok' ? ICON_OK : ICON_ERR) + '<span>' + msg + '</span>';
    box.setAttribute('role', kind === 'ok' ? 'status' : 'alert');
  }
  function clearStatus(box) {
    if (!box) return;
    box.className = 'status';
    box.innerHTML = '';
  }

  function fieldError(input, msg) {
    var wrap = input.closest('.field');
    var err = wrap ? wrap.querySelector('.field__err') : null;
    if (msg) {
      input.setAttribute('aria-invalid', 'true');
      if (err) err.textContent = msg;
    } else {
      input.removeAttribute('aria-invalid');
      if (err) err.textContent = '';
    }
  }

  function validate(form) {
    var ok = true, firstBad = null;
    $$('input, select, textarea', form).forEach(function (el) {
      if (el.type === 'hidden' || el.closest('.hp')) return;
      fieldError(el, '');
      if (!el.hasAttribute('required')) return;

      var v = (el.value || '').trim();
      var msg = '';
      if (!v) {
        msg = 'This field is required.';
      } else if (el.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v)) {
        msg = 'Enter a valid email address, like name@company.com.';
      } else if (el.tagName === 'TEXTAREA' && v.length < 10) {
        msg = 'Add a little more detail — at least 10 characters.';
      }
      if (msg) { ok = false; fieldError(el, msg); if (!firstBad) firstBad = el; }
    });
    if (firstBad) firstBad.focus();
    return ok;
  }

  function initForms() {
    $$('form[data-endpoint]').forEach(function (form) {
      var status = form.querySelector('.status');
      var btn = form.querySelector('button[type="submit"]');
      var label = btn ? btn.innerHTML : '';

      // Clear a field's error as soon as the user corrects it
      $$('input, select, textarea', form).forEach(function (el) {
        el.addEventListener('input', function () {
          if (el.getAttribute('aria-invalid') === 'true') fieldError(el, '');
        });
      });

      form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearStatus(status);
        if (!validate(form)) {
          setStatus(status, 'err', 'Check the highlighted fields and try again.');
          var bad = form.querySelector('[aria-invalid="true"]');
          if (bad && typeof bad.scrollIntoView === 'function') {
            bad.scrollIntoView({
              behavior: reduceMotion ? 'auto' : 'smooth',
              block: 'center'
            });
          }
          return;
        }

        var fd = new FormData(form);
        if (btn) {
          btn.dataset.busy = 'true';
          btn.disabled = true;
          btn.innerHTML = '<span class="spinner"></span><span>Sending…</span>';
        }

        var ctrl = new AbortController();
        var to = setTimeout(function () { ctrl.abort(); }, 20000);

        fetch(form.getAttribute('data-endpoint'), {
          method: 'POST',
          body: fd,
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          signal: ctrl.signal
        })
          .then(function (res) {
            clearTimeout(to);
            return res.text().then(function (text) {
              var data;
              try { data = JSON.parse(text); }
              catch (err) {
                throw new Error('The server returned an unexpected response. Please email info@rekawi.com.');
              }
              if (!res.ok || data.status !== 'success') {
                throw new Error(data.message || 'We could not send your message. Please try again.');
              }
              return data;
            });
          })
          .then(function (data) {
            var okMsg = data.message || 'Message sent. We will reply within one business day.';
            setStatus(status, 'ok', okMsg);
            toast('ok', okMsg);
            var dlg = form.closest('.modal');
            if (dlg) scrollModalTop(dlg, true);
            form.reset();
            $$('[aria-invalid]', form).forEach(function (el) { el.removeAttribute('aria-invalid'); });
            if (form.dataset.closeOnSuccess) {
              setTimeout(function () { Modal.close(); clearStatus(status); }, 2600);
            }
          })
          .catch(function (err) {
            clearTimeout(to);
            var msg = err.name === 'AbortError'
              ? 'The request timed out. Check your connection, or email info@rekawi.com.'
              : err.message;
            setStatus(status, 'err', msg);
            toast('err', msg);
          })
          .finally(function () {
            if (btn) {
              btn.dataset.busy = 'false';
              btn.disabled = false;
              btn.innerHTML = label;
            }
          });
      });
    });
  }


  /* ---------------------------------------------------------------------
     Custom select + date picker
     Progressive enhancement: the native control stays in the DOM, holds the
     value and is what the form submits. If this code never runs, the native
     control is simply revealed and everything still works.
     --------------------------------------------------------------------- */
  var CHEV = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
  var openPick = null;

  function closePick() {
    if (!openPick) return;
    openPick.root.classList.remove('is-open', 'is-up');
    openPick.btn.setAttribute('aria-expanded', 'false');
    openPick = null;
  }

  // Flip the panel above the trigger when there isn't room below.
  // Measured after the panel is visible, so offsetHeight is real.
  function placePanel(root, panel) {
    root.classList.remove('is-up');

    var r = root.getBoundingClientRect();
    var h = panel.offsetHeight || 240;

    // Constrain to the scrolling pane if we're inside one, else the viewport.
    var pane = root.closest('.qform__body') || root.closest('.modal__box');
    var lowerLimit = pane ? pane.getBoundingClientRect().bottom : window.innerHeight;
    var upperLimit = pane ? pane.getBoundingClientRect().top : 0;
    lowerLimit = Math.min(lowerLimit, window.innerHeight);

    var roomBelow = lowerLimit - r.bottom;
    var roomAbove = r.top - upperLimit;

    // Flip up only when it genuinely helps.
    if (roomBelow < h + 12 && roomAbove > roomBelow) root.classList.add('is-up');

    // If neither side fits, cap the list so the panel stays inside the pane.
    var list = panel.querySelector('.pick__list');
    if (list) {
      var room = Math.max(roomBelow, roomAbove) - 24;
      list.style.maxHeight = room > 120 ? Math.min(240, room) + 'px' : '';
    }
  }

  function initSelects() {
    $$('select.field__select').forEach(function (sel) {
      if (sel.closest('.pick')) return;

      var root = document.createElement('div');
      root.className = 'pick';
      sel.parentNode.insertBefore(root, sel);
      root.appendChild(sel);

      var opts = $$('option', sel);
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'pick__btn';
      btn.setAttribute('aria-haspopup', 'listbox');
      btn.setAttribute('aria-expanded', 'false');
      if (sel.id) btn.setAttribute('aria-labelledby', sel.id + '-label');

      var panel = document.createElement('div');
      panel.className = 'pick__panel';
      var list = document.createElement('div');
      list.className = 'pick__list';
      list.setAttribute('role', 'listbox');
      panel.appendChild(list);

      // Keep the visible <label for="..."> pointing at something focusable
      var lbl = sel.id ? document.querySelector('label[for="' + sel.id + '"]') : null;
      if (lbl) {
        lbl.id = sel.id + '-label';
        lbl.addEventListener('click', function (e) { e.preventDefault(); btn.focus(); btn.click(); });
      }

      function paint() {
        var o = opts[sel.selectedIndex] || opts[0];
        var isPlaceholder = !o || o.value === '';
        btn.innerHTML = '<span>' + ((o && o.textContent) || '') + '</span>' + CHEV;
        btn.dataset.placeholder = String(isPlaceholder);
        $$('.pick__opt', list).forEach(function (b, i) {
          b.setAttribute('aria-selected', String(i === sel.selectedIndex));
        });
      }

      opts.forEach(function (o, i) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'pick__opt';
        b.setAttribute('role', 'option');
        b.textContent = o.textContent;
        b.addEventListener('click', function () {
          sel.selectedIndex = i;
          sel.dispatchEvent(new Event('change', { bubbles: true }));
          sel.dispatchEvent(new Event('input', { bubbles: true }));
          paint();
          closePick();
          btn.focus();
        });
        list.appendChild(b);
      });

      btn.addEventListener('click', function () {
        var wasOpen = root.classList.contains('is-open');
        closePick();
        if (wasOpen) return;
        root.classList.add('is-open');
        btn.setAttribute('aria-expanded', 'true');
        placePanel(root, panel);
        openPick = { root: root, btn: btn };
        var cur = list.children[sel.selectedIndex];
        if (cur && cur.scrollIntoView) cur.scrollIntoView({ block: 'nearest' });
      });

      btn.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
          e.preventDefault();
          var next = sel.selectedIndex + (e.key === 'ArrowDown' ? 1 : -1);
          if (next >= 0 && next < opts.length) {
            sel.selectedIndex = next;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
            paint();
          }
        } else if (e.key === 'Escape') { closePick(); }
      });

      // Mirror programmatic resets (form.reset() etc.)
      sel.addEventListener('change', paint);

      root.appendChild(btn);
      root.appendChild(panel);
      paint();
    });
  }

  var MONTHS = ['January','February','March','April','May','June',
                'July','August','September','October','November','December'];
  var DOW = ['S','M','T','W','T','F','S'];

  function initDates() {
    $$('input[type="date"]').forEach(function (input) {
      if (input.closest('.pick')) return;

      var root = document.createElement('div');
      root.className = 'pick';
      input.parentNode.insertBefore(root, input);
      root.appendChild(input);

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'pick__btn';
      btn.setAttribute('aria-haspopup', 'dialog');
      btn.setAttribute('aria-expanded', 'false');

      var panel = document.createElement('div');
      panel.className = 'pick__panel pick__panel--date';

      var lbl = input.id ? document.querySelector('label[for="' + input.id + '"]') : null;
      if (lbl) {
        lbl.addEventListener('click', function (e) { e.preventDefault(); btn.focus(); btn.click(); });
      }

      var today = new Date(); today.setHours(0,0,0,0);
      var view = new Date(today.getFullYear(), today.getMonth(), 1);
      var min = input.min ? parseISO(input.min) : today;   // no past dates by default

      function parseISO(v) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v || '');
        return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
      }
      function toISO(d) {
        return d.getFullYear() + '-' +
               String(d.getMonth() + 1).padStart(2, '0') + '-' +
               String(d.getDate()).padStart(2, '0');
      }
      function pretty(d) {
        return d.getDate() + ' ' + MONTHS[d.getMonth()].slice(0, 3) + ' ' + d.getFullYear();
      }

      function paintBtn() {
        var d = parseISO(input.value);
        btn.innerHTML = '<span>' + (d ? pretty(d) : 'Select a date') + '</span>' +
          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
          '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>';
        btn.dataset.placeholder = String(!d);
      }

      function render() {
        var sel = parseISO(input.value);
        var y = view.getFullYear(), mo = view.getMonth();
        var first = new Date(y, mo, 1).getDay();
        var days = new Date(y, mo + 1, 0).getDate();
        var canBack = new Date(y, mo, 1) > new Date(min.getFullYear(), min.getMonth(), 1);

        var html = '<div class="cal__head">' +
          '<button type="button" class="cal__nav" data-cal="prev" aria-label="Previous month"' + (canBack ? '' : ' disabled') + '>' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>' +
          '</button>' +
          '<span class="cal__title">' + MONTHS[mo] + ' ' + y + '</span>' +
          '<button type="button" class="cal__nav" data-cal="next" aria-label="Next month">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>' +
          '</button></div><div class="cal__grid">';

        DOW.forEach(function (d) { html += '<span class="cal__dow">' + d + '</span>'; });
        for (var i = 0; i < first; i++) html += '<span class="cal__day is-blank"></span>';

        for (var n = 1; n <= days; n++) {
          var d = new Date(y, mo, n);
          var cls = 'cal__day';
          if (+d === +today) cls += ' is-today';
          if (sel && +d === +sel) cls += ' is-selected';
          var off = d < min;
          html += '<button type="button" class="' + cls + '" data-day="' + n + '"' + (off ? ' disabled' : '') + '>' + n + '</button>';
        }

        html += '</div><div class="cal__foot">' +
          '<button type="button" class="cal__act" data-cal="today">Today</button>' +
          '<button type="button" class="cal__act" data-cal="clear">Clear</button></div>';

        panel.innerHTML = html;
      }

      panel.addEventListener('click', function (e) {
        var nav = e.target.closest('[data-cal]');
        if (nav) {
          var a = nav.getAttribute('data-cal');
          if (a === 'prev') { view.setMonth(view.getMonth() - 1); render(); }
          else if (a === 'next') { view.setMonth(view.getMonth() + 1); render(); }
          else if (a === 'today') {
            input.value = toISO(today);
            input.dispatchEvent(new Event('change', { bubbles: true }));
            paintBtn(); closePick(); btn.focus();
          } else if (a === 'clear') {
            input.value = '';
            input.dispatchEvent(new Event('change', { bubbles: true }));
            paintBtn(); closePick(); btn.focus();
          }
          return;
        }
        var day = e.target.closest('[data-day]');
        if (day && !day.disabled) {
          input.value = toISO(new Date(view.getFullYear(), view.getMonth(), +day.getAttribute('data-day')));
          input.dispatchEvent(new Event('change', { bubbles: true }));
          input.dispatchEvent(new Event('input', { bubbles: true }));
          paintBtn(); closePick(); btn.focus();
        }
      });

      btn.addEventListener('click', function () {
        var wasOpen = root.classList.contains('is-open');
        closePick();
        if (wasOpen) return;
        var cur = parseISO(input.value);
        view = cur ? new Date(cur.getFullYear(), cur.getMonth(), 1)
                   : new Date(today.getFullYear(), today.getMonth(), 1);
        render();
        root.classList.add('is-open');
        btn.setAttribute('aria-expanded', 'true');
        placePanel(root, panel);
        openPick = { root: root, btn: btn };
      });

      btn.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closePick();
      });

      input.addEventListener('change', paintBtn);

      root.appendChild(btn);
      root.appendChild(panel);
      paintBtn();
    });
  }

  function initPickers() {
    initSelects();
    initDates();
    document.addEventListener('click', function (e) {
      if (openPick && !e.target.closest('.pick')) closePick();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && openPick) { var b = openPick.btn; closePick(); b.focus(); }
    });
    // A dialog scrolling underneath an open panel would leave it stranded
    $$('.qform__body, .modal__box').forEach(function (n) {
      n.addEventListener('scroll', function () { if (openPick) closePick(); }, { passive: true });
    });
  }

  /* ---------------------------------------------------------------------
     Scroll reveal
     --------------------------------------------------------------------- */
  function initReveal() {
    var items = $$('[data-reveal]');
    if (!items.length) return;
    if (reduceMotion || !('IntersectionObserver' in window)) {
      items.forEach(function (el) { el.classList.add('is-in'); });
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        e.target.classList.add('is-in');
        io.unobserve(e.target);
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    items.forEach(function (el) { io.observe(el); });
  }

  function initYear() {
    var y = $('#year');
    if (y) y.textContent = new Date().getFullYear();
  }

  /* --------------------------------------------------------------------- */
  function init() {
    initHeroVideo();
    initHeader();
    initDrawer();
    initDropdown();
    initSlideshow();
    initTabs();
    initModalTriggers();
    initQuoteModals();
    initPickers();
    initForms();
    initReveal();
    initYear();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else { init(); }
})();
