<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WordboxCreationTest extends TestCase
{
    use RefreshDatabase;

    private function userWithLanguage(): array
    {
        $user = User::factory()->create();
        $language = Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'native_name' => 'Deutsch', 'flag' => '🇩🇪']);
        $user->languages()->attach($language->id, ['users_level' => 'B1']);

        return [$user, $language];
    }

    /**
     * `description` is NOT NULL with no default, and an empty textarea arrives as null
     * (ConvertEmptyStringsToNull) — this used to be a 500 on every wordbox created
     * without a description.
     */
    public function test_a_wordbox_can_be_created_without_a_description(): void
    {
        [$user, $language] = $this->userWithLanguage();

        $response = $this->actingAs($user)->post('/wordbox/new', [
            'name' => 'Travel Vocabulary',
            'description' => '',
        ]);

        $wordbox = $user->wordboxes()->first();

        $this->assertNotNull($wordbox);
        $response->assertRedirect(route('wordbox.show', ['id' => $wordbox->id]));
        $this->assertSame('Travel Vocabulary', $wordbox->name);
        $this->assertSame('', $wordbox->description);
        $this->assertSame('', $wordbox->exam_text);
        $this->assertSame($language->id, $wordbox->language_id);
    }

    public function test_a_description_is_kept_when_one_is_given(): void
    {
        [$user] = $this->userWithLanguage();

        $this->actingAs($user)->post('/wordbox/new', [
            'name' => 'Chapter 3',
            'description' => 'Words from the third chapter.',
        ]);

        $this->assertSame('Words from the third chapter.', $user->wordboxes()->first()->description);
    }

    public function test_positions_append_within_the_language(): void
    {
        [$user] = $this->userWithLanguage();

        $this->actingAs($user)->post('/wordbox/new', ['name' => 'First']);
        $this->actingAs($user)->post('/wordbox/new', ['name' => 'Second']);

        $this->assertSame([1, 2], $user->wordboxes()->orderBy('id')->pluck('position')->all());
    }
}
