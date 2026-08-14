<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Le pont instance → portail — les règles qui le portent :
 *  - le paramètre `saas_portal_url` existe avec un défaut non vide (sinon le
 *    menu disparaît silencieusement de toutes les instances) ;
 *  - la barre haute monte bien le partial (le retirer d'un refactor de
 *    navbar ferait disparaître la couture sans autre signal) ;
 *  - le partial se referme entièrement quand l'URL est vide, et ses liens
 *    sont SANS préfixe de langue (le portail next-intl choisit la locale —
 *    un /fr/ codé en dur enverrait les anglophones au mauvais endroit).
 */
final class PortalMenuTest extends TestCase
{
    private const CONFIG  = __DIR__.'/../../Config/config.php';
    private const NAVBAR  = __DIR__.'/../../../../app/bundles/CoreBundle/Resources/views/Default/navbar.html.twig';
    private const PARTIAL = __DIR__.'/../../../../app/bundles/CoreBundle/Resources/views/Menu/portal.html.twig';
    private const PROFILE = __DIR__.'/../../../../app/bundles/CoreBundle/Resources/views/Menu/profile.html.twig';

    public function testLeParametreExisteAvecUnDefautNonVide(): void
    {
        $config = require self::CONFIG;

        self::assertArrayHasKey('parameters', $config);
        self::assertArrayHasKey('saas_portal_url', $config['parameters']);
        self::assertNotSame('', trim((string) $config['parameters']['saas_portal_url']));
    }

    public function testLaBarreHauteMonteLePartial(): void
    {
        $navbar = (string) file_get_contents(self::NAVBAR);

        self::assertStringContainsString('@MauticCore/Menu/portal.html.twig', $navbar);
    }

    public function testLePartialSeRefermeSansUrlEtNImposePasLaLocale(): void
    {
        $partial = (string) file_get_contents(self::PARTIAL);

        // Garde : URL vide = aucun vestige à l'écran.
        self::assertStringContainsString("configGetParameter('saas_portal_url')", $partial);
        self::assertStringContainsString('{% if portalUrl %}', $partial);

        // Les liens laissent le portail choisir la locale.
        self::assertStringContainsString('{{ portalUrl }}/dashboard/organization', $partial);
        self::assertStringNotContainsString('/fr/dashboard', $partial);

        // Les libellés passent par la traduction, jamais en dur.
        self::assertStringContainsString("'eweb.saas.portal.portal_title'|trans", $partial);
    }

    public function testLePanneauListeLesLogicielsSansLierLApplicationCourante(): void
    {
        // Le motif « Vos logiciels » repris à l'identique (2e itération,
        // capture du proprio) : l'application courante est LISTÉE mais pas
        // liée — un lien vers soi-même se lit comme un bug — et le portail
        // s'ouvre, lui, par un vrai lien.
        $partial = (string) file_get_contents(self::PARTIAL);

        self::assertStringContainsString("'eweb.saas.portal.marketing_title'|trans", $partial);
        self::assertStringContainsString("'eweb.saas.portal.current'|trans", $partial);
        self::assertSame(
            1,
            substr_count($partial, '<a class="sendly-soft-row"'),
            'une seule ligne du panneau doit être un lien : le portail'
        );
    }

