/**
 * Compteur en continu du formulaire de segment — « 1 234 contacts ».
 *
 * Pendant que le client construit ses critères (à la main ou via
 * l'assistant), une pastille au-dessus des lignes de filtre affiche en
 * continu le nombre de contacts correspondants, recalculé côté serveur par
 * le MÊME chemin que l'enregistrement (segment fantôme id 0 — le nombre
 * reflète exactement les critères, jamais les ajouts manuels).
 *
 * PAS une surface IA : la pastille vit dès que `SendlySegmentCountConfig`
 * est injecté (toujours, clé ou pas) — seul l'assistant dépend de la clé.
 *
 * ⚠️ MÊMES PIÈGES QUE ai-segment.js, à ne pas « simplifier » :
 *  1. mQuery.ajax, JAMAIS fetch() — Mautic intercepte tout POST XHR vers /s/
 *     sans jeton CSRF et répond des flashes ; mQuery.ajax porte le jeton.
 *  2. Garde de page : `#leadlist_filters` ET `#available_segment_filters`.
 *     Le contenu dynamique des e-mails a une interface de filtres JUMELLE
 *     (`#dwc_filters`) où nos sélecteurs seraient faux.
 *
 * On sérialise le formulaire NATIF tel quel (`leadlist[filters]`) : ce qui
 * part au serveur est, au caractère près, ce que l'enregistrement enverrait.
 */
