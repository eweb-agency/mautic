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
        self::assertSame(2, substr_count($partial, 'stroke="#fff"'), 'deux tuiles, deux glyphes au trait');

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
    }

    public function testLAvatarEffaceSesInitialesQuandLImageCharge(): void
    {
        // Le gravatar (disque sur fond transparent) laissait transparaître le
        // cercle bleu du repli : « double pastille ». Image chargée → fond
        // blanc + initiales transparentes, via onload -> .has-img.
        $twig = (string) file_get_contents(self::PROFILE);

        self::assertStringContainsString("onload=\"this.parentNode.classList.add('has-img')\"", $twig);
        self::assertStringContainsString('.sendly-avatar.has-img', $twig);
        self::assertStringContainsString('color: transparent', $twig);
    }
}
