<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use App\Shared\Json\CanonicalJson;
use InvalidArgumentException;
use PDO;

final readonly class DatabaseAuditTrail implements AuditTrail
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByRequestId(string $requestId): ?array
    {
        $requestId = trim($requestId);
        if ($requestId === '') {
            throw new InvalidArgumentException('RequestID audit không được để trống.');
        }
        $statement = $this->pdo->prepare(
            'SELECT NguoiThucHienID AS actorId, HanhDong AS action,
                    LoaiDoiTu AS entityType, DoiTuID AS entityId,
                    RequestID AS requestId, DuLieuTruocJSON AS beforeJson,
                    DuLieuSauJSON AS afterJson, DiaChiIP AS clientIp, TaoLuc AS createdAt
             FROM NhatKyHeThong WHERE RequestID = :requestId'
        );
        $statement->execute(['requestId' => $requestId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $row['actorId'] = $row['actorId'] === null ? null : (int) $row['actorId'];
        $row['before'] = $this->decode($row['beforeJson']);
        $row['after'] = $this->decode($row['afterJson']);
        unset($row['beforeJson'], $row['afterJson']);
        return $row;
    }

    public function record(
        ?int $actorId,
        string $action,
        string $entityType,
        string $entityId,
        ?string $requestId,
        ?array $before,
        ?array $after,
        ?string $clientIp,
    ): void {
        if (trim($action) === '' || trim($entityType) === '' || trim($entityId) === '') {
            throw new InvalidArgumentException('Bản ghi audit phải có hành động và đối tượng.');
        }
        if ($actorId !== null && $actorId <= 0) {
            throw new InvalidArgumentException('ID người thực hiện audit không hợp lệ.');
        }
        if ($clientIp !== null && filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('Địa chỉ IP audit không hợp lệ.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO NhatKyHeThong (
                NguoiThucHienID, HanhDong, LoaiDoiTu, DoiTuID,
                RequestID, DuLieuTruocJSON, DuLieuSauJSON, DiaChiIP
             ) VALUES (
                :actorId, :action, :entityType, :entityId,
                :requestId, :beforeJson, :afterJson, :clientIp
             )'
        );
        $statement->execute([
            'actorId' => $actorId,
            'action' => trim($action),
            'entityType' => trim($entityType),
            'entityId' => trim($entityId),
            'requestId' => $requestId === null || trim($requestId) === '' ? null : trim($requestId),
            'beforeJson' => $before === null ? null : CanonicalJson::encode($before),
            'afterJson' => $after === null ? null : CanonicalJson::encode($after),
            'clientIp' => $clientIp,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function decode(mixed $json): ?array
    {
        if ($json === null) {
            return null;
        }
        $decoded = json_decode((string) $json, true, 64, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : null;
    }
}
