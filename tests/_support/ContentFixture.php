<?php

namespace oncode\rawsearch\tests;

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
use CraftCms\Cms\Validation\Contracts\Validatable;
use oncode\rawsearch\RawSearch;
use RuntimeException;

/**
 * Creates the test content: a German site, fields (incl. Matrix), a section and entries.
 * The words are unusual on purpose, so other content of the installation doesn't interfere.
 */
class ContentFixture
{
    public const SITE_HANDLE = 'rawsearchTestDe';
    public const SECTION_HANDLE = 'rawsearchTestPages';

    public Site $primarySite;
    public Site $deSite;
    public Section $section;
    public EntryType $pageType;

    /** @var array<string,int> Entry ids by fixture key */
    public array $ids = [];

    private static ?self $instance = null;

    public static function get(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->createStructure();
    }

    /**
     * The fixture entries. `de` is the content of the German site.
     */
    public static function entries(): array
    {
        return [
            'quokka' => [
                'title' => 'Quokka habitat protection',
                'rsIntro' => 'How rangers protect the quokka habitat.',
                'rsBody' => "Rottnest Island is home to the quokka. Every ranger helps: fences, water points & signs.\n\nThe quokkas are the most important species here.",
                'rsSecret' => 'xylophonist notes',
                'blocks' => ['Block about eucalyptus: leaves, bark and seeds.', 'Another block mentions the quokka near the ferry.'],
                'de' => [
                    'title' => 'Quokka Lebensraum',
                    'rsIntro' => 'Wie Ranger den Lebensraum schützen.',
                    'rsBody' => 'Die Quokkas leben auf der Insel. Müller & Söhne bauen Zäune – Grüße vom Ranger.',
                ],
            ],
            'ferry' => [
                'title' => 'Ferry timetable',
                'rsIntro' => 'Ferries of the week.',
                'rsBody' => 'Monday: 9am. Tuesday: 10am. The ferry carries quokkawatchers and cyclists.',
                'de' => [
                    'title' => 'Fährplan',
                    'rsIntro' => 'Fähren der Woche.',
                    'rsBody' => 'Montag: 9 Uhr. Die Fähre bringt Velofahrer.',
                ],
            ],
            'eucalyptus' => [
                'title' => 'About the eucalyptus project',
                'rsIntro' => 'Planting in numbers.',
                'rsBody' => 'In 2024 we planted 47 trees. <strong>Planting</strong> works &amp; it pays off. &lt;script&gt;alert(1)&lt;/script&gt;',
                'blocks' => ['Planted eucalyptus is used for shade.'],
            ],
            'englishOnly' => [
                'title' => 'Wombat burrows',
                'rsBody' => 'Wombats dig burrows near the quokka colony.',
                'disabledIn' => [self::SITE_HANDLE],
            ],
            'disabled' => [
                'title' => 'Disabled quokka entry',
                'rsBody' => 'quokka quokka quokka',
                'enabled' => false,
            ],
            'future' => [
                'title' => 'Future quokka entry',
                'rsBody' => 'quokka scheduled',
                'postDate' => new \DateTime('+1 year'),
            ],
            'expired' => [
                'title' => 'Expired quokka entry',
                'rsBody' => 'quokka expired',
                'expiryDate' => new \DateTime('-1 day'),
            ],
        ];
    }

    /**
     * Deletes and recreates all fixture entries and builds their index.
     */
    public function reset(): void
    {
        $ids = Entry::find()->sectionId($this->section->id)->status(null)->site('*')->trashed(null)->ids();

        foreach (array_unique($ids) as $id) {
            $entry = Entry::find()->id($id)->status(null)->trashed(null)->one();

            if ($entry) {
                Elements::deleteElement($entry, true);
            }
        }

        $this->ids = [];

        foreach (self::entries() as $key => $data) {
            $this->ids[$key] = $this->createEntry($data)->id;
        }

        self::runQueue();
    }

    public function entry(string $key, ?Site $site = null): Entry
    {
        $entry = Entry::find()
            ->id($this->ids[$key])
            ->siteId(($site ?? $this->primarySite)->id)
            ->status(null)
            ->one();

        if (!$entry) {
            throw new RuntimeException("Fixture entry $key not found");
        }

        return $entry;
    }

    /**
     * Indexes the elements RawSearch collected during saving (the tests run with the sync queue).
     */
    public static function runQueue(): void
    {
        RawSearch::getInstance()->index->pushQueuedElements();
    }

