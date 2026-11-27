/**
 * Sendly Copilot, objets d'e-mail : une BULLE ancrée au champ Objet.
 *
 * Refonte du 26/11/2026 (direction « Pastille ») : plus de fenêtre qui cache
 * l'e-mail. Un clic sur « Proposer des objets » ouvre une bulle à côté du
 * champ ; les cinq propositions arrivent TOUT DE SUITE (ton par défaut), un
 * clic sur une proposition l'écrit dans le champ. Deux clics au lieu de
 * quatre. Les réglages viennent ensuite, sans quitter la bulle : le ton,
 * l'émoji, une consigne, régénérer une proposition. « Tester aussi » retient
 * d'autres objets comme variantes A/B natives de l'e-mail enregistré.
 *
 * Auto-agrégé dans app.js (présent sur toutes les pages admin) mais INERTE
 * tant que window.SendlyAiConfig n'est pas défini — ce drapeau n'est injecté
 * (AiConfigAssetsSubscriber) que si une clé est configurée. La clé n'est
 * jamais ici : tout passe par l'endpoint serveur /s/ai/generate.
 */
(function () {
  'use strict';

  function t(key, fallback) {
    var s = Mautic.translate(key);
    return !s || s === key ? fallback : s;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
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

  /** Le plafond natif : un objet principal et quatre variantes. */
  var VARIANTES_MAX = 4;
  var CHEMIN_ASSETS = (window.mauticBasePath || '') + '/plugins/EwebAiBundle/Assets';

  // Les dessins viennent de la coquille (ai-assistant.js), chargée avant.
  function eclat(taille) {
    var d = window.SendlyAssistant && window.SendlyAssistant.dessin;
    return d ? d.eclat(taille) : '';
  }
  function trace(nom, taille, epaisseur) {
    var d = window.SendlyAssistant && window.SendlyAssistant.dessin;
    return d ? d.trace(nom, taille, epaisseur) : '';
  }
  var REFAIRE = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>';

  function ensureStyles() {
    if (document.getElementById('sendly-ai-style')) {
      return;
    }
    var B = '#sendly-ai-bulle';
    var NUIT = '#001248', GLACE = '#DFEAFF', ENCRE = '#000617', PERLE = '#F5F7F9', GRIS = '#5B6478';
    var COURBE = 'cubic-bezier(.22,.9,.3,1)';
    var VERRE = 'linear-gradient(120deg,rgba(255,255,255,.10),rgba(255,255,255,.03) 40%,rgba(255,255,255,.08))';
    var PLEIN = 'linear-gradient(180deg,#2E6BFF,#004FFF 70%,#0043E0)';
    var POLICE = '"Helvena","Helvetica Neue",-apple-system,"Segoe UI",sans-serif';
    var css = [
      '@font-face{font-family:"Helvena";font-weight:400;font-display:swap;src:url("' + CHEMIN_ASSETS + '/fonts/Helvena_Regular.woff2") format("woff2")}',
      '@font-face{font-family:"Helvena";font-weight:500;font-display:swap;src:url("' + CHEMIN_ASSETS + '/fonts/Helvena_Medium.woff2") format("woff2")}',
      '@keyframes sendly-ai-luit{to{background-position:-200% 0}}',
      '@keyframes sendly-ai-tourne{to{transform:rotate(360deg)}}',

      // Le bouton d'entrée, sous le champ Objet : une pastille de la marque.
      '#sendly-ai-subject-btn{margin-top:8px;width:100%;min-height:34px;box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;gap:8px;' +
        'padding:0 16px;border:0;border-radius:999px;color:#fff;cursor:pointer;font:500 13.5px/1 ' + POLICE + ';letter-spacing:-.005em;text-transform:none;' +
        'background:' + VERRE + ',' + PLEIN + ';box-shadow:0 4px 12px rgba(0,79,255,.22),inset 0 1px 0 rgba(255,255,255,.3);' +
        'transition:transform .16s ' + COURBE + ',box-shadow .16s ease}',
      '#sendly-ai-subject-btn:hover{transform:translateY(-1px);box-shadow:0 8px 18px rgba(0,79,255,.28),inset 0 1px 0 rgba(255,255,255,.3)}',
      '#sendly-ai-subject-btn:active{transform:none}',
      '#sendly-ai-subject-btn:focus-visible{outline:3px solid rgba(0,79,255,.45);outline-offset:2px}',
      '#sendly-ai-subject-btn svg{flex:none;display:block}',

      // La bulle.
      '#sendly-ai-voile{display:none}',
      B + '{position:fixed;z-index:1045;width:452px;max-width:calc(100vw - 32px);max-height:calc(100vh - 32px);overflow-y:auto;background:#fff;border-radius:24px;' +
        'box-shadow:0 24px 60px rgba(0,6,23,.24),0 0 0 1px rgba(0,18,72,.07);transform-origin:100% 0;transition:transform .26s ' + COURBE + ',opacity .16s ease;' +
        'font-family:' + POLICE + ';font-size:16px;font-weight:400;font-style:normal;line-height:normal;letter-spacing:-.005em;text-align:left;text-transform:none;' +
        'color:' + ENCRE + ';-webkit-font-smoothing:antialiased}',
      B + '.sendly-ai-fermee{transform:translateY(-8px) scale(.96);opacity:0;pointer-events:none}',
      B + ' *,' + B + ' *::before,' + B + ' *::after{box-sizing:border-box}',
      B + ' button{font:inherit;letter-spacing:inherit;text-transform:none;cursor:pointer;margin:0}',
      B + ' p{margin:0;padding:0;font:inherit}',
      B + ' [hidden]{display:none!important}',
      B + ' svg{flex:none;display:block}',
      B + ' :focus-visible{outline:3px solid rgba(0,79,255,.45);outline-offset:2px}',
      B + ' .sendly-ai-tete{display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:16px 14px 12px 18px}',
      B + ' .sendly-ai-marque{display:inline-flex;align-items:center;gap:8px;border-radius:999px;padding:6px 13px;font-size:12.5px;font-weight:500;color:#fff;white-space:nowrap;' +
        'background:' + VERRE + ',' + PLEIN + ';box-shadow:0 4px 10px rgba(0,6,23,.16),inset 0 1px 0 rgba(255,255,255,.18)}',
      B + ' .sendly-ai-ton{border:1px solid #D5DCED;background:#fff;color:' + NUIT + ';border-radius:999px;padding:6px 12px;font-size:13px;line-height:1.2;white-space:nowrap}',
      B + ' .sendly-ai-ton:hover{border-color:#004FFF;color:#004FFF}',
      B + ' .sendly-ai-ton.sendly-ai-choisi{background:' + NUIT + ';border-color:' + NUIT + ';color:#fff}',
      B + ' .sendly-ai-ferme{margin-left:auto;flex:none;width:32px;height:32px;padding:0;border:0;border-radius:50%;background:' + PERLE + ';color:' + NUIT + ';' +
        'display:inline-flex;align-items:center;justify-content:center}',
      B + ' .sendly-ai-ferme:hover{background:#EAF0FA}',
      B + ' .sendly-ai-objets{display:grid;gap:8px;padding:0 14px 6px}',
      B + ' .sendly-ai-objet{display:flex;align-items:center;gap:12px;width:100%;text-align:left;border:1.5px solid transparent;border-radius:12px;background:' + PERLE + ';' +
        'padding:10px 8px 10px 14px;min-height:52px;font-size:14.5px;line-height:1.3;color:' + ENCRE + ';cursor:pointer}',
      B + ' .sendly-ai-objet:hover{background:#EAF0FA}',
      B + ' .sendly-ai-objet.sendly-ai-pose{border-color:#004FFF;background:#fff}',
      B + ' .sendly-ai-radio{flex:none;width:18px;height:18px;border-radius:50%;box-shadow:inset 0 0 0 1.5px #B6C0D4;color:#fff;display:inline-flex;align-items:center;justify-content:center}',
      B + ' .sendly-ai-radio svg{opacity:0}',
      B + ' .sendly-ai-pose .sendly-ai-radio{background:#004FFF;box-shadow:none}',
      B + ' .sendly-ai-pose .sendly-ai-radio svg{opacity:1}',
      B + ' .sendly-ai-t{flex:1;min-width:0;overflow-wrap:anywhere}',
      B + ' .sendly-ai-ab{flex:none;border:1px solid #D5DCED;border-radius:999px;background:#fff;color:' + NUIT + ';font-size:12.5px;font-weight:500;padding:5px 11px;white-space:nowrap}',
      B + ' .sendly-ai-ab:hover{border-color:#004FFF}',
      B + ' .sendly-ai-ab.sendly-ai-oui{background:' + GLACE + ';border-color:' + GLACE + '}',
      B + ' .sendly-ai-regen{flex:none;width:32px;height:32px;padding:0;border:0;border-radius:50%;background:none;color:' + GRIS + ';display:inline-flex;align-items:center;justify-content:center}',
      B + ' .sendly-ai-regen:hover{background:#fff;color:#004FFF}',
      B + ' .sendly-ai-regen.sendly-ai-tourne svg{animation:sendly-ai-tourne .9s linear infinite}',
      B + ' .sendly-ai-os{pointer-events:none}',
      B + ' .sendly-ai-os .sendly-ai-t{height:10px;border-radius:5px;background:linear-gradient(90deg,#E3E8F0 30%,#F1F4F8 50%,#E3E8F0 70%);background-size:200% 100%;' +
        'animation:sendly-ai-luit 1.2s linear infinite}',
      B + ' .sendly-ai-consigne{display:flex;gap:8px;padding:6px 14px 0}',
      B + ' .sendly-ai-consigne input{flex:1;min-width:0;height:40px;margin:0;border:1px solid transparent;border-radius:999px;background:' + PERLE + ';padding:0 16px;' +
        'font:400 14px ' + POLICE + ';color:' + ENCRE + ';outline:none;box-shadow:none}',
      B + ' .sendly-ai-consigne input:focus{border-color:#004FFF;background:#fff}',
      B + ' .sendly-ai-consigne input::placeholder{color:' + GRIS + ';opacity:1}',
      B + ' .sendly-ai-pied{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;padding:12px 14px 16px 18px}',
      B + ' .sendly-ai-mot{flex:1;min-width:150px;font-size:13px;line-height:1.35;color:' + GRIS + '}',
      B + ' .sendly-ai-mot.sendly-ai-err{color:#B42318}',
      B + ' .sendly-ai-deux .sendly-ai-mot{flex-basis:100%}',
      B + ' .sendly-ai-deux .sendly-ai-lien{margin-right:auto}',
      B + ' .sendly-ai-objet{padding-right:14px}',
      B + ' .sendly-ai-objet:has(.sendly-ai-regen){padding-right:8px}',
      B + ' .sendly-ai-lien{border:0;background:none;padding:6px 0;font-size:13px;font-weight:500;color:#004FFF}',
      B + ' .sendly-ai-lien:hover{text-decoration:underline}',
      B + ' .sendly-ai-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border-radius:999px;min-height:42px;padding:0 18px;font-size:14.5px;font-weight:500;' +
        'line-height:1;white-space:nowrap;background:#fff;border:1px solid #D5DCED;color:' + NUIT + '}',
      B + ' .sendly-ai-btn:hover{border-color:#004FFF;color:#004FFF}',
      B + ' .sendly-ai-btn.primary{border:0;color:#fff;background-color:#004FFF;' +
        'background-image:linear-gradient(120deg,rgba(255,255,255,.14),rgba(255,255,255,0) 45%),linear-gradient(180deg,#2E6BFF,#004FFF 60%,#0043E0);' +
        'box-shadow:0 4px 14px rgba(0,79,255,.20),inset 0 1px 0 rgba(255,255,255,.35)}',
      B + ' .sendly-ai-btn.primary:hover{color:#fff}',
      B + ' .sendly-ai-btn[disabled]{opacity:.6;pointer-events:none}',

      // Téléphone : la bulle devient une feuille du bas, cibles de 44 px.
      '@media (max-width:600px){' +
        '#sendly-ai-voile{display:block;position:fixed;inset:0;z-index:1044;background:rgba(0,6,23,.45)}' +
        B + '{left:0!important;right:0!important;top:auto!important;bottom:0;width:auto;max-width:none;max-height:88vh;border-radius:24px 24px 0 0;transform-origin:50% 100%}' +
        B + '.sendly-ai-fermee{transform:translateY(100%);opacity:1}' +
        B + ' .sendly-ai-ferme,' + B + ' .sendly-ai-regen{width:44px;height:44px}' +
        // La marque se réduit à son étincelle : le ton, l'émoji et la croix
        // tiennent sur UNE ligne (jamais la croix seule à la ligne).
        B + ' .sendly-ai-marque{padding:0;width:44px;height:44px;justify-content:center}' +
        B + ' .sendly-ai-marque span{display:none}' +
        B + ' .sendly-ai-tete{flex-wrap:nowrap}' +
        B + ' .sendly-ai-ton,' + B + ' .sendly-ai-ab,' + B + ' .sendly-ai-lien{min-height:44px}' +
        B + ' .sendly-ai-btn{min-height:44px}' +
        B + ' .sendly-ai-consigne input{height:44px;font-size:16px}' +
        B + ' .sendly-ai-pied{padding-bottom:22px}' +
        '#sendly-ai-subject-btn{min-height:44px}' +
      '}',
      '@media (prefers-reduced-motion:reduce){' + B + ',' + B + ' *,#sendly-ai-subject-btn{animation-duration:.01s!important;animation-iteration-count:1!important;transition-duration:.01s!important}}'
    ].join('');
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

  /** La langue de l'interface (« French »), repli quand rien n'est choisi. */
  function langueInterface() {
    var code = (document.documentElement.lang || 'fr').toLowerCase().slice(0, 2);
    return LANG_NAMES[code] || 'French';
  }

  /**
   * La langue CHOISIE pour l'e-mail, vide si personne ne l'a choisie.
   *
   * Sur un nouvel e-mail, le champ « Langue » porte la langue par défaut de
   * l'espace, qui peut différer de l'interface : constat du 27/11 sur
   * eweb-agency, « anglais » sur une interface en français, et des objets
   * proposés en anglais. Tant que l'e-mail n'est pas enregistré et que le
   * champ garde sa valeur d'arrivée, on ne la tient donc pas pour un choix.
   */
  function currentLang() {
    var $l = mQuery('#emailform_language');
    if (!$l.length) {
      return '';
    }
    var code = ($l.val() || '').toLowerCase();
    if (!currentEmailId() && code === String($l.attr('data-sendly-langue-arrivee') || '')) {
      return '';
    }
    if (LANG_NAMES[code]) {
      return LANG_NAMES[code];
    }
    var label = mQuery('#emailform_language option:selected').text();
    return (label || code || '').trim();
  }

  /** L'id de l'e-mail en édition, 0 sur un e-mail pas encore enregistré. */
  function currentEmailId() {
    var action = mQuery('form[name="emailform"]').attr('action') || '';
    var m = action.match(/\/emails\/edit\/(\d+)/);
    return m ? parseInt(m[1], 10) : 0;
  }

  // ── État de la bulle ────────────────────────────────────────────────────
  // suggestions : les objets proposés ; pose : l'index écrit dans le champ
  // (null tant qu'aucun) ; ab : les index retenus comme variantes ; params :
  // les réglages, relus à chaque génération (jamais depuis le DOM).
  var state = {
    suggestions: [], pose: null, ab: [], charge: false, erreur: '', fait: '',
    consigneOuverte: false,
    params: { tone: TONES[0].v, emojis: false, instructions: '' }
  };
  var jeton = 0;

  // ── Appel endpoint ──────────────────────────────────────────────────────
  function requestSubjects(count, cb) {
    var cfg = window.SendlyAiConfig;
    var p = state.params;
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
          lang: currentLang(),
          uiLang: langueInterface()
        })
      })
      .done(function (resp) {
        cb(resp && resp.suggestions && resp.suggestions.length ? resp.suggestions : null);
      })
      .fail(function () {
        cb(null);
      });
  }

  function bulle() {
    return document.getElementById('sendly-ai-bulle');
  }

  function toneLabel(v) {
    for (var i = 0; i < TONES.length; i++) {
      if (TONES[i].v === v) {
        return t(TONES[i].k, TONES[i].fb);
      }
    }
    return v;
  }

  /** La bulle se pose à gauche du champ quand la place existe (elle ne
   *  couvre alors ni le champ ni le bouton), sinon juste dessous. */
  function placer() {
    var b = bulle();
    var champ = document.getElementById('emailform_subject');
    if (!b || !champ) { return; }
    var r = champ.getBoundingClientRect();
    var vw = document.documentElement.clientWidth, vh = window.innerHeight;
    var largeur = Math.min(452, vw - 32);
    var gauche, haut;
    if (r.left - largeur - 14 >= 16) {
      gauche = r.left - largeur - 14;
      haut = r.top - 48;
      b.style.transformOrigin = '100% 0';
    } else {
      gauche = Math.min(Math.max(16, r.left), vw - largeur - 16);
      var bouton = document.getElementById('sendly-ai-subject-btn');
      haut = (bouton ? bouton.getBoundingClientRect().bottom : r.bottom) + 8;
      b.style.transformOrigin = '50% 0';
    }
    haut = Math.max(16, Math.min(haut, vh - b.offsetHeight - 16));
    b.style.left = Math.round(gauche) + 'px';
    b.style.top = Math.round(haut) + 'px';
  }

  function fermer() {
    var b = bulle();
    if (!b) { return; }
    jeton++;
    b.classList.add('sendly-ai-fermee');
    b.id = '';
    var voile = document.getElementById('sendly-ai-voile');
    if (voile) { voile.parentNode.removeChild(voile); }
    setTimeout(function () { if (b.parentNode) { b.parentNode.removeChild(b); } }, 260);
    window.removeEventListener('resize', placer);
    document.removeEventListener('scroll', placer, true);
    document.removeEventListener('mousedown', dehors, true);
    var bouton = document.getElementById('sendly-ai-subject-btn');
    if (bouton) { bouton.setAttribute('aria-expanded', 'false'); bouton.focus(); }
  }

  function dehors(e) {
    var b = bulle();
    var bouton = document.getElementById('sendly-ai-subject-btn');
    if (!b || b.contains(e.target) || (bouton && bouton.contains(e.target))) { return; }
    // Un clic dans la page ferme la bulle : l'objet posé reste dans le champ.
    fermer();
  }

  function rendre() {
    var b = bulle();
    if (!b) { return; }
    var p = state.params;
    var n = state.ab.length;
    var html = '<div class="sendly-ai-tete"><span class="sendly-ai-marque" title="Sendly Copilot">' + eclat(13) + '<span>Sendly Copilot</span></span>' +
      '<button type="button" class="sendly-ai-ton sendly-ai-choisi" data-geste="ton" title="' + esc(t('mautic.email.ai.tone.change', 'Changer de ton')) + '">' +
      esc(t('mautic.email.ai.tone', 'Ton')) + ' : ' + esc(toneLabel(p.tone).toLowerCase()) + '</button>' +
      '<button type="button" class="sendly-ai-ton' + (p.emojis ? ' sendly-ai-choisi' : '') + '" data-geste="emoji" aria-pressed="' + (p.emojis ? 'true' : 'false') + '">' +
      esc(t('mautic.email.ai.emoji', 'Émoji')) + '</button>' +
      '<button type="button" class="sendly-ai-ferme" data-geste="fermer" aria-label="' + esc(t('mautic.core.form.close', 'Fermer')) + '">' + trace('croix', 14, 2.4) + '</button></div>';

    if (state.charge) {
      html += '<div class="sendly-ai-objets" role="status" aria-label="' + esc(t('mautic.email.ai.writing', 'Cinq objets en cours d’écriture…')) + '">' +
        [78, 64, 86, 58, 72].map(function (w) {
          return '<div class="sendly-ai-objet sendly-ai-os"><span class="sendly-ai-radio"></span><span class="sendly-ai-t" style="max-width:' + w + '%"></span></div>';
        }).join('') + '</div>' +
        '<div class="sendly-ai-pied"><span class="sendly-ai-mot">' + esc(t('mautic.email.ai.writing', 'Cinq objets en cours d’écriture…')) + '</span></div>';
      b.innerHTML = html;
      placer();
      return;
    }

    html += '<div class="sendly-ai-objets">' + state.suggestions.map(function (objet, i) {
      var pose = state.pose === i;
      var variante = state.ab.indexOf(i) !== -1;
      return '<div class="sendly-ai-objet' + (pose ? ' sendly-ai-pose' : '') + '" role="button" tabindex="0" data-objet="' + i + '"' +
        ' aria-pressed="' + (pose ? 'true' : 'false') + '">' +
        '<span class="sendly-ai-radio">' + trace('coche', 10, 3) + '</span><span class="sendly-ai-t">' + esc(objet) + '</span>' +
        (state.pose !== null && !pose
          ? '<button type="button" class="sendly-ai-ab' + (variante ? ' sendly-ai-oui' : '') + '" data-ab="' + i + '" aria-pressed="' + (variante ? 'true' : 'false') + '">' +
            esc(variante ? t('mautic.email.ai.ab_on', 'Variante A/B') : t('mautic.email.ai.ab_also', 'Tester aussi')) + '</button>'
          : '') +
        // Régénérer : sur chaque ligne avant le choix, puis sur la seule ligne
        // posée (la place revient au texte de l'objet et à « Tester aussi »).
        (state.pose === null || pose
          ? '<button type="button" class="sendly-ai-regen" data-regen="' + i + '" aria-label="' + esc(t('mautic.email.ai.regenerate', 'Régénérer')) + '" title="' +
            esc(t('mautic.email.ai.regenerate', 'Régénérer')) + '">' + REFAIRE + '</button>'
          : '') + '</div>';
    }).join('') + '</div>';

    html += state.consigneOuverte
      ? '<div class="sendly-ai-consigne"><input type="text" id="sendly-ai-instructions" maxlength="200" aria-label="' + esc(t('mautic.email.ai.instructions', 'Consigne (optionnel)')) +
        '" placeholder="' + esc(t('mautic.email.ai.instructions_ph', 'Ex. mettre en avant la livraison gratuite')) + '" value="' + esc(p.instructions) + '">' +
        '<button type="button" class="sendly-ai-btn" data-geste="consigne">' + esc(t('mautic.email.ai.rewrite', 'Réécrire')) + '</button></div>'
      : '';

    // Le pied : ce qui vient d'être fait, et la suite possible.
    var mot, action = '';
    if (state.erreur) {
      mot = '<span class="sendly-ai-mot sendly-ai-err" role="alert">' + esc(state.erreur) + '</span>';
    } else if (n && !currentEmailId()) {
      // Le test A/B exige un e-mail ENREGISTRÉ (les variantes sont des clones
      // persistés du parent).
      mot = '<span class="sendly-ai-mot">' + esc(t('mautic.email.ai.ab_save_short', 'Enregistrez l’e-mail pour tester en A/B.')) + '</span>';
    } else if (n) {
      mot = '<span class="sendly-ai-mot">' + esc(t('mautic.email.ai.ab_note', 'Les objets retenus deviennent des variantes A/B.')) + '</span>';
      action = '<button type="button" class="sendly-ai-btn primary" data-geste="ab">' + esc(t('mautic.email.ai.ab_create', 'Créer le test A/B')) + ' (' + n + ')</button>';
    } else if (state.pose === null) {
      mot = '<span class="sendly-ai-mot">' + esc(state.fait || t('mautic.email.ai.pick', 'Cliquez un objet : il s’écrit dans le champ.')) + '</span>';
    } else {
      mot = '<span class="sendly-ai-mot">' + esc(t('mautic.email.ai.picked', 'Objet écrit dans le champ. Vous pouvez en tester d’autres.')) + '</span>';
      action = '<button type="button" class="sendly-ai-btn" data-geste="fermer">' + esc(t('mautic.email.ai.done', 'Terminé')) + '</button>';
    }
    // Avec un bouton, le mot prend sa ligne et le lien fait face au bouton :
    // jamais un élément seul sur sa ligne.
    html += '<div class="sendly-ai-pied' + (action ? ' sendly-ai-deux' : '') + '">' + mot +
      (state.consigneOuverte ? '' : '<button type="button" class="sendly-ai-lien" data-geste="ouvrir-consigne">' + esc(t('mautic.email.ai.instructions_add', 'Ajouter une consigne')) + '</button>') +
      action + '</div>';
    b.innerHTML = html;
    placer();
  }

  /** Cinq propositions avec les réglages courants. */
  function generer() {
    var mien = ++jeton;
    state.charge = true;
    state.erreur = '';
    state.fait = '';
    rendre();
    requestSubjects(5, function (list) {
      if (mien !== jeton) { return; }
      state.charge = false;
      if (!list) {
        state.erreur = t('mautic.email.ai.error', 'Sendly Copilot n’a pas pu répondre. Réessayez dans un instant.');
      } else {
        state.suggestions = list;
        state.pose = null;
        state.ab = [];
      }
      rendre();
    });
  }

  /** Un clic écrit l'objet dans le champ : le geste historique, inchangé. */
  function poserObjet(i) {
    var objet = state.suggestions[i];
    if (!objet) { return; }
    state.pose = i;
    state.ab = state.ab.filter(function (x) { return x !== i; });
    state.erreur = '';
    var $champ = mQuery('#emailform_subject');
    $champ.val(objet).trigger('change').trigger('blur');
    if (window.SendlyAssistant && typeof window.SendlyAssistant.allumer === 'function') {
      window.SendlyAssistant.allumer($champ.get(0), 'objets', '');
    }
    rendre();
  }

  function basculerVariante(i) {
    var pos = state.ab.indexOf(i);
    if (pos > -1) {
      state.ab.splice(pos, 1);
    } else if (state.ab.length < VARIANTES_MAX) {
      state.ab.push(i);
    }
    state.erreur = '';
    rendre();
  }

  function regenerer(i, bouton) {
    bouton.classList.add('sendly-ai-tourne');
    bouton.disabled = true;
    var mien = jeton;
    requestSubjects(1, function (list) {
      if (mien !== jeton) { return; }
      if (list && list[0]) {
        state.suggestions[i] = list[0];
        // L'objet posé a changé de texte : le champ suit.
        if (state.pose === i) { mQuery('#emailform_subject').val(list[0]).trigger('change').trigger('blur'); }
        state.erreur = '';
      } else {
        state.erreur = t('mautic.email.ai.error', 'Sendly Copilot n’a pas pu répondre. Réessayez dans un instant.');
      }
      rendre();
    });
  }

  function creerTestAB(bouton) {
    var emailId = currentEmailId();
    var variants = state.ab.map(function (i) { return state.suggestions[i]; }).filter(function (s) { return !!s; });
    if (!variants.length || !emailId || !window.SendlyAiConfig.abtestEndpoint) {
      return;
    }
    bouton.disabled = true;
    bouton.textContent = t('mautic.email.ai.ab_creating', 'Création du test A/B…');

    // POST via mQuery : le jeton CSRF global est ajouté automatiquement.
    mQuery.ajax({
      url: window.SendlyAiConfig.abtestEndpoint,
      type: 'POST',
      dataType: 'json',
      data: { emailId: emailId, subjects: variants },
      success: function (res) {
        var n = res && res.created ? res.created.length : 0;
        var texte = t('mautic.email.ai.ab_created', 'Test A/B créé : ')
          + n + ' ' + (n > 1
            ? t('mautic.email.ai.ab_variants', 'variantes d’objet.')
            : t('mautic.email.ai.ab_variant', 'variante d’objet.'))
          + (res && false === res.published
            ? ' ' + t('mautic.email.ai.ab_unpublished', 'Variantes créées en brouillon : leur publication demande un droit que votre compte n’a pas.')
            : '');
        // `Mautic.flashMessage` n'existe pas dans le moteur : l'API réelle
        // est addInfoFlashMessage + setFlashes (constat du 12/11).
        if (typeof Mautic.addInfoFlashMessage === 'function' && typeof Mautic.setFlashes === 'function') {
          Mautic.setFlashes(Mautic.addInfoFlashMessage(texte));
        }
        if (n && window.SendlyAssistant) { window.SendlyAssistant.credit('create_ab_test', 1); }
        fermer();
      },
      error: function () {
        state.erreur = t('mautic.email.ai.error', 'Sendly Copilot n’a pas pu répondre. Réessayez dans un instant.');
        rendre();
      }
    });
  }

  function surClic(e) {
    var cible = e.target;
    var regen = cible.closest('[data-regen]');
    if (regen) { regenerer(parseInt(regen.getAttribute('data-regen'), 10), regen); return; }
    var ab = cible.closest('[data-ab]');
    if (ab) { basculerVariante(parseInt(ab.getAttribute('data-ab'), 10)); return; }
    var objet = cible.closest('[data-objet]');
    if (objet) { poserObjet(parseInt(objet.getAttribute('data-objet'), 10)); return; }
    var geste = cible.closest('[data-geste]');
    if (!geste) { return; }
    var quoi = geste.getAttribute('data-geste');
    if ('fermer' === quoi) { fermer(); }
    if ('ton' === quoi) {
      // Le ton suivant de la liste, et cinq nouvelles propositions.
      var i = 0;
      for (var k = 0; k < TONES.length; k++) { if (TONES[k].v === state.params.tone) { i = k; } }
      state.params.tone = TONES[(i + 1) % TONES.length].v;
      generer();
    }
    if ('emoji' === quoi) { state.params.emojis = !state.params.emojis; generer(); }
    if ('ouvrir-consigne' === quoi) {
      state.consigneOuverte = true;
      rendre();
      var champ = document.getElementById('sendly-ai-instructions');
      if (champ) { champ.focus(); }
    }
    if ('consigne' === quoi) {
      state.params.instructions = (document.getElementById('sendly-ai-instructions') || {}).value || '';
      generer();
    }
    if ('ab' === quoi) { creerTestAB(geste); }
  }

  function ouvrir() {
    if (!window.SendlyAiConfig) {
      return;
    }
    // Teaser (plan gratuit) : le bouton existe, l'action propose le plan.
    if (window.SendlyAiConfig.teaser) {
      if (window.SendlyAiUpsell) { window.SendlyAiUpsell.ouvrir('email'); }
      return;
    }
    if (!window.SendlyAiConfig.enabled) {
      return;
    }
    if (bulle()) {
      fermer();
      return;
    }
    ensureStyles();
    var voile = document.createElement('div');
    voile.id = 'sendly-ai-voile';
    voile.addEventListener('click', fermer);
    var b = document.createElement('section');
    b.id = 'sendly-ai-bulle';
    b.className = 'sendly-ai-fermee';
    b.setAttribute('role', 'dialog');
    b.setAttribute('aria-label', t('mautic.email.ai.subject.dialog', 'Objets proposés par Sendly Copilot'));
    document.body.appendChild(voile);
    document.body.appendChild(b);
    b.addEventListener('click', surClic);
    b.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.stopPropagation(); fermer(); return; }
      if ((e.key === 'Enter' || e.key === ' ') && e.target.getAttribute && e.target.getAttribute('data-objet') !== null) {
        e.preventDefault();
        poserObjet(parseInt(e.target.getAttribute('data-objet'), 10));
      }
      if (e.key === 'Enter' && e.target.id === 'sendly-ai-instructions') {
        e.preventDefault();
        state.params.instructions = e.target.value || '';
        generer();
      }
    });
    window.addEventListener('resize', placer);
    document.addEventListener('scroll', placer, true);
    document.addEventListener('mousedown', dehors, true);
    var bouton = document.getElementById('sendly-ai-subject-btn');
    if (bouton) { bouton.setAttribute('aria-expanded', 'true'); }
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { b.classList.remove('sendly-ai-fermee'); });
    });
    // Les propositions d'abord : la génération part à l'ouverture.
    state.consigneOuverte = !!state.params.instructions;
    generer();
  }

  // ── Injection du bouton d'entrée ────────────────────────────────────────
  function injectButton() {
    if (!window.SendlyAiConfig || (!window.SendlyAiConfig.enabled && !window.SendlyAiConfig.teaser)) {
      return;
    }
    var $subject = mQuery('#emailform_subject');
    if (!$subject.length || document.getElementById('sendly-ai-subject-btn')) {
      return;
    }
    // La langue d'arrivée du formulaire : la distinguer d'un vrai choix.
    var $langue = mQuery('#emailform_language');
    if ($langue.length && !$langue.is('[data-sendly-langue-arrivee]')) {
      $langue.attr('data-sendly-langue-arrivee', ($langue.val() || '').toLowerCase());
    }
    ensureStyles();
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.id = 'sendly-ai-subject-btn';
    btn.setAttribute('aria-haspopup', 'dialog');
    btn.setAttribute('aria-expanded', 'false');
    btn.innerHTML = eclat(14) + '<span>' + esc(t('mautic.email.ai.subject.button', 'Proposer des objets')) + '</span>';
    btn.onclick = ouvrir;
    $subject.after(btn);
  }

  // Lecture seule : la langue que la bulle enverrait (tests et diagnostic).
  window.SendlyAiObjets = {
    langues: function () { return { lang: currentLang(), uiLang: langueInterface() }; }
  };

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
    // Une bulle restée ouverte sur l'écran précédent ne survit pas à la navigation.
    if (bulle() && !document.getElementById('emailform_subject')) { fermer(); }
    injectButton();
  };
})();
