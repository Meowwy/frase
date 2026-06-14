<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Language extends Model
{
    protected $guarded = [];

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }

    public function wordboxes(): HasMany
    {
        return $this->hasMany(Wordbox::class);
    }

    public function themes(): HasMany
    {
        return $this->hasMany(Theme::class);
    }

    /**
     * Users who are learning this language (target-language set).
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
