<?php

namespace App\Jobs;

use App\Models\AI;
use App\Models\Card;
use App\Models\RelatedTerm;
use App\Models\Synonym;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateEmbeddingJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Card $card
    ) {}

    public function handle(): void
    {
        try {
            $embedding = AI::getEmbedding($this->card->phrase);

            if (is_null($embedding)) {
                Log::error('Failed to get embedding for card '.$this->card->id);

                return;
            }

            $this->card->update(['embedding' => $embedding]);

            $otherCards = Card::where('user_id', $this->card->user_id)
                ->where('id', '!=', $this->card->id)
                ->whereNotNull('embedding')
                ->get();

            foreach ($otherCards as $otherCard) {
                $score = AI::cosineSimilarity($embedding, $otherCard->embedding);

                if ($score >= 0.90) {
                    Synonym::updateOrCreate(
                        ['card_id' => $this->card->id, 'synonym_card_id' => $otherCard->id],
                        ['similarity_score' => $score]
                    );
                    Synonym::updateOrCreate(
                        ['card_id' => $otherCard->id, 'synonym_card_id' => $this->card->id],
                        ['similarity_score' => $score]
                    );
                } elseif ($score >= 0.75) {
                    RelatedTerm::updateOrCreate(
                        ['card_id' => $this->card->id, 'related_card_id' => $otherCard->id],
                        ['similarity_score' => $score]
                    );
                    RelatedTerm::updateOrCreate(
                        ['card_id' => $otherCard->id, 'related_card_id' => $this->card->id],
                        ['similarity_score' => $score]
                    );
                }
            }

            Log::info('Embedding generated and comparisons done for card '.$this->card->id);
        } catch (\Exception $e) {
            Log::error('Embedding generation failed for card '.$this->card->id.': '.$e->getMessage());
        }
    }
}
