<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSystemPromptRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:system_prompts,slug'],
            'template' => ['required', 'string'],
            'required_variables' => ['nullable', 'array'],
            'required_variables.*' => ['string'],
            'default_values' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'The prompt name is required.',
            'name.max' => 'The prompt name must not exceed 255 characters.',
            'slug.required' => 'The slug is required.',
            'slug.unique' => 'A prompt with this slug already exists.',
            'template.required' => 'The template content is required.',
        ];
    }
}