    public function testLesTuilesPortentLesGlyphesDuPortailPasLeMonogramme(): void
    {
        // 3e itération (demande proprio) : le monogramme de marque était
        // illisible en tuile — les glyphes du portail le remplacent, inlinés
        // au trait. Le monogramme ne doit pas revenir, ni dans le panneau ni
        // dans le header mobile (qui porte désormais le wordmark).
        $partial = (string) file_get_contents(self::PARTIAL);
        self::assertStringNotContainsString('logo--minimized.svg', $partial);
        // P-1 « teinte douce » (13/08) : le trait prend la couleur du
        // logiciel — bleu marketing, encre portail — sur fond teinté clair.
        self::assertSame(1, substr_count($partial, 'stroke="#004FFF"'), 'le glyphe marketing au trait bleu');
        self::assertSame(1, substr_count($partial, 'stroke="#16233b"'), 'le glyphe portail au trait encre');
        self::assertStringNotContainsString('stroke="#fff"', $partial, 'plus de trait blanc : les aplats satures ont disparu');

        $navbar = (string) file_get_contents(self::NAVBAR);
        self::assertStringContainsString('brand-logo--mobile', $navbar);
        $mobile = (string) substr($navbar, (int) strpos($navbar, 'brand-logo--mobile'));
        self::assertStringContainsString('logo--expanded.svg', $mobile, 'le header mobile porte le wordmark');
        self::assertStringNotContainsString('logo--minimized.svg', $mobile, 'le monogramme ne doit pas revenir en mobile');
    }

    public function testLeProfilAfficheAvatarNomEtChevron(): void
    {
        // L'identité visible du header de référence (capture proprio) :
        // avatar rond (photo, repli initiales) + nom + chevron — pas une
        // icône anonyme.
        $profile = (string) file_get_contents(self::PROFILE);

        self::assertStringContainsString('sendly-avatar', $profile);
        self::assertStringContainsString('gravatarGetImage(app.getUser().getEmail())', $profile);
        self::assertStringContainsString('sendly-profile-name', $profile);
        self::assertStringContainsString('ri-arrow-down-s-line', $profile);
        self::assertStringNotContainsString('ri-account-circle-line', $profile, 'l’icône anonyme ne doit pas revenir');
    }

    public function testLesAvatarsGravatarSontParesseux(): void
    {
        // Le header est sur TOUTES les pages : une image externe qui participe
        // a l'evenement load y retarde CHAQUE navigation quand gravatar.com est
        // injoignable (e2e isole, reseau d'entreprise filtre). Preuve du
        // 07/08 : 7 tests e2e en timeout sur 3 runs, gueris par loading="lazy"
        // (exclu du load par specification). Les initiales restent le repli.
        $twig = (string) file_get_contents(self::PROFILE);

        $imgs = preg_match_all('/<img[^>]*gravatarGetImage[^>]*>/', $twig, $m);
        self::assertGreaterThanOrEqual(1, $imgs, 'l avatar gravatar doit exister');
        foreach ($m[0] as $img) {
            self::assertStringContainsString('loading="lazy"', $img, 'une image gravatar sans loading="lazy" retarde le load de toutes les pages');
            self::assertStringContainsString('onerror', $img, 'le repli initiales exige onerror');
        }
    }

    public function testLIdentiteEstSurLeBoutonPasEnDoublonDansLeMenu(): void
    {
        // Directive proprio 07/08 : le bloc photo+nom natif du sous-menu est
        // DEPLACE sur le bouton — il ne doit plus exister dans le menu, sinon
        // l'identite apparait deux fois l'une au-dessus de l'autre.
        $twig = (string) file_get_contents(self::PROFILE);

        self::assertStringNotContainsString('dropdown-menu-user', $twig, 'le bloc identite du sous-menu doit disparaitre : il vit sur le bouton');
        self::assertStringContainsString('sendly-avatar', $twig);
        self::assertStringContainsString('sendly-profile-name', $twig);
    }