    private function createEntry(array $data): Entry
    {
        $entry = new Entry([
            'sectionId' => $this->section->id,
            'typeId' => $this->pageType->id,
            'siteId' => $this->primarySite->id,
            'title' => $data['title'],
            'enabled' => $data['enabled'] ?? true,
        ]);

        if (isset($data['postDate'])) {
            $entry->postDate = $data['postDate'];
        }

        if (isset($data['expiryDate'])) {
            $entry->expiryDate = $data['expiryDate'];
        }

        if (!empty($data['disabledIn'])) {
            $entry->setEnabledForSite([
                $this->primarySite->id => true,
                $this->deSite->id => !in_array(self::SITE_HANDLE, $data['disabledIn'], true),
            ]);
        }

        $blocks = [];

        foreach ($data['blocks'] ?? [] as $i => $text) {
            $blocks['new' . ($i + 1)] = ['type' => 'rsTextBlock', 'enabled' => true, 'fields' => ['rsBlockText' => $text]];
        }

        $entry->setFieldValues([
            'rsIntro' => $data['rsIntro'] ?? '',
            'rsBody' => $data['rsBody'] ?? '',
            'rsSecret' => $data['rsSecret'] ?? '',
            'rsBlocks' => ['entries' => $blocks, 'sortOrder' => array_keys($blocks)],
        ]);

        $this->save($entry);

        if (isset($data['de'])) {
            $deEntry = Entry::find()->id($entry->id)->siteId($this->deSite->id)->status(null)->one();
            $deEntry->title = $data['de']['title'];
            $deEntry->slug = null;
            $deEntry->setFieldValues(array_diff_key($data['de'], ['title' => true]));
            $this->save($deEntry);
        }

        return $entry;
    }

    private function createStructure(): void
    {
        $this->primarySite = Sites::getPrimarySite();
        $deSite = Sites::getSiteByHandle(self::SITE_HANDLE);

        if (!$deSite) {
            $deSite = new Site([
                'groupId' => $this->primarySite->groupId,
                'name' => 'RawSearch Test DE',
                'handle' => self::SITE_HANDLE,
                'language' => 'de-CH',
                'hasUrls' => true,
                'baseUrl' => $this->primarySite->getBaseUrl() . 'rawsearch-test-de',
            ]);
            $this->save($deSite, fn() => Sites::saveSite($deSite));
        }

        $this->deSite = $deSite;

        $field = function(string $class, string $handle, array $config = []) {
            $field = Fields::getFieldByHandle($handle);

            if (!$field) {
                $field = new $class(array_merge(['name' => $handle, 'handle' => $handle, 'translationMethod' => 'site'], $config));
                $this->save($field, fn() => Fields::saveField($field));
            }

            return $field;
        };

        $intro = $field(PlainText::class, 'rsIntro');
        $body = $field(PlainText::class, 'rsBody', ['multiline' => true]);
        $secret = $field(PlainText::class, 'rsSecret');
        $blockText = $field(PlainText::class, 'rsBlockText', ['multiline' => true]);

        $blockType = EntryTypes::getEntryTypeByHandle('rsTextBlock');

        if (!$blockType) {
            $blockType = new EntryType(['name' => 'RS Text Block', 'handle' => 'rsTextBlock', 'hasTitleField' => false]);
            $blockType->setFieldLayout($this->layout([$blockText], false));
            $this->save($blockType, fn() => EntryTypes::saveEntryType($blockType));
        }

        $matrix = $field(Matrix::class, 'rsBlocks', [
            'entryTypes' => [$blockType],
            'propagationMethod' => 'all',
            'translationMethod' => 'none',
        ]);

        $pageType = EntryTypes::getEntryTypeByHandle('rsPage');

        if (!$pageType) {
            $pageType = new EntryType(['name' => 'RS Page', 'handle' => 'rsPage']);
            $pageType->setFieldLayout($this->layout([$intro, $body, $secret, $matrix]));
            $this->save($pageType, fn() => EntryTypes::saveEntryType($pageType));
        }

        $this->pageType = $pageType;
        $section = Sections::getSectionByHandle(self::SECTION_HANDLE);

        if (!$section) {
            $section = new Section([
                'name' => 'RawSearch Test Pages',
                'handle' => self::SECTION_HANDLE,
                'type' => SectionType::Channel,
                'propagationMethod' => PropagationMethod::All,
            ]);
            $section->setEntryTypes([$pageType]);
            $siteSettings = [];

            foreach ([$this->primarySite, $this->deSite] as $site) {
                $siteSettings[$site->id] = new SectionSiteSettings([
                    'siteId' => $site->id,
                    'enabledByDefault' => true,
                    'hasUrls' => true,
                    'uriFormat' => 'rawsearch-test/{slug}',
                    'template' => '',
                ]);
            }

            $section->setSiteSettings($siteSettings);
            $this->save($section, fn() => Sections::saveSection($section));
        }

        $this->section = $section;
    }

    private function layout(array $fields, bool $withTitle = true): FieldLayout
    {
        $elements = $withTitle ? [new EntryTitleField()] : [];

        foreach ($fields as $field) {
            $elements[] = new CustomField($field);
        }

        $layout = new FieldLayout(['type' => Entry::class]);
        $layout->setTabs([new FieldLayoutTab(['name' => 'Content', 'layout' => $layout, 'elements' => $elements])]);

        return $layout;
    }

    private function save(object $model, ?callable $save = null): void
    {
        $saved = $save ? $save() : Elements::saveElement($model);

        if (!$saved) {
            $errors = $model instanceof Validatable ? $model->errors()->getMessages() : [];
            throw new RuntimeException('Could not save ' . get_class($model) . ': ' . json_encode($errors));
        }
    }
}
