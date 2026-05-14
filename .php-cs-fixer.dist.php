<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->exclude('vendor')
    ->exclude('Tests')
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'protected_to_private' => false,
        'native_constant_invocation' => ['strict' => false],
        'nullable_type_declaration_for_default_null_value' => ['use_nullable_type_declaration' => true],
        'no_superfluous_phpdoc_tags' => ['remove_inheritdoc' => true],
        'header_comment' => [
            'header' => <<<'EOF'
                This file is part of the Cloudflare Mailer package.

                (c) Julien Ramel <julien@ramel.io>

                For the full copyright and license information, please view the LICENSE
                file that was distributed with this source code.
                EOF,
        ],
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder)
;
