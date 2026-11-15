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

use WebserviceRequest;

/**
 * Список сущностей (классов PrestaShop), для которых разрешено создавать вебхуки.
 * Единственное место, где это решается: форма и импорт спрашивают здесь.
 */
final class EntityCatalog
{
    /**
     * Классы, которые отдаёт веб-сервис PrestaShop (товары, заказы, клиенты и т. д.).
     *
     * @return list<string>
     */
    public function getClasses(): array
    {
        $classes = [];
        foreach (WebserviceRequest::getResources() as $resource) {
            if (!empty($resource['class'])) {
                $classes[] = (string) $resource['class'];
            }
        }

        $classes = array_values(array_unique($classes));
        sort($classes);

        return $classes;
    }
}
