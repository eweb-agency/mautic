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
      '#sendly-assist-fab{position:fixed;right:24px;bottom:24px;width:52px;height:52px;' +
      'border-radius:50%;border:0;background:#001248;color:#fff;cursor:pointer;z-index:1035;' +
      'display:flex;align-items:center;justify-content:center;' +
      'box-shadow:0 10px 26px rgba(0,18,72,.38);transition:transform .12s ease}' +
      '#sendly-assist-fab:hover{transform:scale(1.06)}' +
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

  /** Le lanceur : bouton chatbot FLOTTANT en bas à droite (2e itération —
   *  le bouton de barre haute est retiré sur demande proprio), fond #001248,
   *  étincelles fournies par la DA. Le panneau reste le tiroir latéral. */
  function injectLauncher() {
    if (!cfg()) {
      return;
    }
    if (document.getElementById('sendly-assist-fab')) {
      return;
    }
    ensureStyles();
    var fab = document.createElement('button');
    fab.type = 'button';
    fab.id = 'sendly-assist-fab';
    fab.setAttribute('aria-label', t('mautic.core.ai.assistant.button', 'Assistant IA'));
    fab.setAttribute('title', t('mautic.core.ai.assistant.button', 'Assistant IA'));
    fab.innerHTML = '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M9.1071 5.448C9.7051 3.698 12.1231 3.645 12.8321 5.289L12.8921 5.449L13.6991 7.809C13.884 8.35023 14.1829 8.84551 14.5755 9.26142C14.9682 9.67734 15.4454 10.0042 15.9751 10.22L16.1921 10.301L18.5521 11.107C20.3021 11.705 20.3551 14.123 18.7121 14.832L18.5521 14.892L16.1921 15.699C15.6507 15.8838 15.1552 16.1826 14.7391 16.5753C14.323 16.9679 13.996 17.4452 13.7801 17.975L13.6991 18.191L12.8931 20.552C12.2951 22.302 9.8771 22.355 9.1691 20.712L9.1071 20.552L8.3011 18.192C8.11628 17.6506 7.81748 17.1551 7.42485 16.739C7.03221 16.3229 6.5549 15.9959 6.0251 15.78L5.8091 15.699L3.4491 14.893C1.6981 14.295 1.6451 11.877 3.2891 11.169L3.4491 11.107L5.8091 10.301C6.35034 10.1161 6.84562 9.81719 7.26153 9.42457C7.67744 9.03195 8.00432 8.55469 8.2201 8.025L8.3011 7.809L9.1071 5.448ZM19.0001 2C19.1872 2 19.3705 2.05248 19.5293 2.15147C19.688 2.25046 19.8158 2.392 19.8981 2.56L19.9461 2.677L20.2961 3.703L21.3231 4.053C21.5106 4.1167 21.6749 4.23462 21.7953 4.39182C21.9157 4.54902 21.9867 4.73842 21.9994 4.93602C22.012 5.13362 21.9657 5.33053 21.8663 5.50179C21.7669 5.67304 21.6189 5.81094 21.4411 5.898L21.3231 5.946L20.2971 6.296L19.9471 7.323C19.8833 7.51043 19.7653 7.6747 19.608 7.79499C19.4508 7.91529 19.2613 7.98619 19.0637 7.99872C18.8662 8.01125 18.6693 7.96484 18.4981 7.86538C18.3269 7.76591 18.1891 7.61787 18.1021 7.44L18.0541 7.323L17.7041 6.297L16.6771 5.947C16.4896 5.8833 16.3253 5.76538 16.2049 5.60819C16.0845 5.45099 16.0135 5.26158 16.0008 5.06398C15.9882 4.86638 16.0345 4.66947 16.1339 4.49821C16.2333 4.32696 16.3813 4.18906 16.5591 4.102L16.6771 4.054L17.7031 3.704L18.0531 2.677C18.1205 2.47943 18.2481 2.30791 18.4179 2.1865C18.5878 2.06509 18.7913 1.99987 19.0001 2Z" fill="currentColor"></path></svg>';
    fab.addEventListener('click', toggle);
    document.body.appendChild(fab);
  }

  mQuery(function () {
    injectLauncher();

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
