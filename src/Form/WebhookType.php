<?php

/**
 * PrestaShop Webhooks
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file docs/licenses/LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/afl-3.0.php
 *
 * @author    Experto PrestaShop <https://www.youtube.com/@ExpertoPrestaShop>
 * @copyright since 2009 Experto PrestaShop
 * @license   https://opensource.org/licenses/AFL-3.0  Academic Free License ("AFL") v. 3.0
 */

declare(strict_types=1);

namespace PrestaShop\Module\PsWebhooks\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;
use WebserviceRequest;

/**
 * Форма добавления и редактирования вебхука: поля и правила проверки.
 */
final class WebhookType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('action', ChoiceType::class, [
                'label' => 'Action',
                'choices' => [
                    'Created' => 'add',
                    'Updated' => 'update',
                    'Deleted' => 'delete',
                ],
                'constraints' => [new NotBlank()],
            ])
            ->add('entity', ChoiceType::class, [
                'label' => 'Entity',
                'choices' => $this->entityChoices(),
                'constraints' => [new NotBlank()],
            ])
            ->add('url', UrlType::class, [
                'label' => 'URL',
                'default_protocol' => 'https',
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 200),
                    new Url(protocols: ['http', 'https']),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Enabled',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Подписи полей переводятся через домен перевода модуля
        $resolver->setDefaults(['translation_domain' => 'Modules.Pswebhooks.Admin']);
    }

    /**
     * Классы, для которых имеет смысл делать вебхуки: те же, что отдаёт веб-сервис PrestaShop.
     *
     * @return array<string, string>
     */
    private function entityChoices(): array
    {
        $classes = [];
        foreach (WebserviceRequest::getResources() as $resource) {
            if (!empty($resource['class'])) {
                $classes[] = $resource['class'];
            }
        }

        $classes = array_unique($classes);
        sort($classes);

        return array_combine($classes, $classes);
    }
}
