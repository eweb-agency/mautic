/**
 * Assistant IA — le panneau d'aide de la barre haute (choix « A + raccourci »
 * du proprio, 05/08, sur maquettes).
 *
 * Un bouton ✦ « Assistant IA » dans la barre haute ouvre un panneau ancré à
 * droite : on pose une question sur l'outil, l'assistant répond, l'écran
 * reste visible et cliquable. ⌘J / Ctrl+J ouvre le panneau et met le champ
 * en saisie.
 *
 * ⚠️ MÊMES PIÈGES QUE LES AUTRES SURFACES IA :
 *  1. mQuery.ajax, JAMAIS fetch() — Mautic intercepte tout POST XHR vers /s/
 *     sans jeton CSRF ; mQuery.ajax porte le jeton automatiquement.
 *  2. Surface GATÉE par la clé : sans window.SendlyAiConfig.assistEndpoint,
 *     rien ne s'attache — le bouton n'existe même pas.
 *
 * L'historique envoyé est court (le service borne aussi côté serveur) ; la
 * conversation vit en mémoire de page — la navigation Mautic étant en ajax,
 * elle survit aux changements d'écran, et repart à zéro au rechargement.
 */
(function () {
  'use strict';

  var BRAND = '#004FFF';
  var HISTORY_SENT = 6;

  function cfg() {
    var c = window.SendlyAiConfig;
    return c && c.enabled && c.assistEndpoint ? c : null;
  }

  function t(key, fallback) {
    var s = Mautic.translate(key);
    return !s || s === key ? fallback : s;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function ensureStyles() {
    if (document.getElementById('sendly-assist-style')) {
      return;
    }
    var css =
      '@keyframes sendly-assist-spin{to{transform:rotate(360deg)}}' +
      '#sendly-assist-li a{display:inline-flex;align-items:center;gap:6px;color:' + BRAND + ';font-weight:600}' +
      '#sendly-assist-li a:hover{opacity:.8}' +
      '#sendly-assist-panel{position:fixed;right:0;width:380px;max-width:92vw;background:#fff;' +
      'border-left:1px solid #e3e7ee;box-shadow:-10px 0 24px rgba(22,35,59,.10);z-index:1040;' +
      'display:flex;flex-direction:column}' +
      '.sendly-assist-head{display:flex;align-items:center;gap:8px;padding:12px 14px;' +
      'border-bottom:1px solid #eef1f5;font-weight:700;color:#24303f}' +
      '.sendly-assist-head .ri-sparkling-2-line{color:' + BRAND + '}' +
      '.sendly-assist-close{margin-left:auto;cursor:pointer;color:#97a1b3;background:none;border:0;font-size:16px;padding:2px 6px}' +
      '.sendly-assist-close:hover{color:#24303f}' +
      '.sendly-assist-conv{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px}' +
      '.sendly-assist-me{align-self:flex-end;background:' + BRAND + ';color:#fff;border-radius:12px 12px 3px 12px;' +
      'padding:8px 11px;max-width:88%;white-space:pre-wrap;word-break:break-word}' +
      '.sendly-assist-ia{align-self:flex-start;background:#f2f5fa;color:#303a4c;border-radius:12px 12px 12px 3px;' +
      'padding:9px 11px;max-width:94%;white-space:pre-wrap;word-break:break-word}' +
      '.sendly-assist-ia.err{background:#fdeeee;color:#8f2f39}' +
      '.sendly-assist-chips{display:flex;flex-wrap:wrap;gap:6px;padding:0 14px 8px}' +
      '.sendly-assist-chip{border:1px solid #d9e2f2;color:' + BRAND + ';border-radius:999px;padding:3px 10px;' +
      'background:#fff;cursor:pointer;font-size:12px}' +
      '.sendly-assist-chip:hover{background:#f0f5ff}' +
      '.sendly-assist-foot{display:flex;gap:8px;padding:0 14px 14px}' +
      '#sendly-assist-input{flex:1;border:1px solid #d5dced;border-radius:8px;padding:8px 10px;font-size:13px}' +
      '#sendly-assist-input:focus{outline:none;border-color:' + BRAND + '}' +
      '#sendly-assist-send{border:0;border-radius:8px;background:' + BRAND + ';color:#fff;padding:8px 12px;cursor:pointer}' +
      '#sendly-assist-send[disabled]{opacity:.5;cursor:default}' +
      '.sendly-assist-spin{display:inline-block;animation:sendly-assist-spin .8s linear infinite}';
    var style = document.createElement('style');
    style.id = 'sendly-assist-style';
    style.textContent = css;
    document.head.appendChild(style);
  }

  /** Conversation en mémoire de page : [{role, content}] */
  var history = [];
  var busy = false;

  function panel() {
    return document.getElementById('sendly-assist-panel');
  }

  function isOpen() {
    var p = panel();
    return !!p && p.style.display !== 'none';
  }

  function renderConversation() {
    var conv = document.getElementById('sendly-assist-conv');
    if (!conv) {
      return;
    }
    var html = '';
    if (!history.length) {
      html += '<div class="sendly-assist-ia">' + esc(t('mautic.core.ai.assistant.welcome',
        'Bonjour ! Posez-moi une question sur Sendly : segments, campagnes, e-mails, délivrabilité…')) + '</div>';
    }
    history.forEach(function (turn) {
      html += '<div class="' + (turn.role === 'user' ? 'sendly-assist-me' : 'sendly-assist-ia' + (turn.err ? ' err' : '')) + '">' +
        esc(turn.content) + '</div>';
    });
    if (busy) {
      html += '<div class="sendly-assist-ia"><i class="ri-loader-4-line sendly-assist-spin"></i> ' +
        esc(t('mautic.core.ai.assistant.thinking', 'Je réfléchis…')) + '</div>';
    }
    conv.innerHTML = html;
    conv.scrollTop = conv.scrollHeight;

    var chips = document.getElementById('sendly-assist-chips');
    if (chips) {
      chips.style.display = history.length ? 'none' : 'flex';
    }
  }

  function ask(question) {
    var c = cfg();
    if (!c || busy) {
      return;
    }
    var q = String(question || '').trim();
    if (!q) {
      return;
    }

    // L'historique part AVANT d'y ajouter la question : le serveur la reçoit
    // séparément et la borne lui-même.
    var sent = history
      .filter(function (turn) { return !turn.err; })
      .slice(-HISTORY_SENT)
      .map(function (turn) { return { role: turn.role, content: turn.content }; });

    history.push({ role: 'user', content: q });
    busy = true;
    renderConversation();
    var input = document.getElementById('sendly-assist-input');
    if (input) {
      input.value = '';
    }

    mQuery.ajax({
      url: c.assistEndpoint,
      type: 'POST',
      dataType: 'json',
      data: { question: q, history: sent, lang: document.documentElement.lang || 'fr' },
      success: function (res) {
        busy = false;
        if (res && res.answer) {
          history.push({ role: 'assistant', content: String(res.answer) });
        } else {
          history.push({ role: 'assistant', err: true, content: t('mautic.core.ai.assistant.error', 'La requête a échoué. Réessayez.') });
        }
        renderConversation();
      },
      error: function () {
        busy = false;
        history.push({ role: 'assistant', err: true, content: t('mautic.core.ai.assistant.error', 'La requête a échoué. Réessayez.') });
        renderConversation();
      }
    });
  }

  function buildPanel() {
    if (panel()) {
      return;
    }
    ensureStyles();

    var suggestions = [
      t('mautic.core.ai.assistant.suggest1', 'Comment créer un segment ?'),
      t('mautic.core.ai.assistant.suggest2', 'Comment améliorer ma délivrabilité ?'),
      t('mautic.core.ai.assistant.suggest3', 'Comment lancer ma première campagne ?')
    ];

    var el = document.createElement('div');
    el.id = 'sendly-assist-panel';
    el.style.display = 'none';
    el.innerHTML =
      '<div class="sendly-assist-head"><i class="ri-sparkling-2-line"></i> ' +
      esc(t('mautic.core.ai.assistant.title', 'Assistant Sendly')) +
      '<button type="button" class="sendly-assist-close" aria-label="' + esc(t('mautic.core.form.close', 'Fermer')) + '">✕</button></div>' +
      '<div class="sendly-assist-conv" id="sendly-assist-conv"></div>' +
      '<div class="sendly-assist-chips" id="sendly-assist-chips">' +
      suggestions.map(function (s) {
        return '<button type="button" class="sendly-assist-chip">' + esc(s) + '</button>';
      }).join('') +
      '</div>' +
      '<div class="sendly-assist-foot">' +
      '<input id="sendly-assist-input" type="text" placeholder="' + esc(t('mautic.core.ai.assistant.placeholder', 'Posez votre question…')) + '">' +
      '<button type="button" id="sendly-assist-send" aria-label="' + esc(t('mautic.core.ai.assistant.send', 'Envoyer')) + '"><i class="ri-send-plane-2-line"></i></button>' +
      '</div>';
    document.body.appendChild(el);

    el.querySelector('.sendly-assist-close').addEventListener('click', close);
    el.querySelector('#sendly-assist-send').addEventListener('click', function () {
      ask(document.getElementById('sendly-assist-input').value);
    });
    el.querySelector('#sendly-assist-input').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        ask(this.value);
      }
    });
    mQuery(el).on('click', '.sendly-assist-chip', function () {
      ask(this.textContent);
    });
  }

  function open() {
    buildPanel();
    var p = panel();
    // Ancré sous la barre haute, quelle que soit sa hauteur réelle.
    var header = document.getElementById('app-header');
    var top = header ? header.getBoundingClientRect().bottom : 56;
    p.style.top = Math.max(0, top) + 'px';
    p.style.bottom = '0';
    p.style.display = 'flex';
    renderConversation();
    var input = document.getElementById('sendly-assist-input');
    if (input) {
      input.focus();
    }
  }

  function close() {
    var p = panel();
    if (p) {
      p.style.display = 'none';
    }
  }

  function toggle() {
    if (isOpen()) {
      close();
    } else {
      open();
    }
  }

  function injectButton() {
    if (!cfg()) {
      return;
    }
    if (document.getElementById('sendly-assist-li')) {
      return;
    }
    var nav = document.querySelector('.navbar-nav.navbar-right');
    if (!nav) {
      return;
    }
    ensureStyles();
    var li = document.createElement('li');
    li.id = 'sendly-assist-li';
    li.innerHTML = '<a href="javascript:void(0)"><i class="ri-sparkling-2-line ri-lg"></i><span>' +
      esc(t('mautic.core.ai.assistant.button', 'Assistant IA')) + '</span></a>';
    li.firstChild.addEventListener('click', toggle);
    nav.insertBefore(li, nav.firstChild);
  }

  mQuery(function () {
    injectButton();

    // ⌘J / Ctrl+J : ouvre le panneau, champ en saisie (le raccourci de la
    // proposition C, greffé sur le panneau A).
    document.addEventListener('keydown', function (e) {
      if ((e.metaKey || e.ctrlKey) && !e.shiftKey && !e.altKey && (e.key === 'j' || e.key === 'J')) {
        if (!cfg()) {
          return;
        }
        e.preventDefault();
        toggle();
      }
    });
  });
})();
