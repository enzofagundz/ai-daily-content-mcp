<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content classification
    |--------------------------------------------------------------------------
    |
    | Single source for how monitored posts are triaged for possible LinkedIn
    | content: provider credentials, deterministic relevance thresholds and
    | the editorial instructions sent to the classifier.
    |
    */

    'provider' => env('CONTENT_CLASSIFIER_PROVIDER', 'cloudflare'),

    'cloudflare' => [
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        'model' => env('CLOUDFLARE_AI_MODEL', '@cf/cloudflare/clef-flash'),
        'base_url' => env('CLOUDFLARE_AI_BASE_URL', 'https://api.cloudflare.com/client/v4'),
        'timeout' => (int) env('CONTENT_CLASSIFIER_TIMEOUT', 30),
    ],

    'thresholds' => [
        'relevance' => (float) env('CONTENT_CLASSIFIER_RELEVANCE_THRESHOLD', 0.70),
        'profile_fit' => (float) env('CONTENT_CLASSIFIER_PROFILE_THRESHOLD', 0.70),
        'content_value' => (float) env('CONTENT_CLASSIFIER_MIN_VALUE_SCORE', 2.0),
        'adaptability' => (float) env('CONTENT_CLASSIFIER_MIN_ADAPTABILITY_SCORE', 2.0),
        'missing_media' => (float) env('CONTENT_CLASSIFIER_MISSING_MEDIA_THRESHOLD', 0.50),
    ],

    'editorial' => [
        'audience' => 'desenvolvedor brasileiro no LinkedIn',
        'topics' => [
            'desenvolvimento de software',
            'Laravel/PHP',
            'IA aplicada ao desenvolvimento',
            'agentes de código',
            'ferramentas para desenvolvedores',
            'produtividade de programação',
            'engenharia de software',
            'carreira em tecnologia',
        ],
        'prioritize' => [
            'posts que tenham uma ideia interessante',
            'posts que apresentem uma ferramenta ou técnica',
            'posts que tragam uma descoberta',
            'posts que expliquem algo de forma prática',
            'posts que apresentem dados ou experimentos',
            'posts com potencial para gerar discussão',
            'posts que possam ser explicados em português sem depender excessivamente de contexto',
        ],
        'avoid' => [
            'autopromoção vazia',
            'anúncios sem informação útil',
            'conteúdo excessivamente pessoal',
            'frases genéricas',
            'memes',
            'discussões políticas',
            'conteúdo puramente promocional',
            'posts cujo único valor seja uma opinião sem argumento',
            'conteúdo que dependa de uma imagem ou vídeo que não conseguimos acessar',
        ],
    ],

    'questions' => [
        'relevant' => 'Este post contém uma ideia ou informação que vale a pena considerar para possível adaptação em conteúdo para o LinkedIn?',
        'category' => 'Qual é a categoria principal deste post?',
        'content_value' => 'Qual é o valor editorial deste post para possível adaptação em conteúdo?',
        'linkedin_adaptability' => 'Quão adaptável este conteúdo é para um post no LinkedIn?',
        'fits_profile' => 'Este conteúdo está alinhado com os temas e posicionamento profissional definidos nas instruções editoriais?',
        'requires_missing_media' => 'O valor principal deste post depende de uma imagem, vídeo ou outro conteúdo visual que não está disponível para análise?',
    ],

    'categories' => [
        'ai' => 'Inteligência artificial aplicada',
        'programming' => 'Programação e linguagens',
        'software_engineering' => 'Engenharia de software, arquitetura e práticas',
        'developer_tools' => 'Ferramentas para desenvolvedores',
        'web_development' => 'Desenvolvimento web',
        'career' => 'Carreira em tecnologia',
        'product' => 'Produto e negócios de tecnologia',
        'other' => 'Outro tema relevante',
    ],

    'scores' => [
        'content_value' => [
            0 => 'pouco ou nenhum valor editorial',
            1 => 'valor limitado',
            2 => 'ideia interessante',
            3 => 'conteúdo prático ou relevante',
            4 => 'conteúdo com forte potencial editorial',
        ],
        'linkedin_adaptability' => [
            0 => 'difícil ou inadequado para adaptação',
            1 => 'pouca possibilidade de adaptação',
            2 => 'pode ser adaptado com contexto adicional',
            3 => 'facilmente adaptável',
            4 => 'possui uma ideia clara que pode ser explicada para o público',
        ],
    ],

];
