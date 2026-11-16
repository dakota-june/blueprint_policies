<?php
// The draft as Blueprint's YAML parser would hand it to the lexers.
return [
    'models' => [
        'Post' => [
            'user_id' => 'id foreign',
            'title' => 'string:400',
            'status' => 'enum:draft,published',
            'body' => 'longtext',
            'softdeletes' => 'softDeletes',
        ],
        'Comment' => [
            'post_id' => 'id foreign',
            'body' => 'text',
        ],
        'Tag' => [
            'name' => 'string',
        ],
        'User' => [
            'name' => 'string',
        ],
    ],
    'controllers' => [
        'Post' => ['resource' => 'web'],
        'Comment' => ['index' => ['query' => 'all', 'render' => 'comment.index'], 'show' => ['render' => 'comment.show with:comment']],
    ],
    'policies' => [
        'Post' => [
            'before' => 'admin',
            'abilities' => [
                'viewAny' => 'public',
                'view' => 'public',
                'create' => 'verified',
                'update' => 'owner | role:editor',
                'delete' => 'owner & state:status=draft | admin',
                'publish' => 'role:editor & state:status=draft',
            ],
        ],
        'Comment' => null,
        'Tag' => ['owner' => false, 'abilities' => ['viewAny' => 'auth', 'create' => 'role:admin']],
    ],
];
