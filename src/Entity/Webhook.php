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

namespace PrestaShop\Module\PsWebhooks\Entity;

/**
 * Один вебхук: «когда с объектом $entity произойдёт $action, отправь данные на $url».
 * Объект неизменяемый: чтобы «изменить» вебхук, создаётся новый с тем же id.
 */
final class Webhook
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $action,
        public readonly string $entity,
        public readonly string $url,
        public readonly string $description = '',
        public readonly bool $active = true,
    ) {}

    /**
     * Создаёт вебхук из строки таблицы. База отдаёт все значения строками, поэтому типы приводятся явно.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id_webhook'],
            (string) $row['action'],
            (string) $row['entity'],
            (string) $row['url'],
            (string) ($row['description'] ?? ''),
            (bool) (int) $row['active'],
        );
    }

    /**
     * Имя хука PrestaShop, на который подписывается модуль, например actionObjectProductAddAfter.
     */
    public function getHookName(): string
    {
        return 'actionObject' . ucfirst($this->entity) . ucfirst($this->action) . 'After';
    }
}
