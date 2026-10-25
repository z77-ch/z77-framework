<?php

/**
 * Example configuration of a standalone KiBot install.
 * Copy to kibot.config.php (gitignored) and adapt. Return only what deviates from the
 * defaults in src/Config.php. Relative paths resolve against the package root.
 * Never put the key itself here — only the path to the key file.
 */
return [
    'paths' => [
        // outside public/ and outside the repo (plan 7.1)
        'keyFile'    => '/home/<hosting>/.z77/<project>/keys/anthropic.key',
        'sourceRoot' => 'example',
        'topicsFile' => 'topics.example.json',
        'logDir'     => 'logs',
    ],
    'widget' => [
        'botName'    => 'Muster-Assistent',
        'heroTitle'  => 'Fragen zur Muster GmbH?',
        'welcomeMsg' => 'Guten Tag, ich beantworte Fragen zu Öffnungszeiten, Anfahrt und Preisen der Muster GmbH.',
    ],
    'prompt' => [
        'intro' => 'Du bist der Assistent der Website der Muster GmbH.',
    ],
];
