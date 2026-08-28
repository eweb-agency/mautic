<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use MauticPlugin\EwebAiBundle\Service\AiCopilotService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * L'assistant d'aide — ce que ces tests protègent :
 *  - la MARQUE BLANCHE : une réponse qui nomme le moteur est une fuite
 *    publiée directement chez le client (règle B-02) — le filet réécrit
 *    toute échappée, quelle que soit la casse ;
 *  - l'HISTORIQUE est borné et re-validé : tours excédentaires écartés,
 *    alternance imposée (l'API refuse deux tours consécutifs du même rôle,
 *    et le premier doit venir de l'utilisateur) ;
 *  - la CONSIGNE embarque la langue demandée et le sujet borné à Sendly ;
 *  - une question vide est refusée AVANT tout appel réseau.
 */
final class AiCopilotAssistTest extends TestCase
{
    /** @var array<string, mixed> corps JSON réellement envoyé */
    private array $sent = [];

    private ?string $previousKey = null;

    protected function setUp(): void
    {
        $this->previousKey            = $_ENV['SENDLY_ANTHROPIC_KEY'] ?? null;
        $_ENV['SENDLY_ANTHROPIC_KEY'] = 'test-key';
    }

    protected function tearDown(): void
    {
        if (null === $this->previousKey) {
            unset($_ENV['SENDLY_ANTHROPIC_KEY']);
        } else {
            $_ENV['SENDLY_ANTHROPIC_KEY'] = $this->previousKey;
        }
    }

    private function service(string $answer, int $status = 200): AiCopilotService
    {
        $this->sent = [];

        $body = json_encode([
            'content' => [['type' => 'text', 'text' => $answer]],
        ], JSON_THROW_ON_ERROR);

        $client = $this->createMock(Client::class);
        $client->method('request')->willReturnCallback(
            function (string $method, string $uri, array $options) use ($body, $status): Response {
                $this->sent = $options['json'] ?? [];

                return new Response($status, [], $body);
            }
        );

        return new AiCopilotService($client, new NullLogger());
    }

    public function testLaMarqueDuMoteurEstReecriteQuelleQueSoitLaCasse(): void
    {
        $result = $this->service('Dans Mautic, ouvrez « Segments ». MAUTIC gère cela nativement.')
            ->assist(['question' => 'Comment créer un segment ?']);

        self::assertStringNotContainsStringIgnoringCase('mautic', $result['answer']);
        self::assertSame('Dans Sendly, ouvrez « Segments ». Sendly gère cela nativement.', $result['answer']);
        self::assertSame([], $result['actions'], 'sans capacités déclarées, jamais d’action');
    }

    public function testLHistoriqueEstBorneEtLaQuestionCouranteFermeLaConversation(): void
    {
        $history = [];
        for ($i = 1; $i <= 10; ++$i) {
            $history[] = ['role' => 0 === $i % 2 ? 'assistant' : 'user', 'content' => "tour $i"];
        }

        $this->service('ok')->assist(['question' => 'ma question', 'history' => $history]);

        $messages = $this->sent['messages'];
        // 6 tours d'historique au plus + la question courante.
        self::assertLessThanOrEqual(7, count($messages));
        self::assertSame('user', $messages[0]['role'], 'le premier tour doit venir de l’utilisateur');
        self::assertSame(['role' => 'user', 'content' => 'ma question'], end($messages));
        // Alternance stricte sur toute la conversation envoyée.
        for ($i = 1; $i < count($messages); ++$i) {
            self::assertNotSame($messages[$i - 1]['role'], $messages[$i]['role'], "deux tours consécutifs du même rôle (index $i)");
        }
    }

    public function testUnHistoriqueMalFormeEstAssaini(): void
    {
        $this->service('ok')->assist([
            'question' => 'q',
            'history'  => [
                ['role' => 'assistant', 'content' => 'je commence ? non.'],
                'pas un tableau',
                ['role' => 'system', 'content' => 'rôle inconnu → user'],
                ['role' => 'user', 'content' => '   '],
                ['role' => 'assistant', 'content' => 'réponse'],
                // Deux tours assistant CONSÉCUTIFS (cas réel : le client
                // filtre ses tours d'erreur avant l'envoi) : le second doit
                // être écarté, l'API refuse la répétition de rôle.
                ['role' => 'assistant', 'content' => 're-réponse'],
            ],
        ]);

        $messages = $this->sent['messages'];
        self::assertSame(
            [
                ['role' => 'user', 'content' => 'rôle inconnu → user'],
                ['role' => 'assistant', 'content' => 'réponse'],
                ['role' => 'user', 'content' => 'q'],
            ],
            $messages
        );
    }

    public function testLaConsignePorteLaLangueEtLaMarque(): void
    {
        $this->service('ok')->assist(['question' => 'q', 'lang' => 'German']);

        $system = (string) $this->sent['system'];
        self::assertStringContainsString('Answer in German', $system);
        self::assertStringContainsString('called Sendly and ONLY Sendly', $system);
    }

    public function testLeMarkdownLegerEstPreserveLesTitresEtBackticksRetires(): void
    {
        // Retour proprio 14/08 (« un gros texte sans aucune propreté ») : le
        // panneau COMPOSE désormais paragraphes, listes et **gras** — le
        // filet ne retire plus que ce qu'il ne rend pas : titres et backticks.
        $result = $this->service("# Créer un segment\n\n1. Allez dans **Segments → Nouveau**\n2. Cliquez sur `Créer`\n- astuce : gardée")
            ->assist(['question' => 'q']);

        self::assertSame(
            "Créer un segment\n\n1. Allez dans **Segments → Nouveau**\n2. Cliquez sur Créer\n- astuce : gardée",
            $result['answer']
        );
    }

    public function testLaConsigneCoacheEtBorneLaLongueur(): void
    {
        // SANS capacités déclarées (écran non migré), le contrat texte
        // historique demeure : réponse directe d'abord, étapes courtes,
        // UN sujet à la fois. Le mot d'ordre « coach » a disparu — la
        // directive du 26/08 fait de l'assistant un EXÉCUTANT partout où
        // l'écran le permet.
        $service = $this->service('ok');
        $service->assist(['question' => 'q', 'lang' => 'French']);

        $system = (string) $this->sent['system'];
        self::assertStringNotContainsString('COACH', $system);
        self::assertStringContainsString('ONE topic per answer', $system);
        self::assertStringContainsString('5 at most, ONE action each', $system);
        self::assertStringContainsString('roughly 120 words', $system);
        self::assertStringContainsString('Light Markdown only', $system);
    }

    public function testUneQuestionVideEstRefuseeAvantToutAppel(): void
    {
        $service = $this->service('jamais appelé');

        $this->expectException(\InvalidArgumentException::class);

        try {
            $service->assist(['question' => '   ']);
        } finally {
            self::assertSame([], $this->sent, 'aucun appel réseau ne doit partir');
        }
    }

    /**
     * Fabrique un service dont l'API répond par un BLOC D'OUTIL (mode agi).
     *
     * @param array<string, mixed> $input
     */
    private function serviceOutil(array $input): AiCopilotService
    {
        $this->sent = [];

        $body = json_encode([
            'content' => [['type' => 'tool_use', 'name' => 'repondre_et_agir', 'input' => $input]],
        ], JSON_THROW_ON_ERROR);

        $client = $this->createMock(Client::class);
        $client->method('request')->willReturnCallback(
            function (string $method, string $uri, array $options) use ($body): Response {
                $this->sent = $options['json'] ?? [];

                return new Response(200, [], $body);
            }
        );

        return new AiCopilotService($client, new NullLogger());
    }

    public function testAvecCapacitesLeModeExecutantImposeLOutilEtLaConsigneOperator(): void
    {
        $this->serviceOutil(['answer' => 'fait', 'actions' => []])->assist([
            'question' => 'remplis le nom',
            'actions'  => ['fill_field', 'navigate'],
            'context'  => 'Form fields: - name="leadlist[name]" (empty)',
        ]);

        self::assertSame('repondre_et_agir', $this->sent['tools'][0]['name'] ?? null);
        self::assertSame(['type' => 'tool', 'name' => 'repondre_et_agir'], $this->sent['tool_choice'] ?? null);

        $system = (string) $this->sent['system'];
        self::assertStringContainsString('OPERATOR, not a guide', $system);
        self::assertStringNotContainsString('COACH', $system);

        // L'état de l'écran voyage en bloc de DONNÉES dans le tour utilisateur.
        $dernier = end($this->sent['messages']);
        self::assertStringContainsString('<screen_state>', (string) $dernier['content']);
        self::assertStringContainsString('leadlist[name]', (string) $dernier['content']);
    }

    public function testSansCapacitesLaConsigneResteSansOutil(): void
    {
        $this->service('ok')->assist(['question' => 'q']);

        self::assertArrayNotHasKey('tools', $this->sent);
        self::assertStringNotContainsString('OPERATOR', (string) $this->sent['system']);
    }

    public function testLesActionsSontValideesTypesInconnusEtCiblesHorsListeJetes(): void
    {
        $result = $this->serviceOutil([
            'answer'  => 'Je remplis le nom et j’ouvre les segments.',
            'actions' => [
                ['type' => 'fill_field', 'field' => 'leadlist[name]', 'value' => 'Clients actifs'],
                ['type' => 'fill_field', 'field' => '', 'value' => 'sans champ → jeté'],
                ['type' => 'navigate', 'target' => 'segments_new'],
                ['type' => 'navigate', 'target' => 'https://evil.example'],
                ['type' => 'delete_everything', 'field' => 'x'],
                ['type' => 'create_segment', 'description' => str_repeat('a', 900)],
                'pas un tableau',
            ],
        ])->assist([
            'question' => 'crée-moi tout ça',
            'actions'  => ['fill_field', 'navigate', 'create_segment'],
        ]);

        self::assertSame([
            ['type' => 'fill_field', 'field' => 'leadlist[name]', 'value' => 'Clients actifs'],
            ['type' => 'navigate', 'target' => 'segments_new'],
            ['type' => 'create_segment', 'description' => str_repeat('a', 600)],
        ], $result['actions']);
    }

    public function testCreateLandingPageValideNomBriefEtPlanBornes(): void
    {
        $result = $this->serviceOutil([
            'answer'  => 'Je crée la page.',
            'actions' => [[
                'type'        => 'create_landing_page',
                'name'        => '  Offre pare-feu  ',
                'description' => str_repeat('b', 900),
                'sections'    => [
                    ' Un héros avec titre fort et bouton ',
                    str_repeat('s', 300),
                    '', 'S3', 'S4', 'S5', 'S6', 'S7-au-dela-de-la-borne',
                ],
            ]],
        ])->assist([
            'question' => 'crée-moi une landing page pour mon offre pare-feu',
            'actions'  => ['create_landing_page'],
        ]);

        self::assertCount(1, $result['actions']);
        $action = $result['actions'][0];
        self::assertSame('create_landing_page', $action['type']);
        self::assertSame('Offre pare-feu', $action['name']);
        self::assertSame(600, mb_strlen($action['description']));
        self::assertSame('Un héros avec titre fort et bouton', $action['sections'][0]);
        self::assertSame(200, mb_strlen($action['sections'][1]));
        self::assertCount(6, $action['sections'], 'les vides sautent, la borne coupe à 6');
    }

    public function testCreateEmailValideNomObjetBriefEtSegmentOptionnel(): void
    {
        $result = $this->serviceOutil([
            'answer'  => 'Je crée l’e-mail.',
            'actions' => [[
                'type'        => 'create_email',
                'name'        => '  Relance inactifs  ',
                'subject'     => str_repeat('o', 200),
                'description' => 'un e-mail de relance chaleureux',
                'segment'     => '  les clients inactifs 90 jours  ',
            ]],
        ])->assist([
            'question' => 'écris l’e-mail de relance pour les inactifs',
            'actions'  => ['create_email'],
        ]);

        self::assertCount(1, $result['actions']);
        $action = $result['actions'][0];
        self::assertSame('create_email', $action['type']);
        self::assertSame('Relance inactifs', $action['name']);
        self::assertSame(150, mb_strlen($action['subject']));
        self::assertSame('les clients inactifs 90 jours', $action['segment']);
    }

    public function testCreateEmailSansObjetRetombeSurLeNomEtLeSegmentResteOptionnel(): void
    {
        $result = $this->serviceOutil([
            'answer'  => 'ok',
            'actions' => [
                ['type' => 'create_email', 'name' => 'X', 'description' => 'brief'],
                ['type' => 'create_email', 'name' => 'Y', 'subject' => 'Objet', 'description' => 'brief'],
            ],
        ])->assist([
            'question' => 'e-mail',
            'actions'  => ['create_email'],
        ]);

        // Repli du 28/08 : sans objet dicté, l'action n'est PLUS jetée en
        // silence — le nom sert d'objet (recette : « je crée » sans création).
        self::assertCount(2, $result['actions'], 'sans objet → repli sur le nom ; sans segment → accepté');
        self::assertSame('X', $result['actions'][0]['subject']);
        self::assertArrayNotHasKey('segment', $result['actions'][0]);
        self::assertSame('Objet', $result['actions'][1]['subject'], 'l’objet dicté reste verbatim');
    }

    public function testCreateLandingPageSansNomOuSansPlanEstJete(): void
    {
        $result = $this->serviceOutil([
            'answer'  => 'ok',
            'actions' => [
                ['type' => 'create_landing_page', 'description' => 'brief', 'sections' => ['a']],
                ['type' => 'create_landing_page', 'name' => 'Page', 'description' => 'brief', 'sections' => []],
                ['type' => 'create_landing_page', 'name' => 'Page', 'description' => 'brief', 'sections' => 'pas une liste'],
            ],
        ])->assist([
            'question' => 'landing page',
            'actions'  => ['create_landing_page'],
        ]);

        self::assertSame([], $result['actions'], 'sans nom ou sans plan de sections, rien à exécuter — jeté');
    }

    public function testCreateFormValideChampsOptionsEtApresEnvoi(): void
    {
        $result = $this->serviceOutil([
            'answer'  => 'Je crée le formulaire.',
            'actions' => [[
                'type'   => 'create_form',
                'name'   => '  Formulaire devis  ',
                'fields' => [
                    ['type' => 'text', 'label' => ' Nom complet ', 'required' => true],
                    ['type' => 'email', 'label' => 'Adresse e-mail', 'required' => true],
                    ['type' => 'select', 'label' => 'Sujet', 'options' => [' Devis ', 'Support', '']],
                    ['type' => 'select', 'label' => 'Sans choix — jeté', 'options' => []],
                    ['type' => 'motdepasse', 'label' => 'type hors liste — jeté'],
                    ['type' => 'textarea', 'label' => str_repeat('l', 200)],
                ],
                'submit_kind'  => 'message',
                'submit_value' => 'Merci, on vous rappelle vite !',
            ]],
        ])->assist([
            'question' => 'un formulaire devis',
            'actions'  => ['create_form'],
        ]);

        self::assertCount(1, $result['actions']);
        $action = $result['actions'][0];
        self::assertSame('Formulaire devis', $action['name']);
        self::assertCount(4, $action['fields'], 'select sans choix et type hors liste sautent');
        self::assertSame('Nom complet', $action['fields'][0]['label']);
        self::assertTrue($action['fields'][1]['required']);
        self::assertSame(['Devis', 'Support'], $action['fields'][2]['options']);
        self::assertSame(80, mb_strlen($action['fields'][3]['label']));
        self::assertSame(['kind' => 'message', 'value' => 'Merci, on vous rappelle vite !'], $action['submit']);
    }

    public function testCreateFormUrlDeRedirectionInvalideRetombeSurLeMessage(): void
    {
        $result = $this->serviceOutil([
            'answer'  => 'ok',
            'actions' => [[
                'type'         => 'create_form',
                'name'         => 'F',
                'fields'       => [['type' => 'email', 'label' => 'E-mail']],
                'submit_kind'  => 'redirect',
                'submit_value' => 'javascript:alert(1)',
            ]],
        ])->assist(['question' => 'formulaire', 'actions' => ['create_form']]);

        self::assertSame('message', $result['actions'][0]['submit']['kind'], 'jamais de redirection hors http(s)');
        self::assertNotSame('', $result['actions'][0]['submit']['value'], 'le message par défaut prend le relais');
    }

    public function testCreateFormSansNomOuSansChampValideEstJete(): void
    {
        $result = $this->serviceOutil([
            'answer'  => 'ok',
            'actions' => [
                ['type' => 'create_form', 'fields' => [['type' => 'text', 'label' => 'X']]],
                ['type' => 'create_form', 'name' => 'F', 'fields' => [['type' => 'inconnu', 'label' => 'X']]],
            ],
        ])->assist(['question' => 'formulaire', 'actions' => ['create_form']]);

        self::assertSame([], $result['actions']);
    }

    public function testCreateCampaignValideReferencesEtHoraire(): void
    {
        $result = $this->serviceOutil([
            'answer'  => 'Je crée la campagne.',
            'actions' => [[
                'type'    => 'create_campaign',
                'name'    => '  Relance de septembre  ',
                'email'   => ' Bienvenue supervision pare-feu ',
                'segment' => 'Contacts avec adresse e-mail',
                'send_at' => '2026-09-03T09:00',
            ]],
        ])->assist(['question' => 'envoie…', 'actions' => ['create_campaign']]);

        self::assertSame([
            'type'    => 'create_campaign',
            'name'    => 'Relance de septembre',
            'email'   => 'Bienvenue supervision pare-feu',
            'segment' => 'Contacts avec adresse e-mail',
            'send_at' => '2026-09-03T09:00',
        ], $result['actions'][0]);
    }

    public function testCreateCampaignHoraireDeFormeInvalideTombeSansJeterLAction(): void
    {
        $result = $this->serviceOutil([
            'answer'  => 'ok',
            'actions' => [[
                'type'  => 'create_campaign', 'name' => 'C',
                'email' => 'E', 'segment' => 'S', 'send_at' => 'jeudi 9h',
            ]],
        ])->assist(['question' => 'envoie', 'actions' => ['create_campaign']]);

        self::assertCount(1, $result['actions']);
        self::assertArrayNotHasKey('send_at', $result['actions'][0], "l'horaire invalide tombe, la campagne reste en déclenchement immédiat");
    }

    public function testCreateCampaignSansReferenceEstJete(): void
    {
        $result = $this->serviceOutil([
            'answer'  => 'ok',
            'actions' => [
                ['type' => 'create_campaign', 'name' => 'C', 'email' => 'E'],
                ['type' => 'create_campaign', 'name' => 'C', 'segment' => 'S'],
            ],
        ])->assist(['question' => 'envoie', 'actions' => ['create_campaign']]);

        self::assertSame([], $result['actions'], 'sans e-mail OU sans segment, rien à résoudre — jeté');
    }

    public function testLaConsigneExigeLeVerbatimDesFormulationsDictees(): void
    {
        $this->serviceOutil(['answer' => 'ok', 'actions' => []])->assist([
            'question' => 'q', 'actions' => ['create_form'],
        ]);

        self::assertStringContainsString('copy it VERBATIM', (string) $this->sent['system']);
    }

    public function testCreateReportValideNomEtSourceEnListeBlanche(): void
    {
        $result = $this->serviceOutil([
            'answer'  => 'Je crée le rapport.',
            'actions' => [
                ['type' => 'create_report', 'name' => '  Ouvertures de la semaine  ', 'source' => 'email.stats'],
                ['type' => 'create_report', 'name' => 'X', 'source' => 'sql_libre — hors liste'],
                ['type' => 'create_report', 'source' => 'leads'],
            ],
        ])->assist(['question' => 'les ouvertures de la semaine', 'actions' => ['create_report']]);

        self::assertSame([
            ['type' => 'create_report', 'name' => 'Ouvertures de la semaine', 'source' => 'email.stats'],
        ], $result['actions'], 'source hors liste blanche ou nom manquant → jetés');
    }

    public function testLaConsigneImposeLesValeursDOptionPourLesSelects(): void
    {
        $this->serviceOutil(['answer' => 'ok', 'actions' => []])->assist([
            'question' => 'q', 'actions' => ['fill_field'],
        ]);

        $system = (string) $this->sent['system'];
        self::assertStringContainsString('options=@Ln', $system);
        self::assertStringContainsString('never the label', $system);
    }

    public function testLeBaremeDuTempsGagneEstServeurEtBorne(): void
    {
        $service = $this->serviceOutil(['answer' => 'ok', 'actions' => []]);

        self::assertSame(600, $service->creditSeconds('create_segment'));
        self::assertSame(2700, $service->creditSeconds('create_landing_page'));
        self::assertSame(90, $service->creditSeconds('fill_field', 3), 'fill_field se multiplie');
        self::assertSame(30 * 25, $service->creditSeconds('fill_field', 999), 'quantité bornée à 25');
        self::assertSame(1200, $service->creditSeconds('create_campaign', 999), 'seuls les champs se multiplient');
        self::assertSame(0, $service->creditSeconds('geste_inconnu'), 'type inconnu = 0, jamais une exception');
    }

    public function testUnTypeNonDeclareParLEcranEstJeteMemeSilEstAuRegistre(): void
    {
        $result = $this->serviceOutil([
            'answer'  => 'ok',
            'actions' => [['type' => 'navigate', 'target' => 'segments']],
        ])->assist([
            'question' => 'va aux segments',
            'actions'  => ['fill_field'],
        ]);

        self::assertSame([], $result['actions'], 'navigate non déclaré par cet écran → jeté');
    }

    public function testLeModeleRepondEnClairMalgreLOutilLeTexteEstServiSansAction(): void
    {
        $result = $this->service('Réponse en clair de Mautic.')->assist([
            'question' => 'q',
            'actions'  => ['fill_field'],
        ]);

        self::assertSame('Réponse en clair de Sendly.', $result['answer']);
        self::assertSame([], $result['actions']);
    }
}
