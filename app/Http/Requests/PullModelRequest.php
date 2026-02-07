<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PullModelRequest extends FormRequest
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
            'model' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9_\-\.:\/]+$/'],
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
            'model.required' => 'The model name is required.',
            'model.regex' => 'The model name contains invalid characters.',
        ];
    }
}