    public function testLaGeometrieDuProfilEstVerrouilleeA48px(): void
    {
        // Défaut proprio 07/08 : le thème impose flex-direction aux liens de
        // menu → sans verrou explicite, le lien profil s'empile en colonne
        // (180px), étire le bandeau fixe (181px) et le titre + les boutons de
        // page passent SOUS le verre dépoli (le même mécanisme que le
        // « element click intercepted » des e2e). Hauteur 48px + nowrap,
        // validés en direct avant commit.
        $twig = (string) file_get_contents(self::PROFILE);

        self::assertStringContainsString('flex-flow: row nowrap', $twig, 'sans nowrap le theme empile le lien profil en colonne');
        self::assertStringContainsString('height: 48px', $twig, 'le lien profil doit rester a la hauteur des autres items du bandeau');
        // Choix proprio : l'etat ouvert/focus garde le style NATIF du theme
        // (pas de pilule) — mais le padding est FIGE, identique dans les deux
        // etats, sinon la pastille change de taille au clic.
        self::assertStringNotContainsString('border-radius: 24px', $twig);
        self::assertStringContainsString('padding: 0 12px', $twig);
        // Anti-retraction : les clearfix ::before/::after du theme sont des
        // items flex a largeur nulle qui emportent chacun un gap de 8px et
        // disparaissent a l'etat ouvert -> la pastille perdait 16px au clic.
        self::assertStringContainsString('a.dropdown-toggle::before', $twig);
        self::assertStringContainsString('content: none', $twig);
    }

    public function testLAvatarEffaceSesInitialesQuandLImageCharge(): void
    {
        // Le gravatar (disque sur fond transparent) laissait transparaître le
        // cercle bleu du repli : « double pastille ». Image chargée → fond
        // blanc + initiales transparentes, via onload -> .has-img.
        $twig = (string) file_get_contents(self::PROFILE);

        self::assertStringContainsString("onload=\"this.parentNode.classList.add('has-img')\"", $twig);
        self::assertStringContainsString('.sendly-avatar.has-img', $twig);
        // L'image doit remplir le cercle EXACTEMENT (une regle du theme la
        // ramenait a 28px sur 30 : croissant du fond visible sur un bord).
        self::assertStringContainsString('width: 100% !important', $twig);
        self::assertStringContainsString('color: transparent', $twig);
    }

    public function testLaFlecheDuMenuProfilRevitALEtatOuvert(): void
    {
        // Les pseudo-elements du lien profil sont des clearfix a l'etat FERME
        // (supprimes : ils provoquaient la retraction au clic) mais deviennent
        // la FLECHE du menu a l'etat OUVERT (absolue, hors flux flex) : elle
        // doit revivre — retrait proprio 10/08, comme sur les autres boutons.
        $twig = (string) file_get_contents(self::PROFILE);

        self::assertStringContainsString('.sendly-profile.open > a.dropdown-toggle::after { content: "" !important; }', $twig);
        self::assertStringContainsString('content: none !important', $twig, 'la suppression a l etat ferme (anti-retraction) doit rester');
    }

