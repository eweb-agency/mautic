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
      '#sendly-seg-count{display:inline-flex;align-items:center;gap:6px;margin-left:10px;' +
      'padding:4px 12px;border-radius:999px;font-size:12px;font-weight:600;' +
      'background:rgba(0,79,255,.08);color:#004FFF;vertical-align:middle}' +
      '#sendly-seg-count .sendly-seg-count-spin{display:inline-block;width:10px;height:10px;' +
      'border:2px solid rgba(0,79,255,.25);border-top-color:#004FFF;border-radius:50%;' +
      'animation:sendly-seg-count-spin .8s linear infinite}' +
      '#sendly-seg-count.muted{background:rgba(0,0,0,.05);color:inherit;opacity:.65;font-weight:500}';
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

  function render(html, muted) {
    var el = badge();
    if (!el) {
      return;
    }
    el.innerHTML = html;
    el.className = muted ? 'muted' : '';
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
      render('—', true);
      return;
    }

    var mySeq = ++seq;
    render('<span class="sendly-seg-count-spin"></span> ' +
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
          render(t('mautic.lead_list.live_count.unavailable', '—'), true);
          return;
        }
        var label = Number(res.count) === 1
          ? t('mautic.lead_list.live_count.one', 'contact')
          : t('mautic.lead_list.live_count.contacts', 'contacts');
        var html = formatCount(res.count) + ' ' + label;
        if (res.ignored > 0) {
          html += ' <span style="font-weight:500;opacity:.75">· ' +
            t('mautic.lead_list.live_count.partial', 'hors critères à compléter') +
            '</span>';
        }
        render(html, false);
      },
      error: function () {
        if (mySeq === seq) {
          render(t('mautic.lead_list.live_count.unavailable', '—'), true);
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
    pill.className = 'muted';
    pill.textContent = '—';
    $available.closest('.available-filters').before(pill);

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
