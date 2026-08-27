/**
 * Assistant IA — la COQUILLE UNIQUE (directive proprio 07/08).
 *
 * UN seul assistant sur toute la plateforme : même design, même structure,
 * même lanceur flottant partout. Seul le CONTENU s'adapte à l'onglet courant —
 * titre, message d'accueil, raccourcis, et ce que l'assistant sait FAIRE.
 * Chaque écran capable dépose un « contexte » dans le registre
 * window.SendlyAssistantContexts ; l'aide générale (posée ici même) est le
 * contexte par défaut, toujours disponible. Le registre est consulté au clic
 * et à chaque navigation ajax : rien à ré-enregistrer.
 *
 * Contrat d'un contexte :
 *   {
 *     id: 'segment',            // identifiant stable (conversation par id)
 *     priority: 10,             // le plus haut disponible gagne (défaut = 0)
 *     available(): bool,        // suis-je pertinent sur l'écran courant ?
 *     title(): string,          // titre du panneau ET info-bulle du lanceur
 *     welcome(): string,        // message d'accueil (texte brut)
 *     placeholder(): string,    // placeholder du champ de saisie
 *     thinking(): string,       // libellé d'attente (« Analyse… »)
 *     shortcuts(): string[],    // exemples cliquables (panneau « Raccourcis »)
 *     onSend(text, api),        // traite UN tour ; api : voir plus bas
 *     onUndo(turnId, api)?      // annulation d'un tour (si le contexte en pose)
 *   }
 * L'api passée à onSend : { history(n), ia(html, extra), finish(), fail(msg) }
 * — ia() pousse un tour assistant (html DÉJÀ échappé par le contexte ; extra
 * = {turnId, undoable} pour poser un bouton « Annuler les modifications »).
 * L'api passée à onUndo : { markUndone(noteHtml) }.
 *
 * ⚠️ MÊMES PIÈGES QUE TOUTES LES SURFACES IA :
 *  1. mQuery.ajax, JAMAIS fetch() — Mautic intercepte tout POST XHR vers /s/
 *     sans jeton CSRF ; mQuery.ajax porte le jeton automatiquement.
 *  2. Surface GATÉE par la clé : sans window.SendlyAiConfig, ni lanceur ni
 *     panneau — le fichier est agrégé mais inerte.
 */
