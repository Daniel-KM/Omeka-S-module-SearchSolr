<?php declare(strict_types=1);

namespace SearchSolrTest\Unit\Stdlib;

use PHPUnit\Framework\TestCase;
use SearchSolr\Stdlib\SuggesterFields;

/**
 * @group unit
 * @group suggester
 */
class SuggesterFieldsTest extends TestCase
{
    public function testNoCatchallKeepsFields(): void
    {
        $fields = ['dcterms_title_txt', 'dcterms_creator_txt'];
        $this->assertSame($fields, SuggesterFields::reduceToCatchall($fields));
    }

    public function testTextCatchallReducesToText(): void
    {
        $this->assertSame(
            ['_text_'],
            SuggesterFields::reduceToCatchall(['_text_', 'dcterms_title_txt'])
        );
    }

    public function testSuggestCatchallReducesToSuggest(): void
    {
        $this->assertSame(
            ['suggest_txt'],
            SuggesterFields::reduceToCatchall(['suggest_txt', 'dcterms_title_txt'])
        );
    }

    public function testSuggestCatchallHasPriorityOverText(): void
    {
        $this->assertSame(
            ['suggest_txt'],
            SuggesterFields::reduceToCatchall(['_text_', 'suggest_txt', 'dcterms_title_txt'])
        );
    }

    public function testEmptyStaysEmpty(): void
    {
        $this->assertSame([], SuggesterFields::reduceToCatchall([]));
    }
}
