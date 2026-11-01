<?php

namespace App\Jobs;

use App\Services\Biodiversity\GroupPhotoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Busca en segundo plano las fotos de género o familia que le faltan a una
 * página del explorador.
 */
class FetchGroupPhotosJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 300;

    public $uniqueFor = 300;

    /** @param  array<int, array{0: string, 1: string}>  $groups  [rango, nombre] */
    public function __construct(public array $groups) {}

    public function uniqueId(): string
    {
        return sha1(json_encode($this->groups));
    }

    public function handle(GroupPhotoService $photos): void
    {
        foreach ($this->groups as [$rank, $name]) {
            $photos->fetch($rank, $name);
        }
    }
}
