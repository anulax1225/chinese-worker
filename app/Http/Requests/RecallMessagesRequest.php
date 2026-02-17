<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecallMessagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'min:1', 'max:2000'],
            'top_k' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'threshold' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'hybrid' => ['sometimes', 'boolean'],
        ];
    }
}
