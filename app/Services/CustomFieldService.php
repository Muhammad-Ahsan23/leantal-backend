<?php

namespace App\Services;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Illuminate\Support\Facades\DB;

class CustomFieldService
{
    /**
     * Validates every submitted value against its field's OWN type rules
     * (can't be static FormRequest rules since fields are dynamic/
     * per-company). Returns a Laravel-style errors array (empty if valid).
     */
    public function validateValues(string $entityType, array $values, string $connection): array
    {
        $errors = [];
        $fields = CustomField::on($connection)
            ->where('entity_type', $entityType)
            ->whereIn('id', array_keys($values))
            ->get()
            ->keyBy('id');

        // Also check REQUIRED fields that weren't submitted at all.
        $requiredFields = CustomField::on($connection)
            ->where('entity_type', $entityType)
            ->where('required', true)
            ->get();

        foreach ($requiredFields as $field) {
            if (blank($values[$field->id] ?? null)) {
                $errors["values.{$field->id}"] = ["{$field->field_name} is required."];
            }
        }

        foreach ($values as $fieldId => $value) {
            $field = $fields->get($fieldId);
            if (!$field) {
                $errors["values.{$fieldId}"] = ['This field does not exist for this entity type.'];
                continue;
            }
            if (blank($value)) {
                continue; // already covered by the required-check above if needed
            }

            $error = match ($field->field_type) {
                'number' => is_numeric($value) ? null : "{$field->field_name} must be a number.",
                'date' => strtotime($value) !== false ? null : "{$field->field_name} must be a valid date.",
                'boolean' => in_array(strtolower((string) $value), ['1', '0', 'true', 'false'], true) ? null : "{$field->field_name} must be true or false.",
                'dropdown' => in_array($value, $field->options ?? [], true) ? null : "{$field->field_name} must be one of: ".implode(', ', $field->options ?? []),
                default => null, // 'text' — anything goes
            };

            if ($error) {
                $errors["values.{$fieldId}"] = [$error];
            }
        }

        return $errors;
    }

    /**
     * Upserts every submitted value — custom_field_values has a UNIQUE
     * constraint on (custom_field_id, entity_id), so this is safe to
     * call repeatedly (e.g. every time the candidate/job is edited).
     */
    public function setValues(string $entityId, array $values, string $connection): void
    {
        DB::connection($connection)->transaction(function () use ($entityId, $values, $connection) {
            foreach ($values as $fieldId => $value) {
                CustomFieldValue::on($connection)->updateOrCreate(
                    ['custom_field_id' => $fieldId, 'entity_id' => $entityId],
                    ['value' => $value]
                );
            }
        });
    }

    public function getValues(string $entityType, string $entityId, string $connection): array
    {
        return CustomFieldValue::on($connection)
            ->whereHas('field', fn ($q) => $q->where('entity_type', $entityType))
            ->where('entity_id', $entityId)
            ->with('field:id,field_name,field_type')
            ->get()
            ->map(fn ($v) => [
                'custom_field_id' => $v->custom_field_id,
                'field_name' => $v->field->field_name,
                'field_type' => $v->field->field_type,
                'value' => $v->value,
            ])
            ->toArray();
    }
}
