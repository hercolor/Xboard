<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class SingBoxTemplateContractTest extends TestCase
{
    public function test_default_template_uses_core_compatible_rule_field_types(): void
    {
        $template = json_decode(file_get_contents(base_path('resources/rules/default.sing-box.json')), true, 512, JSON_THROW_ON_ERROR);

        foreach (['dns', 'route'] as $section) {
            foreach (data_get($template, $section . '.rules', []) as $index => $rule) {
                $this->assertArrayNotHasKey(
                    'clash_mode',
                    $rule,
                    sprintf('%s.rules[%d].clash_mode is intentionally omitted for 4376 APP import compatibility', $section, $index)
                );

                $arrayFields = $section === 'dns'
                    ? ['outbound', 'protocol', 'domain_suffix', 'domain_keyword', 'rule_set']
                    : ['protocol', 'domain_suffix', 'domain_keyword', 'rule_set'];

                foreach ($arrayFields as $field) {
                    if (! array_key_exists($field, $rule)) {
                        continue;
                    }

                    $this->assertIsArray(
                        $rule[$field],
                        sprintf('%s.rules[%d].%s must be an array for core import', $section, $index, $field)
                    );
                }
            }
        }
    }
}
