<?php

use App\Models\Language;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Aliases for historical free-text values that don't match a code/name directly.
     *
     * @var array<string, string>
     */
    private array $aliases = [
        'czech' => 'cs',
        'english' => 'en',
        'german' => 'de',
        'spanish' => 'es',
        'french' => 'fr',
    ];

    public function up(): void
    {
        // Ensure the languages list exists before resolving anything.
        (new \Database\Seeders\LanguageSeeder)->run();

        $languages = Language::all();

        foreach (DB::table('users')->get() as $user) {
            $native = $this->resolve($user->native_language ?? null, $languages);
            $target = $this->resolve($user->target_language ?? null, $languages);

            $updates = [];
            if ($native) {
                $updates['native_language_id'] = $native->id;
            }
            if ($target) {
                $updates['active_language_id'] = $target->id;

                // Add to the target-language set (idempotent).
                $exists = DB::table('language_user')
                    ->where('user_id', $user->id)
                    ->where('language_id', $target->id)
                    ->exists();
                if (! $exists) {
                    DB::table('language_user')->insert([
                        'user_id' => $user->id,
                        'language_id' => $target->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // All of this user's existing content belongs to their single language today.
                DB::table('cards')->where('user_id', $user->id)->update(['language_id' => $target->id]);
                DB::table('wordboxes')->where('user_id', $user->id)->update(['language_id' => $target->id]);
                DB::table('themes')->where('user_id', $user->id)->update(['language_id' => $target->id]);
            }

            if ($updates) {
                DB::table('users')->where('id', $user->id)->update($updates);
            }
        }
    }

    public function down(): void
    {
        DB::table('users')->update(['active_language_id' => null, 'native_language_id' => null]);
        DB::table('language_user')->delete();
        DB::table('cards')->update(['language_id' => null]);
        DB::table('wordboxes')->update(['language_id' => null]);
        DB::table('themes')->update(['language_id' => null]);
    }

    private function resolve(?string $value, $languages): ?Language
    {
        if ($value === null) {
            return null;
        }
        $needle = strtolower(trim($value));
        if ($needle === '' || $needle === '-1') {
            return null;
        }

        if (isset($this->aliases[$needle])) {
            $needle = $this->aliases[$needle];
        }

        return $languages->first(function ($lang) use ($needle) {
            return strtolower($lang->code) === $needle
                || strtolower($lang->name) === $needle
                || strtolower($lang->native_name) === $needle;
        });
    }
};