(function () {
  'use strict';

  var BRAND = '#004FFF';

  function cfg() {
    var c = window.SendlyAiConfig;
    return c && c.enabled ? c : null;
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

  /** Rendu SÛR des réponses d'aide : tout est échappé d'abord, puis un
   *  balisage LÉGER est reconstruit — paragraphes, listes ordonnées ou à
   *  puces, **gras**, et les chemins d'interface « A → B » en pastilles.
   *  Aucun HTML du modèle n'est jamais interprété. */
  function rendreAide(texte) {
    var brut = String(texte == null ? '' : texte).replace(/\r\n/g, '\n').trim();
    var html = '';
    var liste = null;
    var para = [];
    function enLigne(l) {
      var s = esc(l);
      s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
      s = s.replace(/«\s*([^»]*?→[^»]*?)\s*»/g, function (tout, chemin) {
        return '<span class="sendly-assist-chemin">' + chemin.trim() + '</span>';
      });
      return s;
    }
    function fermeListe() {
      if (liste) { html += '</' + liste + '>'; liste = null; }
    }
    function videPara() {
      if (para.length) { html += '<p>' + para.map(enLigne).join('<br>') + '</p>'; para = []; }
    }
    brut.split('\n').forEach(function (l) {
      var ligne = l.trim();
      if (!ligne) { videPara(); fermeListe(); return; }
      var num = ligne.match(/^\d+[.)]\s+(.*)$/);
      var puce = ligne.match(/^[-•]\s+(.*)$/);
      if (num || puce) {
        videPara();
        var type = num ? 'ol' : 'ul';
        if (liste !== type) { fermeListe(); html += '<' + type + '>'; liste = type; }
        html += '<li>' + enLigne((num || puce)[1]) + '</li>';
        return;
      }
      fermeListe();
      para.push(ligne);
    });
    videPara();
    fermeListe();
    return html || '<p></p>';
  }

  function ensureStyles() {
    if (document.getElementById('sendly-assist-style')) {
      return;
    }
    // Le design de référence (capture Webmecanik validée par le proprio sur
    // l'assistant de segment) devient LE design de l'assistant, partout.
    var css =
      '@keyframes sendly-assist-spin{to{transform:rotate(360deg)}}' +
      '.sendly-assist-spin{display:inline-block;animation:sendly-assist-spin .8s linear infinite}' +
      '#sendly-assist-fab{position:fixed;right:24px;bottom:24px;width:52px;height:52px;' +
      'border-radius:50%;border:0;color:#fff;cursor:pointer;z-index:1035;' +
      /* Le fond DA fourni par le proprio : dégradé radial bleu Sendly — bleu
         roi lumineux en haut fondant vers le bleu nuit des bords. */
      'background:radial-gradient(circle at 50% 18%, #0a52d8 0%, #0640a8 42%, #05215c 74%, #021030 100%);' +
      'display:flex;align-items:center;justify-content:center;' +
      'box-shadow:0 10px 26px rgba(0,18,72,.38);transition:transform .12s ease}' +
      '#sendly-assist-fab:hover{transform:scale(1.06)}' +
      '#sendly-assist-panel{position:fixed;right:14px;width:355px;max-width:92vw;background:#fff;' +
      'border:1px solid #e5e7eb;border-radius:12px;z-index:1040;display:flex;flex-direction:column;' +
      'box-shadow:0 16px 40px rgba(22,35,59,.16);overflow:hidden;' +
      /* Surgissement depuis le bouton (directive proprio 27/08) : le panneau
         naît en bas à droite, léger scale + translation + fondu. */
      'transform-origin:bottom right;transition:transform .18s cubic-bezier(.2,.8,.3,1),opacity .16s ease}' +
      '#sendly-assist-panel.sendly-assist-surgit{transform:translateY(10px) scale(.94);opacity:0}' +
      '#sendly-assist-fab:active{transform:scale(.94)}' +
      '@media (prefers-reduced-motion: reduce){#sendly-assist-panel{transition:none}}' +
      '.sendly-assist-head{display:flex;align-items:center;gap:8px;padding:12px 14px;border-bottom:1px solid #eef1f5}' +
      '.sendly-assist-title{margin:0;font-size:15px;font-weight:700;color:' + BRAND + ';flex:1}' +
      '.sendly-assist-temps{font-size:10.5px;font-weight:500;color:#8b95a7;margin-top:1px}' +
      '.sendly-assist-iconbtn{background:none;border:0;cursor:pointer;color:#97a1b3;font-size:15px;padding:2px 5px}' +
      '.sendly-assist-iconbtn:hover{color:#24303f}' +
      '.sendly-assist-conv{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:12px}' +
      '.sendly-assist-turn{display:flex;gap:9px;align-items:flex-start;font-size:13px;line-height:1.5;color:#303a4c}' +
      '.sendly-assist-turn .ri-sparkling-2-line{color:' + BRAND + ';flex:none;margin-top:2px}' +
      '.sendly-assist-turn ul{margin:6px 0 0;padding-left:16px}' +
      '.sendly-assist-turn li{margin-bottom:3px}' +
      '.sendly-assist-me{align-self:flex-end;background:#f2f3f6;color:#303a4c;border-radius:10px;' +
      'padding:8px 11px;max-width:86%;font-size:12.5px;line-height:1.45;white-space:pre-wrap;word-break:break-word}' +
      '.sendly-assist-note{font-size:12px;color:#92400e;background:#fef3c7;border-radius:4px;padding:1px 6px}' +
      '.sendly-assist-mut{color:#6a7486;font-size:12px}' +
      '.sendly-assist-undo{display:inline-flex;align-items:center;gap:6px;margin-top:8px;padding:5px 12px;' +
      'border:1px solid #d5dced;border-radius:999px;background:#fff;color:#303a4c;font-size:12px;cursor:pointer}' +
      '.sendly-assist-undo:hover{border-color:' + BRAND + ';color:' + BRAND + '}' +
      '.sendly-assist-undo[disabled]{opacity:.55;cursor:default;pointer-events:none}' +
      '.sendly-assist-ex{display:flex;flex-wrap:wrap;gap:6px;padding:0 14px 8px}' +
      '.sendly-assist-ex button{font-size:12px;background:#fff;color:' + BRAND + ';border:1px solid #d9e2f2;' +
      'padding:3px 10px;border-radius:999px;cursor:pointer}' +
      '.sendly-assist-ex button:hover{background:#f0f5ff}' +
      '.sendly-assist-foot{display:flex;gap:8px;padding:0 14px 8px}' +
      '.sendly-assist-fait{margin-top:8px;font-size:12px;font-weight:600;color:#0a7d43}' +
      '#sendly-assist-input{flex:1;border:1px solid #d5dced;border-radius:999px;padding:8px 14px;font-size:13px;background:#f7f8fa}' +
      '#sendly-assist-input:focus{outline:none;border-color:' + BRAND + ';background:#fff}' +
      '#sendly-assist-send{border:0;border-radius:50%;width:34px;height:34px;flex:none;background:' + BRAND + ';' +
      'color:#fff;cursor:pointer;display:inline-flex;align-items:center;justify-content:center}' +
      '#sendly-assist-send[disabled]{opacity:.5;cursor:default}' +
      '.sendly-assist-meta{display:flex;justify-content:space-between;padding:0 14px 12px;color:#97a1b3;font-size:11.5px}' +
      '.sendly-assist-meta a{color:#97a1b3;cursor:pointer}' +
      '.sendly-assist-meta a:hover{color:' + BRAND + '}' +
      // Réponses d'aide COMPOSÉES (retour proprio 14/08 : « un gros texte
      // sans aucune propreté ») : paragraphes, listes, gras, et les chemins
      // d'interface en pastilles.
      '.sendly-assist-turn p{margin:0 0 8px}' +
      '.sendly-assist-turn p:last-child{margin-bottom:0}' +
      '.sendly-assist-turn ol,.sendly-assist-turn ul{margin:2px 0 8px;padding-left:18px;display:grid;gap:5px}' +
      '.sendly-assist-turn ol:last-child,.sendly-assist-turn ul:last-child{margin-bottom:0}' +
      '.sendly-assist-turn strong{color:#001248;font-weight:600}' +
      '.sendly-assist-chemin{display:inline-block;background:#f0f5ff;color:' + BRAND + ';border-radius:6px;' +
      'padding:0 6px;font-weight:600;font-size:12px;line-height:1.7;white-space:nowrap}';
    var style = document.createElement('style');
    style.id = 'sendly-assist-style';
    style.textContent = css;
    document.head.appendChild(style);
  }

  // ── Registre des contextes ─────────────────────────────────────────────

  function contexts() {
    return window.SendlyAssistantContexts || [];
  }

  function activeContext() {
    var best = null;
    contexts().forEach(function (ctx) {
      try {
        if (!ctx.available()) {
          return;
        }
        if (!best || (ctx.priority || 0) > (best.priority || 0)) {
          best = ctx;
        }
      } catch (e) {
        // Un contexte cassé ne doit jamais tuer le lanceur.
      }
    });
    return best;
  }

  // ── État : une conversation PAR contexte ───────────────────────────────

  /** convStore[id] = [{role:'user', text} | {role:'ia', html, turnId?, undoable?}] */
  var convStore = {};
  var busy = false;
  var openCtx = null;

  function conv() {
    if (!openCtx) {
      return [];
    }
    if (!convStore[openCtx.id]) {
      convStore[openCtx.id] = [];
    }
    return convStore[openCtx.id];
  }

  function panel() {
    return document.getElementById('sendly-assist-panel');
  }

  function renderConv() {
    var el = document.getElementById('sendly-assist-conv');
    if (!el || !openCtx) {
      return;
    }
    var html = '<div class="sendly-assist-turn"><i class="ri-sparkling-2-line"></i><div>' +
      esc(openCtx.welcome()) + '</div></div>';
    conv().forEach(function (turn) {
      if (turn.role === 'user') {
        html += '<div class="sendly-assist-me">' + esc(turn.text) + '</div>';
        return;
      }
      html += '<div class="sendly-assist-turn"><i class="ri-sparkling-2-line"></i><div>' + turn.html;
      if (turn.turnId) {
        html += '<br><button type="button" class="sendly-assist-undo" data-turn="' + turn.turnId + '"' +
          (turn.undoable ? '' : ' disabled') + '><i class="ri-arrow-go-back-line"></i> ' +
          esc(t('mautic.core.ai.assistant.undo', 'Annuler les modifications')) + '</button>';
      }
      html += '</div></div>';
    });
    if (busy) {
      html += '<div class="sendly-assist-turn"><i class="ri-sparkling-2-line"></i><div>' +
        '<i class="ri-loader-4-line sendly-assist-spin"></i> ' + esc(openCtx.thinking()) + '</div></div>';
    }
    el.innerHTML = html;
    el.scrollTop = el.scrollHeight;
  }

  /** L'api donnée aux contextes pour un tour d'envoi. */
  function sendApi() {
    return {
      history: function (n) {
        return conv()
          .filter(function (turn) { return turn.role === 'user'; })
          .slice(-n)
          .map(function (turn) { return turn.text; });
      },
      ia: function (html, extra) {
        var turn = { role: 'ia', html: html };
        if (extra && extra.turnId) {
          turn.turnId = extra.turnId;
          turn.undoable = !!extra.undoable;
        }
        conv().push(turn);
      },
      finish: function () {
        busy = false;
        renderConv();
      },
      fail: function (msg) {
        busy = false;
        conv().push({ role: 'ia', html: esc(msg) });
        renderConv();
      }
    };
  }

  function send(text) {
    var q = String(text || '').trim();
    if (!q || busy || !openCtx) {
      return;
    }
    // L'api capture le contexte du tour : si l'utilisateur navigue pendant la
    // requête, la réponse rejoint la BONNE conversation, pas celle du nouvel
    // écran.
    var api = sendApi();
    conv().push({ role: 'user', text: q });
    busy = true;
    renderConv();
    var input = document.getElementById('sendly-assist-input');
    if (input) {
      input.value = '';
    }
    openCtx.onSend(q, api);
  }

  function closePanel() {
    var p = panel();
    if (p) {
      p.classList.add('sendly-assist-surgit');
      p.id = ''; // le mourant ne répond plus aux sélecteurs (réouverture immédiate)
      setTimeout(function () { if (p.parentNode) { p.parentNode.removeChild(p); } }, 160);
    }
    openCtx = null;
    busy = false;
  }

  function openPanel(ctx) {
    if (panel()) {
      closePanel();
    }
    ensureStyles();
    openCtx = ctx;

    var el = document.createElement('div');
    el.id = 'sendly-assist-panel';
    el.setAttribute('role', 'dialog');
    el.className = 'sendly-assist-surgit';
    el.innerHTML =
      '<div class="sendly-assist-head">' +
      '<h4 class="sendly-assist-title" id="sendly-assist-title">' + esc(ctx.title()) + '</h4>' +
      '<button type="button" class="sendly-assist-iconbtn" id="sendly-assist-clear" aria-label="' +
      esc(t('mautic.core.ai.assistant.clear', 'Vider la conversation')) + '"><i class="ri-delete-bin-line"></i></button>' +
      '<button type="button" class="sendly-assist-iconbtn" id="sendly-assist-close" aria-label="' +
      esc(t('mautic.core.form.close', 'Fermer')) + '">✕</button>' +
      '</div>' +
      '<div class="sendly-assist-conv" id="sendly-assist-conv"></div>' +
      '<div class="sendly-assist-ex" id="sendly-assist-ex" style="display:none">' +
      ctx.shortcuts().map(function (ex) {
        return '<button type="button">' + esc(ex) + '</button>';
      }).join('') + '</div>' +
      '<div class="sendly-assist-foot">' +
      '<input id="sendly-assist-input" type="text" placeholder="' + esc(ctx.placeholder()) + '">' +
      '<button type="button" id="sendly-assist-send" aria-label="' +
      esc(t('mautic.core.ai.assistant.send', 'Envoyer')) + '"><i class="ri-arrow-up-line"></i></button>' +
      '</div>' +
      '<div class="sendly-assist-meta">' +
      '<span>' + esc(t('mautic.core.ai.assistant.private', 'Vos données restent privées.')) + '</span>' +
      '<a id="sendly-assist-shortcuts"><i class="ri-question-line"></i> ' +
      esc(t('mautic.core.ai.assistant.shortcuts', 'Raccourcis')) + '</a>' +
      '</div>';

    // Ancré sous la barre haute, quelle que soit sa hauteur réelle.
    var header = document.getElementById('app-header');
    var top = header ? header.getBoundingClientRect().bottom : 56;
    el.style.top = (Math.max(0, top) + 12) + 'px';
    el.style.bottom = '14px';
    document.body.appendChild(el);
    // Deux cadres : l'état initial doit peindre avant la transition.
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { el.classList.remove('sendly-assist-surgit'); });
    });

    // Le temps rendu ce mois-ci — l'argument du plan rendu visible. Ligne
    // discrète sous le titre, seulement s'il y a quelque chose à dire.
    mQuery.ajax({ url: '/s/ai/credit/stats', type: 'GET', dataType: 'json' })
      .done(function (rep) {
        var s = rep && rep.seconds_month;
        if (!s || s < 60) { return; }
        var tete = el.querySelector('.sendly-assist-head');
        var titre = el.querySelector('#sendly-assist-title');
        if (!tete || !titre) { return; }
        var h = Math.floor(s / 3600), m = Math.round((s % 3600) / 60);
        var texte = h > 0 ? ('~' + h + ' h' + (m ? ' ' + (m < 10 ? '0' : '') + m : '')) : ('~' + m + ' min');
        var ligne = document.createElement('div');
        ligne.className = 'sendly-assist-temps';
        ligne.textContent = '⏱ ' + texte + ' rendues ce mois-ci';
        titre.appendChild(ligne);
      });

    el.querySelector('#sendly-assist-close').addEventListener('click', closePanel);
    el.querySelector('#sendly-assist-clear').addEventListener('click', function () {
      convStore[openCtx.id] = [];
      renderConv();
    });
    el.querySelector('#sendly-assist-send').addEventListener('click', function () {
      send(document.getElementById('sendly-assist-input').value);
    });
    el.querySelector('#sendly-assist-input').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        send(this.value);
      }
    });
    el.querySelector('#sendly-assist-shortcuts').addEventListener('click', function () {
      var ex = document.getElementById('sendly-assist-ex');
      ex.style.display = ex.style.display === 'none' ? 'flex' : 'none';
    });
    mQuery(el).on('click', '.sendly-assist-ex button', function () {
      send(this.textContent);
      document.getElementById('sendly-assist-ex').style.display = 'none';
    });
    mQuery(el).on('click', '.sendly-assist-undo', function () {
      var turnId = mQuery(this).data('turn');
      if (!openCtx || typeof openCtx.onUndo !== 'function') {
        return;
      }
      openCtx.onUndo(turnId, {
        markUndone: function (noteHtml) {
          conv().forEach(function (turn) {
            if (turn.turnId === turnId) {
              turn.undoable = false;
              turn.html += '<div class="sendly-assist-mut">' + noteHtml + '</div>';
            }
          });
          renderConv();
        }
      });
    });

    renderConv();
    var input = document.getElementById('sendly-assist-input');
    if (input) {
      input.focus();
    }
  }

  function toggle() {
    if (panel()) {
      closePanel();
      return;
    }
    var ctx = activeContext();
    if (ctx) {
      openPanel(ctx);
    }
  }

  // L'info-bulle du lanceur suit l'onglet (« Assistant de segment » sur
  // l'écran segment, « Assistant IA » ailleurs) — comme le titre du panneau.
  function refreshLabel() {
    var fab = document.getElementById('sendly-assist-fab');
    if (!fab) {
      return;
    }
    var ctx = activeContext();
    var label = ctx ? ctx.title() : t('mautic.core.ai.assistant.button', 'Assistant IA');
    fab.setAttribute('aria-label', label);
    fab.setAttribute('title', label);
  }

  /** Petite façade publique pour les contextes (reset après navigation…). */
  window.SendlyAssistant = {
    /** Crédite le compteur de temps gagné (barème CÔTÉ SERVEUR — le client
     *  dit ce qui s'est passé, jamais combien ça vaut). Feu-et-oublie. */
    credit: function (type, quantite) {
      try {
        mQuery.ajax({
          url: '/s/ai/credit', type: 'POST', dataType: 'json',
          data: JSON.stringify({ type: type, quantite: quantite || 1 }),
          contentType: 'application/json'
        });
      } catch (e) { /* le crédit est du bonus, jamais du chemin critique */ }
    },
    /** Une ligne d'état dans la conversation ouverte (échecs de création :
     *  la raison est DITE — référence introuvable, horaire invalide…). */
    note: function (texte) {
      var conv = document.getElementById('sendly-assist-conv');
      if (!conv) { return; }
      var div = document.createElement('div');
      div.className = 'sendly-assist-mut';
      div.textContent = texte;
      conv.appendChild(div);
      conv.scrollTop = conv.scrollHeight;
    },
    /** Vide la conversation d'un contexte ; ferme le panneau s'il l'affiche. */
    reset: function (ctxId) {
      delete convStore[ctxId];
      if (openCtx && openCtx.id === ctxId) {
        closePanel();
      }
    },
    /** Ouvre le panneau sur un contexte précis (chantier D, P5 : la tuile
     *  « Assistant IA » de l'éditeur est la seule entrée IA — le lanceur
     *  flottant y est masqué, il fallait une porte programmatique). */
    open: function (ctxId) {
      var ctx = contexts().filter(function (c) { return c.id === ctxId && c.available(); })[0];
      if (ctx) {
        openPanel(ctx);
      }
    }
  };

  // ── Contexte PAR DÉFAUT : l'aide générale (priorité 0) ─────────────────
  // L'ACCOMPAGNEMENT SUIT L'ÉCRAN (exigence produit 12/08 — « tout le but
  // de cet assistant ») : le titre, l'accueil et les raccourcis de l'aide
  // se calquent sur la section où l'utilisateur se trouve, et la section
  // part AU SERVEUR pour des réponses contextualisées.
  var SECTIONS_AIDE = [
    [/\/s\/contacts/, 'Contacts', 'vos contacts : création, import CSV, historique, dédoublonnage', ['Comment importer des contacts en CSV ?', 'Comment fusionner des doublons ?', 'Comment voir l\'historique d\'un contact ?']],
    [/\/s\/companies/, 'Sociétés', 'vos sociétés : création, association de contacts', ['Comment associer un contact à une société ?', 'Comment importer des sociétés ?']],
    [/\/s\/segments/, 'Segments', 'vos segments : filtres, combinaisons, mise à jour', ['Comment créer un segment dynamique ?', 'Pourquoi mon segment est-il vide ?', 'Comment combiner plusieurs filtres ?']],
    [/\/s\/campaigns/, 'Campagnes', 'vos campagnes : scénarios, déclencheurs, planification', ['Comment lancer ma première campagne ?', 'Comment ajouter une condition dans un scénario ?', 'Pourquoi ma campagne ne se déclenche pas ?']],
    [/\/s\/emails/, 'E-mails', 'vos e-mails : création, envoi, A/B test, délivrabilité', ['Comment faire un A/B test d\'objet ?', 'Comment améliorer ma délivrabilité ?', 'Quelle différence entre e-mail segment et e-mail modèle ?']],
    [/\/s\/sms/, 'SMS', 'vos SMS : rédaction, envoi via votre connecteur (Twilio…)', ['Comment envoyer un SMS à un segment ?', 'Comment configurer le connecteur SMS ?', 'Comment personnaliser un SMS avec des jetons ?']],
    [/\/s\/forms/, 'Formulaires', 'vos formulaires : champs, actions, intégration', ['Comment intégrer un formulaire sur mon site ?', 'Comment déclencher une action après soumission ?']],
    [/\/s\/pages/, 'Pages', 'vos pages d\'atterrissage : générateur, publication, aperçu', ['Comment publier ma page ?', 'Comment ajouter un formulaire à ma page ?', 'Comment dupliquer une page ?']],
    [/\/s\/assets/, 'Ressources', 'vos ressources : fichiers téléchargeables et suivi', ['Comment suivre les téléchargements d\'un fichier ?']],
    [/\/s\/dwc/, 'Contenu web dynamique', 'le contenu web dynamique : blocs personnalisés par segment', ['Comment afficher un contenu différent selon le segment ?', 'Comment intégrer un bloc dynamique sur mon site ?']],
    [/\/s\/points/, 'Points', 'les points : scoring des contacts et déclencheurs', ['Comment attribuer des points à une ouverture d\'e-mail ?', 'Comment créer un déclencheur de points ?']],
    [/\/s\/stages/, 'Stages', 'les stages : étapes du cycle de vie de vos contacts', ['Comment faire avancer un contact de stage ?']],
    [/\/s\/reports/, 'Rapports', 'vos rapports : sources de données, colonnes, planification', ['Comment créer un rapport d\'ouvertures ?', 'Comment recevoir un rapport par e-mail chaque semaine ?']],
  ];
  function sectionCourante() {
    var chemin = window.location.pathname;
    for (var i = 0; i < SECTIONS_AIDE.length; i++) {
      if (SECTIONS_AIDE[i][0].test(chemin)) {
        return { nom: SECTIONS_AIDE[i][1], sujet: SECTIONS_AIDE[i][2], raccourcis: SECTIONS_AIDE[i][3] };
      }
    }
    return null;
  }

  // ── MODE EXÉCUTANT (directive proprio 26/08 : « un assistant qui FAIT
  //    gagner du temps, pas un guide ») ────────────────────────────────────
  // Le panneau décrit l'écran au serveur (screen_state) et déclare ce qu'il
  // sait EXÉCUTER ; le serveur renvoie des actions validées ; on les joue
  // ici, avec instantané par tour pour « Annuler les modifications ».

  /** Cible → chemin (le serveur porte la même liste blanche). */
  var NAV_CIBLES = {
    segments: '/s/segments', segments_new: '/s/segments/new',
    emails: '/s/emails', emails_new: '/s/emails/new',
    campaigns: '/s/campaigns', campaigns_new: '/s/campaigns/new',
    contacts: '/s/contacts', contacts_import: '/s/contacts/import/new',
    companies: '/s/companies',
    forms: '/s/forms', forms_new: '/s/forms/new',
    pages: '/s/pages', pages_new: '/s/pages/new',
    assets: '/s/assets', points: '/s/points', stages: '/s/stages',
    reports: '/s/reports', sms: '/s/sms'
  };

  /** Le formulaire de TRAVAIL de l'écran : le plus riche en champs visibles,
   *  hors panneau assistant et hors recherche. */
  function formulaireTravail() {
    var meilleur = null, score = 0;
    mQuery('form').each(function () {
      if (mQuery(this).closest('#sendly-assist-panel').length) { return; }
      var n = mQuery(this).find('input[name], select[name], textarea[name]')
        .filter(':not([type=hidden]):not([type=submit]):not([type=button]):not([name*="csrf"]):not([name*="_token"])')
        .filter(':visible').length;
      if (n > score) { score = n; meilleur = this; }
    });
    return meilleur;
  }

  function libelleChamp(el) {
    var id = el.getAttribute('id');
    if (id) {
      var lab = document.querySelector('label[for="' + id + '"]');
      if (lab) { return lab.textContent.trim().slice(0, 60); }
    }
    return '';
  }

  /** L'état de l'écran, en texte sobre : un champ par ligne. */
  function etatEcran() {
    var form = formulaireTravail();
    if (!form) { return { texte: '', champs: 0 }; }
    var lignes = [], count = 0;
    // selects : leurs OPTIONS sont le vocabulaire — sans elles le modèle
    // devine des valeurs invalides. Les listes identiques ne partent qu'UNE
    // fois (l'écran d'import répète la même liste sur chaque colonne).
    var listes = {}, ordreListes = [];
    function refListe(sel) {
      var opts = [];
      mQuery(sel).find('option').each(function () {
        if (opts.length >= 40) { return false; }
        var val = this.value;
        if ('' === val) { return; }
        var lib = (this.textContent || '').trim().slice(0, 30);
        opts.push(val === lib ? val : val + '=' + lib);
      });
      if (!opts.length) { return ''; }
      var signature = opts.join('|');
      if (!listes[signature]) {
        listes[signature] = 'L' + (ordreListes.length + 1);
        ordreListes.push({ id: listes[signature], opts: opts });
      }
      return listes[signature];
    }
    mQuery(form).find('input[name], select[name], textarea[name]')
      .filter(':not([type=hidden]):not([type=submit]):not([type=button]):not([type=password]):not([name*="csrf"]):not([name*="_token"])')
      .filter(':visible')
      .each(function () {
        if (count >= 25) { return false; }
        var v = String(mQuery(this).val() == null ? '' : mQuery(this).val()).slice(0, 160);
        var lab = libelleChamp(this);
        var ref = this.tagName === 'SELECT' ? refListe(this) : '';
        lignes.push('- name="' + this.name + '"' + (lab ? ' label="' + lab + '"' : '') +
          (ref ? ' options=@' + ref : '') +
          (v ? ' value="' + v + '"' : ' (empty)'));
        count++;
      });
    ordreListes.forEach(function (l) {
      lignes.push('@' + l.id + ': ' + l.opts.join(' | '));
    });
    return {
      texte: count ? 'Screen: ' + window.location.pathname + '\nForm fields:\n' + lignes.join('\n') : '',
      champs: count
    };
  }

  /** snapshots[turnId] = [{name, old}] pour l'annulation. */
  var snapshots = {};

  function poserChamp(form, name, value, releve) {
    var el = mQuery(form).find('[name="' + name + '"]').filter(':not([type=hidden])').first();
    if (!el.length) { return false; }
    releve.push({ name: name, old: el.val() });
    el.val(value).trigger('change').trigger('keyup');
    // Chosen (selects Mautic) : rafraîchir le rendu.
    if (el.is('select')) { el.trigger('chosen:updated'); }
    return true;
  }

  /** Joue les actions du serveur ; renvoie {note, undoable} pour le tour. */
  function executerActions(actions, turnId) {
    var form = formulaireTravail();
    var remplis = 0, releve = [], differes = [], creationsEntite = [];
    (actions || []).forEach(function (a) {
      if (a.type === 'fill_field' && form) {
        if (poserChamp(form, a.field, a.value, releve)) { remplis++; }
      } else if (a.type === 'navigate' && NAV_CIBLES[a.target]) {
        differes.push(NAV_CIBLES[a.target]);
      } else if (a.type === 'create_segment' && a.description) {
        try { sessionStorage.setItem('sendlyAiSegmentBrief', a.description); } catch (e) {}
        differes.push(NAV_CIBLES.segments_new);
      } else if (a.type === 'create_email' && a.description && a.name && a.subject) {
        // Même patron que la landing page : le brief voyage jusqu'à l'écran
        // « nouvel e-mail », qui choisit le type segment, remplit nom/objet,
        // fait correspondre le segment et ouvre l'éditeur (ai-email-builder).
        try {
          sessionStorage.setItem('sendlyAiEmailBrief', JSON.stringify({
            name: a.name, subject: a.subject, brief: a.description,
            segment: a.segment || '', ts: Date.now()
          }));
        } catch (e) {}
        differes.push(NAV_CIBLES.emails_new);
      } else if (a.type === 'create_campaign' && a.name && a.email && a.segment) {
        // Même voie que create_form : endpoint gardé, entité DÉPUBLIÉE,
        // écran d'édition ouvert pour relecture du canvas.
        creationsEntite.push({ url: '/s/ai/campaign/create', spec: a, edition: '/s/campaigns/edit/' });
      } else if (a.type === 'create_report' && a.name && a.source) {
        creationsEntite.push({ url: '/s/ai/report/create', spec: a, edition: '/s/reports/edit/' });
      } else if (a.type === 'create_form' && a.name && a.fields) {
        // La création passe par l'endpoint gardé (form:forms:create) : le
        // formulaire naît DÉPUBLIÉ, puis on ouvre son écran d'édition pour
        // la relecture. Même barrière de validation côté serveur.
        creationsEntite.push({ url: (cfg().formCreateEndpoint || '/s/ai/form/create'), spec: a, edition: '/s/forms/edit/' });
      } else if (a.type === 'create_landing_page' && a.description && a.name) {
        // Le brief voyage jusqu'à l'écran « nouvelle page », qui crée la
        // page, ouvre le builder et génère les sections (ai-page-builder).
        try {
          sessionStorage.setItem('sendlyAiPageBrief', JSON.stringify({
            name: a.name, brief: a.description, sections: a.sections || [], ts: Date.now()
          }));
        } catch (e) {}
        differes.push(NAV_CIBLES.pages_new);
      }
    });
    if (releve.length) { snapshots[turnId] = { champs: releve }; }
    if (remplis && window.SendlyAssistant) { window.SendlyAssistant.credit('fill_field', remplis); }
    if (creationsEntite.length) {
      // La création (endpoint gardé) prime sur toute autre navigation du
      // tour : l'entité naît dépubliée, on ouvre son écran d'édition. En
      // échec de résolution (référence introuvable/ambiguë), la raison est
      // DITE dans le panneau — jamais d'invention.
      var creation = creationsEntite[0];
      mQuery.ajax({
        url: creation.url,
        type: 'POST',
        dataType: 'json',
        data: JSON.stringify(creation.spec),
        contentType: 'application/json',
        success: function (rep) {
          if (rep && rep.id) { window.location.assign(creation.edition + rep.id); }
        },
        error: function (xhr) {
          var rep = xhr && xhr.responseJSON;
          var raisons = {
            email_not_found: 'e-mail introuvable',
            email_ambiguous: 'plusieurs e-mails portent ce nom',
            segment_not_found: 'segment introuvable',
            segment_ambiguous: 'plusieurs segments portent ce nom',
            bad_send_at: 'horaire invalide (passé ou trop lointain)'
          };
          var motif = rep && raisons[rep.error];
          if (motif && window.SendlyAssistant && window.SendlyAssistant.note) {
            window.SendlyAssistant.note('✗ ' + motif + (rep.ref ? ' : « ' + rep.ref + ' »' : ''));
          }
        }
      });
    } else if (differes.length) {
      // Une seule navigation par tour, après un court instant de lecture.
      setTimeout(function () { window.location.assign(differes[0]); }, 1200);
    }
    var notes = [];
    if (remplis) {
      notes.push('✓ ' + remplis + ' ' + (remplis > 1 ? 'champs remplis' : 'champ rempli'));
    }
    if (creationsEntite.length) {
      notes.push('✓ ' + t('mautic.core.ai.assistant.entity_created', 'création en cours (dépubliée) — relecture…'));
    } else if (differes.length) {
      notes.push('→ ' + t('mautic.core.ai.assistant.opening', 'ouverture de l\'écran…'));
    }
    return { note: notes.join(' · '), undoable: releve.length > 0 };
  }

  function annulerTour(turnId) {
    var snap = snapshots[turnId];
    if (!snap) { return false; }
    var form = formulaireTravail();
    (snap.champs || []).forEach(function (c) {
      var el = mQuery(form || document).find('[name="' + c.name + '"]').first();
      if (el.length) {
        el.val(c.old).trigger('change');
        if (el.is('select')) { el.trigger('chosen:updated'); }
      }
    });
    delete snapshots[turnId];
    return true;
  }

  window.SendlyAssistantContexts = window.SendlyAssistantContexts || [];
  window.SendlyAssistantContexts.push({
    id: 'help',
    priority: 0,
    available: function () {
      var c = cfg();
      return !!(c && c.assistEndpoint);
    },
    title: function () {
      var s = sectionCourante();
      return s ? 'Assistant ' + s.nom : t('mautic.core.ai.assistant.button', 'Assistant IA');
    },
    welcome: function () {
      var s = sectionCourante();
      if (s) {
        return 'Bonjour ! Vous êtes dans « ' + s.nom + ' » — posez-moi une question sur ' + s.sujet + '.';
      }
      return t('mautic.core.ai.assistant.welcome',
        'Bonjour ! Posez-moi une question sur Sendly : segments, campagnes, e-mails, délivrabilité…');
    },
    placeholder: function () {
      return t('mautic.core.ai.assistant.placeholder', 'Posez votre question…');
    },
    thinking: function () {
      return t('mautic.core.ai.assistant.thinking', 'Je réfléchis…');
    },
    shortcuts: function () {
      var s = sectionCourante();
      if (s) { return s.raccourcis; }
      return [
        t('mautic.core.ai.assistant.suggest1', 'Comment créer un segment ?'),
        t('mautic.core.ai.assistant.suggest2', 'Comment améliorer ma délivrabilité ?'),
        t('mautic.core.ai.assistant.suggest3', 'Comment lancer ma première campagne ?')
      ];
    },
    onSend: function (q, api) {
      // L'historique du service d'aide alterne user/assistant : on reconstruit
      // les tours assistant depuis leur texte (les réponses d'aide sont du
      // texte brut échappé, jamais du balisage riche).
      var history = [];
      conv().slice(-6).forEach(function (turn) {
        if (turn.role === 'user') {
          history.push({ role: 'user', content: turn.text });
        } else if (turn.plain) {
          history.push({ role: 'assistant', content: turn.plain });
        }
      });
      // Le tour utilisateur courant est déjà dans conv() : on le retire de
      // l'historique envoyé, le serveur reçoit la question séparément.
      history = history.filter(function (h, i, arr) {
        return !(i === arr.length - 1 && h.role === 'user' && h.content === q);
      });
      var etat = etatEcran();
      var capacites = ['navigate', 'create_segment', 'create_landing_page', 'create_email', 'create_form', 'create_campaign', 'create_report'];
      if (etat.champs > 0) { capacites.push('fill_field'); }
      mQuery.ajax({
        url: cfg().assistEndpoint,
        type: 'POST',
        dataType: 'json',
        data: {
          question: q, history: history,
          lang: document.documentElement.lang || 'fr',
          section: (sectionCourante() || {}).nom || '',
          context: etat.texte,
          actions: capacites
        },
        success: function (res) {
          if (res && res.answer) {
            var answer = String(res.answer);
            var turnId = res.turnId || null;
            var resultat = executerActions(res.actions, turnId);
            var html = rendreAide(answer);
            if (resultat.note) {
              html += '<div class="sendly-assist-fait">' + esc(resultat.note) + '</div>';
            }
            conv().push({
              role: 'ia', html: html, plain: answer,
              turnId: resultat.undoable ? turnId : null,
              undoable: resultat.undoable
            });
            busy = false;
            renderConv();
          } else {
            api.fail(t('mautic.core.ai.assistant.error', 'La requête a échoué. Réessayez.'));
          }
        },
        error: function () {
          api.fail(t('mautic.core.ai.assistant.error', 'La requête a échoué. Réessayez.'));
        }
      });
    },
    onUndo: function (turnId, api) {
      if (annulerTour(turnId)) {
        api.markUndone(esc(t('mautic.core.ai.assistant.undone', 'Modifications annulées.')));
      }
    }
  });

  /** Le lanceur : bouton chatbot FLOTTANT en bas à droite, fond dégradé
   *  radial Sendly, étincelles fournies par la DA — le MÊME partout. */
  function teaserActif() {
    return !!(window.SendlyAiConfig && window.SendlyAiConfig.teaser);
  }

  function injectLauncher() {
    if (!cfg() && !teaserActif()) {
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
    fab.addEventListener('click', function () {
      // Teaser (plan gratuit) : le lanceur existe, l'action propose le plan.
      if (teaserActif()) {
        if (window.SendlyAiUpsell) { window.SendlyAiUpsell.ouvrir('aide'); }
        return;
      }
      toggle();
    });
    document.body.appendChild(fab);
    refreshLabel();

    // Navigation interne = ajax : l'onglet change sans rechargement — le
    // libellé du lanceur suit, et un panneau ouvert sur un contexte qui n'est
    // plus celui de l'écran se referme (sa conversation reste en mémoire).
    mQuery(document).ajaxComplete(function () {
      refreshLabel();
      if (openCtx) {
        var ctx = activeContext();
        if (!ctx || ctx.id !== openCtx.id) {
          closePanel();
        }
      }
    });
  }

  mQuery(function () {
    injectLauncher();

    // ⌘J / Ctrl+J : ouvre le panneau du contexte courant, champ en saisie.
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
