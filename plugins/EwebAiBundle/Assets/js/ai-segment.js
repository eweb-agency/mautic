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
 * DEPUIS LE 07/08 (directive proprio « un assistant unique ») : ce fichier ne
 * possède PLUS de panneau. Il fournit la machinerie d'application native et se
 * déclare comme CONTEXTE du panneau partagé (ai-assistant.js) : titre, accueil,
 * raccourcis et action d'envoi propres à l'écran segment — même coquille, même
 * design que partout ailleurs.
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

  /**
   * L'état RÉEL des critères du formulaire, envoyé au serveur à chaque tour :
   * c'est LA source de vérité (l'utilisateur annule ou supprime des lignes
   * entre deux tours — l'historique de conversation ne prouve rien, constat
   * proprio 27/08 : « mairies de France » après annulation ne redonnait rien).
   */
  function etatFiltres() {
    var lignes = [];
    mQuery('#leadlist_filters').find('.filter--row').each(function () {
      var $r = mQuery(this);
      var obj = $r.find('input[id$="_object"]').val();
      var champ = $r.find('input[id$="_field"]').val();
      var op = $r.find('select[id$="_operator"]').val();
      if (!champ) { return; }
      var v = $r.find('[id$="_properties_filter"]').val();
      v = Array.isArray(v) ? v.join(',') : String(v == null ? '' : v);
      lignes.push((obj ? obj + '.' : '') + champ + ' ' + (op || '') + (v ? ' ' + v : ''));
    });
    return lignes;
  }

  /**
   * Remplit Nom et Description avec la proposition du serveur — sans jamais
   * écraser une saisie de l'utilisateur : un champ n'est réécrit que s'il est
   * vide ou s'il porte encore NOTRE dernière valeur.
   */
  var dernierNomIA = null;
  var derniereDescIA = null;
  function remplirDetails(res) {
    var faits = [];
    if (res && res.name) {
      var $nom = mQuery('#leadlist_name');
      if ($nom.length && (!$nom.val() || $nom.val() === dernierNomIA)) {
        $nom.val(res.name).trigger('change').trigger('keyup');
        dernierNomIA = res.name;
        faits.push(res.name);
      }
    }
    if (res && res.description) {
      var $desc = mQuery('#leadlist_description');
      var actuel = $desc.length ? String($desc.val() || '') : '';
      if ($desc.length && (!actuel || actuel === derniereDescIA)) {
        $desc.val(res.description).trigger('change');
        derniereDescIA = res.description;
        // L'éditeur riche (s'il est monté) a sa propre copie du texte.
        try {
          if (window.CKEDITOR && window.CKEDITOR.instances && window.CKEDITOR.instances.leadlist_description) {
            window.CKEDITOR.instances.leadlist_description.setData(res.description);
          } else if ($desc[0] && $desc[0].ckeditorInstance) {
            $desc[0].ckeditorInstance.setData(res.description);
          }
        } catch (e) { /* le textarea porte déjà la valeur soumise */ }
      }
    }
    return faits;
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
          html += ' <span class="sendly-assist-note">' +
            esc(t('mautic.lead_list.ai.needs_input', 'valeur à choisir après application')) + '</span>';
        }
        html += '</li>';
      });
      html += '</ul>';
    } else {
      html += esc(t('mautic.lead_list.ai.added_none', 'Aucun nouveau critère à ajouter.'));
    }
    if (result.dups.length) {
      html += '<div class="sendly-assist-mut">' + esc(t('mautic.lead_list.ai.already_present', 'déjà présent — ne sera pas ajouté en double')) +
        ' : ' + esc(result.dups.map(fieldLabel).join(', ')) + '</div>';
    }
    if (result.missed.length) {
      html += '<div class="sendly-assist-mut">' + esc(t('mautic.lead_list.ai.partial', 'Ces critères n\u2019ont pas pu être ajoutés : ')) +
        esc(result.missed.map(fieldLabel).join(' · ')) + '</div>';
    }
    if (dropped && dropped.length) {
      html += '<div class="sendly-assist-mut">' + esc(t('mautic.lead_list.ai.dropped.title', 'Non retenu dans votre demande')) + ' : ';
      html += esc(dropped.map(function (d) { return d.label + ' — ' + d.message; }).join(' · ')) + '</div>';
    }
    return html;
  }

  // ── Le contexte « segment » du panneau partagé ─────────────────────────
  //
  // PAS de panneau propre : la coquille unique (ai-assistant.js) porte le
  // design, ce contexte fournit le contenu et les actions. L'identifiant de
  // tour sert à l'annulation : chaque ligne posée est marquée d'un attribut
  // data-sendly-turn qui SUIT la ligne quand Mautic renumérote.
  var turnSeq = 0;

  window.SendlyAssistantContexts = window.SendlyAssistantContexts || [];
  window.SendlyAssistantContexts.push({
    id: 'segment',
    priority: 10,
    // Garde de page : le sélecteur de critères ET le conteneur de lignes.
    // Sans les deux, on n'est pas sur l'écran d'édition d'un segment.
    available: function () {
      var c = cfg();
      return !!(c && c.enabled && c.segmentEndpoint
        && mQuery('#available_segment_filters').length
        && mQuery('#leadlist_filters').length);
    },
    title: function () {
      return t('mautic.lead_list.ai.panel_title', 'Assistant de segment');
    },
    welcome: function () {
      return t('mautic.lead_list.ai.welcome',
        'Bonjour, quel segment souhaitez-vous créer aujourd\u2019hui ? Décrivez-moi précisément les contacts que vous souhaitez cibler, je m\u2019occupe du reste !');
    },
    placeholder: function () {
      return t('mautic.lead_list.ai.input_placeholder', 'Décrivez vos filtres…');
    },
    thinking: function () {
      return t('mautic.lead_list.ai.generating', 'Analyse…');
    },
    shortcuts: function () {
      return [
        t('mautic.lead_list.ai.example1', 'Les contacts ajoutés le mois dernier'),
        t('mautic.lead_list.ai.example2', 'Ceux qui ont ouvert un e-mail mais jamais cliqué'),
        t('mautic.lead_list.ai.example3', 'Les contacts sans ville renseignée')
      ];
    },
    onSend: function (q, api) {
      // Le contexte envoyé : les demandes utilisateur précédentes (la coquille
      // les fournit), pas les résumés. Le serveur borne aussi de son côté.
      var history = api.history(HISTORY_SENT + 1).slice(0, -1);

      // POST via mQuery : le jeton CSRF est ajouté automatiquement (en-tête).
      mQuery.ajax({
        url: cfg().segmentEndpoint,
        type: 'POST',
        dataType: 'json',
        data: { description: q, history: history, current: etatFiltres() },
        success: function (res) {
          var filters = (res && res.filters) || [];
          var dropped = (res && res.dropped) || [];
          if (!filters.length) {
            api.fail(dropped.length
              ? dropped.map(function (d) { return d.label + ' — ' + d.message; }).join(' · ')
              : t('mautic.lead_list.ai.none', 'Aucun critère exploitable n\u2019a pu être déduit. Reformulez en nommant les informations dont vous disposez sur vos contacts.'));
            return;
          }
          var turnId = ++turnSeq;
          applyTurn(filters, turnId, function (result) {
            var resume = turnSummary(result, dropped);
            var nommes = remplirDetails(res);
            if (nommes.length) {
              resume += '<div class="sendly-assist-fait">' +
                esc(t('mautic.lead_list.ai.named', 'Nom du segment rempli :')) + ' <b>' + esc(nommes[0]) + '</b></div>';
            }
            api.ia(resume,
              result.applied.length ? { turnId: turnId, undoable: true } : null);
            api.finish();
          });
        },
        error: function () {
          api.fail(t('mautic.lead_list.ai.error', 'La requête a échoué. Réessayez.'));
        }
      });
    },
    /** L'annulation d'un tour : le clic sur le « supprimer » NATIF de chaque
     *  ligne marquée — jamais de retrait de DOM à la main. */
    onUndo: function (turnId, api) {
      mQuery('#leadlist_filters .filter--row[data-sendly-turn="' + turnId + '"] a.remove-selected').each(function () {
        mQuery(this).trigger('click');
      });
      api.markUndone(esc(t('mautic.lead_list.ai.undone', 'Modifications annulées')));
    }
  });

  // L'écran de segment se charge aussi en ajax (navigation interne Mautic).
  var _origLeadListOnLoad = Mautic.leadlistOnLoad;
  Mautic.leadlistOnLoad = function (container, response) {
    if (typeof _origLeadListOnLoad === 'function') {
      _origLeadListOnLoad(container, response);
    }
    // La conversation référence des lignes qui n'existent plus après
    // navigation : la coquille la vide et se referme si elle l'affichait.
    if (window.SendlyAssistant) {
      window.SendlyAssistant.reset('segment');
    }
  };

  // ── Relais de l'assistant global (26/08) : quand l'utilisateur a demandé
  //    un segment DEPUIS UN AUTRE ÉCRAN, l'assistant l'amène ici avec sa
  //    description — on ouvre le panneau segment et on JOUE le tour, comme
  //    s'il venait de le taper. Un seul essai, la clé est consommée.
  mQuery(function () {
    var brief = null;
    try { brief = sessionStorage.getItem('sendlyAiSegmentBrief'); } catch (e) {}
    if (!brief) { return; }
    var tenter = function (restants) {
      var ctx = (window.SendlyAssistantContexts || []).filter(function (c) {
        return c.id === 'segment' && c.available();
      })[0];
      if (!ctx || !window.SendlyAssistant) {
        if (restants > 0) { setTimeout(function () { tenter(restants - 1); }, 500); }
        return;
      }
      try { sessionStorage.removeItem('sendlyAiSegmentBrief'); } catch (e) {}
      window.SendlyAssistant.open('segment');
      setTimeout(function () {
        var input = document.getElementById('sendly-assist-input');
        var send  = document.getElementById('sendly-assist-send');
        if (input && send) {
          input.value = brief;
          send.click();
        }
      }, 400);
    };
    tenter(20);
  });
})();