(function () {
  'use strict';

  var DEBOUNCE_MS = 700;

  function cfg() {
    return window.SendlySegmentCountConfig;
  }

  function t(key, fallback) {
    var s = Mautic.translate(key);
    return !s || s === key ? fallback : s;
  }

  function ensureStyles() {
    if (document.getElementById('sendly-seg-count-style')) {
      return;
    }
    var css =
      '@keyframes sendly-seg-count-spin{to{transform:rotate(360deg)}}' +
      /* Pilule FLOTTANTE centrée en bas d'écran — la position du motif de
         référence, choisie par le proprio sur capture réelle (05/08).
         position:fixed, mais l'élément vit DANS le conteneur du formulaire :
         la navigation ajax de Mautic l'emporte avec la page. */
      '#sendly-seg-count{position:fixed;bottom:22px;left:50%;transform:translateX(-50%);' +
      'display:flex;align-items:center;gap:10px;padding:12px 18px;background:#fff;' +
      'border-radius:10px;border-left:4px solid #004FFF;box-shadow:0 10px 28px rgba(22,35,59,.18);' +
      'font-size:13px;color:#55617a;z-index:1030;white-space:nowrap}' +
      '#sendly-seg-count .sendly-seg-count-num{font-size:17px;font-weight:700;color:#24303f}' +
      '#sendly-seg-count .ri-group-line{color:#004FFF;font-size:17px}' +
      '#sendly-seg-count .sendly-seg-count-spin{display:inline-block;width:12px;height:12px;' +
      'border:2px solid rgba(0,79,255,.25);border-top-color:#004FFF;border-radius:50%;' +
      'animation:sendly-seg-count-spin .8s linear infinite}' +
      '#sendly-seg-count.muted{opacity:.7}';
    var style = document.createElement('style');
    style.id = 'sendly-seg-count-style';
    style.textContent = css;
    document.head.appendChild(style);
  }

  var seq = 0;
  var timer = null;
  var lastPayload = null;

  function badge() {
    return document.getElementById('sendly-seg-count');
  }

  /** La pilule : icône contacts + nombre en gras + libellé (motif de la
   *  capture de référence). `num` = chaîne déjà formatée ou spinner HTML. */
  function render(numHtml, labelText, muted) {
    var el = badge();
    if (!el) {
      return;
    }
    el.innerHTML = '<i class="ri-group-line"></i>' +
      '<span class="sendly-seg-count-num">' + numHtml + '</span>' +
      '<span>' + esc(labelText) + '</span>';
    el.className = muted ? 'muted' : '';
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function formatCount(n) {
    try {
      return Number(n).toLocaleString(document.documentElement.lang || 'fr');
    } catch (e) {
      return String(n);
    }
  }

  function serializeFilters() {
    return mQuery('#leadlist_filters').find(':input').serialize();
  }

  function recount() {
    var c = cfg();
    var el = badge();
    if (!c || !c.endpoint || !el) {
      return;
    }

    var payload = serializeFilters();
    if (payload === lastPayload) {
      return; // rien n'a changé — pas d'aller-retour pour rien
    }
    lastPayload = payload;

    if (!payload) {
      render('—', t('mautic.lead_list.live_count.pill_other', 'contacts correspondants'), true);
      return;
    }

    var mySeq = ++seq;
    render('<span class="sendly-seg-count-spin"></span>',
      t('mautic.lead_list.live_count.counting', 'Calcul…'), false);

    mQuery.ajax({
      url: c.endpoint,
      type: 'POST',
      data: payload,
      dataType: 'json',
      success: function (res) {
        if (mySeq !== seq) {
          return; // une saisie plus récente a relancé le calcul
        }
        if (!res || res.count === null || res.count === undefined) {
          render('—', t('mautic.lead_list.live_count.unavailable', 'Nombre indisponible'), true);
          return;
        }
        var label = Number(res.count) === 1
          ? t('mautic.lead_list.live_count.pill_one', 'contact correspondant')
          : t('mautic.lead_list.live_count.pill_other', 'contacts correspondants');
        if (res.ignored > 0) {
          label += ' · ' + t('mautic.lead_list.live_count.partial', 'hors critères à compléter');
        }
        render(esc(formatCount(res.count)), label, false);
      },
      error: function () {
        if (mySeq === seq) {
          render('—', t('mautic.lead_list.live_count.unavailable', 'Nombre indisponible'), true);
        }
      }
    });
  }

  function schedule() {
    if (timer) {
      clearTimeout(timer);
    }
    // 700 ms : couvre aussi l'animation de suppression d'une ligne (~200 ms)
    // avant Mautic.reorderSegmentFilters — on compte le DOM final.
    timer = setTimeout(recount, DEBOUNCE_MS);
  }

  function injectBadge() {
    var c = cfg();
    if (!c || !c.endpoint) {
      return;
    }
    // Garde de page : le sélecteur de critères ET le conteneur de lignes.
    var $available = mQuery('#available_segment_filters');
    var $filters = mQuery('#leadlist_filters');
    if (!$available.length || !$filters.length) {
      return;
    }
    if (badge()) {
      return;
    }

    ensureStyles();
    var pill = document.createElement('span');
    pill.id = 'sendly-seg-count';
    // Insérée DANS la zone du formulaire (la navigation ajax l'emporte avec
    // la page) mais affichée en position fixe, centrée en bas d'écran.
    $available.closest('.available-filters').before(pill);
    render('—', t('mautic.lead_list.live_count.pill_other', 'contacts correspondants'), true);

    // Une seule délégation par surface : les lignes vont et viennent, le
    // conteneur reste. `filter.properties.form.loaded` est l'événement que
    // Mautic émet quand la ligne est COMPLÈTE (valeur restaurée, chosen posé).
    $filters
      .off('.sendlySegCount')
      .on('change.sendlySegCount', ':input', schedule)
      .on('input.sendlySegCount', 'input[type="text"], input[type="number"], textarea', schedule)
      .on('click.sendlySegCount', 'a.remove-selected', schedule)
      .on('filter.properties.form.loaded.sendlySegCount', schedule);

    lastPayload = null;
    recount();
  }

  mQuery(function () {
    injectBadge();
  });

  // L'écran de segment se charge aussi en ajax (navigation interne Mautic).
  var _origLeadListOnLoad = Mautic.leadlistOnLoad;
  Mautic.leadlistOnLoad = function (container, response) {
    if (typeof _origLeadListOnLoad === 'function') {
      _origLeadListOnLoad(container, response);
    }
    injectBadge();
  };
})();
