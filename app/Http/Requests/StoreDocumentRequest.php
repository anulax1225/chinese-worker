<?php

namespace App\Http\Requests;

use App\Enums\DocumentSourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
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
        $maxSize = config('document.extraction.max_file_size', 50 * 1024 * 1024) / 1024; // Convert to KB

        return [
            'source_type' => ['required', Rule::enum(DocumentSourceType::class)],
            'title' => ['nullable', 'string', 'max:255'],

            // File upload
            'file' => [
                'required_if:source_type,upload',
                'file',
                'max:'.$maxSize,
            ],

            // URL ingestion
            'url' => [
                'required_if:source_type,url',
                'url',
                'max:2048',
            ],

            // Text paste
            'text' => [
                'required_if:source_type,paste',
                'string',
                'max:'.(5 * 1024 * 1024), // 5MB of text
            ],
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
            'source_type.required' => 'The source type is required.',
            'source_type.enum' => 'Invalid source type. Must be one of: upload, url, paste.',
            'file.required_if' => 'A file is required when source type is upload.',
            'file.max' => 'The file size exceeds the maximum allowed size.',
            'url.required_if' => 'A URL is required when source type is url.',
            'url.url' => 'The URL must be a valid URL.',
            'text.required_if' => 'Text content is required when source type is paste.',
            'text.max' => 'The text content exceeds the maximum allowed size.',
        ];
    }
}