    public function testLeRedesignVosLogicielsRespecteLaMaquetteValidee(): void
    {
        // Maquette variante A + pastilles P-1 « teinte douce » validées le
        // 13/08 (remplace la passe du 10/08) : plus d'aplat bleu saturé à
        // ombre colorée (lisait comme une icône d'app IA), un en-tête,
        // l'état en point vert + texte (le badge plein se cassait sur deux
        // lignes en mobile — capture proprio), la ligne courante teintée,
        // et la feuille du bas avec voile en mobile (langage P8).
        $twig = (string) file_get_contents(self::PARTIAL);

        self::assertStringContainsString('border-radius: 16px !important', $twig);
        self::assertStringContainsString('sendly-soft-entete', $twig);
        // Pastilles à fond BLANC + liseré (la teinte se fondait dans la
        // rangée courante — dernier retour proprio 13/08).
        self::assertStringContainsString('background: #fff; border: 1px solid #dbe7ff', $twig);
        self::assertStringContainsString('background: #fff; border: 1px solid #e8ebf0', $twig);
        // v2 raffinée (13/08 soir) : sous-titres sur UNE ligne (408px +
        // insécable), graisse 600, rangées à hauteur FIXE (désalignement
        // Espace/Portail), FAB effacé et navbar élevée (le z interne du
        // menu vit dans le contexte navbar z1030 < FAB z1035).
        self::assertStringContainsString('min-width: 408px', $twig);
        self::assertStringContainsString('text-overflow: ellipsis', $twig);
        self::assertStringContainsString('font-weight: 600; font-size: 13.5px', $twig);
        self::assertStringNotContainsString('font-weight: 700; font-size: 14px', $twig);
        self::assertStringContainsString('height: 58px !important', $twig);
        self::assertStringContainsString('body:has(.sendly-softwares.open) #sendly-assist-fab { display: none !important; }', $twig);
        self::assertStringContainsString('z-index: 1060 !important', $twig);
        self::assertStringNotContainsString('box-shadow: 0 4px 12px rgba(0, 79, 255, .32)', $twig, 'l ombre coloree des tuiles saturees ne doit pas revenir');
        self::assertStringContainsString('sendly-soft-row--courant', $twig);
        self::assertStringContainsString('sendly-soft-etat', $twig);
        self::assertStringContainsString('white-space: nowrap', $twig);
        self::assertStringContainsString('background: #0d9455', $twig);
        self::assertStringContainsString('sendly-soft-ouvrir', $twig);
        // Flèche ↗ nue au trait (le boîte+flèche RemixIcon était lourd).
        self::assertStringContainsString('M7 7h10v10', $twig);
        self::assertStringNotContainsString('ri-external-link-line', $twig);
        // Feuille du bas : media mobile, voile, poignée, garde de pouce.
        self::assertStringContainsString('@media (max-width: 767px)', $twig);
        self::assertStringContainsString('.sendly-softwares.open::before', $twig);
        self::assertStringContainsString('rgba(22, 35, 59, .34)', $twig);
        self::assertStringContainsString('border-radius: 16px 16px 0 0 !important', $twig);
        // Le blur du header = bloc conteneur des fixed (constaté live).
        self::assertStringContainsString('.navbar:has(.sendly-softwares.open)', $twig);
        // iOS Safari ne recalcule pas le bloc conteneur d'un fixed quand le
        // backdrop-filter disparaît dynamiquement (iPhone, 14/08) : sur
        // téléphone le flou du header est retiré EN PERMANENCE, pas
        // seulement à l'ouverture.
        self::assertStringContainsString('.navbar { backdrop-filter: none !important; -webkit-backdrop-filter: none !important; }', $twig);
        self::assertStringContainsString('env(safe-area-inset-bottom)', $twig);
        // L-c : sous-titres concrets, dans les deux langues.
        $fr = (string) file_get_contents(__DIR__.'/../../Translations/fr/messages.ini');
        self::assertStringContainsString('Campagnes, e-mails, pages, contacts', $fr);
        self::assertStringContainsString('Abonnement, équipe, facturation', $fr);
        $en = (string) file_get_contents(__DIR__.'/../../Translations/en_US/messages.ini');
        self::assertStringContainsString('Campaigns, emails, pages, contacts', $en);
    }

    public function testLeHeaderMobileTientSurUneRangee(): void
    {
        // Mesure du 10/08 : wordmark desktop => header 98px sur 2 rangees a
        // 390px ; ces regles le ramenent a 50px sur UNE rangee (303px utilises).
        $twig = (string) file_get_contents(self::NAVBAR);

        self::assertStringContainsString('@media (max-width: 767px)', $twig);
        self::assertStringContainsString('height: 20px', $twig, 'le wordmark mobile doit etre a hauteur d icone');
        // Et CENTRE dans la rangee : en display:block il se calait en haut de
        // la case et se faisait couper par le bord du bandeau (proprio 10/08).
        // Le flex sans !important PERD contre `.visible-xs { display: block
        // !important }` de Bootstrap (classe portee par le conteneur) : le
        // centrage restait lettre morte (re-signale proprio 10/08).
        self::assertStringContainsString('.brand-logo--mobile { display: flex !important; align-items: center; height: 48px; }', $twig);
        self::assertStringContainsString('.navbar-right li.quick-help { display: none; }', $twig);
    }
}
