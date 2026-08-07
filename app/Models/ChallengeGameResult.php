<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChallengeGameResult extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'completed' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(ChallengeScene::class, 'challenge_scene_id');
    }
}
