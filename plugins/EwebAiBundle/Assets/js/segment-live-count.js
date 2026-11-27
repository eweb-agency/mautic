/**
 * Compteur en continu du formulaire de segment : « 1 234 contacts ».
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

  var CHEMIN_ASSETS = (window.mauticBasePath || '') + '/plugins/EwebAiBundle/Assets';

  // Le pictogramme « contacts », du même trait que ceux du Copilot.
  var ICONE = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>' +
    '<circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';

  function ensureStyles() {
    if (document.getElementById('sendly-seg-count-style')) {
      return;
    }
    var POLICE = '"Helvena","Helvetica Neue",-apple-system,"Segoe UI",sans-serif';
    var css =
      '@font-face{font-family:"Helvena";font-weight:400;font-display:swap;src:url("' + CHEMIN_ASSETS + '/fonts/Helvena_Regular.woff2") format("woff2")}' +
      '@font-face{font-family:"Helvena";font-weight:500;font-display:swap;src:url("' + CHEMIN_ASSETS + '/fonts/Helvena_Medium.woff2") format("woff2")}' +
      /* Refonte du 27/11/2026, DA de Sendly : une PILULE blanche (rayon 999),
         un rond bleu clair pour le pictogramme, le nombre en Helvena bleu
         nuit, l'ombre portée douce du Copilot. Plus de liseré à gauche. La
         POSITION ne change pas (choix du proprio du 05/08) : flottante,
         centrée en bas d'écran ; l'élément vit DANS le conteneur du
         formulaire, la navigation ajax l'emporte avec la page. */
      '#sendly-seg-count{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);' +
      'display:inline-flex;align-items:center;gap:12px;max-width:calc(100vw - 32px);' +
      'padding:8px 22px 8px 8px;background:#fff;border-radius:999px;' +
      'box-shadow:0 0 0 1px rgba(0,18,72,.08),0 16px 36px rgba(0,18,72,.14),0 2px 6px rgba(0,18,72,.06);' +
      'font-family:' + POLICE + ';font-size:14px;line-height:1.2;letter-spacing:-.005em;color:#5B6478;' +
      '-webkit-font-smoothing:antialiased;z-index:1030;white-space:nowrap}' +
      '#sendly-seg-count .sendly-seg-count-ico{position:relative;flex:none;width:36px;height:36px;border-radius:50%;' +
      'background:#DFEAFF;color:#004FFF;display:inline-flex;align-items:center;justify-content:center}' +
      '#sendly-seg-count .sendly-seg-count-ico svg{display:block}' +
      '#sendly-seg-count .sendly-seg-count-num{font-size:18px;font-weight:500;color:#001248;' +
      'font-variant-numeric:tabular-nums;letter-spacing:-.01em;transition:opacity .18s ease}' +
      '#sendly-seg-count .sendly-seg-count-num.nouveau{animation:sendly-seg-num .24s ease-out}' +
      '#sendly-seg-count .sendly-seg-count-lib{overflow:hidden;text-overflow:ellipsis}' +
      '#sendly-seg-count .court{display:none}' +
      '#sendly-seg-count .sendly-seg-count-note{flex:none;display:inline-flex;align-items:center;height:24px;' +
      'padding:0 10px;border-radius:999px;background:#FFF4E5;color:#8A4B00;font-size:12.5px;font-weight:500}' +
      /* Pendant le calcul : le dernier nombre reste en place (rien ne saute),
         estompé, et un anneau tourne autour du pictogramme. */
      '#sendly-seg-count.calcul .sendly-seg-count-num{opacity:.4}' +
      '#sendly-seg-count.calcul .sendly-seg-count-ico::after{content:"";position:absolute;inset:-3px;border-radius:50%;' +
      'border:2px solid transparent;border-top-color:#004FFF;animation:sendly-seg-count-spin .8s linear infinite}' +
      '#sendly-seg-count.muet .sendly-seg-count-num{color:#5B6478}' +
      '#sendly-seg-count.muet .sendly-seg-count-ico{background:#F5F7F9;color:#5B6478}' +
      '@keyframes sendly-seg-count-spin{to{transform:rotate(360deg)}}' +
      '@keyframes sendly-seg-num{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}' +
      '@media (max-width:480px){#sendly-seg-count{bottom:16px;gap:10px;padding:6px 16px 6px 6px;font-size:13px}' +
      '#sendly-seg-count .sendly-seg-count-ico{width:32px;height:32px}' +
      '#sendly-seg-count .sendly-seg-count-num{font-size:17px}' +
      /* Au téléphone, avec la mention, les textes courts : rien n'est coupé
         (audit des largeurs du 27/11, 360 à 430 px). */
      '#sendly-seg-count.avec-note .long{display:none}#sendly-seg-count.avec-note .court{display:inline}}' +
      '@media (prefers-reduced-motion:reduce){#sendly-seg-count *,#sendly-seg-count *::after{animation:none!important;transition:none!important}}';
    var style = document.createElement('style');
    style.id = 'sendly-seg-count-style';
    style.textContent = css;
    document.head.appendChild(style);
  }

  var seq = 0;
  var timer = null;
  var lastPayload = null;
  /** Le dernier nombre affiché : il reste en place pendant un recalcul. */
  var dernier = null;

  function badge() {
    return document.getElementById('sendly-seg-count');
  }

  /**
   * La pilule. `etat` : { num (chaîne formatée ou null), libelle, note
   * (pastille « critères à compléter »), calcul, muet }.
   */
  function render(etat) {
    var el = badge();
    if (!el) {
      return;
    }
    var num = null === etat.num || undefined === etat.num ? '…' : etat.num;
    var nouveau = !etat.calcul && etat.num && etat.num !== el.getAttribute('data-num');
    el.innerHTML = '<span class="sendly-seg-count-ico">' + ICONE + '</span>' +
      '<span class="sendly-seg-count-num' + (nouveau ? ' nouveau' : '') + '">' + esc(num) + '</span>' +
      '<span class="sendly-seg-count-lib"><span class="long">' + esc(etat.libelle) + '</span>' +
      '<span class="court">' + esc(etat.libelleCourt || etat.libelle) + '</span></span>' +
      (etat.note ? '<span class="sendly-seg-count-note"><span class="long">' + esc(etat.note) + '</span>' +
        '<span class="court">' + esc(etat.noteCourte || etat.note) + '</span></span>' : '');
    el.className = (etat.calcul ? 'calcul' : '') + (etat.muet ? ' muet' : '') + (etat.note ? ' avec-note' : '');
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    el.setAttribute('aria-busy', etat.calcul ? 'true' : 'false');
    if (!etat.calcul && etat.num) {
      el.setAttribute('data-num', etat.num);
    }
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
      render({ num: null, libelle: t('mautic.lead_list.live_count.pill_other', 'contacts correspondants'), muet: true });
      return;
    }

    var mySeq = ++seq;
    render({ num: dernier, libelle: t('mautic.lead_list.live_count.counting', 'Calcul…'), calcul: true });

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
          dernier = null;
          render({ num: null, libelle: t('mautic.lead_list.live_count.unavailable', 'Nombre indisponible'), muet: true });
          return;
        }
        var label = Number(res.count) === 1
          ? t('mautic.lead_list.live_count.pill_one', 'contact correspondant')
          : t('mautic.lead_list.live_count.pill_other', 'contacts correspondants');
        dernier = formatCount(res.count);
        var partiel = res.ignored > 0;
        render({
          num: dernier,
          libelle: label,
          libelleCourt: Number(res.count) === 1
            ? t('mautic.lead_list.live_count.pill_one_short', 'contact')
            : t('mautic.lead_list.live_count.pill_other_short', 'contacts'),
          note: partiel ? t('mautic.lead_list.live_count.pill_partial', 'Critères à compléter') : '',
          noteCourte: partiel ? t('mautic.lead_list.live_count.pill_partial_short', 'À compléter') : ''
        });
      },
      error: function () {
        if (mySeq === seq) {
          dernier = null;
          render({ num: null, libelle: t('mautic.lead_list.live_count.unavailable', 'Nombre indisponible'), muet: true });
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
    dernier = null;
    render({ num: null, libelle: t('mautic.lead_list.live_count.pill_other', 'contacts correspondants'), muet: true });

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
