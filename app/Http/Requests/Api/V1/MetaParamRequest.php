<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class MetaParamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $req = $isUpdate ? 'sometimes' : 'required';

        return [
            'order_number'          => [$req, 'integer', 'min:0'],
            'field_path'            => [$req, 'string', 'max:255'],
            'param_name'            => [$req, 'string', 'max:255'],
            'column_path'           => ['nullable', 'string', 'max:255'],
            'label_name'            => ['nullable', 'string', 'max:255'],
            'data_type'             => [$req, 'string', 'max:50'],
            'data_mask'             => ['nullable', 'string', 'max:255'],
            'render_type'           => ['nullable', 'string', 'max:50'],
            'icon_cls'              => ['nullable', 'string', 'max:255'],
            'ref_meta_id'           => ['nullable', 'integer', 'exists:metas,id'],
            'is_primary'            => ['sometimes', 'boolean'],
            'is_show'               => ['sometimes', 'boolean'],
            'is_required'           => ['sometimes', 'boolean'],
            'is_criteria'           => ['sometimes', 'boolean'],
            'is_unique'             => ['sometimes', 'boolean'],
            'is_merge'              => ['sometimes', 'boolean'],
            'is_select'             => ['sometimes', 'boolean'],
            'is_mandatory_criteria' => ['sometimes', 'boolean'],
            'default_value'         => ['nullable', 'string', 'max:255'],
            'min_value'             => ['nullable', 'string', 'max:255'],
            'max_value'             => ['nullable', 'string', 'max:255'],
            'column_width'          => ['nullable', 'string', 'max:50'],
            'expression_string'     => ['nullable', 'string', 'max:255'],
            'json_config'           => ['nullable', 'array'],
        ];
    }
}