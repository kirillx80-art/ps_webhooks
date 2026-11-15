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

use Db;
use Module;
use PrestaShop\Module\PsWebhooks\Entity\Webhook;
use PrestaShop\Module\PsWebhooks\Repository\WebhookRepository;

/**
 * Следит, чтобы модуль был подписан ровно на те хуки, которые нужны активным вебхукам.
 */
final class HookSubscriber
{
    public function __construct(private readonly WebhookRepository $repository) {}

    /**
     * Приводит подписки модуля в соответствие с таблицей вебхуков.
     * Вызывается после любого изменения: добавления, правки, включения, удаления.
     */
    public function sync(): void
    {
        $module = Module::getInstanceByName('ps_webhooks');
        if (!$module instanceof Module) {
            return;
        }

        // На какие хуки подписаться: по одному на каждое событие активных вебхуков
        $needed = array_values(array_unique(array_map(
            static fn(Webhook $webhook): string => $webhook->getHookName(),
            $this->repository->findActive()
        )));

        // На какие хуки модуль подписан сейчас
        $registered = $this->registeredHooks((int) $module->id);

        foreach (array_diff($needed, $registered) as $hookName) {
            $module->registerHook($hookName);
        }
        foreach (array_diff($registered, $needed) as $hookName) {
            $module->unregisterHook($hookName);
        }
    }

    /**
     * Хуки actionObject...After, на которые модуль подписан в базе PrestaShop.
     *
     * @return list<string>
     */
    private function registeredHooks(int $idModule): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT h.`name` FROM `' . _DB_PREFIX_ . 'hook_module` hm'
                . ' INNER JOIN `' . _DB_PREFIX_ . 'hook` h ON h.`id_hook` = hm.`id_hook`'
                . ' WHERE hm.`id_module` = ' . $idModule
                . ' AND h.`name` LIKE \'actionObject%After\''
        ) ?: [];

        return array_values(array_unique(array_column($rows, 'name')));
    }
}
