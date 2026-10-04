<?php

declare(strict_types=1);

/*
 * Resources settings (ADR 0037). Tuning, not security: nothing here widens what the rich-content profile accepts or who may
 * see a Resource. The document profile, its limits and the audience catalog are code, because they are contracts.
 */
return [

    'summary' => [
        /*
         * How many characters a DERIVED Card summary keeps before it is cut at a word boundary and ends in an ellipsis
         * (decision 35). Bounded in code to what a summary column holds, so a mistyped setting cannot break a write.
         */
        'derived_length' => 200,
    ],

];
