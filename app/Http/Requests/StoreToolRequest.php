<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreToolRequest extends FormRequest
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
            'type' => ['required', 'in:api,function,command'],
            'config' => ['required', 'array'],
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
            'name.required' => 'The tool name is required.',
            'name.max' => 'The tool name must not exceed 255 characters.',
            'type.required' => 'The tool type is required.',
            'type.in' => 'The tool type must be one of: api, function, or command.',
            'config.required' => 'The tool configuration is required.',
            'config.array' => 'The tool configuration must be a valid array.',
        ];
    }
}
