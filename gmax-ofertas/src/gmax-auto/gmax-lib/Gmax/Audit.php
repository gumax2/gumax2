<?php
declare(strict_types=1);

namespace Gmax;

use PDO;

final class Audit
{
    public static function log(PDO $pdo, string $actor, string $action, ?string $entity = null, string|int|null $entityId = null, array $detail = []): void
    {
        Db::run($pdo, 'INSERT INTO gmax3_audit(at,actor,action,entity,entity_id,detail) VALUES(?,?,?,?,?,?)', [
            Time::nowSql(), $actor, $action, $entity, $entityId === null ? null : (string)$entityId,
            $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null,
        ]);
    }
}
