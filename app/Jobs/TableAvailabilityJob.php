<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Table;

class TableAvailabilityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(Table $table)
    {
        //
        $this->table = $table;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        //
        $table = $this->table;

        if ($table) {
            $table->status = 'avaliaable'; // Update the status to "available"
            $table->save();
        }
    }
}
