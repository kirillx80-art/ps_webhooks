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

        if (!function_exists('curl_init')) {
            $this->warning = $this->trans(
                'To be able to use this module, please activate cURL (PHP extension).',
                [],
                'Modules.Pswebhooks.Admin'
            );
        }

        // Пункт меню. Вкладка создаётся при установке и удаляется при удалении модуля автоматически.
        // Без route_name она пока ведёт на старый контроллер AdminWebhooks.
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
     * @param array  $params Аргументы вызова
     */
    public function __call($name, $params)
    {
        if (str_starts_with($name, 'hookActionObject')) {
            return $this->executeHook(str_replace(['hookActionObject', 'After'], '', $name), $params[0]);
        }

        return null;
    }

    /**
     * Execute a hook and send data to the webhook
     *
     * @param string $hook_name Name of the hook to execute
     * @param array $params Hook parameters
     * @return void
     */
    public function executeHook($hook_name, $params)
    {
        $ids_webhooks = Webhook::getIdsByActionEntity($hook_name);
        if (!$ids_webhooks) {
            return;
        }

        $object = $params['object'];
        Hook::exec('actionObjectWebhookSendBefore', ['object' => $object]);

        foreach ($ids_webhooks as $id_webhook) {
            $webhook = new Webhook($id_webhook);
            self::executeUrl($webhook->url, $webhook->action, $webhook->entity, $object);
        }
    }

    /**
     * Send HTTP request to the specified URL to trigger the webhook
     * 
     * @param string $url The target URL for the webhook
     * @param string $action The action performed (add, update, delete)
     * @param string $entity The type of entity concerned
     * @param object $object The object to send
     * @param bool $test If true, performs a connection test
     * @return int|bool HTTP code or false on error
     */
    public static function executeUrl($url, $action, $entity, $object, $test = false)
    {
        // Security: Validate that the entity is a valid class inheriting from ObjectModel
        if (!class_exists($entity) || !is_subclass_of($entity, 'ObjectModel')) {
            return false;
        }

        // Security: Filter sensitive data before sending
        $object_vars = get_object_vars($object);
        unset($object_vars['passwd']);
        unset($object_vars['secure_key']);
        unset($object_vars['password']);

        $curl = curl_init($url);
        $curl_options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 3,
            // Security: Enable SSL verification to prevent MITM attacks when using HTTPS
            CURLOPT_SSL_VERIFYHOST => Tools::usingSecureMode(),
            CURLOPT_SSL_VERIFYPEER => Tools::usingSecureMode(),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['action' => $action, 'entity' => $entity, 'data' => $object_vars]),
        ];
        curl_setopt_array($curl, $curl_options);

        if ($test) {
            $curl_options = [
                CURLOPT_NOBODY => false,
                CURLOPT_FORBID_REUSE => false,
            ];
            curl_setopt_array($curl, $curl_options);
        }

        curl_exec($curl);

        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        return $code;
    }

    public function isMcpCompliant(): bool
    {
        return true;
    }
}
