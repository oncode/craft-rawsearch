<?php
// Creates test fields, a section, a second site and entries for testing RawSearch.
// Run by setup.sh, again with: docker compose run --rm php php /app/seed.php

require '/app/site/vendor/autoload.php';
$app = require '/app/site/bootstrap/app.php';
$app->singleton(Illuminate\Contracts\Console\Kernel::class, CraftCms\Cms\Console\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use CraftCms\Cms\Element\Enums\PropagationMethod;
use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Matrix;
use CraftCms\Cms\Field\PlainText;
use CraftCms\Cms\FieldLayout\FieldLayout;
use CraftCms\Cms\FieldLayout\FieldLayoutTab;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\FieldLayout\LayoutElements\Entries\EntryTitleField;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Data\SectionSiteSettings;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;

function fail($what, $model) {
    fwrite(STDERR, "$what failed: " . json_encode($model->errors()->getMessages()) . "\n");
    exit(1);
}

// second site (German)
$de = Sites::getSiteByHandle('de');
if (!$de) {
    $de = new Site([
        'groupId' => Sites::getPrimarySite()->groupId,
        'name' => 'Deutsch',
        'handle' => 'de',
        'language' => 'de-CH',
        'hasUrls' => true,
        'baseUrl' => Sites::getPrimarySite()->getBaseUrl() . 'de',
    ]);
    Sites::saveSite($de) || fail('site', $de);
}

// fields
$make = function(string $class, string $handle, string $name, array $config = []) {
    $field = Fields::getFieldByHandle($handle);
    if (!$field) {
        $field = new $class(array_merge(['name' => $name, 'handle' => $handle], $config));
        Fields::saveField($field) || fail("field $handle", $field);
    }
    return $field;
};

$body = $make(PlainText::class, 'body', 'Body', ['multiline' => true, 'translationMethod' => 'site']);
$intro = $make(PlainText::class, 'intro', 'Intro', ['translationMethod' => 'site']);
$secret = $make(PlainText::class, 'secret', 'Secret notes', ['translationMethod' => 'site']);
$blockText = $make(PlainText::class, 'blockText', 'Block text', ['multiline' => true, 'translationMethod' => 'site']);

$layoutFor = function(array $fieldsList, bool $withTitle = true) {
    $elements = $withTitle ? [new EntryTitleField()] : [];
    foreach ($fieldsList as $f) {
        $elements[] = new CustomField($f);
    }
    $layout = new FieldLayout(['type' => Entry::class]);
    $layout->setTabs([new FieldLayoutTab(['name' => 'Content', 'layout' => $layout, 'elements' => $elements])]);
    return $layout;
};

// matrix block entry type
$blockType = EntryTypes::getEntryTypeByHandle('textBlock');
if (!$blockType) {
    $blockType = new EntryType(['name' => 'Text Block', 'handle' => 'textBlock', 'hasTitleField' => false]);
    $blockType->setFieldLayout($layoutFor([$blockText], false));
    EntryTypes::saveEntryType($blockType) || fail('block type', $blockType);
}

$matrix = $make(Matrix::class, 'contentBlocks', 'Content Blocks', [
    'entryTypes' => [$blockType],
    'propagationMethod' => 'all',
]);

// page entry type + section
$pageType = EntryTypes::getEntryTypeByHandle('page');
if (!$pageType) {
    $pageType = new EntryType(['name' => 'Page', 'handle' => 'page']);
    $pageType->setFieldLayout($layoutFor([$intro, $body, $secret, $matrix]));
    EntryTypes::saveEntryType($pageType) || fail('page type', $pageType);
}

$section = Sections::getSectionByHandle('pages');
if (!$section) {
    $section = new Section([
        'name' => 'Pages',
        'handle' => 'pages',
        'type' => SectionType::Channel,
        'propagationMethod' => PropagationMethod::All,
    ]);
    $section->setEntryTypes([$pageType]);
    $settings = [];
    foreach (Sites::getAllSites() as $site) {
        $settings[$site->id] = new SectionSiteSettings([
            'siteId' => $site->id,
            'enabledByDefault' => true,
            'hasUrls' => true,
            'uriFormat' => 'pages/{slug}',
            'template' => 'page',
        ]);
    }
    $section->setSiteSettings($settings);
    Sections::saveSection($section) || fail('section', $section);
}

// entries
$data = [
    [
        'title' => 'Environmental protection at work',
        'intro' => 'How employees contribute to the environment.',
        'body' => "Our company takes environmental protection seriously. Every employee can help: switch off the lights, use the stairs and recycle paper.\n\nThe environment is our most important resource, and protecting it is a team effort.",
        'secret' => 'hiddenword should never be found',
        'blocks' => ['A block about recycling: glass, paper and aluminium are collected separately.', 'Another block mentions the environment again, next to the canteen.'],
        'de' => [
            'title' => 'Umweltschutz bei der Arbeit',
            'intro' => 'Wie Mitarbeitende zur Umwelt beitragen.',
            'body' => 'Unser Unternehmen nimmt den Umweltschutz ernst. Jeder Mitarbeiter kann helfen: Licht löschen, Treppe benutzen und Papier rezyklieren. Die Umwelt ist unsere wichtigste Ressource – Müller und Söhne machen mit.',
        ],
    ],
    [
        'title' => 'Canteen menu',
        'intro' => 'The menu of the week.',
        'body' => 'Monday: pasta. Tuesday: curry. Wednesday: fish & chips. The canteen uses regional and environmentally friendly products.',
        'secret' => '',
        'blocks' => [],
        'de' => [
            'title' => 'Menüplan der Kantine',
            'intro' => 'Das Menü der Woche.',
            'body' => 'Montag: Pasta. Dienstag: Curry. Die Kantine verwendet regionale und umweltfreundliche Produkte. Grüße aus der Küche!',
        ],
    ],
    [
        'title' => 'About the recycling project',
        'intro' => 'Recycling in numbers.',
        'body' => 'In 2024 we recycled 12 tons of paper. <strong>Recycling</strong> works &amp; it pays off. About it: see the report.',
        'secret' => '',
        'blocks' => ['Recycled paper is used for all printers.'],
        'de' => null,
    ],
    [
        'title' => 'Annual environment report',
        'intro' => 'What we achieved for the environment this year.',
        'body' => "Our environment team had a busy year. Since 12. March 2024 we measure the energy use of every building, e.g. the offices in Zurich and the depot in Winterthur.\n\nDr. Weber presented the results at the environment forum. The numbers are clear: we used approx. 14% less energy than last year!\n\nThis report is long on purpose, because the sentence snippets need a sentence that is longer than the maximum length so that it gets shortened to the words around the found word, and this sentence also mentions the environment somewhere in the middle before it finally ends after many more words about nothing in particular.\n\nNext year we want to do even more. Ideas are welcome, write to the environment team.",
        'secret' => '',
        'blocks' => ['Did you know? Recycling one aluminium can saves enough energy to run a TV for three hours.'],
        'de' => null,
    ],
    [
        'title' => 'Disabled entry about the environment',
        'intro' => 'Should not show up.',
        'body' => 'environment environment environment',
        'secret' => '',
        'blocks' => [],
        'de' => null,
        'enabled' => false,
    ],
    [
        'title' => 'Future entry about the environment',
        'intro' => 'Should not show up before it is published.',
        'body' => 'environment scheduled',
        'secret' => '',
        'blocks' => [],
        'de' => null,
        'postDate' => new DateTime('+1 year'),
    ],
];

foreach ($data as $item) {
    if (Entry::find()->sectionId($section->id)->title($item['title'])->status(null)->exists()) {
        continue;
    }

    $entry = new Entry([
        'sectionId' => $section->id,
        'typeId' => $pageType->id,
        'title' => $item['title'],
        'enabled' => $item['enabled'] ?? true,
    ]);
    if (isset($item['postDate'])) {
        $entry->postDate = $item['postDate'];
    }

    $blocks = [];
    foreach ($item['blocks'] as $i => $text) {
        $blocks['new' . ($i + 1)] = ['type' => 'textBlock', 'enabled' => true, 'fields' => ['blockText' => $text]];
    }

    $entry->setFieldValues([
        'intro' => $item['intro'],
        'body' => $item['body'],
        'secret' => $item['secret'],
        'contentBlocks' => ['entries' => $blocks, 'sortOrder' => array_keys($blocks)],
    ]);
    Elements::saveElement($entry) || fail('entry ' . $item['title'], $entry);

    if ($item['de']) {
        $deEntry = Entry::find()->id($entry->id)->siteId($de->id)->status(null)->one();
        $deEntry->title = $item['de']['title'];
        $deEntry->slug = null;
        $deEntry->setFieldValues(['intro' => $item['de']['intro'], 'body' => $item['de']['body']]);
        Elements::saveElement($deEntry) || fail('de entry', $deEntry);
    }

    echo "Created: {$item['title']}\n";
}

// push the index jobs for the saved entries
oncode\rawsearch\RawSearch::getInstance()->index->pushQueuedElements();

// saves the project config and releases its lock, like at the end of a request
$app->terminate();
echo "Done\n";
