/**
 * Assistant de segmentation — « Décris ta cible, je propose les critères ».
 *
 * Bouton sous le sélecteur de critères de l'écran de segment. L'utilisateur
 * écrit son audience en français ; le serveur propose des critères VALIDÉS,
 * accompagnés du nombre de contacts correspondant, et de ce qui n'a pas pu
 * être traduit. Rien n'est appliqué sans un clic explicite.
 *
 * ⚠️ DEUX PIÈGES À NE PAS « SIMPLIFIER ».
 *
 * 1. mQuery.ajax, JAMAIS fetch(). Mautic intercepte tout POST XHR vers /s/ qui
 *    n'a pas de jeton CSRF valide (RequestSubscriber) et répond 200 avec un
 *    corps de flashes — le contrôleur n'est jamais atteint, et le symptôme
 *    ressemble à un bug serveur. mQuery.ajax en POST reçoit le jeton
 *    automatiquement ; fetch() ne le reçoit pas. C'est exactement le défaut qui
 *    avait cassé le bouton de l'éditeur d'e-mail.
 *
 * 2. On n'écrit PAS les lignes de filtre à la main. On appelle le point
 *    d'entrée natif Mautic.addLeadListFilter(), puis on ajuste opérateur et
 *    valeur. Le DOM produit est donc identique, au caractère près, à celui
 *    d'une saisie manuelle : mêmes identifiants, mêmes noms de champs, mêmes
 *    événements attachés, mêmes sélecteurs « chosen » et sélecteurs de date.
 *    Reconstruire ce balisage à la main marcherait aujourd'hui et casserait à
 *    la première montée de version.
 *
 * Comme le reste de la surface IA, ce fichier est auto-agrégé dans app.js mais
 * reste INERTE tant que window.SendlyAiConfig n'est pas injecté (clé absente).
 */
