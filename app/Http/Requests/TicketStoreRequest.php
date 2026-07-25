<?php

namespace App\Http\Requests;

use App\Enums\TicketPriority;
use App\Enums\TicketType;
use App\Models\TicketCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TicketStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $categoryKeys = array_keys(config('tickets.categories', []));

        return [
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'client_id' => ['required', 'exists:clients,id'],
            'contact_id' => ['nullable', 'exists:people,id'],
            'asset_id' => ['nullable', 'exists:assets,id'],
            'type' => ['required', Rule::enum(TicketType::class)],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'category' => ['nullable', 'string', 'max:100', Rule::in($categoryKeys)],
            'subcategory' => ['nullable', 'string', 'max:100'],
            // ITIL taxonomy node (so-0ftg) — distinct from the legacy free-text
            // pair above. A category chosen at creation must be an ACTIVE node;
            // retired/unknown ids are rejected. No grandfather branch (unlike
            // TicketUpdateRequest): a brand-new ticket has no current node to
            // preserve. Nullable = an explicit "Uncategorized" create (blank =>
            // null via the convert-empty-strings middleware, so the closure is
            // skipped). category_source is stamped by TicketObserver, not input.
            'category_id' => [
                'nullable',
                function (string $attribute, $value, $fail): void {
                    if (blank($value)) {
                        return; // no category chosen — nothing to check
                    }

                    $isActiveNode = TicketCategory::query()
                        ->whereKey($value)
                        ->where('is_active', true)
                        ->exists();

                    if (! $isActiveNode) {
                        $fail('The selected SOP category must be an active taxonomy node.');
                    }
                },
            ],
            'assignee_id' => ['nullable', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $category = $this->input('category');
            $subcategory = $this->input('subcategory');

            if ($category && $subcategory) {
                $validSubs = config("tickets.categories.{$category}", []);
                if (! in_array($subcategory, $validSubs, true)) {
                    $validator->errors()->add('subcategory', 'Invalid subcategory for the selected category.');
                }
            }
        });
    }
}
