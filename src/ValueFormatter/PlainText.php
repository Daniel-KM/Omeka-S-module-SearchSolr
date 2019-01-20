<?php

namespace Solr\ValueFormatter;

/**
 * ValueFormatter to strip all HTML tags. Useful for HTML media content.
 */
class PlainText implements ValueFormatterInterface
{
    public function getLabel()
    {
        return 'Plain text'; // @translate
    }

    public function format($value)
    {
        return strip_tags($value);
    }
}
