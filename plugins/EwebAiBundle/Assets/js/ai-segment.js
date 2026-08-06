/**
 * Assistant de segment — panneau CONVERSATIONNEL (2e génération, 06/08).
 *
 * Le tiroir « une demande → une proposition → Ajouter » devient une
 * conversation, à l'identique du motif de référence choisi sur capture par le
 * proprio : panneau ancré à droite, l'utilisateur décrit sa cible, l'assistant
 * APPLIQUE directement les critères validés au formulaire natif et résume ce
 * qu'il a fait, avec « Annuler les modifications » par tour. Les demandes
 * précédentes partent au serveur comme contexte (« et qui n'ont pas cliqué »
 * n'a de sens qu'avec la demande d'avant). La pilule de décompte, elle,
 * réagit toute seule : l'application déclenche les événements du formulaire.
 *
 * ⚠️ DEUX PIÈGES À NE PAS « SIMPLIFIER » (hérités de la 1re génération).
 *
 * 1. mQuery.ajax, JAMAIS fetch(). Mautic intercepte tout POST XHR vers /s/ qui
 *    n'a pas de jeton CSRF valide (RequestSubscriber) et répond 200 avec un
 *    corps de flashes — le contrôleur n'est jamais atteint, et le symptôme
 *    ressemble à un bug serveur. mQuery.ajax en POST reçoit le jeton
 *    automatiquement ; fetch() ne le reçoit pas.
 *
 * 2. On n'écrit PAS les lignes de filtre à la main. On appelle le point
 *    d'entrée natif Mautic.addLeadListFilter(), puis on ajuste opérateur et
 *    valeur. Le DOM produit est donc identique, au caractère près, à celui
 *    d'une saisie manuelle. L'ANNULATION passe par le même chemin : le clic
 *    sur le « supprimer » natif de chaque ligne du tour — marquée par un
 *    attribut data qui SUIT la ligne quand Mautic renumérote.
 *
 * On n'affiche JAMAIS la phrase d'explication du modèle : le validateur a pu
 * corriger le critère derrière lui. Le résumé d'un tour liste les critères
 * RÉELLEMENT appliqués, avec les libellés natifs de l'écran.
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

  /** Contexte envoyé au serveur : les N dernières demandes (il borne aussi). */
  var HISTORY_SENT = 5;

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
      '#sendly-seg-panel{position:fixed;right:14px;width:355px;max-width:92vw;background:#fff;' +
      'border:1px solid #e5e7eb;border-radius:12px;z-index:1040;display:flex;flex-direction:column;' +
      'box-shadow:0 16px 40px rgba(22,35,59,.16);overflow:hidden}' +
      '.sendly-seg-head{display:flex;align-items:center;gap:8px;padding:12px 14px;border-bottom:1px solid #eef1f5}' +
      '.sendly-seg-title{margin:0;font-size:15px;font-weight:700;color:' + BRAND + ';flex:1}' +
      '.sendly-seg-iconbtn{background:none;border:0;cursor:pointer;color:#97a1b3;font-size:15px;padding:2px 5px}' +
      '.sendly-seg-iconbtn:hover{color:#24303f}' +
      '.sendly-seg-conv{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:12px}' +
      '.sendly-seg-turn{display:flex;gap:9px;align-items:flex-start;font-size:13px;line-height:1.5;color:#303a4c}' +
      '.sendly-seg-turn .ri-sparkling-2-line{color:' + BRAND + ';flex:none;margin-top:2px}' +
      '.sendly-seg-turn ul{margin:6px 0 0;padding-left:16px}' +
      '.sendly-seg-turn li{margin-bottom:3px}' +
      '.sendly-seg-me{align-self:flex-end;background:#f2f3f6;color:#303a4c;border-radius:10px;' +
      'padding:8px 11px;max-width:86%;font-size:12.5px;line-height:1.45;white-space:pre-wrap;word-break:break-word}' +
      '.sendly-seg-note{font-size:12px;color:#92400e;background:#fef3c7;border-radius:4px;padding:1px 6px}' +
      '.sendly-seg-mut{color:#6a7486;font-size:12px}' +
      '.sendly-seg-undo{display:inline-flex;align-items:center;gap:6px;margin-top:8px;padding:5px 12px;' +
      'border:1px solid #d5dced;border-radius:999px;background:#fff;color:#303a4c;font-size:12px;cursor:pointer}' +
      '.sendly-seg-undo:hover{border-color:' + BRAND + ';color:' + BRAND + '}' +
      '.sendly-seg-undo[disabled]{opacity:.55;cursor:default;pointer-events:none}' +
      '.sendly-seg-ex{display:flex;flex-wrap:wrap;gap:6px;padding:0 14px 8px}' +
      '.sendly-seg-ex button{font-size:12px;background:#fff;color:' + BRAND + ';border:1px solid #d9e2f2;' +
      'padding:3px 10px;border-radius:999px;cursor:pointer}' +
      '.sendly-seg-ex button:hover{background:#f0f5ff}' +
      '.sendly-seg-foot{display:flex;gap:8px;padding:0 14px 8px}' +
      '#sendly-seg-input{flex:1;border:1px solid #d5dced;border-radius:999px;padding:8px 14px;font-size:13px;background:#f7f8fa}' +
      '#sendly-seg-input:focus{outline:none;border-color:' + BRAND + ';background:#fff}' +
      '#sendly-seg-send{border:0;border-radius:50%;width:34px;height:34px;flex:none;background:' + BRAND + ';' +
      'color:#fff;cursor:pointer;display:inline-flex;align-items:center;justify-content:center}' +
      '#sendly-seg-send[disabled]{opacity:.5;cursor:default}' +
      '.sendly-seg-meta{display:flex;justify-content:space-between;padding:0 14px 12px;color:#97a1b3;font-size:11.5px}' +
      '.sendly-seg-meta a{color:#97a1b3;cursor:pointer}' +
      '.sendly-seg-meta a:hover{color:' + BRAND + '}';
    var style = document.createElement('style');
    style.id = 'sendly-ai-seg-style';
    style.textContent = css;
    document.head.appendChild(style);
  }

  // ── Libellés VRAIS, lus dans le DOM de Mautic ──────────────────────────

  function option(f) {
    return mQuery('#available_' + f.object + '_' + f.field);
  }

  /**
   * ⚠️ LE LIBELLÉ DE CHAMP CONTIENT DU BALISAGE, PAR CONCEPTION.
   * `mautic.lead_list.filter.field.label` vaut « <em>%object%</em>: %field% »
   * (LeadBundle/Translations/en_US/messages.ini:1187), donc `data-field-label`
   * porte de vraies balises. On retire les balises AVANT l'échappement.
   */
  function stripTags(s) {
    return String(s == null ? '' : s).replace(/<[^>]*>/g, '');
  }

  function fieldLabel(f) {
    var $o = option(f);
    var raw = ($o.length && ($o.data('field-label') || $o.text().trim())) || f.field;
    return stripTags(raw).trim();
  }

  /**
   * Le critère est-il DÉJÀ dans le formulaire ? L'assistant ajoute aux
   * critères existants ; sans ce contrôle, relancer empile des doublons.
   */
  function alreadyPresent(f) {
    var mine = Array.isArray(f.value) ? f.value.join('|') : String(f.value == null ? '' : f.value);
    var found = false;

    mQuery('#leadlist_filters').find('.filter--row').each(function () {
      if (found) {
        return;
      }
      var $r = mQuery(this);
      if ($r.find('input[id$="_field"]').val() !== f.field) {
        return;
      }
      if ($r.find('input[id$="_object"]').val() !== f.object) {
        return;
      }
      if ($r.find('select[id$="_operator"]').val() !== f.operator) {
        return;
      }
      var v = $r.find('[id$="_properties_filter"]').val();
      var theirs = Array.isArray(v) ? v.join('|') : String(v == null ? '' : v);
      if (theirs === mine) {
        found = true;
      }
    });

    return found;
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

  // ── Application via le chemin natif ────────────────────────────────────

  /**
   * S'accroche à l'événement que Mautic émet quand le formulaire de propriétés
   * d'une ligne a fini de se charger. C'est le seul signal fiable : la
   * conversion de l'input de valeur est asynchrone.
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
      // Quand le champ est rendu en input texte, Mautic reconstitue la liste
      // avec explode('|') (BaseDecorator::getParameterValue, cas « in »).
      // Joindre avec ", " donnerait UNE valeur littérale : segment vide, sans
      // erreur — pendant que l'aperçu, servi d'un vrai tableau côté serveur,
      // afficherait le bon nombre et cautionnerait le segment faux.
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
   * Ajoute UNE ligne, puis appelle done(ok, num). Séquentiel volontairement :
   * les lignes sont numérotées d'après le nombre de lignes présentes, et
   * l'ordre affiché doit correspondre à l'ordre proposé.
   */
  function applyOne(f, done) {
    var $opt = option(f);
    if (!$opt.length) {
      // Le critère a passé la validation serveur mais son option n'existe pas
      // dans cet écran. Plutôt que de produire une ligne fausse, on renonce.
      done(false, null);
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
      done(false, null);
      return;
    }

    var num = Mautic.segmentFilter().getFilterCount();

    onceLoaded(num, function (ok) {
      if (!ok) {
        done(false, null);
        return;
      }
      var $op = mQuery('#leadlist_filters_' + num + '_operator');
      if ($op.val() === f.operator) {
        setValue(num, f);
        setGlue(num, f);
        done(true, num);
        return;
      }
      $op.val(f.operator);
      onceLoaded(num, function (ok2) {
        if (ok2) {
          setValue(num, f);
          setGlue(num, f);
        }
        done(ok2, ok2 ? num : null);
      });
      // Recharge le formulaire de valeur pour l'opérateur retenu.
      Mautic.convertLeadFilterInput('#leadlist_filters_' + num + '_operator');
    });

    Mautic.addLeadListFilter(f.field, f.object);
  }

  /**
   * Applique les critères d'UN tour de conversation, séquentiellement, en
   * marquant chaque ligne posée (l'attribut suit la ligne quand Mautic
   * renumérote — c'est lui que l'annulation retrouve).
   */
  function applyTurn(filters, turnId, cb) {
    var queue = filters.slice();
    var result = { applied: [], dups: [], missed: [] };

    function next() {
      if (!queue.length) {
        cb(result);
        return;
      }
      var f = queue.shift();
      if (alreadyPresent(f)) {
        result.dups.push(f);
        next();
        return;
      }
      applyOne(f, function (ok, num) {
        if (ok) {
          result.applied.push(f);
          mQuery('#leadlist_filters_' + num + '_field')
            .closest('.filter--row')
            .attr('data-sendly-turn', String(turnId));
        } else {
          result.missed.push(f);
        }
        next();
      });
    }

    next();
  }

  // ── Le panneau conversationnel ─────────────────────────────────────────

  /** Tours : {role:'user'|'ia', html, turnId?, undoable?} ; turnId → annulation. */
  var conv = [];
  var busy = false;
  var turnSeq = 0;

  function panel() {
    return document.getElementById('sendly-seg-panel');
  }

  function closePanel() {
    var p = panel();
    if (p) {
      p.parentNode.removeChild(p);
    }
  }

  function welcomeHtml() {
    return '<div class="sendly-seg-turn"><i class="ri-sparkling-2-line"></i><div>' +
      esc(t('mautic.lead_list.ai.welcome',
        'Bonjour, quel segment souhaitez-vous créer aujourd’hui ? Décrivez-moi précisément les contacts que vous souhaitez cibler, je m’occupe du reste !')) +
      '</div></div>';
  }

  function renderConv() {
    var el = document.getElementById('sendly-seg-conv');
    if (!el) {
      return;
    }
    var html = welcomeHtml();
    conv.forEach(function (turn) {
      if (turn.role === 'user') {
        html += '<div class="sendly-seg-me">' + esc(turn.text) + '</div>';
        return;
      }
      html += '<div class="sendly-seg-turn"><i class="ri-sparkling-2-line"></i><div>' + turn.html;
      if (turn.turnId) {
        html += '<br><button type="button" class="sendly-seg-undo" data-turn="' + turn.turnId + '"' +
          (turn.undoable ? '' : ' disabled') + '><i class="ri-arrow-go-back-line"></i> ' +
          esc(t('mautic.lead_list.ai.undo', 'Annuler les modifications')) + '</button>';
      }
      html += '</div></div>';
    });
    if (busy) {
      html += '<div class="sendly-seg-turn"><i class="ri-sparkling-2-line"></i><div>' +
        '<i class="ri-loader-4-line sendly-seg-spin"></i> ' +
        esc(t('mautic.lead_list.ai.generating', 'Analyse…')) + '</div></div>';
    }
    el.innerHTML = html;
    el.scrollTop = el.scrollHeight;
  }

  /** Le résumé d'un tour : ce qui a été RÉELLEMENT appliqué, en libellés natifs. */
  function turnSummary(result, dropped) {
    var html = '';
    if (result.applied.length) {
      html += esc(t('mautic.lead_list.ai.added', 'Critères ajoutés au segment :')) + '<ul>';
      result.applied.forEach(function (f) {
        var val = valueLabel(f);
        html += '<li><b>' + esc(fieldLabel(f)) + '</b> ' + esc(operatorLabel(f)) +
          (val ? ' <b>' + esc(val) + '</b>' : '');
        if (f.needsInput) {
          html += ' <span class="sendly-seg-note">' +
            esc(t('mautic.lead_list.ai.needs_input', 'valeur à choisir après application')) + '</span>';
        }
        html += '</li>';
      });
      html += '</ul>';
    } else {
      html += esc(t('mautic.lead_list.ai.added_none', 'Aucun nouveau critère à ajouter.'));
    }
    if (result.dups.length) {
      html += '<div class="sendly-seg-mut">' + esc(t('mautic.lead_list.ai.already_present', 'déjà présent — ne sera pas ajouté en double')) +
        ' : ' + esc(result.dups.map(fieldLabel).join(', ')) + '</div>';
    }
    if (result.missed.length) {
      html += '<div class="sendly-seg-mut">' + esc(t('mautic.lead_list.ai.partial', 'Ces critères n’ont pas pu être ajoutés : ')) +
        esc(result.missed.map(fieldLabel).join(' · ')) + '</div>';
    }
    if (dropped && dropped.length) {
      html += '<div class="sendly-seg-mut">' + esc(t('mautic.lead_list.ai.dropped.title', 'Non retenu dans votre demande')) + ' : ';
      html += esc(dropped.map(function (d) { return d.label + ' — ' + d.message; }).join(' · ')) + '</div>';
    }
    return html;
  }

  function send(text) {
    var q = String(text || '').trim();
    if (!q || busy || !cfg()) {
      return;
    }

    // Le contexte : les demandes utilisateur précédentes, pas les résumés.
    var history = conv
      .filter(function (turn) { return turn.role === 'user'; })
      .slice(-HISTORY_SENT)
      .map(function (turn) { return turn.text; });

    conv.push({ role: 'user', text: q });
    busy = true;
    renderConv();
    var input = document.getElementById('sendly-seg-input');
    if (input) {
      input.value = '';
    }

    // POST via mQuery : le jeton CSRF est ajouté automatiquement (voir en-tête).
    mQuery.ajax({
      url: cfg().segmentEndpoint,
      type: 'POST',
      dataType: 'json',
      data: { description: q, history: history },
      success: function (res) {
        var filters = (res && res.filters) || [];
        var dropped = (res && res.dropped) || [];
        if (!filters.length) {
          busy = false;
          conv.push({ role: 'ia', html: esc(dropped.length
            ? dropped.map(function (d) { return d.label + ' — ' + d.message; }).join(' · ')
            : t('mautic.lead_list.ai.none', 'Aucun critère exploitable n’a pu être déduit. Reformulez en nommant les informations dont vous disposez sur vos contacts.')) });
          renderConv();
          return;
        }
        var turnId = ++turnSeq;
        applyTurn(filters, turnId, function (result) {
          busy = false;
          var turn = { role: 'ia', html: turnSummary(result, dropped) };
          if (result.applied.length) {
            turn.turnId = turnId;
            turn.undoable = true;
          }
          conv.push(turn);
          renderConv();
        });
      },
      error: function () {
        busy = false;
        conv.push({ role: 'ia', html: esc(t('mautic.lead_list.ai.error', 'La requête a échoué. Réessayez.')) });
        renderConv();
      }
    });
  }

  /** L'annulation d'un tour : le clic sur le « supprimer » NATIF de chaque
   *  ligne marquée — jamais de retrait de DOM à la main. */
  function undoTurn(turnId) {
    mQuery('#leadlist_filters .filter--row[data-sendly-turn="' + turnId + '"] a.remove-selected').each(function () {
      mQuery(this).trigger('click');
    });
    conv.forEach(function (turn) {
      if (turn.turnId === turnId) {
        turn.undoable = false;
        turn.html += '<div class="sendly-seg-mut">' +
          esc(t('mautic.lead_list.ai.undone', 'Modifications annulées')) + '</div>';
      }
    });
    renderConv();
  }

  function openPanel() {
    if (panel()) {
      return;
    }
    ensureStyles();

    var examples = [
      t('mautic.lead_list.ai.example1', 'Les contacts ajoutés le mois dernier'),
      t('mautic.lead_list.ai.example2', 'Ceux qui ont ouvert un e-mail mais jamais cliqué'),
      t('mautic.lead_list.ai.example3', 'Les contacts sans ville renseignée')
    ];

    var el = document.createElement('div');
    el.id = 'sendly-seg-panel';
    el.setAttribute('role', 'dialog');
    el.innerHTML =
      '<div class="sendly-seg-head">' +
      '<h4 class="sendly-seg-title">' + esc(t('mautic.lead_list.ai.panel_title', 'Assistant de segment')) + '</h4>' +
      '<button type="button" class="sendly-seg-iconbtn" id="sendly-seg-clear" aria-label="' +
      esc(t('mautic.lead_list.ai.clear', 'Vider la conversation')) + '"><i class="ri-delete-bin-line"></i></button>' +
      '<button type="button" class="sendly-seg-iconbtn" id="sendly-seg-close" aria-label="' +
      esc(t('mautic.lead_list.ai.cancel', 'Annuler')) + '">✕</button>' +
      '</div>' +
      '<div class="sendly-seg-conv" id="sendly-seg-conv"></div>' +
      '<div class="sendly-seg-ex" id="sendly-seg-ex" style="display:none">' +
      examples.map(function (ex) {
        return '<button type="button">' + esc(ex) + '</button>';
      }).join('') + '</div>' +
      '<div class="sendly-seg-foot">' +
      '<input id="sendly-seg-input" type="text" placeholder="' +
      esc(t('mautic.lead_list.ai.input_placeholder', 'Décrivez vos filtres…')) + '">' +
      '<button type="button" id="sendly-seg-send" aria-label="' +
      esc(t('mautic.lead_list.ai.generate', 'Proposer des critères')) + '"><i class="ri-arrow-up-line"></i></button>' +
      '</div>' +
      '<div class="sendly-seg-meta">' +
      '<span>' + esc(t('mautic.lead_list.ai.private', 'Vos données restent privées.')) + '</span>' +
      '<a id="sendly-seg-shortcuts"><i class="ri-question-line"></i> ' +
      esc(t('mautic.lead_list.ai.shortcuts', 'Raccourcis')) + '</a>' +
      '</div>';

    // Ancré sous la barre haute, quelle que soit sa hauteur réelle.
    var header = document.getElementById('app-header');
    var top = header ? header.getBoundingClientRect().bottom : 56;
    el.style.top = (Math.max(0, top) + 12) + 'px';
    el.style.bottom = '14px';
    document.body.appendChild(el);

    el.querySelector('#sendly-seg-close').addEventListener('click', closePanel);
    el.querySelector('#sendly-seg-clear').addEventListener('click', function () {
      conv = [];
      renderConv();
    });
    el.querySelector('#sendly-seg-send').addEventListener('click', function () {
      send(document.getElementById('sendly-seg-input').value);
    });
    el.querySelector('#sendly-seg-input').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        send(this.value);
      }
    });
    el.querySelector('#sendly-seg-shortcuts').addEventListener('click', function () {
      var ex = document.getElementById('sendly-seg-ex');
      ex.style.display = ex.style.display === 'none' ? 'flex' : 'none';
    });
    mQuery(el).on('click', '.sendly-seg-ex button', function () {
      send(this.textContent);
      document.getElementById('sendly-seg-ex').style.display = 'none';
    });
    mQuery(el).on('click', '.sendly-seg-undo', function () {
      undoTurn(mQuery(this).data('turn'));
    });

    renderConv();
    var input = document.getElementById('sendly-seg-input');
    if (input) {
      input.focus();
    }
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
    btn.onclick = openPanel;
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
    // Le panneau appartient à l'ANCIEN écran : la conversation référence des
    // lignes qui n'existent plus après navigation.
    closePanel();
    conv = [];
    busy = false;
    injectButton();
  };
})();
