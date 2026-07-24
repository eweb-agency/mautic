/**
 * Copilote IA — « Suggérer un objet avec l'IA » (façon Webmecanik).
 *
 * Un bouton nommé sous le champ objet ouvre un panneau en deux temps :
 *   1. paramètres (ton, emojis, consigne)
 *   2. trois propositions, chacune régénérable → « Utiliser l'objet sélectionné ».
 *
 * Auto-agrégé dans app.js (présent sur toutes les pages admin) mais INERTE
 * tant que window.SendlyAiConfig n'est pas défini — ce drapeau n'est injecté
 * (AiConfigAssetsSubscriber) que si une clé Anthropic est configurée. La clé
 * n'est jamais ici : tout passe par l'endpoint serveur /s/ai/generate.
 */
(function () {
  'use strict';

  var BRAND = '#004FFF';

  function t(key, fallback) {
    var s = Mautic.translate(key);
    return !s || s === key ? fallback : s;
  }

  var TONES = [
    { v: 'persuasif', k: 'mautic.email.ai.tone.persuasive', fb: 'Persuasif' },
    { v: 'informatif', k: 'mautic.email.ai.tone.informative', fb: 'Informatif' },
    { v: 'amical', k: 'mautic.email.ai.tone.friendly', fb: 'Amical' },
    { v: 'urgent', k: 'mautic.email.ai.tone.urgent', fb: 'Urgent' },
    { v: 'professionnel', k: 'mautic.email.ai.tone.professional', fb: 'Professionnel' }
  ];

  var LANG_NAMES = {
    fr: 'French', en: 'English', es: 'Spanish', de: 'German',
    it: 'Italian', pt: 'Portuguese', nl: 'Dutch'
  };

  function ensureStyles() {
    if (document.getElementById('sendly-ai-style')) {
      return;
    }
    var css =
      '@keyframes sendly-ai-spin{to{transform:rotate(360deg)}}' +
      '.sendly-ai-spin{display:inline-block;animation:sendly-ai-spin .8s linear infinite}' +
      '#sendly-ai-subject-btn{margin-top:8px;display:inline-flex;align-items:center;gap:6px;' +
      'padding:7px 14px;border:1px solid ' + BRAND + ';border-radius:6px;background:rgba(0,79,255,.06);' +
      'color:' + BRAND + ';font-weight:600;font-size:13px;cursor:pointer;line-height:1.2}' +
      '#sendly-ai-subject-btn:hover{background:rgba(0,79,255,.12)}' +
      '#sendly-ai-subject-btn[disabled]{opacity:.6;cursor:default}' +
      '.sendly-ai-overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:20000;' +
      'display:flex;align-items:center;justify-content:center;padding:16px}' +
      '.sendly-ai-panel{background:#fff;border-radius:12px;width:560px;max-width:100%;' +
      'max-height:88vh;overflow:auto;box-shadow:0 12px 40px rgba(0,0,0,.25);color:#1f2937}' +
      '.sendly-ai-head{display:flex;align-items:center;gap:8px;padding:18px 20px 6px}' +
      '.sendly-ai-head i{color:' + BRAND + '}' +
      '.sendly-ai-title{font-size:16px;font-weight:700;margin:0}' +
      '.sendly-ai-body{padding:8px 20px 20px}' +
      '.sendly-ai-lbl{display:block;font-size:12px;color:#6b7280;margin:12px 0 4px}' +
      '.sendly-ai-panel select,.sendly-ai-panel textarea{width:100%;border:1px solid #d1d5db;' +
      'border-radius:6px;padding:8px 10px;font-size:13px;font-family:inherit;box-sizing:border-box}' +
      '.sendly-ai-check{display:flex;align-items:center;gap:8px;margin-top:12px;font-size:13px;color:#374151}' +
      '.sendly-ai-chips{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:4px 0 14px}' +
      '.sendly-ai-chip{font-size:12px;background:#f3f4f6;color:#4b5563;padding:4px 10px;border-radius:6px}' +
      '.sendly-ai-row{display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid #e5e7eb;' +
      'border-radius:8px;margin-bottom:8px;cursor:pointer}' +
      '.sendly-ai-row.sel{border:2px solid ' + BRAND + ';background:rgba(0,79,255,.04)}' +
      '.sendly-ai-row .txt{flex:1;font-size:14px;line-height:1.4}' +
      '.sendly-ai-regen{border:none;background:transparent;color:#6b7280;cursor:pointer;padding:6px;line-height:0}' +
      '.sendly-ai-regen:hover{color:' + BRAND + '}' +
      '.sendly-ai-err{color:#c0392b;font-size:13px;margin:10px 0 0;display:none}' +
      '.sendly-ai-foot{display:flex;justify-content:flex-end;gap:8px;margin-top:16px;padding-top:14px;border-top:1px solid #e5e7eb}' +
      '.sendly-ai-btn{padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;border:1px solid #d1d5db;background:#fff;color:#374151}' +
      '.sendly-ai-btn.primary{background:' + BRAND + ';border-color:' + BRAND + ';color:#fff}' +
      '.sendly-ai-btn[disabled]{opacity:.6;cursor:default}';
    var style = document.createElement('style');
    style.id = 'sendly-ai-style';
    style.textContent = css;
    document.head.appendChild(style);
  }

  // ── Contexte de l'e-mail courant ────────────────────────────────────────
  function currentBody() {
    var candidates = ['textarea.builder-mjml', 'textarea.builder-html', '#emailform_customHtml'];
    for (var i = 0; i < candidates.length; i++) {
      var val = mQuery(candidates[i]).val();
      if (val && val.trim() !== '') {
        return val;
      }
    }
    return '';
  }

  function currentLang() {
    var $l = mQuery('#emailform_language');
    if (!$l.length) {
      return '';
    }
    var code = ($l.val() || '').toLowerCase();
    if (LANG_NAMES[code]) {
      return LANG_NAMES[code];
    }
    var label = mQuery('#emailform_language option:selected').text();
    return (label || code || '').trim();
  }

  // ── Appel endpoint ──────────────────────────────────────────────────────
  // Les paramètres (ton, emojis, consigne) sont mémorisés dans state.params au
  // « Générer » : à l'étape résultats leurs champs DOM n'existent plus, donc la
  // régénération d'une ligne doit les relire depuis l'état, pas depuis le DOM.
  function activeParams() {
    if (state.params) {
      return state.params;
    }
    return {
      tone: mQuery('#sendly-ai-tone').val() || '',
      emojis: mQuery('#sendly-ai-emojis').is(':checked'),
      instructions: mQuery('#sendly-ai-instructions').val() || ''
    };
  }

  function requestSubjects(count, cb) {
    var cfg = window.SendlyAiConfig;
    var p = activeParams();
    mQuery
      .ajax({
        url: cfg.endpoint,
        method: 'POST',
        contentType: 'application/json; charset=UTF-8',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        data: JSON.stringify({
          mode: 'subject',
          count: count,
          content: currentBody(),
          subject: (mQuery('#emailform_subject').val() || ''),
          tone: p.tone,
          emojis: p.emojis,
          instructions: p.instructions,
          lang: currentLang()
        })
      })
      .done(function (resp) {
        cb(resp && resp.suggestions && resp.suggestions.length ? resp.suggestions : null);
      })
      .fail(function () {
        cb(null);
      });
  }

  // ── Modale ──────────────────────────────────────────────────────────────
  var state = { suggestions: [], selected: 0, params: null };

  function buildModal() {
    if (document.getElementById('sendly-ai-overlay')) {
      return;
    }
    var overlay = document.createElement('div');
    overlay.id = 'sendly-ai-overlay';
    overlay.className = 'sendly-ai-overlay';
    overlay.style.display = 'none';
    overlay.innerHTML =
      '<div class="sendly-ai-panel" role="dialog" aria-modal="true">' +
      '<div class="sendly-ai-head"><i class="ri-sparkling-2-line"></i>' +
      '<p class="sendly-ai-title">' + t('mautic.email.ai.subject.title', "Suggérer un objet avec l'IA") + '</p></div>' +
      '<div class="sendly-ai-body" id="sendly-ai-content"></div>' +
      '</div>';
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) {
        closeModal();
      }
    });
  }

  function closeModal() {
    var o = document.getElementById('sendly-ai-overlay');
    if (o) {
      o.style.display = 'none';
    }
  }

  function esc(s) {
    return (s || '').replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function openModal() {
    if (!window.SendlyAiConfig || !window.SendlyAiConfig.enabled) {
      return;
    }
    ensureStyles();
    buildModal();
    document.getElementById('sendly-ai-overlay').style.display = 'flex';
    renderParams();
  }

  // Étape 1 — paramètres
  function renderParams() {
    var opts = '';
    for (var i = 0; i < TONES.length; i++) {
      opts += '<option value="' + TONES[i].v + '">' + esc(t(TONES[i].k, TONES[i].fb)) + '</option>';
    }
    document.getElementById('sendly-ai-content').innerHTML =
      '<label class="sendly-ai-lbl">' + t('mautic.email.ai.tone', 'Ton') + '</label>' +
      '<select id="sendly-ai-tone">' + opts + '</select>' +
      '<label class="sendly-ai-check"><input type="checkbox" id="sendly-ai-emojis"> ' +
      t('mautic.email.ai.emojis', 'Autoriser un emoji') + '</label>' +
      '<label class="sendly-ai-lbl">' + t('mautic.email.ai.instructions', 'Consigne (optionnel)') + '</label>' +
      '<textarea id="sendly-ai-instructions" rows="2" placeholder="' +
      esc(t('mautic.email.ai.instructions_ph', 'Ex. mettre en avant la livraison gratuite')) + '"></textarea>' +
      '<p class="sendly-ai-err" id="sendly-ai-err"></p>' +
      '<div class="sendly-ai-foot">' +
      '<button class="sendly-ai-btn" id="sendly-ai-cancel">' + t('mautic.email.ai.cancel', 'Annuler') + '</button>' +
      '<button class="sendly-ai-btn primary" id="sendly-ai-go">' + t('mautic.email.ai.generate', 'Générer') + '</button>' +
      '</div>';
    // Repré-remplir depuis l'état (« Modifier » conserve les choix).
    if (state.params) {
      mQuery('#sendly-ai-tone').val(state.params.tone);
      mQuery('#sendly-ai-emojis').prop('checked', !!state.params.emojis);
      mQuery('#sendly-ai-instructions').val(state.params.instructions || '');
    }
    document.getElementById('sendly-ai-cancel').onclick = closeModal;
    document.getElementById('sendly-ai-go').onclick = function () {
      var btn = this;
      btn.disabled = true;
      btn.textContent = t('mautic.email.ai.generating', 'Génération…');
      hideErr();
      // Fige les paramètres pour toute la session (génération + régénérations).
      state.params = {
        tone: mQuery('#sendly-ai-tone').val() || '',
        emojis: mQuery('#sendly-ai-emojis').is(':checked'),
        instructions: mQuery('#sendly-ai-instructions').val() || ''
      };
      requestSubjects(3, function (list) {
        if (!list) {
          btn.disabled = false;
          btn.textContent = t('mautic.email.ai.generate', 'Générer');
          showErr();
          return;
        }
        state.suggestions = list;
        state.selected = 0;
        renderResults();
      });
    };
  }

  // Étape 2 — propositions
  function renderResults() {
    var p = activeParams();
    var params = [
      '<span class="sendly-ai-chip">' + t('mautic.email.ai.tone', 'Ton') + ' : ' +
        esc(t(toneKey(p.tone), p.tone)) + '</span>',
      '<span class="sendly-ai-chip">' + t('mautic.email.ai.emojis_short', 'Emojis') + ' : ' +
        (p.emojis ? t('mautic.email.ai.yes', 'oui') : t('mautic.email.ai.no', 'non')) + '</span>'
    ].join('');

    var rows = '';
    for (var i = 0; i < state.suggestions.length; i++) {
      rows +=
        '<div class="sendly-ai-row' + (i === state.selected ? ' sel' : '') + '" data-i="' + i + '">' +
        '<input type="radio" name="sendly-ai-sub"' + (i === state.selected ? ' checked' : '') + '>' +
        '<span class="txt">' + esc(state.suggestions[i]) + '</span>' +
        '<button class="sendly-ai-regen" data-i="' + i + '" aria-label="' +
        esc(t('mautic.email.ai.regenerate', 'Régénérer')) + '"><i class="ri-refresh-line"></i></button>' +
        '</div>';
    }

    document.getElementById('sendly-ai-content').innerHTML =
      '<div class="sendly-ai-chips">' + params +
      '<button class="sendly-ai-btn" id="sendly-ai-back" style="margin-left:auto;padding:5px 10px">' +
      t('mautic.email.ai.modify', 'Modifier') + '</button></div>' +
      rows +
      '<p class="sendly-ai-err" id="sendly-ai-err"></p>' +
      '<div class="sendly-ai-foot">' +
      '<button class="sendly-ai-btn" id="sendly-ai-back2">' + t('mautic.email.ai.back', 'Retour') + '</button>' +
      '<button class="sendly-ai-btn primary" id="sendly-ai-use">' +
      t('mautic.email.ai.use', "Utiliser l'objet sélectionné") + '</button></div>';

    mQuery('.sendly-ai-row').on('click', function (e) {
      if (mQuery(e.target).closest('.sendly-ai-regen').length) {
        return;
      }
      selectRow(parseInt(this.getAttribute('data-i'), 10));
    });
    mQuery('.sendly-ai-regen').on('click', function (e) {
      e.stopPropagation();
      regenerateRow(parseInt(this.getAttribute('data-i'), 10), this);
    });
    document.getElementById('sendly-ai-back').onclick = renderParams;
    document.getElementById('sendly-ai-back2').onclick = renderParams;
    document.getElementById('sendly-ai-use').onclick = applySelected;
  }

  function toneKey(v) {
    for (var i = 0; i < TONES.length; i++) {
      if (TONES[i].v === v) {
        return TONES[i].k;
      }
    }
    return '';
  }

  function selectRow(i) {
    state.selected = i;
    mQuery('.sendly-ai-row').each(function () {
      var idx = parseInt(this.getAttribute('data-i'), 10);
      mQuery(this).toggleClass('sel', idx === i);
      mQuery(this).find('input[type=radio]').prop('checked', idx === i);
    });
  }

  function regenerateRow(i, btn) {
    var icon = btn.querySelector('i');
    var prev = icon.className;
    icon.className = 'ri-loader-4-line sendly-ai-spin';
    requestSubjects(1, function (list) {
      icon.className = prev;
      if (list && list[0]) {
        state.suggestions[i] = list[0];
        var row = mQuery('.sendly-ai-row[data-i=' + i + ']');
        row.find('.txt').text(list[0]);
        selectRow(i);
      } else {
        showErr();
      }
    });
  }

  function applySelected() {
    var chosen = state.suggestions[state.selected];
    if (chosen) {
      mQuery('#emailform_subject').val(chosen).trigger('blur');
    }
    closeModal();
  }

  function hideErr() {
    var e = document.getElementById('sendly-ai-err');
    if (e) {
      e.style.display = 'none';
    }
  }

  function showErr() {
    var e = document.getElementById('sendly-ai-err');
    if (e) {
      e.textContent = t('mautic.email.ai.error', "La requête IA a échoué. Réessayez.");
      e.style.display = 'block';
    }
  }

  // ── Injection du bouton d'entrée ────────────────────────────────────────
  function injectButton() {
    if (!window.SendlyAiConfig || !window.SendlyAiConfig.enabled) {
      return;
    }
    var $subject = mQuery('#emailform_subject');
    if (!$subject.length || document.getElementById('sendly-ai-subject-btn')) {
      return;
    }
    ensureStyles();
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.id = 'sendly-ai-subject-btn';
    btn.innerHTML = '<i class="ri-sparkling-2-line"></i> ' +
      t('mautic.email.ai.subject.title', "Suggérer un objet avec l'IA");
    btn.onclick = openModal;
    $subject.after(btn);
  }

  // Hook de chargement de la page e-mail (email.js appelle Mautic.emailOnLoad,
  // mais on se branche aussi au DOM ready pour les rechargements ajax).
  mQuery(function () {
    injectButton();
  });
  var _origEmailOnLoad = Mautic.emailOnLoad;
  Mautic.emailOnLoad = function (container, response) {
    if (typeof _origEmailOnLoad === 'function') {
      _origEmailOnLoad(container, response);
    }
    injectButton();
  };
})();
