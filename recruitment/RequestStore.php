<?php

namespace Kelast\Recruitment;

use Bitrix\Main\Application;

/** Private server journal. No endpoint exposes its payloads. */
class RequestStore
{
    public const TABLE = 'b_kelast_hr_request';

    public function withLock(string $key, callable $callback)
    {
        $pool = Application::getInstance()->getConnectionPool();
        $previous = $pool->isMasterOnly();
        $pool->useMasterOnly(true);
        $connection = Application::getConnection();
        $lock = 'kelast_hr_' . substr(hash('sha256', $connection->getDatabase() . ':' . $key), 0, 48);
        $locked = false;
        try {
            $locked = $connection->lock($lock, 3);
            if (!$locked) {
                throw new \RuntimeException('Заявка уже обрабатывается. Дождитесь ответа и повторите отправку этой же заявки.');
            }
            return $callback();
        } finally {
            try {
                if ($locked) {
                    $connection->unlock($lock);
                }
            } finally {
                $pool->useMasterOnly($previous);
            }
        }
    }

    public function ready(): bool
    {
        return Application::getConnection()->isTableExists(self::TABLE);
    }

    public function get(string $key): ?array
    {
        $connection = Application::getConnection();
        $key = $connection->getSqlHelper()->forSql($key);
        return $connection->query("SELECT * FROM " . self::TABLE . " WHERE REQUEST_KEY = '" . $key . "'")->fetch() ?: null;
    }

    public function insert(string $key, int $actor, int $responsible, string $hash, string $payload): void
    {
        $connection = Application::getConnection();
        $sql = $connection->getSqlHelper();
        $connection->queryExecute('INSERT INTO ' . self::TABLE
            . ' (REQUEST_KEY, ACTOR_ID, RESPONSIBLE_ID, PAYLOAD_HASH, PAYLOAD_JSON, STATE, TASK_ID, VACANCY_ID, ERROR_CODE, CREATED_AT, UPDATED_AT) VALUES ('
            . "'" . $sql->forSql($key) . "', " . $actor . ', ' . $responsible . ", '" . $sql->forSql($hash)
            . "', '" . $sql->forSql($payload) . "', 'NEW', NULL, 0, '', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    }

    public function update(string $key, array $values): void
    {
        $connection = Application::getConnection();
        $sql = $connection->getSqlHelper();
        $sets = [];
        foreach ($values as $name => $value) {
            if (in_array($name, ['TASK_ID', 'VACANCY_ID'], true)) {
                $sets[] = $name . ' = ' . (int)$value;
            } elseif (in_array($name, ['STATE', 'ERROR_CODE'], true)) {
                $sets[] = $name . " = '" . $sql->forSql((string)$value) . "'";
            } else {
                throw new \InvalidArgumentException('Invalid journal column');
            }
        }
        $sets[] = 'UPDATED_AT = CURRENT_TIMESTAMP';
        $connection->queryExecute('UPDATE ' . self::TABLE . ' SET ' . implode(', ', $sets)
            . " WHERE REQUEST_KEY = '" . $sql->forSql($key) . "'");
    }

    public function discardUnstarted(string $key): void
    {
        $connection = Application::getConnection();
        $key = $connection->getSqlHelper()->forSql($key);
        $connection->queryExecute('DELETE FROM ' . self::TABLE . " WHERE REQUEST_KEY = '" . $key
            . "' AND STATE = 'NEW' AND TASK_ID IS NULL AND VACANCY_ID = 0");
    }

    public static function createSql(): string
    {
        return 'CREATE TABLE ' . self::TABLE . " (
            REQUEST_KEY CHAR(64) NOT NULL PRIMARY KEY,
            ACTOR_ID INT NOT NULL,
            RESPONSIBLE_ID INT NOT NULL,
            PAYLOAD_HASH CHAR(64) NOT NULL,
            PAYLOAD_JSON MEDIUMTEXT NOT NULL,
            STATE VARCHAR(32) NOT NULL,
            TASK_ID INT NULL DEFAULT NULL,
            VACANCY_ID INT NOT NULL DEFAULT 0,
            ERROR_CODE VARCHAR(64) NOT NULL DEFAULT '',
            CREATED_AT DATETIME NOT NULL,
            UPDATED_AT DATETIME NOT NULL,
            UNIQUE KEY UX_HR_TASK (TASK_ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }
}
