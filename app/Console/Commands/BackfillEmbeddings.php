<?php

namespace App\Console\Commands;

use App\Jobs\GenerateEmbeddingJob;
use App\Models\Card;
use Illuminate\Console\Command;

class BackfillEmbeddings extends Command
{
    protected $signature = 'cards:backfill-embeddings {--user= : Specific user ID}';

    protected $description = 'Generate embeddings for existing cards that don\'t have one';

    public function handle(): void
    {
        $query = Card::whereNull('embedding');

        if ($userId = $this->option('user')) {
            $query->where('user_id', $userId);
        }

        $count = $query->count();
        $this->info("Dispatching embedding jobs for {$count} cards...");

        $query->each(function (Card $card) {
            GenerateEmbeddingJob::dispatch($card);
        });

        $this->info('All jobs dispatched. Run the queue worker to process them.');
    }
}
