(function () {
  'use strict';

  // Куда отправлять заявку. send.php работает на любом хостинге с PHP.
  // Пустая строка включает демо-режим: форма проверяется, но никуда не отправляется.
  var FORM_ENDPOINT = 'send.php';

  var header = document.querySelector('[data-header]');
  var burger = header.querySelector('.burger');
  var nav = document.getElementById('site-nav');

  /* ---------- Мобильное меню ---------- */
  function setNav(open) {
    header.classList.toggle('is-open', open);
    burger.setAttribute('aria-expanded', String(open));
    burger.setAttribute('aria-label', open ? 'Закрыть меню' : 'Открыть меню');
    document.body.classList.toggle('no-scroll', open);
  }

  burger.addEventListener('click', function () {
    setNav(!header.classList.contains('is-open'));
  });

  nav.addEventListener('click', function (e) {
    if (e.target.closest('a')) setNav(false);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && header.classList.contains('is-open')) {
      setNav(false);
      burger.focus();
    }
  });

  var desktop = window.matchMedia('(min-width: 1025px)');
  var onDesktop = function (e) { if (e.matches) setNav(false); };
  if (desktop.addEventListener) desktop.addEventListener('change', onDesktop);

  /* ---------- Шапка при прокрутке ---------- */
  var onScroll = function () {
    header.classList.toggle('is-scrolled', window.scrollY > 8);
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* ---------- Активный пункт меню ---------- */
  var links = Array.prototype.slice.call(nav.querySelectorAll('.nav__link'));
  var sections = links
    .map(function (link) { return document.querySelector(link.getAttribute('href')); })
    .filter(Boolean);

  if ('IntersectionObserver' in window && sections.length) {
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        var id = '#' + entry.target.id;
        links.forEach(function (link) {
          link.classList.toggle('is-active', link.getAttribute('href') === id);
        });
      });
    }, { rootMargin: '-40% 0px -55% 0px' });
    sections.forEach(function (section) { observer.observe(section); });
  }

  /* ---------- Год в подвале ---------- */
  var year = String(new Date().getFullYear());
  Array.prototype.forEach.call(document.querySelectorAll('[data-year]'), function (el) {
    el.textContent = year;
  });

  /* ---------- Форма заявки ---------- */
  var form = document.getElementById('request-form');
  if (!form) return;

  var card = form.closest('.form-card');
  var done = card.querySelector('.form-done');
  var status = form.querySelector('.form__status');
  var submit = form.querySelector('button[type="submit"]');
  var phone = form.elements.phone;

  // Маска телефона: +7 (XXX) XXX-XX-XX
  function formatPhone(value) {
    var d = value.replace(/\D/g, '');
    if (!d) return '';
    if (d.charAt(0) === '8') d = '7' + d.slice(1);
    if (d.charAt(0) !== '7') d = '7' + d;
    d = d.slice(0, 11);
    var out = '+7';
    if (d.length > 1) out += ' (' + d.slice(1, 4);
    if (d.length >= 4) out += ')';
    if (d.length > 4) out += ' ' + d.slice(4, 7);
    if (d.length > 7) out += '-' + d.slice(7, 9);
    if (d.length > 9) out += '-' + d.slice(9, 11);
    return out;
  }

  phone.addEventListener('input', function (e) {
    if (e.inputType && e.inputType.indexOf('delete') === 0) return;
    phone.value = formatPhone(phone.value);
  });
  phone.addEventListener('blur', function () {
    phone.value = formatPhone(phone.value);
  });

  // Кнопки «Запросить цену» в карточках отмечают нужное направление в форме
  document.addEventListener('click', function (e) {
    var trigger = e.target.closest('[data-service]');
    if (!trigger) return;
    var box = form.querySelector('input[name="services[]"][value="' + trigger.getAttribute('data-service') + '"]');
    if (box) box.checked = true;
  });

  function setError(input, message) {
    var field = input.closest('.field');
    var error = field.querySelector('.field__error');
    field.classList.toggle('has-error', Boolean(message));
    input.setAttribute('aria-invalid', message ? 'true' : 'false');
    if (error) {
      error.textContent = message || '';
      if (message) input.setAttribute('aria-describedby', error.id);
      else input.removeAttribute('aria-describedby');
    }
  }

  var rules = {
    name: function (input) {
      return input.value.trim().length < 2 ? 'Укажите имя, чтобы мы знали, как к вам обращаться' : '';
    },
    phone: function (input) {
      return input.value.replace(/\D/g, '').length !== 11 ? 'Введите номер полностью: +7 и ещё 10 цифр' : '';
    },
    consent: function (input) {
      return input.checked ? '' : 'Без согласия на обработку данных мы не сможем принять заявку';
    }
  };

  function validate() {
    var firstInvalid = null;
    Object.keys(rules).forEach(function (name) {
      var input = form.elements[name];
      var message = rules[name](input);
      setError(input, message);
      if (message && !firstInvalid) firstInvalid = input;
    });
    return firstInvalid;
  }

  // После первой ошибки поле перепроверяется на лету
  Object.keys(rules).forEach(function (name) {
    var input = form.elements[name];
    var evt = input.type === 'checkbox' ? 'change' : 'input';
    input.addEventListener(evt, function () {
      if (input.getAttribute('aria-invalid') === 'true') setError(input, rules[name](input));
    });
  });

  function showStatus(message, kind) {
    status.innerHTML = message;
    status.classList.toggle('is-info', kind === 'info');
    status.hidden = false;
  }

  function showDone() {
    form.hidden = true;
    done.hidden = false;
    done.querySelector('.form__title').focus();
  }

  card.querySelector('[data-form-reset]').addEventListener('click', function () {
    form.reset();
    status.hidden = true;
    done.hidden = true;
    form.hidden = false;
    form.elements.name.focus();
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    status.hidden = true;

    if (form.elements.website.value) return; // бот заполнил поле-ловушку

    var invalid = validate();
    if (invalid) {
      invalid.focus();
      return;
    }

    if (!FORM_ENDPOINT) {
      showStatus('Демо-режим: заявка не отправлена. Чтобы форма заработала, разместите сайт на хостинге с PHP и укажите адрес обработчика в <code>assets/js/main.js</code>.', 'info');
      return;
    }

    submit.disabled = true;
    submit.classList.add('is-loading');

    fetch(FORM_ENDPOINT, {
      method: 'POST',
      body: new FormData(form),
      headers: { Accept: 'application/json' }
    })
      .then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (data) {
          if (!res.ok || !data.ok) throw new Error('send failed');
        });
      })
      .then(function () {
        form.reset();
        showDone();
      })
      .catch(function () {
        showStatus('Не удалось отправить заявку. Попробуйте ещё раз через минуту или позвоните нам: <a href="tel:+79953680721">+7&nbsp;(995)&nbsp;368-07-21</a>.');
      })
      .then(function () {
        submit.disabled = false;
        submit.classList.remove('is-loading');
      });
  });
})();
