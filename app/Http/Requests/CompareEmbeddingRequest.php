<?php

namespace App\Http\Requests;

use App\Models\Embedding;
use Illuminate\Foundation\Http\FormRequest;

class CompareEmbeddingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $userId = $this->user()->id;

        // Verify user owns any referenced embedding IDs
        $ids = $this->collectEmbeddingIds();

        if (empty($ids)) {
            return true;
        }

        return Embedding::whereIn('id', $ids)
            ->where('user_id', '!=', $userId)
            ->doesntExist();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'source' => ['required', 'array'],
            'source.id' => ['required_without:source.text', 'nullable', 'integer', 'exists:embeddings,id'],
            'source.text' => ['required_without:source.id', 'nullable', 'string', 'min:1', 'max:8192'],
            'targets' => ['required', 'array', 'min:1', 'max:500'],
            'targets.*.id' => ['required_without:targets.*.text', 'nullable', 'integer', 'exists:embeddings,id'],
            'targets.*.text' => ['required_without:targets.*.id', 'nullable', 'string', 'min:1', 'max:8192'],
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
            'source.required' => 'A source embedding is required.',
            'source.id.exists' => 'The source embedding ID does not exist.',
            'targets.required' => 'At least one target embedding is required.',
            'targets.min' => 'At least one target embedding is required.',
            'targets.max' => 'A maximum of 50 targets can be compared at once.',
        ];
    }

    /**
     * Collect all embedding IDs referenced in the request.
     *
     * @return array<int>
     */
    protected function collectEmbeddingIds(): array
    {
        $ids = [];

        if ($this->input('source.id')) {
            $ids[] = (int) $this->input('source.id');
        }

        foreach ($this->input('targets', []) as $target) {
            if (! empty($target['id'])) {
                $ids[] = (int) $target['id'];
            }
        }

        return $ids;
    }
}
