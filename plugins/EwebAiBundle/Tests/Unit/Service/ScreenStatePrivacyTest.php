<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit\Service;

use MauticPlugin\EwebAiBundle\Service\ScreenStatePrivacy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Confidentialité du relevé d'écran, CÔTÉ SERVEUR (décision proprio 29/10,
 * option A) : le dernier point avant le modèle. Les relevés ci-dessous ont
 * le format exact d'etatEcran (ai-assistant.js) tel que l'ANCIEN app.js les
 * envoie encore depuis un onglet resté ouvert : valeurs en clair. Les alias
 * de champs sont ceux du cœur Mautic (FieldModel::$coreFields et
 * $coreCompanyFields) et les noms ceux des formulaires LeadType/CompanyType.
 */
final class ScreenStatePrivacyTest extends TestCase
{
    public function testUneFicheContactNeLaissePartirQueLesChampsNonPersonnels(): void
    {
        $releve = implode("\n", [
            'Screen: /s/contacts/edit/42',
            'Form fields:',
            '- name="lead[firstname]" label="Prénom" value="Jeanne"',
            '- name="lead[lastname]" label="Nom" value="Martin"',
            '- name="lead[email]" label="E-mail" value="jeanne.martin@example.fr"',
            '- name="lead[mobile]" label="Mobile" value="06 12 34 56 78"',
            '- name="lead[country]" label="Pays" options=@L1 value="France"',
            '- name="lead[naissance]" label="Date de naissance" value="02/03/1984"',
            '- name="lead[fax]" label="Fax" (empty)',
            '- name="lead[points]" label="Points" value="40"',
            '- name="lead[stage]" label="Étape" options=@L2 value="3"',
            '- name="lead[owner]" label="Propriétaire" options=@L3 (empty)',
            '- name="lead[tags][]" label="Tags" options=@L4 value="vip,salon-2026"',
            '- name="lead[companies][]" label="Société" options=@L5 value="12"',
            '@L1: France | Belgique',
            '@L2: 1=Prospect | 3=Client',
            '@L3: 1=Admin Sendly',
            '@L4: vip | salon-2026 | newsletter',
            '@L5: 12=Boulangerie Martin',
        ]);

        self::assertSame(implode("\n", [
            'Screen: /s/contacts/edit/42',
            'Form fields:',
            '- name="lead[firstname]" label="Prénom" (filled)',
            '- name="lead[lastname]" label="Nom" (filled)',
            '- name="lead[email]" label="E-mail" (filled)',
            '- name="lead[mobile]" label="Mobile" (filled)',
            '- name="lead[country]" label="Pays" (filled)',
            '- name="lead[naissance]" label="Date de naissance" (filled)',
            '- name="lead[fax]" label="Fax" (empty)',
            '- name="lead[points]" label="Points" value="40"',
            '- name="lead[stage]" label="Étape" options=@L2 value="3"',
            '- name="lead[owner]" label="Propriétaire" options=@L3 (empty)',
            '- name="lead[tags][]" label="Tags" options=@L4 value="vip,salon-2026"',
            '- name="lead[companies][]" label="Société" (filled)',
            '@L2: 1=Prospect | 3=Client',
            '@L3: 1=Admin Sendly',
            '@L4: vip | salon-2026 | newsletter',
        ]), ScreenStatePrivacy::mask($releve));
    }

    public function testUneValeurSurPlusieursLignesTombeEnEntier(): void
    {
        // Une zone de texte garde ses retours à la ligne dans le relevé : les
        // lignes de suite SONT la valeur, elles ne doivent pas survivre.
        $releve = implode("\n", [
            'Screen: /s/contacts/new',
            'Form fields:',
            '- name="lead[notes]" label="Notes" value="Rappeler jeudi',
            'au 01 23 45 67 89',
            'domicile : 12 rue des Lilas"',
            '- name="lead[points]" label="Points" value="5"',
        ]);

        $masque = ScreenStatePrivacy::mask($releve);

        self::assertSame(implode("\n", [
            'Screen: /s/contacts/new',
            'Form fields:',
            '- name="lead[notes]" label="Notes" (filled)',
            '- name="lead[points]" label="Points" value="5"',
        ]), $masque);
        self::assertStringNotContainsString('01 23', $masque);
    }

    public function testUneFicheSocieteMasqueSesCoordonneesEtGardeSonActivite(): void
    {
        $releve = implode("\n", [
            'Screen: /s/companies/edit/7',
            'Form fields:',
            '- name="company[companyname]" label="Nom de la société" value="Boulangerie Martin"',
            '- name="company[companyemail]" label="E-mail" value="contact@boulangerie-martin.fr"',
            '- name="company[companyaddress1]" label="Adresse 1" value="12 rue des Lilas"',
            '- name="company[companyindustry]" label="Secteur" options=@L1 value="Retail"',
            '- name="company[score]" label="Score" value="12"',
            '@L1: Agriculture | Retail',
        ]);

        self::assertSame(implode("\n", [
            'Screen: /s/companies/edit/7',
            'Form fields:',
            '- name="company[companyname]" label="Nom de la société" (filled)',
            '- name="company[companyemail]" label="E-mail" (filled)',
            '- name="company[companyaddress1]" label="Adresse 1" (filled)',
            '- name="company[companyindustry]" label="Secteur" options=@L1 value="Retail"',
            '- name="company[score]" label="Score" value="12"',
            '@L1: Agriculture | Retail',
        ]), ScreenStatePrivacy::mask($releve));
    }

