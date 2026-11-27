<?php

require_once __DIR__ . '/site-root.php';

/**
 * The demo page shows the FAQ block in group mode (a group "General" with three FAQs), the migration has a documented mapping file and a runbook.
 */
$root = pkde_site_root();

it('seeds the General group with three FAQs and the FAQ block in group mode, with the hand-typed items as the fallback', function () use ($root) {
    $demo = file_get_contents("$root/bin/generate-demo-content.php");

    expect($demo)->toContain("wp_insert_term('General', 'pkde_faq_group'", "'post_type' => 'pkde_faq'", "'groups' => [\$faqGroupId]", '_pkde_faq_order', 'sage_accordion_item_block($question, $answer)')
        ->and(substr_count($demo, "['Do I need to do anything special for SEO?'") + substr_count($demo, "['Where are the questions and answers kept?'") + substr_count($demo, "['Who can change them?'"))->toBe(3);
});

it('documents the approved mapping file with an example that has the shape the migration reads', function () use ($root) {
    $mapping = include "$root/config/pkde-faq-groups.php.example";

    expect($mapping)->toHaveKeys(['groups', 'blocks'])
        ->and($mapping['groups'][0])->toHaveKeys(['name', 'slug', 'description', 'questions'])
        ->and($mapping['groups'][0]['questions'][0])->toMatch('/^[0-9a-f]{12}$/')
        ->and(array_keys($mapping['blocks'])[0])->toMatch('/^[^#\s]+#\d+$/')
        ->and(file_get_contents("$root/.gitignore"))->not->toContain('pkde-faq-groups.php.example');

    $runbook = file_get_contents("$root/bin/docs/faq-migration.md");

    expect($runbook)->toContain('faq-migrate.php', 'plan', 'apply mapping=config/pkde-faq-groups.php', 'The owner reviews and edits that file', 'staging', 'Production')
        ->and(file_get_contents("$root/README.md"))->toContain('bin/docs/faq-migration.md');
});
