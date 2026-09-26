<?php

namespace oncode\rawsearch\tests;

use Craft;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
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
        $elements = Craft::$app->getElements();

        foreach (Entry::find()->sectionId($this->section->id)->status(null)->site('*')->unique()->trashed(null)->all() as $entry) {
            $elements->deleteElement($entry, true);
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
     * Pushes the elements RawSearch collected during saving and runs the queue.
     */
    public static function runQueue(): void
    {
        RawSearch::getInstance()->index->pushQueuedElements();
        Craft::$app->getQueue()->run();
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
        $sites = Craft::$app->getSites();
        $fields = Craft::$app->getFields();
        $entries = Craft::$app->getEntries();

        $this->primarySite = $sites->getPrimarySite();
        $deSite = $sites->getSiteByHandle(self::SITE_HANDLE);

        if (!$deSite) {
            $deSite = new Site([
                'groupId' => $this->primarySite->groupId,
                'name' => 'RawSearch Test DE',
                'handle' => self::SITE_HANDLE,
                'language' => 'de-CH',
                'hasUrls' => true,
                'baseUrl' => '@web/rawsearch-test-de',
            ]);
            $this->save($deSite, fn() => $sites->saveSite($deSite));
        }

        $this->deSite = $deSite;

        $field = function(string $class, string $handle, array $config = []) use ($fields) {
            $field = $fields->getFieldByHandle($handle);

            if (!$field) {
                $field = new $class(array_merge(['name' => $handle, 'handle' => $handle, 'translationMethod' => 'site'], $config));
                $this->save($field, fn() => $fields->saveField($field));
            }

            return $field;
        };

        $intro = $field(PlainText::class, 'rsIntro');
        $body = $field(PlainText::class, 'rsBody', ['multiline' => true]);
        $secret = $field(PlainText::class, 'rsSecret');
        $blockText = $field(PlainText::class, 'rsBlockText', ['multiline' => true]);

        $blockType = $entries->getEntryTypeByHandle('rsTextBlock');

        if (!$blockType) {
            $blockType = new EntryType(['name' => 'RS Text Block', 'handle' => 'rsTextBlock', 'hasTitleField' => false]);
            $blockType->setFieldLayout($this->layout([$blockText], false));
            $this->save($blockType, fn() => $entries->saveEntryType($blockType));
        }

        $matrix = $field(Matrix::class, 'rsBlocks', [
            'entryTypes' => [$blockType],
            'propagationMethod' => 'all',
            'translationMethod' => 'none',
        ]);

        $pageType = $entries->getEntryTypeByHandle('rsPage');

        if (!$pageType) {
            $pageType = new EntryType(['name' => 'RS Page', 'handle' => 'rsPage']);
            $pageType->setFieldLayout($this->layout([$intro, $body, $secret, $matrix]));
            $this->save($pageType, fn() => $entries->saveEntryType($pageType));
        }

        $this->pageType = $pageType;
        $section = $entries->getSectionByHandle(self::SECTION_HANDLE);

        if (!$section) {
            $section = new Section([
                'name' => 'RawSearch Test Pages',
                'handle' => self::SECTION_HANDLE,
                'type' => Section::TYPE_CHANNEL,
                'propagationMethod' => Section::PROPAGATION_METHOD_ALL,
            ]);
            $section->setEntryTypes([$pageType]);
            $siteSettings = [];

            foreach ([$this->primarySite, $this->deSite] as $site) {
                $siteSettings[$site->id] = new Section_SiteSettings([
                    'siteId' => $site->id,
                    'enabledByDefault' => true,
                    'hasUrls' => true,
                    'uriFormat' => 'rawsearch-test/{slug}',
                    'template' => '',
                ]);
            }

            $section->setSiteSettings($siteSettings);
            $this->save($section, fn() => $entries->saveSection($section));
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
        $saved = $save ? $save() : Craft::$app->getElements()->saveElement($model);

        if (!$saved) {
            throw new RuntimeException('Could not save ' . get_class($model) . ': ' . json_encode($model->getErrors()));
        }
    }
}
