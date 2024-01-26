<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Table;
use Carbon\Carbon;

class UpdateTableStatus extends Command
{

    
    /**
     * Execute the console command.
     */
    protected $signature = 'table:update-status';
    protected $description = 'Update table statuses';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $tables = Table::where('status', 'unavaliable')
            ->where('updated_at', '<=', Carbon::now()->subHours(2))
            ->get();

        foreach ($tables as $table) {
            $table->status = 'avaliable';
            $table->save();
        }

        $this->info('Table statuses updated successfully.');
    }
}
