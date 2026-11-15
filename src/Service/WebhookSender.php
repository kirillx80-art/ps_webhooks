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

namespace PrestaShop\Module\PsWebhooks\Service;

use ObjectModel;
use PrestaShopLogger;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Отправляет данные объекта на адрес вебхука.
 */
final class WebhookSender
{
    /** Поля, которые никогда не уходят наружу. */
    private const SENSITIVE_FIELDS = ['passwd', 'secure_key', 'password'];

    private HttpClientInterface $client;

    public function __construct(?HttpClientInterface $client = null)
    {
        $this->client = $client ?? HttpClient::create();
    }

    /**
     * Отправляет POST с JSON на адрес вебхука.
     *
     * @return int|false HTTP-код ответа или false, если отправить не удалось
     */
    public function send(string $url, string $action, string $entity, object $object): int|false
    {
        // Безопасность: entity должна быть настоящим классом, наследующим ObjectModel
        if (!class_exists($entity) || !is_subclass_of($entity, ObjectModel::class)) {
            return false;
        }

        // Безопасность: убираем чувствительные поля перед отправкой
        $data = array_diff_key(get_object_vars($object), array_flip(self::SENSITIVE_FIELDS));

        try {
            $response = $this->client->request('POST', $url, [
                'headers' => ['Accept' => 'application/json'],
                'json' => ['action' => $action, 'entity' => $entity, 'data' => $data],
                'timeout' => 3.0,
                'max_duration' => 3.0,
                'max_redirects' => 0,
            ]);

            return $response->getStatusCode();
        } catch (Throwable $e) {
            // Недоступный адрес не должен ломать сохранение товара или заказа
            PrestaShopLogger::addLog('ps_webhooks: ' . $e->getMessage(), 3);

            return false;
        }
    }
}
