<?php

namespace App\Helpers;

class SearchFilterOptions
{
    public static function properties(): array
    {
        return [
            'course' => 'Course Identity',
            'topics' => 'Topics',
            'learning_outcomes' => 'Learning Objectives',
            'assessments' => 'Assessments',
            'descriptions' => 'Descriptions',
            'materials' => 'Materials',
        ];
    }

    public static function propertyKeys(): array
    {
        return array_keys(self::properties());
    }

    public static function propertyValidationRule(): string
    {
        return 'in:' . implode(',', self::propertyKeys());
    }
}
