<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WebsiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = ['expected_version' => ['required', 'integer', 'min:1'],
            'change_note' => ['required', 'string', 'min:3', 'max:255']];
        if (! $this->isMethod('patch')) {
            return $rules;
        }
        $rules['content'] = ['required', 'array:brand,home,pricing,features,steps,faqs,media,footer,sections'];
        $groups = [
            'brand' => ['name' => 80, 'tagline' => 120],
            'home' => ['title' => 120, 'description' => 320, 'eyebrow' => 100, 'headline' => 140,
                'intro' => 600, 'cta_label' => 60, 'features_title' => 140, 'features_intro' => 400,
                'steps_title' => 140, 'pricing_title' => 140, 'pricing_intro' => 400, 'faq_title' => 140,
                'final_title' => 140, 'final_intro' => 400],
            'pricing' => ['title' => 120, 'description' => 320, 'headline' => 140, 'intro' => 600],
        ];
        foreach ($groups as $group => $fields) {
            $rules['content.'.$group] = ['required', 'array:'.implode(',', array_keys($fields))];
            foreach ($fields as $field => $length) {
                $rules['content.'.$group.'.'.$field] = ['required', 'string', 'max:'.$length];
            }
        }
        foreach (['features' => ['title', 'body'], 'steps' => ['title', 'body'], 'faqs' => ['question', 'answer']] as $list => $fields) {
            $rules['content.'.$list] = ['required', 'array', 'list', 'min:1', 'max:12'];
            $rules['content.'.$list.'.*'] = ['required', 'array:'.implode(',', $fields)];
            foreach ($fields as $field) {
                $rules['content.'.$list.'.*.'.$field] = ['required', 'string', 'max:'.(in_array($field, ['title', 'question']) ? 160 : 1200)];
            }
        }
        $rules['content.sections'] = ['required', 'array:order,hidden'];
        $rules['content.sections.order'] = ['required', 'array', 'list', 'size:4'];
        $rules['content.sections.hidden'] = ['present', 'array', 'list', 'max:4'];
        foreach (['order', 'hidden'] as $field) {
            $rules['content.sections.'.$field.'.*'] = ['required', 'string', 'distinct', Rule::in(['features', 'steps', 'pricing', 'faq'])];
        }
        $rules['content.media'] = ['required', 'array:hero_url,hero_alt,og_image_url,og_image_alt,logo_url'];
        foreach (['hero_url', 'og_image_url', 'logo_url'] as $field) {
            $rules['content.media.'.$field] = ['present', 'nullable', 'string', 'max:2048', function ($attribute, $value, $fail) {
                if (! is_string($value)) {
                    return;
                }
                $parts = parse_url($value);
                $relative = str_starts_with($value, '/') && ! str_starts_with($value, '//');
                $absolute = is_array($parts) && in_array($parts['scheme'] ?? '', ['http', 'https'], true) && isset($parts['host']);
                if ((! $relative && ! $absolute) || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\\\\]/', $value)) {
                    $fail('Use a local image path or a valid HTTP(S) image URL.');
                }
            }];
        }
        $rules['content.media.hero_alt'] = ['required', 'string', 'max:250'];
        $rules['content.media.og_image_alt'] = ['required', 'string', 'max:250'];
        $rules['content.footer'] = ['required', 'array:description,contact_email'];
        $rules['content.footer.description'] = ['required', 'string', 'max:300'];
        $rules['content.footer.contact_email'] = ['present', 'nullable', 'email:rfc', 'max:254'];

        return $rules;
    }
}