    public function testLaCreationRapideDepuisLaListeEstReconnueParLeNomDuChamp(): void
    {
        // Modale « Création rapide » de la liste des contacts : le chemin est
        // /s/contacts (pas une fiche), mais le formulaire est bien `lead`.
        $masque = ScreenStatePrivacy::mask(implode("\n", [
            'Screen: /s/contacts',
            'Form fields:',
            '- name="lead[email]" label="E-mail" value="paul@example.fr"',
            '- name="search" value="dupont"',
        ]));

        self::assertStringContainsString('- name="lead[email]" label="E-mail" (filled)', $masque);
        self::assertStringNotContainsString('paul@example.fr', $masque);
        self::assertStringContainsString('- name="search" value="dupont"', $masque, 'hors fiche, un champ sans préfixe ne change pas');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function relevesHorsFiche(): iterable
    {
        yield 'segment' => [implode("\n", [
            'Screen: /s/segments/new',
            'Form fields:',
            '- name="leadlist[name]" label="Nom" value="Clients VIP"',
            '- name="leadlist[isGlobal]" label="Visible" options=@L1 value="1"',
            '@L1: 0=Non | 1=Oui',
        ])];
        yield 'e-mail' => [implode("\n", [
            'Screen: /s/emails/edit/3',
            'Form fields:',
            '- name="emailform[subject]" label="Objet" value="Nos offres de novembre"',
            '- name="emailform[fromAddress]" label="Adresse de l\'expéditeur" value="contact@agence.fr"',
        ])];
        yield 'correspondance d import' => [implode("\n", [
            'Screen: /s/contacts/import/new',
            'Form fields:',
            '- name="lead_field_import[email]" label="email" options=@L1 value="email"',
            '@L1: email=E-mail | companyname=Nom de la société',
        ])];
        yield 'champs personnalises' => [implode("\n", [
            'Screen: /s/contacts/fields/edit/3',
            'Form fields:',
            '- name="leadfield[label]" label="Libellé" value="Date de naissance"',
        ])];
    }

    #[DataProvider('relevesHorsFiche')]
    public function testHorsDesFichesRienNeChange(string $releve): void
    {
        self::assertSame($releve, ScreenStatePrivacy::mask($releve));
    }

    public function testUnReleveDejaMasqueParLeNavigateurRessortALIdentique(): void
    {
        $releve = implode("\n", [
            'Screen: /s/contacts/edit/42',
            'Form fields:',
            '- name="lead[email]" label="E-mail" (filled)',
            '- name="lead[fax]" (empty)',
            '- name="lead[stage]" label="Étape" options=@L1 value="3"',
            '@L1: 1=Prospect | 3=Client',
        ]);

        self::assertSame($releve, ScreenStatePrivacy::mask($releve));
        self::assertSame('', ScreenStatePrivacy::mask(''));
    }

    public function testLaListeBlancheNePeutPasRelacherUneCoordonnee(): void
    {
        foreach (ScreenStatePrivacy::COORDONNEES as $alias) {
            self::assertNotContains($alias, ScreenStatePrivacy::NON_PERSONNELS, "« $alias » est une coordonnée");
            self::assertTrue(ScreenStatePrivacy::isMasked('lead['.$alias.']', false), "lead[$alias] doit être masqué");
        }
        // Champ personnalisé inconnu : masqué par défaut sur une fiche.
        self::assertTrue(ScreenStatePrivacy::isMasked('lead[numero_client]', false));
        self::assertTrue(ScreenStatePrivacy::isMasked('search', true), 'sur un écran de fiche, même sans préfixe');
        self::assertFalse(ScreenStatePrivacy::isMasked('lead[tags][]', false));
        self::assertFalse(ScreenStatePrivacy::isMasked('leadlist[name]', false), 'leadlist[ n est pas lead[');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function chemins(): iterable
    {
        yield 'création de contact' => ['/s/contacts/new', true];
        yield 'édition de contact' => ['/s/contacts/edit/42', true];
        yield 'liste des sociétés' => ['/s/companies', true];
        yield 'création de société' => ['/s/companies/new', true];
        yield 'édition de société' => ['/s/companies/edit/7', true];
        yield 'liste des contacts' => ['/s/contacts', false];
        yield 'import de contacts' => ['/s/contacts/import/new', false];
        yield 'champs personnalisés' => ['/s/contacts/fields/edit/3', false];
        yield 'segment' => ['/s/segments/edit/3', false];
        yield 'e-mail' => ['/s/emails/new', false];
    }

    #[DataProvider('chemins')]
    public function testLesEcransDeFiche(string $chemin, bool $fiche): void
    {
        self::assertSame($fiche, 1 === preg_match(ScreenStatePrivacy::FICHE_CHEMINS, $chemin));
    }
}
