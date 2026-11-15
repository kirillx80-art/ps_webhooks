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
use PrestaShop\Module\PsWebhooks\Service\WebhookImporter;
use PrestaShop\Module\PsWebhooks\Service\WebhookSender;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Страницы управления вебхуками в админке.
 * Один маршрут из config/routes.yml = один метод, возвращающий Response.
 *
 * Права проверяет атрибут #[AdminSecurity]: он читает права сотрудника на вкладку AdminWebhooks
 * (read, create, update, delete). При отказе сотрудника перенаправляет на список.
 */
class WebhookController extends PrestaShopAdminController
{
    private const DOMAIN = 'Modules.Pswebhooks.Admin';
    private const FORM_TEMPLATE = '@Modules/ps_webhooks/views/templates/Admin/Webhook/form.html.twig';
    private const MAX_IMPORT_BYTES = 1000000;
    private const MAX_SHOWN_ERRORS = 5;

    /**
     * Список вебхуков.
     */
    #[AdminSecurity("is_granted('read', 'AdminWebhooks')")]
    public function indexAction(WebhookRepository $repository): Response
    {
        return $this->render('@Modules/ps_webhooks/views/templates/Admin/Webhook/index.html.twig', [
            'webhooks' => $repository->findAll(),
        ]);
    }

    /**
     * Форма нового вебхука. GET показывает форму, POST принимает её.
     */
    #[AdminSecurity("is_granted('create', 'AdminWebhooks')", redirectRoute: 'ps_webhooks_index')]
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
    #[AdminSecurity("is_granted('update', 'AdminWebhooks')", redirectRoute: 'ps_webhooks_index')]
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
    #[AdminSecurity("is_granted('update', 'AdminWebhooks')", redirectRoute: 'ps_webhooks_index')]
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
     * Проверка соединения: отправляет на адрес вебхука пустой объект и показывает код ответа.
     */
    #[AdminSecurity("is_granted('update', 'AdminWebhooks')", redirectRoute: 'ps_webhooks_index')]
    public function testAction(
        int $id,
        Request $request,
        WebhookRepository $repository,
        WebhookSender $sender
    ): Response {
        $webhook = $repository->find($id);

        if ($webhook !== null && $this->hasValidToken($request, 'ps_webhooks_test_' . $id)) {
            $code = $sender->sendTest($webhook);

            if ($code !== false && $code >= 200 && $code < 300) {
                $this->addFlash('success', $this->trans(
                    'Webhook connection tested: HTTP %code%',
                    ['%code%' => $code],
                    self::DOMAIN
                ));
            } else {
                $this->addFlash('error', $this->trans(
                    'Is not possible to connect to this URL: HTTP %code%',
                    ['%code%' => $code === false ? 'n/a' : $code],
                    self::DOMAIN
                ));
            }
        }

        return $this->redirectToRoute('ps_webhooks_index');
    }

    /**
     * Удаляет вебхук.
     */
    #[AdminSecurity("is_granted('delete', 'AdminWebhooks')", redirectRoute: 'ps_webhooks_index')]
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
     * Удаляет все вебхуки и снимает подписки модуля на хуки.
     */
    #[AdminSecurity("is_granted('delete', 'AdminWebhooks')", redirectRoute: 'ps_webhooks_index')]
    public function deleteAllAction(
        Request $request,
        WebhookRepository $repository,
        HookSubscriber $subscriber
    ): Response {
        if ($this->hasValidToken($request, 'ps_webhooks_delete_all')) {
            $repository->deleteAll();
            $subscriber->sync();
            $this->addFlash('success', $this->trans('All webhooks deleted.', [], self::DOMAIN));
        }

        return $this->redirectToRoute('ps_webhooks_index');
    }

    /**
     * Скачивает все вебхуки одним файлом webhooks.json.
     */
    #[AdminSecurity("is_granted('read', 'AdminWebhooks')", redirectRoute: 'ps_webhooks_index')]
    public function exportAction(WebhookRepository $repository): Response
    {
        $rows = array_map(
            static fn(Webhook $webhook): array => [
                'action' => $webhook->action,
                'entity' => $webhook->entity,
                'url' => $webhook->url,
                'description' => $webhook->description,
                'active' => $webhook->active,
            ],
            $repository->findAll()
        );

        $response = new JsonResponse($rows);
        $response->setEncodingOptions(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'webhooks.json')
        );

        return $response;
    }

    /**
     * Принимает загруженный файл webhooks.json и добавляет вебхуки из него.
     */
    #[AdminSecurity("is_granted('create', 'AdminWebhooks')", redirectRoute: 'ps_webhooks_index')]
    public function importAction(
        Request $request,
        WebhookImporter $importer,
        HookSubscriber $subscriber
    ): Response {
        if (!$this->hasValidToken($request, 'ps_webhooks_import')) {
            return $this->redirectToRoute('ps_webhooks_index');
        }

        $file = $request->files->get('import_json');
        if (!$file instanceof UploadedFile || !$file->isValid() || (int) $file->getSize() > self::MAX_IMPORT_BYTES) {
            $this->addFlash('error', $this->trans('No file uploaded or invalid file.', [], self::DOMAIN));

            return $this->redirectToRoute('ps_webhooks_index');
        }

        $result = $importer->import((string) file_get_contents($file->getPathname()));

        if ($result['errors'] !== []) {
            foreach (array_slice($result['errors'], 0, self::MAX_SHOWN_ERRORS) as $error) {
                $this->addFlash('error', $this->trans($error, [], self::DOMAIN));
            }
            if (count($result['errors']) > self::MAX_SHOWN_ERRORS) {
                $this->addFlash('error', $this->trans('There are more errors in the file.', [], self::DOMAIN));
            }

            return $this->redirectToRoute('ps_webhooks_index');
        }

        $subscriber->sync();
        $this->addFlash('success', $this->trans(
            'Webhooks imported: %imported%, skipped as duplicates: %skipped%',
            ['%imported%' => $result['imported'], '%skipped%' => $result['skipped']],
            self::DOMAIN
        ));

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
