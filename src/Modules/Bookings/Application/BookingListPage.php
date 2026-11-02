<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use InvalidArgumentException;

final readonly class BookingListPage
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
        if (!array_is_list($this->items) || $this->page < 1 || $this->perPage < 1 || $this->total < 0) {
            throw new InvalidArgumentException('Trang danh sách booking không hợp lệ.');
        }
    }

    public function pageCount(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }
}
