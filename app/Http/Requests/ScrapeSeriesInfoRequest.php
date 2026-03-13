<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScrapeSeriesInfoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'list_page_url' => ['required', 'string', 'url', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'list_page_url.required' => 'L\'URL de la page liste est obligatoire.',
            'list_page_url.url' => 'Veuillez fournir une URL valide.',
        ];
    }
}
