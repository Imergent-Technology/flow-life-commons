<?php

declare(strict_types=1);

/*
 * Resources settings (ADR 0037). Tuning, not security: nothing here widens what the rich-content profile accepts, which file types
 * are allowed, or who may see a Resource. The document profile, the file allowlist, their limits and the audience catalog are code,
 * because they are contracts.
 */
return [

    'summary' => [
        /*
         * How many characters a DERIVED Card summary keeps before it is cut at a word boundary and ends in an ellipsis
         * (decision 35). Bounded in code to what a summary column holds, so a mistyped setting cannot break a write.
         */
        'derived_length' => 200,
    ],

    'assets' => [
        /*
         * The largest file a File Card may hold, in bytes (decision 64). The APPLICATION refuses anything larger
         * (`413 file_too_large`); it does not rely on PHP or the web server to. Bounded in code to 1-100 MiB. PHP's
         * `upload_max_filesize` must be at least this and `post_max_size` at least this plus 1 MiB for the rest of the form;
         * `security:production-check` fails otherwise (it reads the CLI's PHP; the web server's is an owner check). Lower it here
         * rather than leave uploads that PHP would reject before the application can answer.
         */
        'max_bytes' => (int) env('RESOURCES_ASSET_MAX_BYTES', 20 * 1024 * 1024),

        /*
         * How old an unreferenced file must be before `resources:assets:prune` removes it. An upload whose row has not committed
         * yet is unreferenced for a few seconds; this keeps the prune clear of it by a wide margin.
         */
        'prune_grace_hours' => 24,
    ],

];
