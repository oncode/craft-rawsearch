<?php

namespace oncode\rawsearch\services;

use CraftCms\Cms\Cms;
use Illuminate\Support\Facades\Event;
use oncode\rawsearch\events\RowScoreResolving;
use oncode\rawsearch\events\ScoreResolving;
use oncode\rawsearch\RawSearch;

/**
 * Sorts the found elements according to the weight configuration.
 */
class Sort
{
    /**
     * Calculates a score for every element row and sorts the rows by it (highest first).
     * In dev mode a `_score` array explains how the score was calculated.
     */
    public function weightedSort(array &$elementRows, string $normalizedQuery): void
    {
        $plugin = RawSearch::getInstance();
        $settings = $plugin->getSettings();
        $devMode = Cms::config()->devMode;
        $queryWords = array_filter(explode(' ', $normalizedQuery), fn($w) => $w !== '');

        $elementTypeMatchWeights = $plugin->elementTypeConfigs->getAllMatchWeights();
        $fieldMatchWeights = $plugin->fieldConfigs->getAllMatchWeights();

        foreach ($elementRows as $key => $elementRow) {
            if (empty($elementRow['rows'])) {
                $elementRows[$key]['score'] = 0;
                continue;
            }

            $explain = [];
            $score = $elementTypeMatchWeights[$elementRow['type']] ?? $settings->elementTypeMatchWeight;
            $explain[] = ['type' => 'Element Type Match', 'points' => $score];

            foreach ($elementRow['rows'] as $rowKey => $row) {
                $rowScore = 0;
                $rowExplain = [];
                $isTitle = in_array($row['attribute'], ['title', 'slug'], true);

                $matchWeight = $settings->fieldMatchWeight;
                $partialMatchWeight = $settings->partialFieldMatchWeight;

                if (isset($row['fieldId'], $fieldMatchWeights[$row['fieldId']])) {
                    $matchWeight = $fieldMatchWeights[$row['fieldId']]['exact'] ?? $matchWeight;
                    $partialMatchWeight = $fieldMatchWeights[$row['fieldId']]['partial'] ?? $partialMatchWeight;
                }

                $haystack = (string)($row['normalizedWords'] ?? '');

                foreach ($queryWords as $queryWord) {
                    $wordLength = mb_strlen($queryWord);
                    $pos = mb_strpos($haystack, $queryWord);

                    // count every occurrence, whole words get more points than partial ones
                    while ($pos !== false) {
                        $endPos = $pos + $wordLength;
                        $prevChar = $pos > 0 ? mb_substr($haystack, $pos - 1, 1) : ' ';
                        $nextChar = mb_substr($haystack, $endPos, 1);
                        $partialMatch = trim($prevChar) !== '' || trim($nextChar) !== '';

                        if ($isTitle) {
                            $points = $partialMatch ? $settings->partialTitleMatchWeight : $settings->titleMatchWeight;
                            $type = 'Title/Slug Match';
                        } else {
                            $points = $partialMatch ? $partialMatchWeight : $matchWeight;
                            $type = 'Field Match';
                        }

                        $rowScore += $points;
                        $rowExplain[] = ['type' => $type . ($partialMatch ? ' (Partial)' : ''), 'points' => $points, 'word' => $queryWord];
                        $pos = mb_strpos($haystack, $queryWord, $endPos);
                    }
                }

                if (Event::hasListeners(RowScoreResolving::class)) {
                    event($event = new RowScoreResolving($elementRow, $row, $rowScore, $normalizedQuery));

                    if ($event->score !== $rowScore) {
                        $rowExplain[] = ['type' => 'Points from event', 'points' => $event->score - $rowScore];
                        $rowScore = $event->score;
                    }
                }

                if ($devMode) {
                    $elementRows[$key]['rows'][$rowKey]['_score'] = $rowExplain;
                }

                $score += $rowScore;
            }

            if (Event::hasListeners(ScoreResolving::class)) {
                event($event = new ScoreResolving($elementRows[$key], null, $score, $normalizedQuery));

                if ($event->score !== $score) {
                    $explain[] = ['type' => 'Points from event', 'points' => $event->score - $score];
                    $score = $event->score;
                }
            }

            if ($devMode) {
                $elementRows[$key]['_score'] = $explain;
            }

            $elementRows[$key]['score'] = $score;
        }

        // stable sort, rows with the same score keep their order
        usort($elementRows, fn($a, $b) => $b['score'] <=> $a['score']);
    }
}
