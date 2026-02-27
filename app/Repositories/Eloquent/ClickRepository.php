<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\ClickRepositoryInterface;
use App\Models\Click;
use Prettus\Repository\Eloquent\BaseRepository;

class ClickRepository extends BaseRepository implements ClickRepositoryInterface
{
    public function model(): string
    {
        return Click::class;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function insertClick(array $data): int
    {
        $data['created_at'] = now();

        return $this->model->newQuery()->insertGetId($data);
    }
}
