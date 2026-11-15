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

namespace PrestaShop\Module\PsWebhooks\Controller\Admin;

use PrestaShop\Module\PsWebhooks\Entity\Webhook;
use PrestaShop\Module\PsWebhooks\Form\WebhookType;
use PrestaShop\Module\PsWebhooks\Repository\WebhookRepository;
use PrestaShop\Module\PsWebhooks\Service\HookSubscriber;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Страницы управления вебхуками в админке.
 * Один маршрут из config/routes.yml = один метод, возвращающий Response.
 */
class WebhookController extends PrestaShopAdminController
{
    private const DOMAIN = 'Modules.Pswebhooks.Admin';
    private const FORM_TEMPLATE = '@Modules/ps_webhooks/views/templates/Admin/Webhook/form.html.twig';

    /**
     * Список вебхуков.
     */
    public function indexAction(WebhookRepository $repository): Response
    {
        return $this->render('@Modules/ps_webhooks/views/templates/Admin/Webhook/index.html.twig', [
            'webhooks' => $repository->findAll(),
        ]);
    }

    /**
     * Форма нового вебхука. GET показывает форму, POST принимает её.
     */
    public function createAction(
        Request $request,
        WebhookRepository $repository,
        HookSubscriber $subscriber
    ): Response {
        $form = $this->createForm(WebhookType::class, ['active' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $repository->save($this->buildWebhook($form->getData(), null));
            $subscriber->sync();
            $this->addFlash('success', $this->trans('Webhook created.', [], self::DOMAIN));

            return $this->redirectToRoute('ps_webhooks_index');
        }

        return $this->render(self::FORM_TEMPLATE, [
            'form' => $form->createView(),
            'title' => $this->trans('New webhook', [], self::DOMAIN),
        ]);
    }

    /**
     * Форма правки существующего вебхука.
     */
    public function editAction(
        int $id,
        Request $request,
        WebhookRepository $repository,
        HookSubscriber $subscriber
    ): Response {
        $webhook = $repository->find($id);
        if ($webhook === null) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(WebhookType::class, [
            'action' => $webhook->action,
            'entity' => $webhook->entity,
            'url' => $webhook->url,
            'description' => $webhook->description,
            'active' => $webhook->active,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $repository->save($this->buildWebhook($form->getData(), $webhook->id));
            $subscriber->sync();
            $this->addFlash('success', $this->trans('Webhook saved.', [], self::DOMAIN));

            return $this->redirectToRoute('ps_webhooks_index');
        }

        return $this->render(self::FORM_TEMPLATE, [
            'form' => $form->createView(),
            'title' => $this->trans('Edit webhook', [], self::DOMAIN),
        ]);
    }

    /**
     * Включает или выключает вебхук.
     */
    public function toggleAction(
        int $id,
        Request $request,
        WebhookRepository $repository,
        HookSubscriber $subscriber
    ): Response {
        $webhook = $repository->find($id);

        if ($webhook !== null && $this->hasValidToken($request, 'ps_webhooks_toggle_' . $id)) {
            $repository->save(new Webhook(
                $webhook->id,
                $webhook->action,
                $webhook->entity,
                $webhook->url,
                $webhook->description,
                !$webhook->active
            ));
            $subscriber->sync();
            $this->addFlash('success', $this->trans('Webhook saved.', [], self::DOMAIN));
        }

        return $this->redirectToRoute('ps_webhooks_index');
    }

    /**
     * Удаляет вебхук.
     */
    public function deleteAction(
        int $id,
        Request $request,
        WebhookRepository $repository,
        HookSubscriber $subscriber
    ): Response {
        if ($this->hasValidToken($request, 'ps_webhooks_delete_' . $id)) {
            $repository->delete($id);
            $subscriber->sync();
            $this->addFlash('success', $this->trans('Webhook deleted.', [], self::DOMAIN));
        }

        return $this->redirectToRoute('ps_webhooks_index');
    }

    /**
     * Собирает объект Webhook из данных формы.
     *
     * @param array<string, mixed> $data
     */
    private function buildWebhook(array $data, ?int $id): Webhook
    {
        return new Webhook(
            $id,
            (string) $data['action'],
            (string) $data['entity'],
            (string) $data['url'],
            (string) ($data['description'] ?? ''),
            (bool) ($data['active'] ?? false)
        );
    }

    /**
     * Проверяет CSRF-токен кнопки. При ошибке показывает сообщение.
     */
    private function hasValidToken(Request $request, string $tokenId): bool
    {
        if ($this->isCsrfTokenValid($tokenId, (string) $request->request->get('_csrf_token'))) {
            return true;
        }

        $this->addFlash('error', $this->trans('Invalid security token. Please try again.', [], self::DOMAIN));

        return false;
    }
}
