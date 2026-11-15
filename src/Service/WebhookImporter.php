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

use JsonException;
use PrestaShop\Module\PsWebhooks\Entity\Webhook;
use PrestaShop\Module\PsWebhooks\Repository\WebhookRepository;

/**
 * Импорт вебхуков из JSON: сначала проверяется весь файл, и только если ошибок нет, строки сохраняются.
 * Сообщения об ошибках не содержат данных из файла: они показываются в админке как есть.
 */
final class WebhookImporter
{
    private const ACTIONS = ['add', 'update', 'delete'];
    private const MAX_ROWS = 500;

    public function __construct(
        private readonly WebhookRepository $repository,
        private readonly EntityCatalog $catalog,
    ) {}

    /**
     * @return array{imported: int, skipped: int, errors: list<string>}
     */
    public function import(string $json): array
    {
        try {
            $rows = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->failure('Invalid JSON format.');
        }

        if (!is_array($rows) || !array_is_list($rows) || $rows === []) {
            return $this->failure('The file must contain a list of webhooks.');
        }
        if (count($rows) > self::MAX_ROWS) {
            return $this->failure('Too many webhooks in the file.');
        }

        $allowedEntities = $this->catalog->getClasses();

        // Уже существующие вебхуки: повторный импорт того же файла не создаёт дубликатов
        $known = [];
        foreach ($this->repository->findAll() as $existing) {
            $known[$this->key($existing->action, $existing->entity, $existing->url)] = true;
        }

        $errors = [];
        $toSave = [];
        $skipped = 0;

        foreach ($rows as $index => $row) {
            $problem = $this->validate($row, $allowedEntities);
            if ($problem !== null) {
                $errors[] = sprintf('Item %d: %s.', $index + 1, $problem);
                continue;
            }

            $key = $this->key($row['action'], $row['entity'], $row['url']);
            if (isset($known[$key])) {
                ++$skipped;
                continue;
            }
            $known[$key] = true;

            $toSave[] = new Webhook(
                null,
                $row['action'],
                $row['entity'],
                $row['url'],
                (string) ($row['description'] ?? ''),
                array_key_exists('active', $row) ? filter_var($row['active'], FILTER_VALIDATE_BOOLEAN) : true
            );
        }

        // Всё или ничего: при любой ошибке не сохраняется ни одна строка
        if ($errors !== []) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => $errors];
        }

        foreach ($toSave as $webhook) {
            $this->repository->save($webhook);
        }

        return ['imported' => count($toSave), 'skipped' => $skipped, 'errors' => []];
    }

    /**
     * Возвращает описание проблемы или null, если строка корректна.
     *
     * @param list<string> $allowedEntities
     */
    private function validate(mixed $row, array $allowedEntities): ?string
    {
        if (!is_array($row)) {
            return 'must be an object';
        }
        if (!in_array($row['action'] ?? null, self::ACTIONS, true)) {
            return 'action must be add, update or delete';
        }
        if (!is_string($row['entity'] ?? null) || !in_array($row['entity'], $allowedEntities, true)) {
            return 'unknown entity';
        }

        $url = $row['url'] ?? null;
        if (!is_string($url) || strlen($url) > 200 || !$this->isHttpUrl($url)) {
            return 'url must be a valid http or https address of up to 200 characters';
        }
        if (isset($row['description']) && !is_string($row['description'])) {
            return 'description must be text';
        }

        return null;
    }

    private function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private function key(string $action, string $entity, string $url): string
    {
        return strtolower($action . '|' . $entity . '|' . $url);
    }

    /**
     * @return array{imported: int, skipped: int, errors: list<string>}
     */
    private function failure(string $message): array
    {
        return ['imported' => 0, 'skipped' => 0, 'errors' => [$message]];
    }
}
