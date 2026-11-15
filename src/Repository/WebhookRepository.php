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

namespace PrestaShop\Module\PsWebhooks\Repository;

use Db;
use PrestaShop\Module\PsWebhooks\Entity\Webhook;

/**
 * Единственное место модуля, которое работает с таблицей webhook.
 */
final class WebhookRepository
{
    private const TABLE = 'webhook';

    /**
     * Активные вебхуки для события, например entity=Product, action=add.
     *
     * @return list<Webhook>
     */
    public function findActiveByEvent(string $entity, string $action): array
    {
        return $this->fetch(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '`'
                . ' WHERE `entity` = \'' . pSQL($entity) . '\''
                . ' AND `action` = \'' . pSQL($action) . '\''
                . ' AND `active` = 1'
        );
    }

    /**
     * Все активные вебхуки (нужны, чтобы понять, на какие хуки подписан модуль).
     *
     * @return list<Webhook>
     */
    public function findActive(): array
    {
        return $this->fetch(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `active` = 1'
        );
    }

    /**
     * @return list<Webhook>
     */
    public function findAll(): array
    {
        return $this->fetch('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` ORDER BY `id_webhook`');
    }

    public function find(int $id): ?Webhook
    {
        $rows = $this->fetch(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_webhook` = ' . $id
        );

        return $rows[0] ?? null;
    }

    /**
     * Сохраняет вебхук: без id создаёт новый, с id обновляет существующий. Возвращает id.
     */
    public function save(Webhook $webhook): int
    {
        $db = Db::getInstance();

        // pSQL экранирует значение для SQL; второй аргумент true сохраняет символы вроде & в адресах
        $data = [
            'action' => pSQL($webhook->action),
            'entity' => pSQL($webhook->entity),
            'url' => pSQL($webhook->url, true),
            'description' => pSQL($webhook->description, true),
            'active' => (int) $webhook->active,
        ];

        if ($webhook->id === null) {
            $db->insert(self::TABLE, $data);

            return (int) $db->Insert_ID();
        }

        $db->update(self::TABLE, $data, '`id_webhook` = ' . $webhook->id);

        return $webhook->id;
    }

    public function delete(int $id): bool
    {
        return (bool) Db::getInstance()->delete(self::TABLE, '`id_webhook` = ' . $id);
    }

    public function deleteAll(): bool
    {
        return (bool) Db::getInstance()->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . self::TABLE . '`');
    }

    /**
     * Выполняет запрос и превращает строки таблицы в объекты Webhook.
     *
     * @return list<Webhook>
     */
    private function fetch(string $sql): array
    {
        $rows = Db::getInstance()->executeS($sql) ?: [];

        return array_map(static fn(array $row): Webhook => Webhook::fromRow($row), $rows);
    }
}
