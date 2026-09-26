<?php

namespace oncode\rawsearch\db;

abstract class Table
{
    public const INDEX = '{{%rawsearch_index}}';
    public const ELEMENT_TYPE_CONFIGS = '{{%rawsearch_elementtypeconfigs}}';
    public const FIELD_CONFIGS = '{{%rawsearch_fieldconfigs}}';
    public const QUERIES = '{{%rawsearch_queries}}';
}
