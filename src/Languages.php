<?php
/**
 * 22-language character-set registry used by the language validation check.
 *
 * @package contact-form-7-spam-checker
 */

declare(strict_types=1);

namespace CF7Sec;

defined('ABSPATH') || exit;

/**
 * Language definitions (Unicode script regexes).
 */
final class Languages
{
    /**
     * Language key => [name, regex].
     *
     * @var array<string, array{name: string, regex: string}>
     */
    public const REGEX = [
        'russian'    => ['name' => 'Russian',    'regex' => '/^[\p{Cyrillic}\s\-\'\.]+$/u'],
        'english'    => ['name' => 'English',    'regex' => '/^[A-Za-z\s\-\'\.]+$/u'],
        'spanish'    => ['name' => 'Spanish',    'regex' => '/^[A-Za-zÁÉÍÓÚáéíóúÑñ\s\-\'\.]+$/u'],
        'french'     => ['name' => 'French',     'regex' => '/^[A-Za-zÀÂÆÇÉÈÊËÎÏÔŒÙÛÜŸàâæçéèêëîïôœùûüÿ\s\-\'\.]+$/u'],
        'german'     => ['name' => 'German',     'regex' => '/^[A-Za-zÄÖÜßäöü\s\-\'\.]+$/u'],
        'chinese'    => ['name' => 'Chinese',    'regex' => '/^[\p{Han}\s]+$/u'],
        'japanese'   => ['name' => 'Japanese',   'regex' => '/^[\p{Hiragana}\p{Katakana}\p{Han}\s]+$/u'],
        'korean'     => ['name' => 'Korean',     'regex' => '/^[\p{Hangul}\s]+$/u'],
        'arabic'     => ['name' => 'Arabic',     'regex' => '/^[\p{Arabic}\s]+$/u'],
        'hindi'      => ['name' => 'Hindi',      'regex' => '/^[\p{Devanagari}\s]+$/u'],
        'portuguese' => ['name' => 'Portuguese', 'regex' => '/^[A-Za-zÁÉÍÓÚáéíóúÃÕãõÇç\s\-\'\.]+$/u'],
        'italian'    => ['name' => 'Italian',    'regex' => '/^[A-Za-zÀÈÉÌÒÓÙàèéìòóù\s\-\'\.]+$/u'],
        'turkish'    => ['name' => 'Turkish',    'regex' => '/^[A-Za-zÇĞİÖŞÜçğıöşü\s\-\'\.]+$/u'],
        'dutch'      => ['name' => 'Dutch',      'regex' => '/^[A-Za-z\s\-\'\.]+$/u'],
        'polish'     => ['name' => 'Polish',     'regex' => '/^[A-Za-zĄĆĘŁŃÓŚŹŻąćęłńóśźż\s\-\'\.]+$/u'],
        'swedish'    => ['name' => 'Swedish',    'regex' => '/^[A-Za-zÅÄÖåäö\s\-\'\.]+$/u'],
        'vietnamese' => ['name' => 'Vietnamese', 'regex' => '/^[A-Za-zÀÁÂÃÈÉÊÌÍÒÓÔÕÙÚÝàáâãèéêìíòóôõùúýĂăĐđĨĩŨũƠơƯư\s\-\'\.]+$/u'],
        'greek'      => ['name' => 'Greek',      'regex' => '/^[\p{Greek}\s]+$/u'],
        'hebrew'     => ['name' => 'Hebrew',     'regex' => '/^[\p{Hebrew}\s]+$/u'],
        'thai'       => ['name' => 'Thai',       'regex' => '/^[\p{Thai}\s]+$/u'],
        'ukrainian'  => ['name' => 'Ukrainian',  'regex' => '/^[\p{Cyrillic}\s\-\'\.ІіЇїЄєҐґ]+$/u'],
        'czech'      => ['name' => 'Czech',      'regex' => '/^[A-Za-zÁČĎÉĚÍŇÓŘŠŤÚŮÝŽáčďéěíňóřšťúůýž\s\-\'\.]+$/u'],
    ];

    /**
     * Translated language names for admin UI.
     *
     * @return array<string, string>
     */
    public static function names(): array
    {
        $names = [];
        foreach (self::REGEX as $key => $def) {
            $names[$key] = translate_with_gettext_context($def['name'], 'language name', 'contact-form-7-spam-checker');
        }

        return $names;
    }
}
