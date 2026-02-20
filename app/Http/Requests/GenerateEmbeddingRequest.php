<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateEmbeddingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize string input to array before validation.
     */
    protected function prepareForValidation(): void
    {
        $input = $this->input('input');

        if (is_string($input)) {
            $this->merge(['input' => [$input]]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'input' => ['required', 'array', 'min:1'],
            'input.*' => ['required', 'string', 'min:1', 'max:8192'],
            'model' => ['nullable', 'string'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'input.required' => 'The input text is required.',
            'input.*.min' => 'Each input text must be at least 1 character.',
            'input.*.max' => 'Each input text must not exceed 8192 characters.',
        ];
    }

    /**
     * Get normalized array of texts.
     *
     * @return array<string>
     */
    public function getTexts(): array
    {
        return array_values($this->input('input'));
    }

    /**
     * Get the model to use for embedding.
     */
    public function getModel(): ?string
    {
        return $this->input('model');
    }
}
