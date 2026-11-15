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

use PrestaShop\Module\PsWebhooks\Repository\WebhookRepository;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Страница управления вебхуками в админке.
 */
class WebhookController extends PrestaShopAdminController
{
    /**
     * Список вебхуков. Один маршрут = один метод, возвращающий Response.
     */
    public function indexAction(WebhookRepository $repository): Response
    {
        return $this->render('@Modules/ps_webhooks/views/templates/Admin/Webhook/index.html.twig', [
            'webhooks' => $repository->findAll(),
        ]);
    }
}
