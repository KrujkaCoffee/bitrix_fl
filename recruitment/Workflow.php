<?php

namespace Kelast\Recruitment;

/** Checkpoint task and vacancy creation separately; never repeat an ambiguous task write. */
class Workflow
{
    private $store;
    private $gateway;

    public function __construct(RequestStore $store, Gateway $gateway)
    {
        $this->store = $store;
        $this->gateway = $gateway;
    }

    public function submit(string $token, int $actor, int $responsible, array $values,
        string $description, string $fingerprint, callable $createTask): array
    {
        if (!preg_match('/^[a-f0-9-]{32,64}$/Di', $token) || $actor <= 0 || $responsible <= 0) {
            throw new \RuntimeException('Некорректный ключ заявки. Обновите форму перед созданием новой заявки.');
        }
        if (!$this->store->ready()) {
            throw new \RuntimeException('Пилот подбора ещё не подготовлен администратором.');
        }
        $key = hash('sha256', $actor . ':344025:' . strtolower($token));
        return $this->store->withLock($key, function () use ($key, $actor, $responsible, $values, $description, $fingerprint, $createTask) {
            $row = $this->store->get($key);
            // A reservation which never entered task creation has no task side effects.
            if ($row && $row['STATE'] === 'NEW' && (int)$row['TASK_ID'] === 0) {
                $this->store->discardUnstarted($key);
                $row = null;
            }
            if ($row && !hash_equals($row['PAYLOAD_HASH'], $fingerprint)) {
                throw new \RuntimeException('Эта заявка уже отправлялась с другими данными. Проверьте результат её обработки перед созданием новой заявки.');
            }
            if ($row && (int)$row['ACTOR_ID'] !== $actor) {
                throw new \RuntimeException('Автор заявки не совпадает.');
            }
            if (!$row) {
                // Fail before any task/file writes if CRM schema or writer permissions are unavailable.
                $this->gateway->preflight($actor, $responsible);
                $payload = json_encode(['values' => $values, 'description' => $description], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $this->store->insert($key, $actor, $responsible, $fingerprint, $payload);
                $row = $this->store->get($key);
            }
            if ((int)$row['TASK_ID'] <= 0) {
                if ($row['STATE'] !== 'NEW') {
                    return $this->uncertain($actor);
                }
                $this->store->update($key, ['STATE' => 'TASK_CREATING']);
                try {
                    $taskId = (int)$createTask();
                    if ($taskId <= 0) {
                        throw new \RuntimeException('Task ID missing');
                    }
                } catch (BeforeTaskCreationException $error) {
                    $this->store->update($key, ['STATE' => 'NEW']);
                    $this->store->discardUnstarted($key);
                    return ['validationErrors' => [$error->getMessage()], 'fieldErrors' => []];
                } catch (\Throwable $error) {
                    $this->store->update($key, ['STATE' => 'TASK_UNCERTAIN', 'ERROR_CODE' => 'TASK_RESULT_UNKNOWN']);
                    return $this->uncertain($actor);
                }
                // If this checkpoint itself fails the durable TASK_CREATING state forbids another build().
                $this->store->update($key, ['STATE' => 'TASK_CREATED', 'TASK_ID' => $taskId]);
                $row = $this->store->get($key);
            }
            return $this->finish($key, $row);
        });
    }

    /** Read the result by the authenticated author's token without accepting a payload. */
    public function status(string $token, int $actor): array
    {
        if (!preg_match('/^[a-f0-9-]{32,64}$/Di', $token) || $actor <= 0) {
            throw new \RuntimeException('Некорректный ключ заявки.');
        }
        if (!$this->store->ready()) {
            return ['recruitmentStatus' => 'NOT_FOUND'];
        }
        $key = hash('sha256', $actor . ':344025:' . strtolower($token));
        return $this->store->withLock($key, function () use ($key, $actor) {
            $row = $this->store->get($key);
            if (!$row || ($row['STATE'] === 'NEW' && (int)$row['TASK_ID'] === 0)) {
                return ['recruitmentStatus' => 'NOT_FOUND'];
            }
            if ((int)$row['ACTOR_ID'] !== $actor) {
                throw new \RuntimeException('Автор заявки не совпадает.');
            }
            if ((int)$row['TASK_ID'] <= 0) {
                return $this->uncertain($actor);
            }
            return ['recruitmentStatus' => $row['STATE'] === 'COMPLETE' ? 'COMPLETE' : 'PENDING',
                'userId' => $actor, 'taskId' => (int)$row['TASK_ID'], 'recruitmentMessage' =>
                'Задача №' . $row['TASK_ID'] . ' сохранена. Повторно создавать заявку не требуется.'];
        });
    }

    /** Admin-only caller supplies an existing journal key, never creates a task. */
    public function retryVacancy(string $key): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $key)) {
            throw new \RuntimeException('Некорректный ключ журнала.');
        }
        return $this->store->withLock($key, function () use ($key) {
            $row = $this->store->get($key);
            if (!$row || (int)$row['TASK_ID'] <= 0) {
                throw new \RuntimeException('Нет подтверждённой задачи. Сначала требуется сверить результат создания задачи.');
            }
            return $this->finish($key, $row);
        });
    }

    private function finish(string $key, array $row): array
    {
        $taskId = (int)$row['TASK_ID'];
        $actor = (int)$row['ACTOR_ID'];
        $responsible = (int)$row['RESPONSIBLE_ID'];
        $base = ['userId' => $actor, 'taskId' => $taskId];
        if ($row['STATE'] === 'COMPLETE' && (int)$row['VACANCY_ID'] > 0) {
            return $base + ['recruitmentStatus' => 'COMPLETE'];
        }
        try {
            $payload = json_decode($row['PAYLOAD_JSON'], true, 512, JSON_THROW_ON_ERROR);
            $vacancy = $this->gateway->ensureVacancy($taskId, $actor, $responsible,
                $payload['values'], $payload['description']);
            $this->store->update($key, ['STATE' => 'COMPLETE', 'VACANCY_ID' => $vacancy['id'], 'ERROR_CODE' => '']);
            return $base + ['recruitmentStatus' => 'COMPLETE'];
        } catch (\Throwable $error) {
            $this->store->update($key, ['STATE' => 'VACANCY_PENDING', 'ERROR_CODE' => 'CRM_RETRY_REQUIRED']);
            return $base + ['recruitmentStatus' => 'PENDING', 'recruitmentMessage' =>
                'Задача №' . $taskId . ' сохранена. Создание вакансии пока не подтверждено. Сообщите администратору номер задачи; повторно создавать заявку не требуется.'];
        }
    }

    private function uncertain(int $actor): array
    {
        return ['userId' => $actor, 'recruitmentStatus' => 'UNCERTAIN', 'recruitmentMessage' =>
            'Не удалось подтвердить результат создания задачи. Не создавайте эту заявку повторно: администратору нужно проверить, сохранилась ли задача.'];
    }
}
