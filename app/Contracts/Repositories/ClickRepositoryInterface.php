<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use Prettus\Repository\Contracts\RepositoryInterface;

interface ClickRepositoryInterface extends RepositoryInterface
{
    /**
     * Insert a click record (bulk-compatible signature).
     *
     * @param  array<string, mixed>  $data
     */
    public function insertClick(array $data): int;
}
