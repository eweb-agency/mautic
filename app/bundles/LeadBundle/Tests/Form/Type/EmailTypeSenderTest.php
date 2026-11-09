<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Tests\Form\Type;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\LeadBundle\Form\Type\EmailType;
use Mautic\UserBundle\Entity\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * « Envoyer l'email » depuis la fiche d'un contact : l'expéditeur proposé.
 *
 * Constaté le 09/11 : le formulaire proposait l'adresse PERSONNELLE de
 * l'utilisateur connecté. Envoyée via le domaine d'envoi de l'espace, elle
 * échouait au DMARC (spam ou refus). L'adresse proposée est désormais celle de
 * l'espace (mailer_from_email) ; le nom affiché reste celui de l'utilisateur.
 */
final class EmailTypeSenderTest extends TestCase
{
    /**
     * @param array<string, string> $data
     *
     * @return array<string, array<string, mixed>> options passées à add(), par champ
     */
    private function build(string $spaceFrom, array $data = []): array
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn('camille@gmail.com');
        $user->method('getFirstName')->willReturn('Camille');
        $user->method('getLastName')->willReturn('Martin');

        $userHelper = $this->createMock(UserHelper::class);
        $userHelper->method('getUser')->willReturn($user);

        $parameters = $this->createMock(CoreParametersHelper::class);
        $parameters->method('get')->willReturnCallback(
            fn (string $name) => 'mailer_from_email' === $name ? $spaceFrom : null
        );

        $options = [];
        /** @var FormBuilderInterface<mixed>&MockObject $builder */
        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(
            /**
             * @param array<string, mixed> $fieldOptions
             */
            function (string $name, ?string $type = null, array $fieldOptions = []) use (&$options, $builder) {
                $options[$name] = $fieldOptions;

                return $builder;
            }
        );

        (new EmailType($userHelper, $parameters))->buildForm($builder, ['data' => $data]);

        return $options;
    }

    public function testProposeLAdresseDEnvoiDeLEspace(): void
    {
        $options = $this->build('fleap@sendly-mail.fr');

        $this->assertSame('fleap@sendly-mail.fr', $options['from']['data']);
        $this->assertSame('Camille Martin', $options['fromname']['data']);
    }

    public function testSansAdresseDEspaceGardeLeComportementDOrigine(): void
    {
        $options = $this->build('');

        $this->assertSame('camille@gmail.com', $options['from']['data']);
    }

    public function testUneAdresseFournieResteProposee(): void
    {
        $options = $this->build('fleap@sendly-mail.fr', ['from' => 'proprietaire@client.fr', 'fromname' => 'Propriétaire']);

        $this->assertSame('proprietaire@client.fr', $options['from']['data']);
        $this->assertSame('Propriétaire', $options['fromname']['data']);
    }
}
