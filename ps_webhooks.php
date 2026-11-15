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

use PrestaShop\Module\PsWebhooks\Repository\WebhookRepository;
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
        $this->version = '2.0.0';
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
        // route_name связывает пункт меню с маршрутом из config/routes.yml.
        $this->tabs = [
            [
                'name' => 'Webhooks',
                'class_name' => 'AdminWebhooks',
                'parent_class_name' => 'AdminAdvancedParameters',
                'route_name' => 'ps_webhooks_index',
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
     * Кнопка «Настроить» в списке модулей: перекидывает на Symfony-страницу модуля.
     */
    public function getContent()
    {
        Tools::redirectAdmin($this->get('router')->generate('ps_webhooks_index'));
    }

    /**
     * Сообщает PrestaShop, что модуль использует новую систему переводов.
     */
    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    /**
     * Перехват хуков вида hookActionObject<Сущность><Add|Update|Delete>After.
     * PHP вызывает __call, когда вызванного метода в классе нет.
     *
     * Пример: hookActionObjectProductAddAfter -> сущность Product, действие add.
     *
     * @param string $name   Имя вызванного метода
     * @param array  $params Аргументы вызова: $params[0] — массив параметров хука
     */
    public function __call($name, $params)
    {
        if (preg_match('/^hookActionObject(.+)(Add|Update|Delete)After$/', $name, $matches)) {
            $this->executeHook($matches[1], strtolower($matches[2]), $params[0] ?? []);
        }

        return null;
    }

    /**
     * Находит вебхуки, подписанные на событие, и отправляет им данные объекта.
     *
     * @param string $entity Класс объекта, например Product
     * @param string $action add, update или delete
     * @param array  $params Параметры хука, объект лежит под ключом 'object'
     */
    public function executeHook(string $entity, string $action, array $params): void
    {
        $webhooks = (new WebhookRepository())->findActiveByEvent($entity, $action);
        if (empty($webhooks)) {
            return;
        }

        $object = $params['object'] ?? null;
        if (!is_object($object)) {
            return;
        }

        // Точка расширения: другие модули могут подписаться и изменить объект перед отправкой
        Hook::exec('actionObjectWebhookSendBefore', ['object' => $object]);

        $sender = new WebhookSender();
        foreach ($webhooks as $webhook) {
            $sender->send($webhook->url, $webhook->action, $webhook->entity, $object);
        }
    }
}
