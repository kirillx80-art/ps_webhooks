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

use PrestaShop\Module\PsWebhooks\Service\WebhookSender;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

class Ps_Webhooks extends Module
{
    /**
     * Паспорт модуля. PrestaShop создаёт этот объект, когда читает список модулей.
     */
    public function __construct()
    {
        $this->name = 'ps_webhooks';
        $this->author = 'Experto PrestaShop';
        $this->version = '1.2.0';
        $this->tab = 'others';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->trans('PrestaShop Webhooks', [], 'Modules.Pswebhooks.Admin');
        $this->description = $this->trans(
            'Trigger real-time notifications on PrestaShop events attaching object data in JSON format to the request.',
            [],
            'Modules.Pswebhooks.Admin'
        );

        // Пункт меню. Вкладка создаётся при установке и удаляется при удалении модуля автоматически.
        // Пока без route_name: она ведёт на старый контроллер AdminWebhooks.
        $this->tabs = [
            [
                'name' => 'Webhooks',
                'class_name' => 'AdminWebhooks',
                'parent_class_name' => 'AdminAdvancedParameters',
                'visible' => true,
            ],
        ];
    }

    /**
     * Установка: регистрирует модуль и вкладку (parent::install), затем создаёт таблицу.
     */
    public function install(): bool
    {
        return parent::install() && $this->createTable();
    }

    /**
     * Удаление: убирает модуль и вкладку (parent::uninstall), затем удаляет таблицу вместе с вебхуками.
     */
    public function uninstall(): bool
    {
        return parent::uninstall() && $this->dropTable();
    }

    private function createTable(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'webhook` (
            `id_webhook` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `action` varchar(10) NOT NULL,
            `entity` varchar(100) NOT NULL,
            `description` text,
            `url` varchar(200) NOT NULL,
            `active` tinyint(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (`id_webhook`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        return (bool) Db::getInstance()->execute($sql);
    }

    private function dropTable(): bool
    {
        return (bool) Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'webhook`');
    }

    /**
     * Кнопка «Настроить» в списке модулей: перекидывает на страницу управления вебхуками.
     */
    public function getContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminWebhooks'));
    }

    /**
     * Сообщает PrestaShop, что модуль использует новую систему переводов.
     */
    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    /**
     * Перехват хуков вида hookActionObject...After.
     * PHP вызывает __call, когда вызванного метода в классе нет.
     *
     * @param string $name   Имя вызванного метода, например hookActionObjectProductAddAfter
     * @param array  $params Аргументы вызова: $params[0] — массив параметров хука
     */
    public function __call($name, $params)
    {
        if (str_starts_with($name, 'hookActionObject')) {
            $this->executeHook(
                str_replace(['hookActionObject', 'After'], '', $name),
                $params[0] ?? []
            );
        }

        return null;
    }

    /**
     * Находит вебхуки, подписанные на событие, и отправляет им данные объекта.
     *
     * @param string $hookName Имя события без обрамления, например ProductAdd
     * @param array  $params   Параметры хука, объект лежит под ключом 'object'
     */
    public function executeHook(string $hookName, array $params): void
    {
        $idsWebhooks = Webhook::getIdsByActionEntity($hookName);
        if (empty($idsWebhooks)) {
            return;
        }

        $object = $params['object'] ?? null;
        if (!is_object($object)) {
            return;
        }

        // Точка расширения: другие модули могут подписаться и изменить объект перед отправкой
        Hook::exec('actionObjectWebhookSendBefore', ['object' => $object]);

        $sender = new WebhookSender();
        foreach ($idsWebhooks as $idWebhook) {
            $webhook = new Webhook((int) $idWebhook);
            $sender->send($webhook->url, $webhook->action, $webhook->entity, $object);
        }
    }

    public function isMcpCompliant(): bool
    {
        return true;
    }
}
