<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    /*
    protected $fillable = [
        'name',
        'email',
        'password',
    ];
*/
    protected $guarded = [];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function cards()
    {
        return $this->hasMany(Card::class);
    }

    public function themes()
    {
        return $this->hasMany(Theme::class);
    }

    public function wordboxes()
    {
        return $this->hasMany(Wordbox::class);
    }

    /**
     * The languages the user is currently learning (target-language set, up to 5).
     * The pivot carries the user's proficiency in each language (CEFR: A1..C2).
     */
    public function languages()
    {
        return $this->belongsToMany(Language::class)->withPivot('users_level');
    }

    /**
     * The user's proficiency level (e.g. "B1") for a given target language,
     * or null if the language is not in their set / has no level yet.
     */
    public function levelForLanguage(?Language $language): ?string
    {
        if (! $language) {
            return null;
        }

        return $this->languages()->whereKey($language->id)->first()?->pivot?->users_level;
    }

    /**
     * The currently selected target language (durable default for the save-destination picker).
     */
    public function activeLanguage()
    {
        return $this->belongsTo(Language::class, 'active_language_id');
    }

    /**
     * The user's single native language, used for translations.
     */
    public function nativeLanguage()
    {
        return $this->belongsTo(Language::class, 'native_language_id');
    }

    /**
     * Resolve the language a newly captured word should be saved under:
     * session selection -> active_language_id -> first target language. May be null
     * if the user has not set up any languages yet.
     */
    public function currentSaveLanguage(): ?Language
    {
        $sessionId = session('capture_language_id');
        if ($sessionId && $this->languages()->whereKey($sessionId)->exists()) {
            return Language::find($sessionId);
        }

        if ($this->active_language_id && $this->languages()->whereKey($this->active_language_id)->exists()) {
            return $this->activeLanguage()->first();
        }

        return $this->languages()->first();
    }
}