(function () {
  'use strict';

  var BRAND = '#004FFF';

  /** Filet de sécurité : si le formulaire de propriétés ne revient jamais
   *  (erreur réseau, session expirée), l'application ne doit pas rester figée. */
  var LOAD_TIMEOUT_MS = 15000;

  function t(key, fallback) {
    var s = Mautic.translate(key);
    return !s || s === key ? fallback : s;
  }

  function cfg() {
    return window.SendlyAiConfig;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function ensureStyles() {
    if (document.getElementById('sendly-ai-seg-style')) {
      return;
    }
    var css =
      '@keyframes sendly-seg-spin{to{transform:rotate(360deg)}}' +
      '.sendly-seg-spin{display:inline-block;animation:sendly-seg-spin .8s linear infinite}' +
      '#sendly-seg-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;' +
      'border:1px solid ' + BRAND + ';border-radius:6px;background:rgba(0,79,255,.06);' +
      'color:' + BRAND + ';font-weight:600;font-size:13px;cursor:pointer;line-height:1.2;margin-bottom:12px}' +
      '#sendly-seg-btn:hover{background:rgba(0,79,255,.12)}' +
      '.sendly-seg-overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:20000;' +
      'display:flex;align-items:center;justify-content:center;padding:16px}' +
      '.sendly-seg-panel{background:#fff;border-radius:12px;width:620px;max-width:100%;' +
      'max-height:88vh;overflow:auto;box-shadow:0 12px 40px rgba(0,0,0,.25);color:#1f2937}' +
      '.sendly-seg-head{display:flex;align-items:center;gap:8px;padding:18px 20px 6px}' +
      '.sendly-seg-head i{color:' + BRAND + '}' +
      '.sendly-seg-title{font-size:16px;font-weight:700;margin:0}' +
      '.sendly-seg-body{padding:8px 20px 20px}' +
      '.sendly-seg-lbl{display:block;font-size:12px;color:#6b7280;margin:12px 0 4px}' +
      '.sendly-seg-panel textarea{width:100%;border:1px solid #d1d5db;border-radius:6px;' +
      'padding:8px 10px;font-size:13px;font-family:inherit;box-sizing:border-box;min-height:78px}' +
      '.sendly-seg-ex{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 4px}' +
      '.sendly-seg-ex button{font-size:12px;background:#f3f4f6;color:#4b5563;border:1px solid #e5e7eb;' +
      'padding:4px 10px;border-radius:6px;cursor:pointer}' +
      '.sendly-seg-ex button:hover{border-color:' + BRAND + ';color:' + BRAND + '}' +
      '.sendly-seg-count{display:flex;align-items:baseline;gap:8px;background:rgba(0,79,255,.05);' +
      'border:1px solid rgba(0,79,255,.18);border-radius:8px;padding:12px 14px;margin:4px 0 14px}' +
      '.sendly-seg-count b{font-size:22px;color:' + BRAND + ';font-variant-numeric:tabular-nums}' +
      '.sendly-seg-count span{font-size:13px;color:#4b5563}' +
      '.sendly-seg-row{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;' +
      'border:1px solid #e5e7eb;border-radius:8px;margin-bottom:6px;font-size:13px;line-height:1.45}' +
      '.sendly-seg-row .glue{flex:0 0 auto;font-size:11px;text-transform:uppercase;letter-spacing:.04em;' +
      'color:#9ca3af;padding-top:2px;min-width:26px}' +
      '.sendly-seg-row .crit{flex:1}' +
      '.sendly-seg-row .crit b{font-weight:600}' +
      '.sendly-seg-todo{display:inline-block;margin-top:3px;font-size:11px;color:#92400e;' +
      'background:#fef3c7;border-radius:4px;padding:2px 7px}' +
      '.sendly-seg-drop{margin:12px 0 0;padding:12px 14px;background:#fffbeb;' +
      'border:1px solid #fde68a;border-radius:8px;font-size:12px;color:#78350f}' +
      '.sendly-seg-drop p{margin:0 0 6px;font-weight:600}' +
      '.sendly-seg-drop ul{margin:0;padding-left:18px}' +
      '.sendly-seg-err{color:#c0392b;font-size:13px;margin:10px 0 0;display:none}' +
      '.sendly-seg-foot{display:flex;justify-content:flex-end;gap:8px;margin-top:16px;' +
      'padding-top:14px;border-top:1px solid #e5e7eb}' +
      '.sendly-seg-btn{padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;' +
      'cursor:pointer;border:1px solid #d1d5db;background:#fff;color:#374151}' +
      '.sendly-seg-btn.primary{background:' + BRAND + ';border-color:' + BRAND + ';color:#fff}' +
      '.sendly-seg-btn[disabled]{opacity:.6;cursor:default}';
    var style = document.createElement('style');
    style.id = 'sendly-ai-seg-style';
    style.textContent = css;
    document.head.appendChild(style);
  }

  var state = { filters: [], dropped: [], count: null, ignored: 0, failed: false };

  // ── Libellés VRAIS, lus dans le DOM de Mautic ──────────────────────────
  //
  // On n'affiche jamais la phrase d'explication du modèle : le validateur a pu
  // corriger le critère derrière lui, et une jolie phrase qui décrit autre
  // chose que le filtre réel est le pire scénario. Les libellés ci-dessous
  // viennent des options natives du sélecteur — ce sont ceux que l'utilisateur
  // verra dans la ligne de filtre une fois appliquée.

  function option(f) {
    return mQuery('#available_' + f.object + '_' + f.field);
  }

  function fieldLabel(f) {
    var $o = option(f);
    return ($o.length && ($o.data('field-label') || $o.text().trim())) || f.field;
  }

  function operatorLabel(f) {
    var $o = option(f);
    var ops = $o.length ? $o.data('field-operators') : null;
    if (ops && typeof ops === 'object') {
      for (var label in ops) {
        if (Object.prototype.hasOwnProperty.call(ops, label) && ops[label] === f.operator) {
          return label;
        }
      }
    }
    return f.operator;
  }

  function valueLabel(f) {
    if (f.value === '' || f.value == null) {
      return '';
    }
    return Array.isArray(f.value) ? f.value.join(', ') : String(f.value);
  }

  // ── Panneau ────────────────────────────────────────────────────────────

  function close() {
    var o = document.getElementById('sendly-seg-overlay');
    if (o) {
      o.parentNode.removeChild(o);
    }
  }

  function open() {
    ensureStyles();
    close();

    var overlay = document.createElement('div');
    overlay.className = 'sendly-seg-overlay';
    overlay.id = 'sendly-seg-overlay';
    overlay.innerHTML =
      '<div class="sendly-seg-panel" role="dialog" aria-modal="true">' +
      '<div class="sendly-seg-head"><i class="ri-sparkling-2-line"></i>' +
      '<h4 class="sendly-seg-title">' + esc(t('mautic.lead_list.ai.title', 'Créer les critères avec l’IA')) + '</h4></div>' +
      '<div class="sendly-seg-body" id="sendly-seg-body"></div></div>';

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) {
        close();
      }
    });
    document.body.appendChild(overlay);
    renderAsk();
  }

  function body() {
    return document.getElementById('sendly-seg-body');
  }

  function renderAsk(previous) {
    var examples = [
      t('mautic.lead_list.ai.example1', 'Les contacts ajoutés le mois dernier'),
      t('mautic.lead_list.ai.example2', 'Ceux qui ont ouvert un e-mail mais jamais cliqué'),
      t('mautic.lead_list.ai.example3', 'Les contacts sans ville renseignée')
    ];

    var html =
      '<label class="sendly-seg-lbl" for="sendly-seg-desc">' +
      esc(t('mautic.lead_list.ai.describe', 'Décrivez la cible que vous voulez atteindre')) + '</label>' +
      '<textarea id="sendly-seg-desc" placeholder="' +
      esc(t('mautic.lead_list.ai.placeholder', 'Ex. les contacts ajoutés depuis le mois dernier qui ont ouvert au moins un e-mail')) +
      '">' + esc(previous || '') + '</textarea>' +
      '<div class="sendly-seg-ex">';
    for (var i = 0; i < examples.length; i++) {
      html += '<button type="button" data-ex="' + esc(examples[i]) + '">' + esc(examples[i]) + '</button>';
    }
    html +=
      '</div><p class="sendly-seg-err" id="sendly-seg-err"></p>' +
      '<div class="sendly-seg-foot">' +
      '<button type="button" class="sendly-seg-btn" id="sendly-seg-cancel">' +
      esc(t('mautic.lead_list.ai.cancel', 'Annuler')) + '</button>' +
      '<button type="button" class="sendly-seg-btn primary" id="sendly-seg-go">' +
      esc(t('mautic.lead_list.ai.generate', 'Proposer des critères')) + '</button></div>';

    body().innerHTML = html;

    mQuery('#sendly-seg-body [data-ex]').on('click', function () {
      document.getElementById('sendly-seg-desc').value = mQuery(this).data('ex');
    });
    mQuery('#sendly-seg-cancel').on('click', close);
    mQuery('#sendly-seg-go').on('click', request);
  }

  function request() {
    var desc = (document.getElementById('sendly-seg-desc').value || '').trim();
    if (!desc) {
      return;
    }

    var $go = mQuery('#sendly-seg-go');
    $go.attr('disabled', 'disabled').html(
      '<i class="ri-loader-4-line sendly-seg-spin"></i> ' + esc(t('mautic.lead_list.ai.generating', 'Analyse…'))
    );
    mQuery('#sendly-seg-err').hide();

    // POST via mQuery : le jeton CSRF est ajouté automatiquement (voir en-tête).
    mQuery.ajax({
      url: cfg().segmentEndpoint,
      type: 'POST',
      dataType: 'json',
      data: { description: desc },
      success: function (res) {
        state.filters = (res && res.filters) || [];
        state.dropped = (res && res.dropped) || [];
        state.count = res ? res.count : null;
        state.ignored = (res && res.ignored) || 0;
        state.failed = !!(res && res.failed);
        if (!state.filters.length) {
          renderAsk(desc);
          fail(t('mautic.lead_list.ai.none', 'Aucun critère exploitable n’a pu être déduit. Reformulez en nommant les informations dont vous disposez sur vos contacts.'));
          return;
        }
        renderResult(desc);
      },
      error: function () {
        renderAsk(desc);
        fail(t('mautic.lead_list.ai.error', 'La requête a échoué. Réessayez.'));
      }
    });
  }

  function fail(msg) {
    var e = document.getElementById('sendly-seg-err');
    if (e) {
      e.textContent = msg;
      e.style.display = 'block';
    }
  }

  function renderResult(desc) {
    var html = '';

    // Le nombre d'abord : c'est ce qui permet de juger la proposition.
    if (state.count !== null && !state.failed) {
      var suffix = state.ignored > 0
        ? t('mautic.lead_list.ai.count_partial', 'contacts, hors critères à compléter')
        : t('mautic.lead_list.ai.count', 'contacts correspondent à ces critères');
      html += '<div class="sendly-seg-count"><b>' + esc(String(state.count)) + '</b><span>' + esc(suffix) + '</span></div>';
    } else {
      html += '<div class="sendly-seg-count"><span>' +
        esc(t('mautic.lead_list.ai.count_unavailable', 'Le nombre de contacts n’a pas pu être calculé. Les critères restent applicables.')) +
        '</span></div>';
    }

    for (var i = 0; i < state.filters.length; i++) {
      var f = state.filters[i];
      var glue = i === 0 ? '' : t('mautic.lead_list.ai.glue_' + f.glue, f.glue === 'or' ? 'ou' : 'et');
      var val = valueLabel(f);
      html += '<div class="sendly-seg-row"><span class="glue">' + esc(glue) + '</span><span class="crit">' +
        '<b>' + esc(fieldLabel(f)) + '</b> ' + esc(operatorLabel(f)) +
        (val ? ' <b>' + esc(val) + '</b>' : '');
      if (f.needsInput) {
        html += '<br><span class="sendly-seg-todo">' +
          esc(t('mautic.lead_list.ai.needs_input', 'valeur à choisir après application')) + '</span>';
      }
      html += '</span></div>';
    }

    if (state.dropped.length) {
      html += '<div class="sendly-seg-drop"><p>' +
        esc(t('mautic.lead_list.ai.dropped.title', 'Non retenu dans votre demande')) + '</p><ul>';
      for (var d = 0; d < state.dropped.length; d++) {
        var item = state.dropped[d];
        html += '<li>' + esc(item.label) + ' — ' + esc(item.message) + '</li>';
      }
      html += '</ul></div>';
    }

    html +=
      '<p class="sendly-seg-err" id="sendly-seg-err"></p>' +
      '<div class="sendly-seg-foot">' +
      '<button type="button" class="sendly-seg-btn" id="sendly-seg-back">' +
      esc(t('mautic.lead_list.ai.back', 'Reformuler')) + '</button>' +
      '<button type="button" class="sendly-seg-btn primary" id="sendly-seg-apply">' +
      esc(t('mautic.lead_list.ai.apply', 'Ajouter ces critères')) + '</button></div>';

    body().innerHTML = html;

    mQuery('#sendly-seg-back').on('click', function () {
      renderAsk(desc);
    });
    mQuery('#sendly-seg-apply').on('click', apply);
  }

  // ── Application via le chemin natif ────────────────────────────────────

  /**
   * S'accroche à l'événement que Mautic émet quand le formulaire de propriétés
   * d'une ligne a fini de se charger. C'est le seul signal fiable : la
   * conversion de l'input de valeur est asynchrone. Un délai fixe marcherait
   * sur une instance rapide et échouerait sur une instance chargée.
   */
  function onceLoaded(num, cb) {
    var selector = '#leadlist_filters_' + num;
    var done = false;
    var timer = null;

    function handler(event, loadedSelector) {
      if (done || loadedSelector !== selector) {
        return;
      }
      done = true;
      clearTimeout(timer);
      mQuery('#leadlist_filters').off('filter.properties.form.loaded', handler);
      cb(true);
    }

    timer = setTimeout(function () {
      if (done) {
        return;
      }
      done = true;
      mQuery('#leadlist_filters').off('filter.properties.form.loaded', handler);
      cb(false);
    }, LOAD_TIMEOUT_MS);

    mQuery('#leadlist_filters').on('filter.properties.form.loaded', handler);
  }

  function setValue(num, f) {
    var $input = mQuery('#leadlist_filters_' + num + '_properties_filter');
    if (!$input.length) {
      // Opérateurs sans valeur (« est vide ») : le formulaire n'a pas d'input.
      return;
    }
    if (Array.isArray(f.value)) {
      // ⚠️ LE SÉPARATEUR EST LA BARRE VERTICALE, PAS LA VIRGULE.
      // Sur un select multiple, jQuery prend le tableau tel quel. Mais quand le
      // champ est rendu en input texte, Mautic reconstitue la liste avec
      // explode('|') (BaseDecorator::getParameterValue, cas « in »/« !in ») —
      // c'est aussi ce que fait son propre gestionnaire de collage. Joindre
      // avec ", " donnerait UNE valeur littérale « 12, 7 » qui ne correspond à
      // rien : segment vide, sans aucune erreur.
      //
      // Le piège était doublement traître ici : l'aperçu reçoit un vrai tableau
      // côté serveur, donc il aurait affiché le BON nombre de contacts, pendant
      // que le formulaire appliqué en aurait produit un autre. Le décompte
      // aurait servi de caution à un segment faux.
      $input.val($input.is('select') ? f.value : f.value.join('|'));
    } else {
      $input.val(f.value);
    }
    if ($input.is('select')) {
      $input.trigger('chosen:updated');
    }
    $input.trigger('change');
  }

  function setGlue(num, f) {
    var $glue = mQuery('#leadlist_filters_' + num + '_glue');
    if (!$glue.length) {
      return;
    }
    $glue.val(f.glue);
    // Mautic force lui-même « et » sur la première ligne et gère le visuel de
    // regroupement ; on lui redonne la main plutôt que de l'imiter.
    Mautic.updateFilterPositioning($glue);
    Mautic.segmentFilter().showCopyBasedOnGlue($glue.closest('.filter--row'));
  }

  /**
   * Ajoute UNE ligne, puis appelle done(ok). Séquentiel volontairement : les
   * lignes sont numérotées d'après le nombre de lignes présentes, et l'ordre
   * affiché doit correspondre à l'ordre proposé.
   */
  function applyOne(f, done) {
    var $opt = option(f);
    if (!$opt.length) {
      // Le critère a passé la validation serveur mais son option n'existe pas
      // dans cet écran. Plutôt que de produire une ligne fausse, on renonce.
      done(false);
      return;
    }

    var $ops = $opt.data('field-operators') || {};
    var known = false;
    for (var label in $ops) {
      if (Object.prototype.hasOwnProperty.call($ops, label) && $ops[label] === f.operator) {
        known = true;
        break;
      }
    }
    if (!known) {
      // Poser un opérateur absent de la liste laisserait le premier opérateur
      // sélectionné : un filtre silencieusement FAUX. On préfère renoncer.
      done(false);
      return;
    }

    var num = Mautic.segmentFilter().getFilterCount();

    onceLoaded(num, function (ok) {
      if (!ok) {
        done(false);
        return;
      }
      var $op = mQuery('#leadlist_filters_' + num + '_operator');
      if ($op.val() === f.operator) {
        setValue(num, f);
        setGlue(num, f);
        done(true);
        return;
      }
      $op.val(f.operator);
      onceLoaded(num, function (ok2) {
        if (ok2) {
          setValue(num, f);
          setGlue(num, f);
        }
        done(ok2);
      });
      // Recharge le formulaire de valeur pour l'opérateur retenu.
      Mautic.convertLeadFilterInput('#leadlist_filters_' + num + '_operator');
    });

    Mautic.addLeadListFilter(f.field, f.object);
  }

  function apply() {
    var $btn = mQuery('#sendly-seg-apply');
    var label = $btn.html();

    $btn.attr('disabled', 'disabled').html(
      '<i class="ri-loader-4-line sendly-seg-spin"></i> ' + esc(t('mautic.lead_list.ai.applying', 'Ajout…'))
    );

    var queue = state.filters.slice();
    var missed = [];

    function next() {
      if (!queue.length) {
        if (!missed.length) {
          close();
          return;
        }
        // On NE FERME PAS quand un critère a échoué. Fermer laisserait croire
        // que tout a été ajouté, et le client enregistrerait un segment
        // incomplet en pensant qu'il correspond à sa demande. Le panneau reste
        // ouvert, nomme ce qui manque, et le contexte est encore à l'écran.
        $btn.removeAttr('disabled').html(label);
        fail(
          t('mautic.lead_list.ai.partial', 'Ces critères n’ont pas pu être ajoutés : ') +
          missed.join(' · ')
        );
        return;
      }

      var f = queue.shift();
      applyOne(f, function (ok) {
        if (!ok) {
          missed.push(fieldLabel(f));
        }
        next();
      });
    }

    next();
  }

  // ── Injection du bouton ────────────────────────────────────────────────

  function injectButton() {
    var c = cfg();
    if (!c || !c.enabled || !c.segmentEndpoint) {
      return;
    }
    // Garde de page : le sélecteur de critères ET le conteneur de lignes.
    // Sans les deux, on n'est pas sur l'écran d'édition d'un segment.
    var $available = mQuery('#available_segment_filters');
    if (!$available.length || !mQuery('#leadlist_filters').length) {
      return;
    }
    if (document.getElementById('sendly-seg-btn')) {
      return;
    }

    ensureStyles();
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.id = 'sendly-seg-btn';
    btn.innerHTML = '<i class="ri-sparkling-2-line"></i> ' +
      t('mautic.lead_list.ai.button', 'Créer les critères avec l’IA');
    btn.onclick = open;
    $available.closest('.available-filters').before(btn);
  }

  mQuery(function () {
    injectButton();
  });

  // L'écran de segment se charge aussi en ajax (navigation interne Mautic).
  var _origLeadListOnLoad = Mautic.leadlistOnLoad;
  Mautic.leadlistOnLoad = function (container, response) {
    if (typeof _origLeadListOnLoad === 'function') {
      _origLeadListOnLoad(container, response);
    }
    injectButton();
  };
})();
