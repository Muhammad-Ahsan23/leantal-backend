<?php

namespace App\Support;

use App\Models\Candidate;
use App\Models\Company;
use App\Models\Department;
use App\Models\Job;
use App\Models\User;

/**
 * Display-friendly capitalization for the short text fields people type into LeanTal
 * (job title, department, location, names). It only ever FIXES lowercase words:
 *   "senior backend engineer" -> "Senior Backend Engineer"
 *   "pakistan"                -> "Pakistan"
 *   "new york, ny"            -> "New York, NY"
 * Anything that already contains a capital letter is left exactly as typed, so
 * "QA Engineer", "iOS Developer", "McDonald" and "UI/UX Designer" are never damaged.
 * Emails, websites, slugs and long descriptions are NOT touched.
 */
class TextFormat
{
    /** Small words that stay lowercase inside a title (never as the first word). */
    private const TITLE_SMALL = ['a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'in', 'nor', 'of', 'on', 'or', 'per', 'the', 'to', 'via', 'vs'];

    /** Name particles that stay lowercase inside a person's name. */
    private const NAME_SMALL = ['de', 'del', 'della', 'di', 'da', 'la', 'le', 'van', 'von', 'der', 'den', 'bin', 'bint', 'al', 'el'];

    /** Job-title / company abbreviations that read wrong as "Hr" or "Qa" (not applied to person names). */
    private const ACRONYMS = [
        'hr' => 'HR', 'qa' => 'QA', 'qc' => 'QC', 'ui' => 'UI', 'ux' => 'UX', 'it' => 'IT', 'cto' => 'CTO', 'ceo' => 'CEO',
        'cfo' => 'CFO', 'coo' => 'COO', 'cmo' => 'CMO', 'vp' => 'VP', 'svp' => 'SVP', 'evp' => 'EVP', 'seo' => 'SEO',
        'sem' => 'SEM', 'sql' => 'SQL', 'api' => 'API', 'ai' => 'AI', 'ml' => 'ML', 'erp' => 'ERP', 'crm' => 'CRM',
        'php' => 'PHP', 'aws' => 'AWS', 'b2b' => 'B2B', 'b2c' => 'B2C', 'saas' => 'SaaS', 'sdet' => 'SDET',
        'ios' => 'iOS', 'devops' => 'DevOps', 'qa/qc' => 'QA/QC',
    ];

    /** Always upper case when written as a whole word in a location. */
    private const LOCATION_ACRONYMS = ['usa', 'uk', 'uae', 'us'];

    /** model => [column => mode]. Applied automatically every time one of these models is saved. */
    public const FIELDS = [
        Job::class => ['title' => 'title', 'department' => 'title', 'location' => 'location'],
        Department::class => ['name' => 'title'],
        Candidate::class => ['name' => 'name', 'location' => 'location', 'current_title' => 'title', 'current_company' => 'title'],
        Company::class => ['name' => 'title', 'location' => 'location'],
        User::class => ['name' => 'name'],
    ];

    /** Called once from AppServiceProvider::boot(). */
    public static function register(): void
    {
        foreach (self::FIELDS as $model => $fields) {
            $model::saving(function ($record) use ($fields) {
                foreach ($fields as $column => $mode) {
                    $value = $record->getAttribute($column);
                    if (is_string($value) && $record->isDirty($column)) {
                        $record->setAttribute($column, self::apply($mode, $value));
                    }
                }
            });
        }
    }

    public static function apply(string $mode, string $value): string
    {
        return match ($mode) {
            'name' => self::name($value),
            'location' => self::location($value),
            default => self::title($value),
        };
    }

    /** Job titles, departments, company names, current title/company. */
    public static function title(string $value): string
    {
        return self::words($value, self::TITLE_SMALL, true);
    }

    /** Person names. Also fixes a name typed completely in capitals ("JOHN SMITH"). */
    public static function name(string $value): string
    {
        $value = trim($value);
        if ($value !== '' && !preg_match('/\p{Ll}/u', $value) && mb_strlen($value) >= 3) {
            $value = mb_strtolower($value);
        }

        return self::words($value, self::NAME_SMALL, false);
    }

    /** "new york, ny" -> "New York, NY"; "london, uk" -> "London, UK". */
    public static function location(string $value): string
    {
        $out = self::words($value, self::TITLE_SMALL, false);
        // A 2-letter piece after a comma is a state / country code.
        $out = preg_replace_callback('/(,\s*)(\p{L}{2})(?=\s*(?:,|$))/u', fn ($m) => $m[1].mb_strtoupper($m[2]), $out);
        $acr = implode('|', self::LOCATION_ACRONYMS);

        return preg_replace_callback('/(?<![\p{L}\p{N}])('.$acr.')(?![\p{L}\p{N}])/iu', fn ($m) => mb_strtoupper($m[1]), $out);
    }

    private static function words(string $value, array $small, bool $acronyms): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return $value;
        }
        $parts = explode(' ', $value);
        foreach ($parts as $i => $word) {
            $parts[$i] = self::word($word, $i === 0, $small, $acronyms);
        }

        return implode(' ', $parts);
    }

    private static function word(string $word, bool $first, array $small, bool $acronyms): string
    {
        // Already has a capital (QA, iOS, McDonald) or has no letters at all (2024, &, -): leave as typed.
        if (preg_match('/\p{Lu}/u', $word) || !preg_match('/\p{Ll}/u', $word)) {
            return $word;
        }
        $bare = mb_strtolower(trim($word, ',.;:()[]'));
        if (!$first && in_array($bare, $small, true)) {
            return $word;
        }

        // Work on each piece between - / ( [ ) ] so "full-stack", "ui/ux" and "(remote)" are each handled.
        $pieces = preg_split('/([-\/()\[\]]+)/u', $word, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($pieces as $i => $piece) {
            if ($piece === '' || preg_match('/^[-\/()\[\]]+$/u', $piece)) {
                continue;
            }
            $key = mb_strtolower(trim($piece, ',.;:'));
            if ($acronyms && isset(self::ACRONYMS[$key])) {
                $pieces[$i] = str_replace($key, self::ACRONYMS[$key], mb_strtolower($piece));
                continue;
            }
            // o'brien -> O'Brien (single letter before the apostrophe), otherwise just the first letter.
            $piece = preg_replace_callback("/^(\p{Ll})'(\p{Ll})/u", fn ($m) => mb_strtoupper($m[1])."'".mb_strtoupper($m[2]), $piece, 1, $count);
            if (!$count) {
                $piece = preg_replace_callback('/^(\p{Ll})/u', fn ($m) => mb_strtoupper($m[1]), $piece);
            }
            $pieces[$i] = $piece;
        }

        return implode('', $pieces);
    }
}
