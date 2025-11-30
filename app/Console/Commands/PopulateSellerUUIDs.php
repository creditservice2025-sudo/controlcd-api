<?php

namespace App\Console\Commands;

use App\Models\Seller;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PopulateSellerUUIDs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sellers:populate-uuids';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate UUIDs for sellers that do not have one';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting UUID population for sellers...');

        $sellers = Seller::whereNull('uuid')->get();

        if ($sellers->isEmpty()) {
            $this->info('No sellers found without UUID.');
            return 0;
        }

        $count = 0;
        $bar = $this->output->createProgressBar($sellers->count());
        $bar->start();

        foreach ($sellers as $seller) {
            $seller->uuid = Str::uuid();
            $seller->save();
            $count++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Successfully generated UUIDs for {$count} sellers.");

        return 0;
    }
}
