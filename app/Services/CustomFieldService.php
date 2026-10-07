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
     *
     * TENANT ISOLATION (PRD Section 104 — "every database query must
     * enforce tenant filtering by company_id"). This previously loaded
     * custom fields with NO company filter, which broke two ways:
     * (1) the "required" check picked up EVERY company's required fields
     *     in the region, so one company marking a field required made
     *     every other company's save fail with "X is required" for a
     *     field they don't even have;
     * (2) a submitted field ID was accepted if it existed anywhere in the
     *     region, so one company could write values against — and read
     *     back the name/type of — another company's field.
     *
     * Now the caller's OWN fields for this entity type are loaded once,
     * and both the required-check and the per-value lookup are resolved
     * against that set in memory. A foreign ID and a non-existent ID are
     * indistinguishable (same "does not exist" error), so another
     * tenant's field IDs can't be probed. As a side effect, user-supplied
     * keys no longer reach SQL, so a malformed (non-UUID) key can't
     * trigger a Postgres "invalid uuid" 500 either.
     */
    public function validateValues(string $entityType, array $values, string $companyId, string $connection): array
    {
        $errors = [];

        $fields = CustomField::on($connection)
            ->where('company_id', $companyId)
            ->where('entity_type', $entityType)
            ->get()
            ->keyBy('id');

        // REQUIRED fields that weren't submitted at all — only this
        // company's own, from the same already-loaded set.
        foreach ($fields->where('required', true) as $field) {
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
     *
     * NOTE: custom_field_values has no company_id column, so the tenant
     * boundary on this write rests entirely on validateValues() having
     * already rejected any field ID outside the caller's company. Never
     * call this with values that haven't been through validateValues().
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

    /**
     * custom_field_values has no company_id column of its own, so the
     * tenant filter (PRD Section 104) is applied through the field the
     * value belongs to — a value attached to another company's field is
     * never returned, even if such a row exists from before this fix.
     */
    public function getValues(string $entityType, string $entityId, string $companyId, string $connection): array
    {
        return CustomFieldValue::on($connection)
            ->whereHas('field', fn ($q) => $q->where('company_id', $companyId)->where('entity_type', $entityType))
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
