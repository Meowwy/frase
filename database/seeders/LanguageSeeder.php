<?php

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Seeder;

class LanguageSeeder extends Seeder
{
    /**
     * A curated set of commonly learned languages (ISO 639-1 codes).
     * The list is static; re-running is idempotent via updateOrCreate on `code`.
     *
     * @var array<int, array{0:string,1:string,2:string,3:string}> [code, English name, native name, flag]
     */
    public const LANGUAGES = [
        ['en', 'English', 'English', '🇬🇧'],
        ['cs', 'Czech', 'Čeština', '🇨🇿'],
        ['sk', 'Slovak', 'Slovenčina', '🇸🇰'],
        ['de', 'German', 'Deutsch', '🇩🇪'],
        ['fr', 'French', 'Français', '🇫🇷'],
        ['es', 'Spanish', 'Español', '🇪🇸'],
        ['it', 'Italian', 'Italiano', '🇮🇹'],
        ['pt', 'Portuguese', 'Português', '🇵🇹'],
        ['nl', 'Dutch', 'Nederlands', '🇳🇱'],
        ['pl', 'Polish', 'Polski', '🇵🇱'],
        ['ru', 'Russian', 'Русский', '🇷🇺'],
        ['uk', 'Ukrainian', 'Українська', '🇺🇦'],
        ['sv', 'Swedish', 'Svenska', '🇸🇪'],
        ['no', 'Norwegian', 'Norsk', '🇳🇴'],
        ['da', 'Danish', 'Dansk', '🇩🇰'],
        ['fi', 'Finnish', 'Suomi', '🇫🇮'],
        ['is', 'Icelandic', 'Íslenska', '🇮🇸'],
        ['hu', 'Hungarian', 'Magyar', '🇭🇺'],
        ['ro', 'Romanian', 'Română', '🇷🇴'],
        ['bg', 'Bulgarian', 'Български', '🇧🇬'],
        ['hr', 'Croatian', 'Hrvatski', '🇭🇷'],
        ['sr', 'Serbian', 'Српски', '🇷🇸'],
        ['sl', 'Slovenian', 'Slovenščina', '🇸🇮'],
        ['el', 'Greek', 'Ελληνικά', '🇬🇷'],
        ['tr', 'Turkish', 'Türkçe', '🇹🇷'],
        ['ar', 'Arabic', 'العربية', '🇸🇦'],
        ['he', 'Hebrew', 'עברית', '🇮🇱'],
        ['fa', 'Persian', 'فارسی', '🇮🇷'],
        ['hi', 'Hindi', 'हिन्दी', '🇮🇳'],
        ['bn', 'Bengali', 'বাংলা', '🇧🇩'],
        ['ur', 'Urdu', 'اردو', '🇵🇰'],
        ['th', 'Thai', 'ไทย', '🇹🇭'],
        ['vi', 'Vietnamese', 'Tiếng Việt', '🇻🇳'],
        ['id', 'Indonesian', 'Bahasa Indonesia', '🇮🇩'],
        ['ms', 'Malay', 'Bahasa Melayu', '🇲🇾'],
        ['zh', 'Chinese', '中文', '🇨🇳'],
        ['ja', 'Japanese', '日本語', '🇯🇵'],
        ['ko', 'Korean', '한국어', '🇰🇷'],
        ['sw', 'Swahili', 'Kiswahili', '🇰🇪'],
        ['ca', 'Catalan', 'Català', '🇪🇸'],
        ['et', 'Estonian', 'Eesti', '🇪🇪'],
        ['lv', 'Latvian', 'Latviešu', '🇱🇻'],
        ['lt', 'Lithuanian', 'Lietuvių', '🇱🇹'],
    ];

    public function run(): void
    {
        foreach (self::LANGUAGES as [$code, $name, $nativeName, $flag]) {
            Language::updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'native_name' => $nativeName, 'flag' => $flag],
            );
        }
    }
}
