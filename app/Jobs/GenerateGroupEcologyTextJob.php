<?php

namespace App\Jobs;

use App\Services\Biodiversity\EcologyTextService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Redacta el texto ecológico de un género o una familia (una sola vez).
 * Va a la cola ecology-batch salvo que se pida otra al despacharlo.
 */
class GenerateGroupEcologyTextJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $backoff = [120];

    public $timeout = 90;

    public $uniqueFor = 3600;

    public function __construct(public string $rank, public string $name, public string $pool = EcologyTextService::POOL_BATCH)
    {
        $this->onQueue('ecology-batch');
    }

    public function uniqueId(): string
    {
        return $this->rank.':'.mb_strtolower($this->name);
    }

    public function handle(EcologyTextService $texts): void
    {
        if (! $texts->needsGroupText($this->rank, $this->name)) {
            return;
        }

        if ($texts->reserveDailySlot($this->pool)) {
            $texts->generateGroup($this->rank, $this->name);
        }
    }
}
