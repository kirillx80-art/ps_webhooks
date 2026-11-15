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

use Hook;
use PrestaShop\Module\PsWebhooks\Entity\Webhook;
use PrestaShopLogger;
use Throwable;

/**
 * Копит события в течение одного запроса и отправляет их в конце, без повторов.
 *
 * Зачем: PrestaShop может вызвать один и тот же хук несколько раз за одно действие
 * (например, при сохранении товара). Получателю нужно одно событие с итоговым состоянием объекта.
 *
 * Состояние хранится в статических свойствах: один запрос PHP = один процесс со своей очередью.
 */
final class WebhookDispatcher
{
    /** Сколько событий копить до принудительной отправки (защита от массовых операций). */
    private const MAX_QUEUE = 50;

    /** Сколько раз подряд обрабатывать очередь, если во время отправки появились новые события. */
    private const MAX_ROUNDS = 5;

    /** @var array<string, array{webhook: Webhook, object: object}> */
    private static array $queue = [];

    private static bool $registered = false;

    /**
     * Ставит событие в очередь. Повторное событие с тем же ключом заменяет прежнее:
     * побеждает последний объект, в нём накоплены все изменения запроса.
     */
    public static function enqueue(Webhook $webhook, object $object): void
    {
        $key = strtolower($webhook->entity . '|' . $webhook->action . '|' . $webhook->url)
            . '|' . self::objectKey($object);

        self::$queue[$key] = ['webhook' => $webhook, 'object' => $object];

        if (!self::$registered) {
            self::$registered = true;
            register_shutdown_function([self::class, 'flush']);
        }

        if (count(self::$queue) >= self::MAX_QUEUE) {
            self::flush();
        }
    }

    /**
     * Отправляет накопленные события. Вызывается автоматически в конце запроса.
     */
    public static function flush(): void
    {
        for ($round = 0; $round < self::MAX_ROUNDS && self::$queue !== []; ++$round) {
            $batch = self::$queue;
            self::$queue = [];
            self::process($batch);
        }

        self::$queue = [];
    }

    /**
     * @param array<string, array{webhook: Webhook, object: object}> $batch
     */
    private static function process(array $batch): void
    {
        try {
            $sender = new WebhookSender();
            $prepared = [];

            foreach ($batch as $item) {
                $object = $item['object'];
                $objectId = spl_object_id($object);

                // Хук для других модулей вызывается один раз на объект, а не на каждый вебхук
                if (!isset($prepared[$objectId])) {
                    Hook::exec('actionObjectWebhookSendBefore', ['object' => $object]);
                    $prepared[$objectId] = true;
                }

                $webhook = $item['webhook'];
                $sender->send($webhook->url, $webhook->action, $webhook->entity, $object);
            }
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('ps_webhooks: ' . $e->getMessage(), 3);
        }
    }

    /**
     * Идентификатор объекта для ключа: id объекта PrestaShop или, если его нет, номер экземпляра в памяти.
     */
    private static function objectKey(object $object): string
    {
        $id = $object->id ?? null;

        return $id !== null ? (string) $id : 'obj' . spl_object_id($object);
    }
}
